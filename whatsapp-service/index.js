/**
 * whatsapp-service — micro-serviço multi-sessão Baileys.
 *
 * Arquitetura:
 *   instances: Map<slug, InstanceState>
 *   InstanceState = { slug, name, sock, status, qr, qrDataUrl, lastUpdate, inbox: [] }
 *   Pastas em disco: AUTH_DIR/{slug}/ (bind-mounted)
 *
 * Todos os endpoints sao parametrizados por :id (slug da instancia).
 * O webhook ao Laravel inclui `instance_id` em todo evento.
 */

const express = require('express');
const axios = require('axios');
const QRCode = require('qrcode');
const pino = require('pino');
const multer = require('multer');
const Redis = require('ioredis');
const fs = require('fs').promises;
const path = require('path');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  downloadMediaMessage,
  Browsers,
} = require('@whiskeysockets/baileys');
const { startWarming } = require('./warming/engine');

// =============================================================================
// CONFIG
// =============================================================================

const PORT = process.env.PORT || 3000;
const WEBHOOK_URL =
  process.env.LARAVEL_WEBHOOK_URL || 'http://nginx/api/whatsapp/webhook';
// Base da API do Laravel pros endpoints INTERNOS do aquecimento (sem /webhook):
// GET /internal/warming-projects e POST /internal/warming-events.
const WARMING_API_BASE =
  process.env.WARMING_API_BASE || WEBHOOK_URL.replace(/\/webhook\/?$/, '');
const AUTH_DIR = process.env.AUTH_DIR || '/usr/src/app/auth_info';

const INBOX_SIZE = 500;

// Anti-ban: limita a cadência de envio POR sessão. Sem isso, um pico de mensagens
// faz o WhatsApp banar o número. Espaça os envios em ~1s, tolerando um burst curto.
const REDIS_HOST = process.env.REDIS_HOST || null;
const REDIS_PORT = Number(process.env.REDIS_PORT || 6379);
// Trata "null"/"" como sem senha (a convenção do Laravel usa a string "null").
const REDIS_PASSWORD =
  process.env.REDIS_PASSWORD && process.env.REDIS_PASSWORD !== 'null'
    ? process.env.REDIS_PASSWORD
    : undefined;
const WA_RATE_BURST = Number(process.env.WA_RATE_BURST || 5); // máx por segundo
const WA_RATE_MAX_WAIT_MS = Number(process.env.WA_RATE_MAX_WAIT_MS || 10000);

const logger = pino({ level: 'info' });

// Cliente dedicado ao rate limit. Se REDIS_HOST não estiver setado (ex.: rodando
// fora do compose), o throttle vira no-op — não trava o envio por causa disso.
const rateRedis = REDIS_HOST
  ? new Redis({ host: REDIS_HOST, port: REDIS_PORT, password: REDIS_PASSWORD, maxRetriesPerRequest: 1, lazyConnect: false })
  : null;

if (rateRedis) {
  rateRedis.on('error', (err) => logger.warn({ err: err.message }, 'redis (rate limit) indisponível'));
}

/**
 * Throttle por sessão via Redis. Reserva um slot no balde do segundo atual
 * (`rate:instance:{slug}:{segundo}`, INCR + TTL 2s). Até `WA_RATE_BURST` envios
 * cabem no mesmo segundo; passando disso, espera o próximo segundo e tenta de novo,
 * até `WA_RATE_MAX_WAIT_MS`. Estourou a espera → recusa (429). Sem Redis, no-op.
 */
async function throttleInstance(slug) {
  if (!rateRedis) return;

  const deadline = Date.now() + WA_RATE_MAX_WAIT_MS;

  for (;;) {
    const second = Math.floor(Date.now() / 1000);
    const key = `rate:instance:${slug}:${second}`;

    let count;
    try {
      count = await rateRedis.incr(key);
      if (count === 1) await rateRedis.expire(key, 2);
    } catch (err) {
      // Redis fora do ar não pode impedir o envio — só perdemos o anti-ban.
      logger.warn({ slug, err: err.message }, 'rate limit sem Redis; enviando sem throttle');
      return;
    }

    if (count <= WA_RATE_BURST) return;

    if (Date.now() >= deadline) {
      const err = new Error('limite de envio por segundo excedido (anti-ban); tente novamente');
      err.statusCode = 429;
      throw err;
    }

    // Espera até virar o segundo e disputa o balde seguinte.
    await new Promise((resolve) => setTimeout(resolve, 1000 - (Date.now() % 1000)));
  }
}

// =============================================================================
// STATE
// =============================================================================

/** @type {Map<string, InstanceState>} */
const instances = new Map();

function createInstanceState(slug, name) {
  return {
    slug,
    name: name || slug,
    sock: null,
    status: 'INITIALIZING',
    qr: null,
    qrDataUrl: null,
    lastUpdate: new Date().toISOString(),
    inbox: [],
    // Pareamento por código de 8 dígitos (alternativa ao QR).
    // pairingPhone: número aguardando código; o handler de connection.update
    // pede o código quando o socket fica pronto e guarda em pairingCode.
    pairingPhone: null,
    pairingCode: null,
    pairingRequested: false,
    pairingError: null,
  };
}

// =============================================================================
// WEBHOOK
// =============================================================================

async function notifyLaravel(instance, payload) {
  try {
    await axios.post(
      WEBHOOK_URL,
      { instance_id: instance.slug, ...payload },
      { timeout: 5000, headers: { 'Content-Type': 'application/json' } },
    );
    logger.info(
      { slug: instance.slug, event: payload.event },
      'webhook entregue ao Laravel',
    );
  } catch (err) {
    logger.warn(
      { err: err.message, slug: instance.slug, event: payload.event },
      'falha ao entregar webhook (seguindo em frente)',
    );
  }
}

function setInstanceState(instance, patch, event) {
  Object.assign(instance, patch, { lastUpdate: new Date().toISOString() });
  if (event) {
    notifyLaravel(instance, {
      event,
      status: instance.status,
      qr: instance.qr,
      qr_data_url: instance.qrDataUrl,
      timestamp: instance.lastUpdate,
    });
  }
}

// =============================================================================
// PARSER / FILTROS
// =============================================================================

const NOISE_KEYS = new Set([
  'senderKeyDistributionMessage',
  'messageContextInfo',
  'protocolMessage',
  'reactionMessage',
  'ephemeralMessage',
  'viewOnceMessage',
  'viewOnceMessageV2',
]);

function isRealMessage(rawMsg) {
  const message = rawMsg?.message;
  if (!message) return false;

  const jid = rawMsg?.key?.remoteJid || '';
  if (jid === 'status@broadcast') return false;

  const keys = Object.keys(message).filter((k) => !NOISE_KEYS.has(k));
  return keys.length > 0;
}

function chatTypeFromJid(jid) {
  if (!jid) return 'unknown';
  if (jid.endsWith('@g.us')) return 'group';
  if (jid.endsWith('@newsletter')) return 'newsletter';
  if (jid.endsWith('@broadcast')) return 'broadcast';
  if (jid.endsWith('@lid')) return 'private_lid';
  if (jid.endsWith('@s.whatsapp.net')) return 'private';
  return 'unknown';
}

function summarizeMessage(rawMsg) {
  const message = rawMsg?.message || {};
  let type = 'unknown';
  let body = null;

  if (message.conversation) {
    type = 'text';
    body = message.conversation;
  } else if (message.extendedTextMessage) {
    type = 'text';
    body = message.extendedTextMessage.text;
  } else if (message.imageMessage) {
    type = 'image';
    body = message.imageMessage.caption || null;
  } else if (message.videoMessage) {
    type = 'video';
    body = message.videoMessage.caption || null;
  } else if (message.audioMessage) {
    type = 'audio';
  } else if (message.documentMessage) {
    type = 'document';
    body = message.documentMessage.fileName || null;
  } else if (message.stickerMessage) {
    type = 'sticker';
  } else if (message.locationMessage) {
    type = 'location';
    body = JSON.stringify({
      lat: message.locationMessage.degreesLatitude,
      lng: message.locationMessage.degreesLongitude,
    });
  } else if (message.contactMessage) {
    type = 'contact';
    body = message.contactMessage.displayName || null;
  }

  const jid = rawMsg?.key?.remoteJid || null;
  return {
    from: jid,
    chat_type: chatTypeFromJid(jid),
    participant: rawMsg?.key?.participant || null,
    from_me: Boolean(rawMsg?.key?.fromMe),
    type,
    body,
    whatsapp_message_id: rawMsg?.key?.id || null,
    received_at: new Date().toISOString(),
    sender_name:  rawMsg?.pushName || null,
    sender_phone: (() => {
      const jid = rawMsg?.key?.participant || rawMsg?.key?.remoteJid || '';
      const num = jid.split('@')[0];
      return /^\d+$/.test(num) ? num : null;
    })(),
  };
}

function mediaMetadataFromMessage(msg) {
  if (msg?.imageMessage) {
    return { mime: msg.imageMessage.mimetype || 'image/jpeg', ext: 'jpg' };
  }
  if (msg?.videoMessage) {
    return { mime: msg.videoMessage.mimetype || 'video/mp4', ext: 'mp4' };
  }
  if (msg?.audioMessage) {
    return { mime: msg.audioMessage.mimetype || 'audio/ogg', ext: 'ogg' };
  }
  if (msg?.documentMessage) {
    return {
      mime: msg.documentMessage.mimetype || 'application/octet-stream',
      ext: (msg.documentMessage.fileName || '').split('.').pop() || 'bin',
      filename: msg.documentMessage.fileName,
    };
  }
  if (msg?.stickerMessage) {
    return { mime: msg.stickerMessage.mimetype || 'image/webp', ext: 'webp' };
  }
  return null;
}

// =============================================================================
// BAILEYS LIFECYCLE
// =============================================================================

async function startBaileys(slug) {
  const instance = instances.get(slug);
  if (!instance) {
    throw new Error(`Instancia "${slug}" nao encontrada`);
  }

  const instanceAuthDir = path.join(AUTH_DIR, slug);
  await fs.mkdir(instanceAuthDir, { recursive: true });

  const { state: authState, saveCreds } = await useMultiFileAuthState(instanceAuthDir);
  const { version } = await fetchLatestBaileysVersion();

  // O pareamento por código só é aceito pelo WhatsApp com uma assinatura de browser
  // PADRÃO (ex.: ['Mac OS','Chrome','14.4.1']). Com nome custom ("Gorila Piloto") o QR
  // funciona, mas o pairing code é recusado ("não foi possível conectar o dispositivo").
  // Por isso, quando a sessão está pareando por código, usamos Browsers.macOS('Chrome')
  // e desligamos o timeout de query (o handshake do pareamento pode demorar).
  const isPairing = Boolean(instance.pairingPhone);

  instance.sock = makeWASocket({
    version,
    auth: authState,
    printQRInTerminal: false,
    logger: pino({ level: 'silent' }),
    browser: isPairing ? Browsers.macOS('Chrome') : ['Gorila Piloto', 'Chrome', '1.0.0'],
    ...(isPairing ? { defaultQueryTimeoutMs: undefined } : {}),
  });

  instance.sock.ev.on('creds.update', saveCreds);

  instance.sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      // Pareamento por código: pedir SÓ quando o socket está pronto pra login (é o que
      // o evento `qr` sinaliza — ws aberto e handshake feito). Pedir logo após
      // makeWASocket dá "Connection Closed"; pedir num socket antigo já ciclando QR
      // gera código que o WhatsApp não completa. Aqui é um socket fresco
      // (startSessionForPairing) no momento certo. Uma vez só (guard pairingRequested).
      if (
        instance.pairingPhone &&
        !instance.pairingRequested &&
        !instance.sock.authState?.creds?.registered
      ) {
        instance.pairingRequested = true;
        const phone = instance.pairingPhone;
        instance.pairingPhone = null;
        try {
          instance.pairingCode = await instance.sock.requestPairingCode(phone);
          logger.info({ slug }, 'pairing code gerado');
        } catch (e) {
          instance.pairingError = e.message;
          logger.error({ slug, err: e.message }, 'falha ao gerar pairing code');
        }
      }

      const qrDataUrl = await QRCode.toDataURL(qr);
      setInstanceState(instance, { status: 'PENDING_QR', qr, qrDataUrl }, 'qr');
      logger.info({ slug }, 'Novo QR Code gerado');
    }

    if (connection === 'open') {
      // Pareou (por QR ou código): limpa o estado de pareamento.
      instance.pairingPhone = null;
      instance.pairingRequested = false;
      instance.pairingError = null;
      instance.pairingCode = null;

      setInstanceState(
        instance,
        { status: 'CONNECTED', qr: null, qrDataUrl: null },
        'connected',
      );
      logger.info({ slug }, 'WhatsApp conectado');
    }

    if (connection === 'close') {
      // Se um pareamento por código estava em curso e ainda não saiu o código,
      // sinaliza erro pra quem está aguardando (evita espera até o timeout).
      if ((instance.pairingPhone || instance.pairingRequested) && !instance.pairingCode) {
        instance.pairingError =
          instance.pairingError || 'conexão fechada antes de gerar o código';
      }

      const statusCode = lastDisconnect?.error?.output?.statusCode ?? null;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

      setInstanceState(
        instance,
        {
          status: shouldReconnect ? 'RECONNECTING' : 'LOGGED_OUT',
          qr: null,
          qrDataUrl: null,
        },
        'disconnected',
      );

      logger.warn({ slug, statusCode, shouldReconnect }, 'Conexao fechada');

      if (shouldReconnect) {
        setTimeout(
          () => startBaileys(slug).catch((e) => logger.error({ slug, err: e.message })),
          2000,
        );
      }
    }
  });

  instance.sock.ev.on('messages.upsert', (m) => {
    if (m.type !== 'notify' && m.type !== 'append') return;

    if (Array.isArray(m.messages)) {
      for (const raw of m.messages) {

        // Reações chegam via messages.upsert como reactionMessage
        const reaction = raw?.message?.reactionMessage;
        if (reaction) {
          notifyLaravel(instance, {
            event: 'message_reaction',
            status: instance.status,
            payload: {
              messageId:  reaction.key?.id,
              remoteJid:  reaction.key?.remoteJid,
              emoji:      reaction.text ?? '',
              fromMe:     reaction.key?.fromMe ?? false,
              reactorJid: raw.key?.participant ?? raw.key?.remoteJid,
              ts:         reaction.senderTimestampMs,
            },
            timestamp: new Date().toISOString(),
          });
          continue;
        }

        if (!isRealMessage(raw)) continue;

        const summary = summarizeMessage(raw);

        const existing = instance.inbox.findIndex(
          (x) => x.whatsapp_message_id === summary.whatsapp_message_id,
        );
        if (existing >= 0) instance.inbox.splice(existing, 1);

        instance.inbox.unshift({ ...summary, raw });
        if (instance.inbox.length > INBOX_SIZE) {
          instance.inbox.length = INBOX_SIZE;
        }
      }
    }

    notifyLaravel(instance, {
      event: 'message',
      status: instance.status,
      payload: m,
      timestamp: new Date().toISOString(),
    });
  });

  instance.sock.ev.on('messages.delete', (item) => {
    notifyLaravel(instance, {
      event: 'message_deleted',
      status: instance.status,
      payload: item, // { keys: [{ remoteJid, id, fromMe }] }
      timestamp: new Date().toISOString(),
    });
  });

  // Recibos de entrega/leitura. O ack do WhatsApp é numérico (proto WAMessageStatus):
  //   3 = DELIVERY_ACK (entregue), 4 = READ, 5 = PLAYED (áudio ouvido).
  // Só interessam mensagens NOSSAS (fromMe) — é o status do que enviamos.
  instance.sock.ev.on('messages.update', (updates) => {
    if (!Array.isArray(updates)) return;

    for (const u of updates) {
      const ack = u?.update?.status;
      if (ack === undefined || ack === null) continue;
      if (!(u?.key?.fromMe)) continue;

      let state = null;
      if (ack === 3) state = 'delivered';
      else if (ack === 4 || ack === 5) state = 'read';
      if (!state) continue;

      notifyLaravel(instance, {
        event: 'message_status',
        status: instance.status,
        payload: {
          id: u.key?.id,
          remoteJid: u.key?.remoteJid,
          fromMe: true,
          ack,
          state,
        },
        timestamp: new Date().toISOString(),
      });
    }
  });

  // messages.reaction não é emitido em Baileys 6.7.x —
  // reações chegam via messages.upsert como reactionMessage (tratado acima).
}

// =============================================================================
// BOOTSTRAP
// =============================================================================

async function bootstrap() {
  await fs.mkdir(AUTH_DIR, { recursive: true });

  const entries = await fs.readdir(AUTH_DIR, { withFileTypes: true });
  const slugs = entries
    .filter((e) => e.isDirectory() && !e.name.startsWith('.'))
    .map((e) => e.name);

  if (slugs.length === 0) {
    logger.info('Nenhuma instancia em disco. Crie uma via POST /instances.');
    return;
  }

  for (const slug of slugs) {
    instances.set(slug, createInstanceState(slug, slug));
    startBaileys(slug).catch((err) =>
      logger.error({ slug, err: err.message }, 'falha ao iniciar Baileys'),
    );
  }
  logger.info({ count: slugs.length, slugs }, 'instancias inicializadas');
}

// =============================================================================
// HELPERS DE ROTA
// =============================================================================

function attachInstance(req, res, next) {
  const instance = instances.get(req.params.id);
  if (!instance) {
    return res
      .status(404)
      .json({ ok: false, error: `instancia "${req.params.id}" nao encontrada` });
  }
  req.instance = instance;
  next();
}

function requireConnected(req, res, next) {
  const instance = req.instance;
  if (instance.status !== 'CONNECTED' || !instance.sock) {
    return res.status(409).json({
      ok: false,
      error: 'WhatsApp nao conectado',
      status: instance.status,
    });
  }
  next();
}

function isValidSlug(s) {
  return typeof s === 'string' && /^[a-z0-9][a-z0-9_-]{0,30}$/.test(s);
}

// =============================================================================
// EXPRESS APP
// =============================================================================

const app = express();
app.use(express.json({ limit: '5mb' }));

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 25 * 1024 * 1024 },
});

// ----- INSTANCES CRUD --------------------------------------------------------

app.get('/instances', (_req, res) => {
  const list = Array.from(instances.values()).map((i) => ({
    slug: i.slug,
    name: i.name,
    status: i.status,
    last_update: i.lastUpdate,
  }));
  res.json({ count: list.length, instances: list });
});

app.post('/instances', async (req, res) => {
  const { id, name } = req.body || {};
  if (!isValidSlug(id)) {
    return res.status(422).json({
      ok: false,
      error: 'id deve ser lowercase, ascii, [a-z0-9_-], 1-31 chars',
    });
  }
  if (instances.has(id)) {
    return res.status(409).json({ ok: false, error: `instancia "${id}" ja existe` });
  }

  const instance = createInstanceState(id, name || id);
  instances.set(id, instance);

  startBaileys(id).catch((err) => {
    logger.error({ slug: id, err: err.message }, 'falha ao iniciar Baileys');
  });

  res.status(201).json({
    ok: true,
    slug: id,
    name: instance.name,
    status: instance.status,
  });
});

app.delete('/instances/:id', attachInstance, async (req, res) => {
  const instance = req.instance;

  try { if (instance.sock) await instance.sock.logout(); } catch (_) {}
  try { if (instance.sock) instance.sock.end(undefined); } catch (_) {}

  const dir = path.join(AUTH_DIR, instance.slug);
  await fs.rm(dir, { recursive: true, force: true }).catch(() => {});

  instances.delete(instance.slug);

  res.json({ ok: true });
});

// ----- STATUS / RESET --------------------------------------------------------

app.get('/instances/:id/status', attachInstance, (req, res) => {
  const i = req.instance;
  res.json({
    slug: i.slug,
    name: i.name,
    status: i.status,
    qr: i.qr,
    qr_data_url: i.qrDataUrl,
    last_update: i.lastUpdate,
  });
});

app.post('/instances/:id/reset', attachInstance, async (req, res) => {
  const instance = req.instance;
  try {
    if (instance.sock) {
      try { await instance.sock.logout(); } catch (_) {}
      try { instance.sock.end(undefined); } catch (_) {}
      instance.sock = null;
    }

    const dir = path.join(AUTH_DIR, instance.slug);
    try {
      const entries = await fs.readdir(dir);
      await Promise.all(
        entries.map((e) =>
          fs.rm(path.join(dir, e), { recursive: true, force: true }),
        ),
      );
    } catch (e) {
      logger.warn({ slug: instance.slug, err: e.message }, 'falha ao limpar auth');
    }

    instance.inbox = [];
    setInstanceState(
      instance,
      { status: 'INITIALIZING', qr: null, qrDataUrl: null },
      'reset',
    );

    setTimeout(() => {
      startBaileys(instance.slug).catch((e) =>
        logger.error({ slug: instance.slug, err: e.message }, 'restart pos-reset falhou'),
      );
    }, 500);

    res.json({ ok: true });
  } catch (err) {
    logger.error({ slug: instance.slug, err: err.message }, 'falha no /reset');
    res.status(500).json({ ok: false, error: err.message });
  }
});

// ----- PAIRING CODE ----------------------------------------------------------

// Aguarda o handler de connection.update popular pairingCode (ou pairingError),
// até `timeoutMs`. O Baileys só aceita requestPairingCode com o socket de pé, por
// isso a solicitação acontece no evento `qr` e o resultado é lido aqui.
function waitForPairingCode(instance, timeoutMs) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const tick = () => {
      if (instance.pairingCode) return resolve(instance.pairingCode);
      if (instance.pairingError) {
        const msg = instance.pairingError;
        instance.pairingError = null;
        return reject(new Error(msg || 'falha ao gerar o código'));
      }
      if (Date.now() - start > timeoutMs) {
        const e = new Error('tempo esgotado aguardando o WhatsApp gerar o código');
        e.statusCode = 504;
        return reject(e);
      }
      setTimeout(tick, 250);
    };
    tick();
  });
}

// Reinicia a sessão do zero pedindo pareamento por código. Usado quando não há um
// socket fresco de pé (ex.: LOGGED_OUT): limpa auth, marca pairingPhone e sobe o
// Baileys — o handler pede o código quando o socket ficar pronto.
async function startSessionForPairing(instance, phone) {
  if (instance.sock) {
    try { instance.sock.end(undefined); } catch (_) {}
    instance.sock = null;
  }

  const dir = path.join(AUTH_DIR, instance.slug);
  try {
    const entries = await fs.readdir(dir);
    await Promise.all(
      entries.map((e) => fs.rm(path.join(dir, e), { recursive: true, force: true })),
    );
  } catch (_) {}

  instance.pairingPhone = phone;
  instance.pairingCode = null;
  instance.pairingError = null;
  instance.pairingRequested = false;

  setInstanceState(instance, { status: 'INITIALIZING', qr: null, qrDataUrl: null }, 'reset');
  await startBaileys(instance.slug);

  return waitForPairingCode(instance, 18000);
}

// Alternativa ao QR: parear digitando um código de 8 dígitos no celular do chip.
// Só faz sentido enquanto a sessão ainda NÃO está registrada; se já conectou, não há
// o que parear. Se a sessão não estiver aguardando login (ex.: LOGGED_OUT), reinicia
// uma sessão fresca e pede o código automaticamente.
app.post('/instances/:id/pair-code', attachInstance, async (req, res) => {
  const instance = req.instance;
  const phone = String(req.body?.phone || '').replace(/\D/g, '');

  if (phone.length < 10) {
    return res.status(422).json({
      ok: false,
      error: 'informe "phone" com DDI, só dígitos (ex.: 5511999999999)',
    });
  }

  if (instance.status === 'CONNECTED' || instance.sock?.authState?.creds?.registered) {
    return res.status(409).json({
      ok: false,
      error: 'esta sessão já está pareada',
      status: instance.status,
    });
  }

  try {
    // Sempre reinicia numa sessão fresca e pede o código logo na criação do socket.
    // Reaproveitar um socket que já entrou no fluxo de QR gera um código que o
    // WhatsApp rejeita no fim do pareamento.
    const raw = await startSessionForPairing(instance, phone);

    // Baileys devolve "ABCD1234"; exibimos como "ABCD-1234" pra facilitar a leitura.
    const code = raw.length === 8 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
    res.json({ ok: true, code, expires_in_seconds: 60 });
  } catch (err) {
    logger.error({ slug: instance.slug, err: err.message }, 'falha ao gerar pairing code');
    res.status(err.statusCode || 500).json({ ok: false, error: err.message });
  }
});

// ----- SEND ------------------------------------------------------------------

// Resolve o JID de destino. Se veio `jid` explícito, usa direto. Senão, PERGUNTA ao
// WhatsApp (onWhatsApp) qual o JID real daquele número — isso cobre os contatos
// endereçados por LID e a ambiguidade do 9º dígito dos celulares BR. Enviar pro
// `numero@s.whatsapp.net` cru (sem resolver) costuma "sair" mas não ser entregue.
// Lança erro 422 se o número não estiver no WhatsApp (em vez de enviar pro nada).
async function resolveTarget(instance, jid, number) {
  if (jid) return jid;

  const cleaned = String(number).replace(/\D/g, '');

  let results;
  try {
    results = await instance.sock.onWhatsApp(cleaned);
  } catch (e) {
    logger.warn({ slug: instance.slug, number: cleaned, err: e.message }, 'onWhatsApp falhou; usando JID de número puro');
    return `${cleaned}@s.whatsapp.net`;
  }

  const hit = results?.[0];
  if (!hit?.exists || !hit?.jid) {
    const err = new Error(`número ${cleaned} não está no WhatsApp`);
    err.statusCode = 422;
    throw err;
  }

  return hit.jid;
}

app.post('/instances/:id/send-message', attachInstance, requireConnected, async (req, res) => {
  const instance = req.instance;
  const { jid, number, message } = req.body || {};

  if (!message || (!jid && !number)) {
    return res
      .status(422)
      .json({ ok: false, error: 'informe "message" e "jid" ou "number"' });
  }

  try {
    await throttleInstance(instance.slug);
    const target = await resolveTarget(instance, jid, number);
    const result = await instance.sock.sendMessage(target, { text: String(message) });
    res.json({ ok: true, id: result?.key?.id ?? null, to: target });
  } catch (err) {
    logger.error({ slug: instance.slug, err: err.message }, 'falha ao enviar mensagem');
    res.status(err.statusCode || 500).json({ ok: false, error: err.message });
  }
});

app.post('/instances/:id/send-media', attachInstance, requireConnected, upload.single('file'), async (req, res) => {
  const instance = req.instance;

  if (!req.file) {
    return res.status(422).json({ ok: false, error: 'campo "file" ausente' });
  }

  const { jid, number, caption } = req.body || {};
  if (!jid && !number) {
    return res.status(422).json({ ok: false, error: 'informe "jid" ou "number"' });
  }

  const buffer = req.file.buffer;
  const mime = req.file.mimetype || 'application/octet-stream';
  const filename = req.file.originalname || 'arquivo';

  let payload;
  if (mime.startsWith('image/')) {
    payload = { image: buffer, caption: caption || undefined };
  } else if (mime.startsWith('video/')) {
    payload = { video: buffer, caption: caption || undefined, mimetype: mime };
  } else if (mime.startsWith('audio/')) {
    const isOgg = mime.includes('ogg');
    payload = {
      audio: buffer,
      mimetype: isOgg ? 'audio/ogg; codecs=opus' : mime,
      ptt: isOgg,
    };
  } else {
    payload = {
      document: buffer,
      mimetype: mime,
      fileName: filename,
      caption: caption || undefined,
    };
  }

  try {
    await throttleInstance(instance.slug);
    const target = await resolveTarget(instance, jid, number);
    const result = await instance.sock.sendMessage(target, payload);
    res.json({
      ok: true,
      id: result?.key?.id ?? null,
      to: target,
      bytes: buffer.length,
      mime,
    });
  } catch (err) {
    logger.error({ slug: instance.slug, err: err.message }, 'falha ao enviar midia');
    res.status(err.statusCode || 500).json({ ok: false, error: err.message });
  }
});

// ----- CHECK -----------------------------------------------------------------

app.get('/instances/:id/check/:number', attachInstance, requireConnected, async (req, res) => {
  const instance = req.instance;
  const cleaned = String(req.params.number).replace(/\D/g, '');
  try {
    const results = await instance.sock.onWhatsApp(cleaned);
    res.json({
      ok: true,
      input: cleaned,
      exists: Boolean(results?.[0]?.exists),
      jid: results?.[0]?.jid ?? null,
      results,
    });
  } catch (err) {
    res.status(500).json({ ok: false, error: err.message });
  }
});

// ----- MEDIA -----------------------------------------------------------------

app.get('/instances/:id/media/:messageId', attachInstance, async (req, res) => {
  const instance = req.instance;
  const messageId = req.params.messageId;

  const entry = instance.inbox.find((m) => m.whatsapp_message_id === messageId);
  if (!entry || !entry.raw) {
    return res
      .status(404)
      .json({ ok: false, error: 'mensagem nao encontrada no buffer' });
  }

  const meta = mediaMetadataFromMessage(entry.raw.message);
  if (!meta) {
    return res.status(400).json({ ok: false, error: 'mensagem sem midia' });
  }

  try {
    const buffer = await downloadMediaMessage(
      entry.raw,
      'buffer',
      {},
      {
        logger,
        reuploadRequest: instance.sock?.updateMediaMessage?.bind(instance.sock),
      },
    );

    res.setHeader('Content-Type', meta.mime);
    res.setHeader('Cache-Control', 'public, max-age=3600');
    if (meta.filename) {
      res.setHeader(
        'Content-Disposition',
        `inline; filename="${meta.filename}"`,
      );
    }
    res.send(buffer);
  } catch (err) {
    logger.error(
      { slug: instance.slug, messageId, err: err.message },
      'falha ao baixar midia',
    );
    res.status(500).json({ ok: false, error: err.message });
  }
});

// ----- CHATS / MESSAGES ------------------------------------------------------

app.get('/instances/:id/chats', attachInstance, (req, res) => {
  const inbox = req.instance.inbox;
  const chats = new Map();
  for (const msg of inbox) {
    if (!msg.from) continue;
    if (!chats.has(msg.from)) {
      chats.set(msg.from, {
        jid: msg.from,
        chat_type: msg.chat_type,
        last_message: msg,
        message_count: 1,
      });
    } else {
      const entry = chats.get(msg.from);
      entry.message_count += 1;
      if (new Date(msg.received_at) > new Date(entry.last_message.received_at)) {
        entry.last_message = msg;
      }
    }
  }
  const list = Array.from(chats.values()).sort(
    (a, b) =>
      new Date(b.last_message.received_at).getTime() -
      new Date(a.last_message.received_at).getTime(),
  );
  res.json({ count: list.length, chats: list });
});

app.get('/instances/:id/chats/:jid/messages', attachInstance, (req, res) => {
  const jid = req.params.jid;
  const msgs = req.instance.inbox
    .filter((m) => m.from === jid)
    .sort(
      (a, b) =>
        new Date(a.received_at).getTime() - new Date(b.received_at).getTime(),
    );
  res.json({ jid, count: msgs.length, messages: msgs });
});

// ----- INBOX (debug) ---------------------------------------------------------

app.get('/instances/:id/inbox', attachInstance, (req, res) => {
  const includeRaw = req.query.raw === '1';
  const data = req.instance.inbox.map((m) =>
    includeRaw ? m : (({ raw, ...rest }) => rest)(m),
  );
  res.json({ count: data.length, messages: data });
});

app.delete('/instances/:id/inbox', attachInstance, (req, res) => {
  req.instance.inbox = [];
  res.json({ ok: true });
});

// ----- HEALTH ----------------------------------------------------------------

app.get('/health', (_req, res) =>
  res.json({ ok: true, instances: instances.size }),
);

// =============================================================================
// BOOT
// =============================================================================

app.listen(PORT, () => {
  logger.info({ port: PORT }, 'whatsapp-service rodando');
  bootstrap().catch((err) => {
    logger.error({ err: err.message }, 'falha no bootstrap');
  });

  // Aquecimento (Fase 1): scheduler que faz as instâncias de cada projeto com
  // warming ligado conversarem entre si. Independente do bootstrap — ele consulta
  // o Laravel e re-checa o status das sessões ao vivo antes de cada mensagem.
  try {
    startWarming({
      instances,
      axios,
      apiBase: WARMING_API_BASE,
      throttle: throttleInstance,
      logger,
    });
  } catch (err) {
    logger.error({ err: err.message }, 'warming: falha ao iniciar engine');
  }
});

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
 *
 * Eventos enviados ao Laravel:
 *   - event: 'message'        — mensagem nova (messages.upsert)
 *   - event: 'message-status' — atualização de status (messages.update):
 *                                status ∈ {sent, delivered, read}
 *   - event: 'qr'|'connected'|'disconnected'|'reset' — connection lifecycle
 */

const express = require('express');
const axios = require('axios');
const QRCode = require('qrcode');
const pino = require('pino');
const multer = require('multer');
const fs = require('fs').promises;
const path = require('path');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  downloadMediaMessage,
} = require('@whiskeysockets/baileys');

// =============================================================================
// CONFIG
// =============================================================================

const PORT = process.env.PORT || 3000;
const WEBHOOK_URL =
  process.env.LARAVEL_WEBHOOK_URL || 'http://nginx/api/whatsapp/webhook';
const AUTH_DIR = process.env.AUTH_DIR || '/usr/src/app/auth_info';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || '';
const MEDIA_URL_ALLOWLIST = (process.env.MEDIA_URL_ALLOWLIST || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);
const crypto = require('crypto');

const INBOX_SIZE = 500;
const MAX_MEDIA_BYTES = parseInt(
  process.env.MAX_MEDIA_BYTES || String(20 * 1024 * 1024),
  10,
);

const ALLOWED_MIMES = {
  image: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
  video: ['video/mp4', 'video/quicktime', 'video/3gpp'],
  audio: [
    'audio/ogg',
    'audio/mpeg',
    'audio/mp4',
    'audio/aac',
    'audio/opus',
    'audio/webm',
  ],
  document: [
    'application/pdf',
    'application/zip',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
    'application/octet-stream',
  ],
};

const SEND_TYPES = new Set([
  'text',
  'image',
  'video',
  'audio',
  'document',
  'location',
  'contact',
]);

const logger = pino({ level: 'info' });

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
  };
}

// =============================================================================
// WEBHOOK
// =============================================================================

async function notifyLaravel(instance, payload) {
  try {
    const body = JSON.stringify({ instance_id: instance.slug, ...payload });
    const headers = { 'Content-Type': 'application/json' };

    if (WEBHOOK_SECRET) {
      headers['X-WhatsApp-Signature'] = crypto
        .createHmac('sha256', WEBHOOK_SECRET)
        .update(body)
        .digest('hex');
    }

    await axios.post(WEBHOOK_URL, body, { timeout: 5000, headers });
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

function mapBaileysStatus(code) {
  switch (code) {
    case 2: return 'sent';        // SERVER_ACK
    case 3: return 'delivered';   // DELIVERY_ACK
    case 4: return 'read';        // READ
    case 5: return 'read';        // PLAYED (consideramos como leitura)
    default: return null;         // PENDING (1) e desconhecidos: ignora
  }
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

  instance.sock = makeWASocket({
    version,
    auth: authState,
    printQRInTerminal: false,
    logger: pino({ level: 'silent' }),
    browser: ['Gorila Piloto', 'Chrome', '1.0.0'],
  });

  instance.sock.ev.on('creds.update', saveCreds);

  instance.sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      const qrDataUrl = await QRCode.toDataURL(qr);
      setInstanceState(instance, { status: 'PENDING_QR', qr, qrDataUrl }, 'qr');
      logger.info({ slug }, 'Novo QR Code gerado');
    }

    if (connection === 'open') {
      setInstanceState(
        instance,
        { status: 'CONNECTED', qr: null, qrDataUrl: null },
        'connected',
      );
      logger.info({ slug }, 'WhatsApp conectado');
    }

    if (connection === 'close') {
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

  instance.sock.ev.on('messages.update', (updates) => {
    if (!Array.isArray(updates) || updates.length === 0) return;

    for (const u of updates) {
      const id = u?.key?.id;
      const jid = u?.key?.remoteJid;
      const fromMe = Boolean(u?.key?.fromMe);
      const statusCode = u?.update?.status;

      // Baileys enum (WAMessageStatus):
      //   1 = PENDING, 2 = SERVER_ACK (sent),
      //   3 = DELIVERY_ACK (delivered), 4 = READ, 5 = PLAYED
      // Mapeamos para o vocabulário interno do Laravel.
      const status = mapBaileysStatus(statusCode);
      if (!id || !status) continue;

      notifyLaravel(instance, {
        event: 'message-status',
        whatsapp_message_id: id,
        jid,
        from_me: fromMe,
        status,
        timestamp: new Date().toISOString(),
      });
    }
  });
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

function resolveTarget({ jid, number, to }) {
  if (jid) return jid;
  const digits = String(number || to || '').replace(/\D/g, '');
  if (!digits) return null;
  return `${digits}@s.whatsapp.net`;
}

function normalizeMime(mime) {
  return String(mime || 'application/octet-stream')
    .split(';')[0]
    .trim()
    .toLowerCase();
}

function validateMimeForType(type, mime) {
  const normalized = normalizeMime(mime);
  const allowed = ALLOWED_MIMES[type];
  if (!allowed || !allowed.includes(normalized)) {
    throw new Error(`MIME "${normalized}" nao permitido para tipo "${type}"`);
  }
  return normalized;
}

function isSafeMediaUrl(rawUrl) {
  let url;
  try {
    url = new URL(String(rawUrl));
  } catch (_) {
    return false;
  }
  if (url.protocol !== 'https:') return false;
  if (MEDIA_URL_ALLOWLIST.length === 0) return false;
  return MEDIA_URL_ALLOWLIST.includes(url.hostname);
}

async function resolveMediaBuffer(payload, type) {
  if (payload?.buffer instanceof Buffer) {
    if (payload.buffer.length > MAX_MEDIA_BYTES) {
      throw new Error(`midia excede limite de ${MAX_MEDIA_BYTES} bytes`);
    }
    return payload.buffer;
  }

  if (payload?.media_base64) {
    const buffer = Buffer.from(String(payload.media_base64), 'base64');
    if (buffer.length > MAX_MEDIA_BYTES) {
      throw new Error(`midia excede limite de ${MAX_MEDIA_BYTES} bytes`);
    }
    return buffer;
  }

  if (payload?.media_url) {
    if (!isSafeMediaUrl(payload.media_url)) {
      throw new Error('media_url precisa ser https e ter host na allowlist (MEDIA_URL_ALLOWLIST)');
    }
    const resp = await axios.get(String(payload.media_url), {
      responseType: 'arraybuffer',
      maxContentLength: MAX_MEDIA_BYTES,
      maxBodyLength: MAX_MEDIA_BYTES,
      timeout: 60000,
    });
    const buffer = Buffer.from(resp.data);
    if (buffer.length > MAX_MEDIA_BYTES) {
      throw new Error(`midia excede limite de ${MAX_MEDIA_BYTES} bytes`);
    }
    return buffer;
  }

  throw new Error('informe media_base64 ou media_url (https + allowlist)');
}

function buildMediaPayload(type, buffer, payload) {
  const mime = validateMimeForType(type, payload.mimetype || payload.mime);
  const caption = payload.caption || undefined;
  const filename = payload.filename || payload.fileName || 'arquivo';

  if (type === 'image') {
    return { image: buffer, caption, mimetype: mime };
  }
  if (type === 'video') {
    return { video: buffer, caption, mimetype: mime };
  }
  if (type === 'audio') {
    const isOgg = mime.includes('ogg') || mime.includes('opus');
    return {
      audio: buffer,
      mimetype: isOgg ? 'audio/ogg; codecs=opus' : mime,
      ptt: Boolean(payload.ptt ?? isOgg),
    };
  }
  return {
    document: buffer,
    mimetype: mime,
    fileName: filename,
    caption,
  };
}

async function buildSendPayload(body) {
  const { type, message, payload = {} } = body;

  if (!type && message) {
    return { text: String(message) };
  }

  const msgType = type || 'text';
  if (!SEND_TYPES.has(msgType)) {
    throw new Error(`tipo "${msgType}" invalido`);
  }

  if (msgType === 'text') {
    const text = payload.message ?? message ?? payload.text;
    if (!text) throw new Error('informe payload.message para tipo text');
    return { text: String(text) };
  }

  if (['image', 'video', 'audio', 'document'].includes(msgType)) {
    const buffer = await resolveMediaBuffer(payload, msgType);
    return buildMediaPayload(msgType, buffer, payload);
  }

  if (msgType === 'location') {
    const lat = payload.latitude ?? payload.lat;
    const lng = payload.longitude ?? payload.lng;
    if (lat == null || lng == null) {
      throw new Error('informe payload.latitude e payload.longitude');
    }
    return {
      location: {
        degreesLatitude: Number(lat),
        degreesLongitude: Number(lng),
        name: payload.name || undefined,
        address: payload.address || undefined,
      },
    };
  }

  if (msgType === 'contact') {
    const vcard = payload.vcard;
    if (!vcard) throw new Error('informe payload.vcard');
    return {
      contacts: {
        displayName: payload.display_name || payload.displayName || 'Contato',
        contacts: [{ vcard: String(vcard) }],
      },
    };
  }

  throw new Error(`tipo "${msgType}" nao suportado`);
}

// =============================================================================
// EXPRESS APP
// =============================================================================

const app = express();
app.use(express.json({ limit: '5mb' }));

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_MEDIA_BYTES },
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

// ----- SEND ------------------------------------------------------------------

app.post('/instances/:id/send-message', attachInstance, requireConnected, async (req, res) => {
  const instance = req.instance;
  const body = req.body || {};
  const { jid, number, to, message, type, payload } = body;

  const target = resolveTarget({ jid, number, to });
  if (!target) {
    return res.status(422).json({
      ok: false,
      error: 'informe "jid", "number" ou "to"',
    });
  }

  const hasLegacyText = !type && message;
  const hasTypedPayload = type || (payload && Object.keys(payload).length > 0);
  if (!hasLegacyText && !hasTypedPayload) {
    return res.status(422).json({
      ok: false,
      error: 'informe type+payload ou message (legado)',
    });
  }

  try {
    const sendPayload = await buildSendPayload({ type, message, payload });
    const result = await instance.sock.sendMessage(target, sendPayload);
    res.json({
      ok: true,
      id: result?.key?.id ?? null,
      to: target,
      type: type || 'text',
    });
  } catch (err) {
    const status = err.message.includes('informe') || err.message.includes('invalido') ? 422 : 500;
    logger.error({ slug: instance.slug, err: err.message }, 'falha ao enviar mensagem');
    res.status(status).json({ ok: false, error: err.message });
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

  const target = jid || `${String(number).replace(/\D/g, '')}@s.whatsapp.net`;
  const buffer = req.file.buffer;
  const mime = req.file.mimetype || 'application/octet-stream';
  const filename = req.file.originalname || 'arquivo';

  let mediaType = 'document';
  if (mime.startsWith('image/')) mediaType = 'image';
  else if (mime.startsWith('video/')) mediaType = 'video';
  else if (mime.startsWith('audio/')) mediaType = 'audio';

  let payload;
  try {
    payload = buildMediaPayload(mediaType, buffer, {
      mimetype: mime,
      caption,
      filename,
    });
  } catch (err) {
    return res.status(422).json({ ok: false, error: err.message });
  }

  try {
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
    res.status(500).json({ ok: false, error: err.message });
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
});

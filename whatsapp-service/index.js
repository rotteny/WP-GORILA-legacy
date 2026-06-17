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

const PORT = process.env.PORT || 3000;
const WEBHOOK_URL =
  process.env.LARAVEL_WEBHOOK_URL || 'http://nginx/api/whatsapp/webhook';
const AUTH_DIR = process.env.AUTH_DIR || 'auth_info_baileys';

const logger = pino({ level: 'info' });

const state = {
  status: 'INITIALIZING',
  qr: null,
  qrDataUrl: null,
  lastUpdate: new Date().toISOString(),
};

// Buffer em memória das últimas mensagens recebidas — temporário, será
// substituído pela persistência em Postgres no Chunk 2 da API.
const INBOX_SIZE = 500;
const inbox = [];

let sock = null;

// Tipos de "mensagem" do Baileys que NÃO são mensagens reais (protocolo,
// chaves de criptografia, contextos). Ignoramos para não poluir o inbox.
const NOISE_KEYS = new Set([
  'senderKeyDistributionMessage',
  'messageContextInfo',
  'protocolMessage',
  'reactionMessage', // por enquanto ignoramos reações
  'ephemeralMessage',
  'viewOnceMessage',
  'viewOnceMessageV2',
]);

function isRealMessage(rawMsg) {
  const message = rawMsg?.message;
  if (!message) return false;

  // Ignora updates de status (stories) — não são mensagens.
  const jid = rawMsg?.key?.remoteJid || '';
  if (jid === 'status@broadcast') return false;

  // Se as únicas chaves de "message" são de ruído de protocolo, ignora.
  // (Nunca filtramos por @lid — em WA com privacy enhanced, conversas
  //  privadas podem chegar identificadas por LinkedID em vez do número.)
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
    participant: rawMsg?.key?.participant || null, // quem mandou em grupo
    from_me: Boolean(rawMsg?.key?.fromMe),
    type,
    body,
    whatsapp_message_id: rawMsg?.key?.id || null,
    received_at: new Date().toISOString(),
  };
}

async function notifyLaravel(payload) {
  try {
    await axios.post(WEBHOOK_URL, payload, {
      timeout: 5000,
      headers: { 'Content-Type': 'application/json' },
    });
    logger.info({ event: payload.event }, 'webhook entregue ao Laravel');
  } catch (err) {
    logger.warn(
      { err: err.message, event: payload.event },
      'falha ao entregar webhook (seguindo em frente)',
    );
  }
}

function setState(patch, event) {
  Object.assign(state, patch, { lastUpdate: new Date().toISOString() });
  if (event) {
    notifyLaravel({
      event,
      status: state.status,
      qr: state.qr,
      qr_data_url: state.qrDataUrl,
      timestamp: state.lastUpdate,
    });
  }
}

async function startBaileys() {
  const { state: authState, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    version,
    auth: authState,
    printQRInTerminal: false,
    logger: pino({ level: 'silent' }),
    browser: ['Gorila Piloto', 'Chrome', '1.0.0'],
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      const qrDataUrl = await QRCode.toDataURL(qr);
      setState(
        { status: 'PENDING_QR', qr, qrDataUrl },
        'qr',
      );
      logger.info('Novo QR Code gerado');
    }

    if (connection === 'open') {
      setState(
        { status: 'CONNECTED', qr: null, qrDataUrl: null },
        'connected',
      );
      logger.info('WhatsApp conectado com sucesso');
    }

    if (connection === 'close') {
      const statusCode =
        lastDisconnect?.error?.output?.statusCode ?? null;
      const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

      setState(
        {
          status: shouldReconnect ? 'RECONNECTING' : 'LOGGED_OUT',
          qr: null,
          qrDataUrl: null,
        },
        'disconnected',
      );

      logger.warn(
        { statusCode, shouldReconnect },
        'Conexão fechada',
      );

      if (shouldReconnect) {
        setTimeout(() => startBaileys().catch((e) => logger.error(e)), 2000);
      }
    }
  });

  sock.ev.on('messages.upsert', (m) => {
    // Aceita 'notify' (mensagens novas) e 'append' (newsletters/canais
    // e algumas atualizações que trazem mensagens novas também).
    if (m.type !== 'notify' && m.type !== 'append') return;

    if (Array.isArray(m.messages)) {
      for (const raw of m.messages) {
        if (!isRealMessage(raw)) continue;

        const summary = summarizeMessage(raw);

        // Dedup: se já temos esse whatsapp_message_id no buffer, atualiza
        // (não duplica).
        const existing = inbox.findIndex(
          (x) => x.whatsapp_message_id === summary.whatsapp_message_id,
        );
        if (existing >= 0) {
          inbox.splice(existing, 1);
        }

        inbox.unshift({ ...summary, raw });
        if (inbox.length > INBOX_SIZE) inbox.length = INBOX_SIZE;
      }
    }

    notifyLaravel({
      event: 'message',
      status: state.status,
      payload: m,
      timestamp: new Date().toISOString(),
    });
  });
}

const app = express();
app.use(express.json({ limit: '5mb' }));

// Upload em memória — 25 MB. Suficiente pra imagem/áudio/PDF do dia-a-dia.
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 25 * 1024 * 1024 },
});

app.get('/status', (_req, res) => {
  res.json({
    status: state.status,
    qr: state.qr,
    qr_data_url: state.qrDataUrl,
    last_update: state.lastUpdate,
  });
});

app.post('/send-message', async (req, res) => {
  const { jid, number, message } = req.body || {};

  if (state.status !== 'CONNECTED' || !sock) {
    return res
      .status(409)
      .json({ ok: false, error: 'WhatsApp não está conectado', status: state.status });
  }

  if (!message || (!jid && !number)) {
    return res
      .status(422)
      .json({ ok: false, error: 'Informe "message" e "jid" ou "number".' });
  }

  const target = jid || `${String(number).replace(/\D/g, '')}@s.whatsapp.net`;

  try {
    const result = await sock.sendMessage(target, { text: String(message) });
    return res.json({ ok: true, id: result?.key?.id ?? null, to: target });
  } catch (err) {
    logger.error({ err: err.message }, 'falha ao enviar mensagem');
    return res.status(500).json({ ok: false, error: err.message });
  }
});

app.post('/reset', async (_req, res) => {
  try {
    if (sock) {
      try { await sock.logout(); } catch (_) {}
      try { sock.end(undefined); } catch (_) {}
      sock = null;
    }

    try {
      const entries = await fs.readdir(AUTH_DIR);
      await Promise.all(
        entries.map((e) =>
          fs.rm(path.join(AUTH_DIR, e), { recursive: true, force: true }),
        ),
      );
    } catch (e) {
      logger.warn({ err: e.message }, 'falha ao limpar auth_info (seguindo)');
    }

    setState(
      { status: 'INITIALIZING', qr: null, qrDataUrl: null },
      'reset',
    );

    setTimeout(() => {
      startBaileys().catch((e) => logger.error({ err: e.message }, 'restart pos-reset falhou'));
    }, 500);

    res.json({ ok: true });
  } catch (err) {
    logger.error({ err: err.message }, 'falha no /reset');
    res.status(500).json({ ok: false, error: err.message });
  }
});

app.get('/check/:number', async (req, res) => {
  if (state.status !== 'CONNECTED' || !sock) {
    return res.status(409).json({ ok: false, error: 'WhatsApp não conectado' });
  }
  const cleaned = String(req.params.number).replace(/\D/g, '');
  try {
    const results = await sock.onWhatsApp(cleaned);
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

app.get('/media/:messageId', async (req, res) => {
  const messageId = req.params.messageId;
  const entry = inbox.find((m) => m.whatsapp_message_id === messageId);
  if (!entry || !entry.raw) {
    return res.status(404).json({ ok: false, error: 'message not found in buffer' });
  }

  const meta = mediaMetadataFromMessage(entry.raw.message);
  if (!meta) {
    return res.status(400).json({ ok: false, error: 'message has no downloadable media' });
  }

  try {
    const buffer = await downloadMediaMessage(
      entry.raw,
      'buffer',
      {},
      {
        logger,
        reuploadRequest: sock?.updateMediaMessage?.bind(sock),
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
    logger.error({ err: err.message, messageId }, 'falha ao baixar mídia');
    res.status(500).json({ ok: false, error: err.message });
  }
});

app.get('/chats', (_req, res) => {
  // Agrupa o inbox por "from" (peer) e devolve lista de conversas com
  // última mensagem + contador. Ordenado pela mais recente.
  const chats = new Map();
  for (const msg of inbox) {
    if (!msg.from) continue;
    const key = msg.from;
    if (!chats.has(key)) {
      chats.set(key, {
        jid: key,
        chat_type: msg.chat_type,
        last_message: msg,
        message_count: 1,
      });
    } else {
      const entry = chats.get(key);
      entry.message_count += 1;
      if (
        new Date(msg.received_at) > new Date(entry.last_message.received_at)
      ) {
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

app.get('/chats/:jid/messages', (req, res) => {
  // Mensagens de UMA conversa específica, ordenadas por data ascendente
  // (mais antigas primeiro, como no WhatsApp).
  const jid = req.params.jid;
  const msgs = inbox
    .filter((m) => m.from === jid)
    .sort(
      (a, b) =>
        new Date(a.received_at).getTime() - new Date(b.received_at).getTime(),
    );
  res.json({ jid, count: msgs.length, messages: msgs });
});

app.get('/inbox', (req, res) => {
  // Endpoint de debug — buffer em memória, NÃO use em produção.
  // ?raw=1 inclui o payload bruto do Baileys; default retorna só o resumo.
  const includeRaw = req.query.raw === '1';
  const data = inbox.map((m) => (includeRaw ? m : (({ raw, ...rest }) => rest)(m)));
  res.json({ count: data.length, messages: data });
});

app.delete('/inbox', (_req, res) => {
  inbox.length = 0;
  res.json({ ok: true });
});

app.post('/send-media', upload.single('file'), async (req, res) => {
  if (state.status !== 'CONNECTED' || !sock) {
    return res.status(409).json({
      ok: false,
      error: 'WhatsApp não conectado',
      status: state.status,
    });
  }

  if (!req.file) {
    return res.status(422).json({ ok: false, error: 'campo "file" ausente' });
  }

  const { jid, number, caption } = req.body || {};
  if (!jid && !number) {
    return res.status(422).json({
      ok: false,
      error: 'informe "jid" ou "number"',
    });
  }

  const target = jid || `${String(number).replace(/\D/g, '')}@s.whatsapp.net`;
  const buffer = req.file.buffer;
  const mime = req.file.mimetype || 'application/octet-stream';
  const filename = req.file.originalname || 'arquivo';

  let payload;
  if (mime.startsWith('image/')) {
    payload = { image: buffer, caption: caption || undefined };
  } else if (mime.startsWith('video/')) {
    payload = { video: buffer, caption: caption || undefined, mimetype: mime };
  } else if (mime.startsWith('audio/')) {
    // O navegador manda o mime sem codec (`audio/ogg` em vez de
    // `audio/ogg; codecs=opus`). Sem o codec explícito, o WhatsApp
    // do destinatário não decodifica. Por isso forçamos aqui.
    const isOgg = mime.includes('ogg');
    payload = {
      audio: buffer,
      mimetype: isOgg ? 'audio/ogg; codecs=opus' : mime,
      ptt: isOgg, // OGG = mensagem de voz; outros = música/anexo
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
    const result = await sock.sendMessage(target, payload);
    return res.json({
      ok: true,
      id: result?.key?.id ?? null,
      to: target,
      bytes: buffer.length,
      mime,
    });
  } catch (err) {
    logger.error({ err: err.message }, 'falha ao enviar mídia');
    return res.status(500).json({ ok: false, error: err.message });
  }
});

app.get('/health', (_req, res) => res.json({ ok: true }));

app.listen(PORT, () => {
  logger.info(`whatsapp-service rodando na porta ${PORT}`);
  startBaileys().catch((err) => {
    logger.error({ err: err.message }, 'falha ao iniciar Baileys');
    setTimeout(() => startBaileys().catch(() => {}), 3000);
  });
});

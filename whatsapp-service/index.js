const express = require('express');
const axios = require('axios');
const QRCode = require('qrcode');
const pino = require('pino');
const fs = require('fs').promises;
const path = require('path');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
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

let sock = null;

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

app.get('/health', (_req, res) => res.json({ ok: true }));

app.listen(PORT, () => {
  logger.info(`whatsapp-service rodando na porta ${PORT}`);
  startBaileys().catch((err) => {
    logger.error({ err: err.message }, 'falha ao iniciar Baileys');
    setTimeout(() => startBaileys().catch(() => {}), 3000);
  });
});

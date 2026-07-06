/**
 * warming/engine.js — entrypoint do aquecimento. Chamado uma vez pelo index.js.
 *
 * ctx = {
 *   instances,   // Map<slug, InstanceState> do serviço (fonte do sock/status ao vivo)
 *   axios,       // cliente HTTP (reusa o do serviço)
 *   apiBase,     // ex.: http://nginx/api/whatsapp  (sem /webhook)
 *   throttle,    // throttleInstance(slug) — anti-ban por sessão
 *   logger,
 * }
 *
 * Desligável por completo com WARMING_DISABLED=1 (ex.: ambiente onde não se quer
 * aquecimento rodando).
 */

'use strict';

const Scheduler = require('./scheduler');

function startWarming(ctx) {
  if (process.env.WARMING_DISABLED === '1') {
    ctx.logger.info('warming: desabilitado (WARMING_DISABLED=1)');
    return null;
  }

  const scheduler = new Scheduler(ctx);
  scheduler.start();
  ctx.logger.info({ apiBase: ctx.apiBase }, 'warming: engine iniciado');
  return scheduler;
}

module.exports = { startWarming };

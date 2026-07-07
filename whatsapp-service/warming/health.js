/**
 * warming/health.js — proteções do pool de aquecimento (Fase 4):
 *
 *  - Circuit breaker por instância: 3 erros de envio em 5 min → pausa o warming
 *    daquele número por 30 min; reset automático depois do cooldown.
 *  - Cool-down de reconexão: depois que uma instância volta (→CONNECTED vindo de
 *    outro estado), espera 2 min antes de reintroduzi-la no pool.
 *
 * Uma única instância de Health é compartilhada por todos os runners (via ctx).
 */

'use strict';

const ERROR_WINDOW_MS = 5 * 60_000; // janela pra contar erros
const ERROR_THRESHOLD = 3; // nº de erros que abre o circuito
const CIRCUIT_OPEN_MS = 30 * 60_000; // duração da pausa
const RECONNECT_COOLDOWN_MS = 2 * 60_000; // espera após reconectar

class Health {
  constructor(logger) {
    this.logger = logger;
    this.errors = new Map(); // slug -> [timestamps]
    this.openUntil = new Map(); // slug -> epoch ms
    this.lastStatus = new Map(); // slug -> status
    this.reconnectedAt = new Map(); // slug -> epoch ms
  }

  /** Observa o status ao vivo pra detectar reconexão (→CONNECTED) e iniciar cooldown. */
  observe(slug, status) {
    const prev = this.lastStatus.get(slug);
    if (status === 'CONNECTED' && prev !== undefined && prev !== 'CONNECTED') {
      this.reconnectedAt.set(slug, Date.now());
    }
    this.lastStatus.set(slug, status);
  }

  /**
   * Registra um erro de envio. Se atingiu o limiar na janela, abre o circuito e
   * loga (motivo + duração). Retorna true se ABRIU agora (pra reportar a métrica).
   */
  recordError(slug) {
    const now = Date.now();
    const arr = (this.errors.get(slug) || []).filter((t) => now - t < ERROR_WINDOW_MS);
    arr.push(now);
    this.errors.set(slug, arr);

    if (arr.length >= ERROR_THRESHOLD) {
      this.openUntil.set(slug, now + CIRCUIT_OPEN_MS);
      this.errors.set(slug, []);
      this.logger?.warn(
        { slug, motivo: `${ERROR_THRESHOLD} erros em ${ERROR_WINDOW_MS / 60000}min`, duracao_min: CIRCUIT_OPEN_MS / 60000 },
        'warming: circuit breaker aberto',
      );
      return true;
    }
    return false;
  }

  /** A instância está de fora do pool agora? (circuito aberto ou cooldown de reconexão) */
  isPaused(slug) {
    const now = Date.now();
    if (now < (this.openUntil.get(slug) || 0)) return true;
    const rc = this.reconnectedAt.get(slug) || 0;
    if (rc && now < rc + RECONNECT_COOLDOWN_MS) return true;
    return false;
  }

  /** Quantos circuitos estão abertos agora (métrica). */
  openCount() {
    const now = Date.now();
    let n = 0;
    for (const until of this.openUntil.values()) if (now < until) n++;
    return n;
  }
}

module.exports = {
  Health,
  ERROR_WINDOW_MS,
  ERROR_THRESHOLD,
  CIRCUIT_OPEN_MS,
  RECONNECT_COOLDOWN_MS,
};

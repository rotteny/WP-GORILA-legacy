/**
 * warming/scheduler.js — consulta o Laravel a cada 30s (GET /internal/warming-projects)
 * e gerencia o ciclo de vida dos ProjectRunners: cria os que apareceram, atualiza a
 * config dos que continuam e para os que sumiram (warming desligado / inelegível).
 *
 * Ligar/desligar reflete em < 1min: o poll é a cada 30s e o runner acorda em <=1s.
 */

'use strict';

const { ProjectRunner } = require('./project-runner');

const POLL_MS = Number(process.env.WARMING_POLL_MS || 30000);

class Scheduler {
  constructor(ctx) {
    this.ctx = ctx;
    this.runners = new Map(); // slug -> ProjectRunner
    this.timer = null;
    this.polling = false;
  }

  start() {
    this._tick();
    this.timer = setInterval(() => this._tick(), POLL_MS);
    if (this.timer.unref) this.timer.unref(); // não segura o processo no shutdown
  }

  stop() {
    if (this.timer) clearInterval(this.timer);
    for (const runner of this.runners.values()) runner.stop();
    this.runners.clear();
  }

  async _tick() {
    if (this.polling) return; // evita polls sobrepostos
    this.polling = true;
    try {
      const { data } = await this.ctx.axios.get(
        `${this.ctx.apiBase}/internal/warming-projects`,
        { timeout: 8000 },
      );
      const projects = Array.isArray(data?.projects) ? data.projects : [];
      const seen = new Set();

      for (const p of projects) {
        if (!p?.slug) continue;
        seen.add(p.slug);
        const existing = this.runners.get(p.slug);
        if (existing) {
          existing.update(p.config, p.instances);
        } else {
          const runner = new ProjectRunner(p.slug, p.config, p.instances, this.ctx);
          this.runners.set(p.slug, runner);
          runner.start();
          this.ctx.logger.info({ project: p.slug }, 'warming: runner iniciado');
        }
      }

      // Para runners de projetos que não estão mais elegíveis.
      for (const slug of [...this.runners.keys()]) {
        if (!seen.has(slug)) {
          this.runners.get(slug).stop();
          this.runners.delete(slug);
          this.ctx.logger.info({ project: slug }, 'warming: runner parado');
        }
      }
    } catch (err) {
      this.ctx.logger.warn({ err: err.message }, 'warming: falha ao consultar projetos');
    } finally {
      this.polling = false;
    }
  }
}

module.exports = Scheduler;

/**
 * warming/project-runner.js — um runner por projeto ativo. Loop que faz as
 * instâncias CONNECTED do projeto trocarem mensagens de aquecimento entre si.
 *
 * Ciclo (card [Warming] Fase 1):
 *   1. verifica a janela horária;
 *   2. escolhe par (sender ≠ receiver, ambos CONNECTED) — pair-selector;
 *   3. escolhe um script do catálogo;
 *   4. delay uniforme entre mensagens (derivado da intensidade);
 *   5. presença "digitando…" 2-6s;
 *   6. sock.sendMessage(receiver, { text });
 *   7. lado receiver: aguarda 5-40s e marca como lida (best-effort);
 *   8. reporta o warming_event ao Laravel (fire-and-forget).
 */

'use strict';

const { jidNormalizedUser } = require('@whiskeysockets/baileys');
const scripts = require('./scripts');
const behavior = require('./behavior');
const { pickPair } = require('./pair-selector');

// Intensidade → faixa de intervalo entre mensagens de aquecimento do projeto (ms).
// Alvo aproximado: média 50-80 msgs/dia por número na janela padrão (8h-22h).
const INTENSITY_DELAY = {
  baixa: [8 * 60_000, 16 * 60_000],
  media: [4 * 60_000, 9 * 60_000],
  alta: [2 * 60_000, 5 * 60_000],
};

function delayRange(intensity) {
  return INTENSITY_DELAY[intensity] || INTENSITY_DELAY.media;
}

class ProjectRunner {
  /**
   * @param {string} slug
   * @param {{intensity?:string, window_start?:number, window_end?:number}} config
   * @param {Array<{slug:string, warming_only:boolean}>} members
   * @param {{instances:Map, axios:object, apiBase:string, throttle:Function, logger:object}} ctx
   */
  constructor(slug, config, members, ctx) {
    this.slug = slug;
    this.config = config || {};
    this.members = members || [];
    this.ctx = ctx;
    this.stopping = false;
  }

  /** Atualiza config/membros no lugar (o scheduler chama a cada poll). */
  update(config, members) {
    if (config) this.config = config;
    if (members) this.members = members;
  }

  start() {
    this.stopping = false;
    this._loop().catch((err) =>
      this.ctx.logger.error({ project: this.slug, err: err.message }, 'warming: runner morreu'),
    );
  }

  stop() {
    this.stopping = true;
  }

  /** Hora atual dentro da janela? Trata janela que cruza a meia-noite. */
  _inWindow() {
    const h = new Date().getHours();
    const s = Number(this.config.window_start ?? 8);
    const e = Number(this.config.window_end ?? 22);
    if (s === e) return true; // 24h
    if (s < e) return h >= s && h < e; // mesma data
    return h >= s || h < e; // cruza meia-noite
  }

  /** Membros que estão vivos e CONNECTED agora (re-checa o estado ao vivo). */
  _liveConnected() {
    return this.members
      .map((m) => {
        const st = this.ctx.instances.get(m.slug);
        return st && st.status === 'CONNECTED' && st.sock
          ? { slug: m.slug, warming_only: !!m.warming_only }
          : null;
      })
      .filter(Boolean);
  }

  /** Sleep interrompível: acorda em <=1s quando stop() é chamado. */
  async _sleep(ms) {
    const step = 1000;
    let elapsed = 0;
    while (elapsed < ms && !this.stopping) {
      const d = Math.min(step, ms - elapsed);
      await behavior.sleep(d);
      elapsed += d;
    }
  }

  async _loop() {
    // Primeiro disparo rápido: garante atividade em < 1min depois de ligar.
    await this._sleep(behavior.randInt(2000, 10000));

    while (!this.stopping) {
      try {
        if (this._inWindow()) {
          const pair = pickPair(this._liveConnected());
          if (pair) await this._exchange(pair);
        }
      } catch (err) {
        this.ctx.logger.warn({ project: this.slug, err: err.message }, 'warming: ciclo falhou');
      }
      const wait = this._inWindow() ? behavior.uniformDelay(delayRange(this.config.intensity)) : 60_000;
      await this._sleep(wait);
    }
  }

  async _exchange(pair) {
    const senderState = this.ctx.instances.get(pair.sender.slug);
    const receiverState = this.ctx.instances.get(pair.receiver.slug);
    if (!senderState?.sock || !receiverState?.sock) return;

    const script = scripts.getRandomScript();

    let receiverJid;
    let senderJid;
    try {
      receiverJid = jidNormalizedUser(receiverState.sock.user.id);
      senderJid = jidNormalizedUser(senderState.sock.user.id);
    } catch (_) {
      return; // socket ainda sem user resolvido
    }

    try {
      await this.ctx.throttle(pair.sender.slug); // respeita o anti-ban por sessão

      const [tmin, tmax] = script.variations?.typing_delay_ms || [1500, 4500];
      await behavior.showTyping(senderState.sock, receiverJid, behavior.randInt(tmin, tmax));

      const sent = await senderState.sock.sendMessage(receiverJid, { text: script.seed.text });

      // Lado receiver: depois de 5-40s marca como lida. Fire-and-forget: não pode
      // travar o loop nem estourar erro se a chave não bater exatamente.
      const readKey = { remoteJid: senderJid, id: sent?.key?.id, fromMe: false };
      setTimeout(() => {
        try {
          receiverState.sock?.readMessages?.([readKey])?.catch?.(() => {});
        } catch (_) { /* ignora */ }
      }, behavior.randInt(5000, 40000));

      await this._report({
        sender: pair.sender.slug,
        receiver: pair.receiver.slug,
        script_id: script.id,
        status: 'sent',
      });
      this.ctx.logger.info(
        { project: this.slug, from: pair.sender.slug, to: pair.receiver.slug, script: script.id },
        'warming: mensagem trocada',
      );
    } catch (err) {
      await this._report({
        sender: pair.sender.slug,
        receiver: pair.receiver.slug,
        script_id: script.id,
        status: 'failed',
        error: err.message,
      });
      this.ctx.logger.warn({ project: this.slug, err: err.message }, 'warming: falha no envio');
    }
  }

  async _report(event) {
    try {
      await this.ctx.axios.post(
        `${this.ctx.apiBase}/internal/warming-events`,
        { project: this.slug, ...event },
        { timeout: 5000 },
      );
    } catch (_) {
      /* histórico é best-effort; não trava o loop */
    }
  }
}

module.exports = { ProjectRunner, INTENSITY_DELAY, delayRange };

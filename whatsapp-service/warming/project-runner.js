/**
 * warming/project-runner.js — um runner por projeto ativo. Loop que faz as
 * instâncias CONNECTED do projeto trocarem mensagens de aquecimento entre si.
 *
 * Fase 1: uma mensagem solta por ciclo.
 * Fase 2 (padrões humanoides): THREADS de 3-7 mensagens entre o mesmo par, com
 * pausa longa depois; par escolhido por GRAFO DE PESO variável; delays GAUSSIANOS;
 * VARIAÇÃO TEXTUAL; ~15% de REACTIONS em vez de resposta; PICOS/VALES por horário.
 *
 * Ciclo:
 *   1. verifica a janela horária;
 *   2. escolhe o par (grafo ponderado; warming-only sempre receiver);
 *   3. monta uma thread (seed → branch → followups, encadeando scripts);
 *   4. toca a thread: por turno → "digitando" (gauss) → texto variado OU reaction →
 *      leitura do outro lado (5-40s) → registra o warming_event;
 *   5. pausa longa proporcional ao tamanho da thread e à curva de atividade.
 */

'use strict';

const { jidNormalizedUser } = require('@whiskeysockets/baileys');
const scripts = require('./scripts');
const behavior = require('./behavior');
const { PairGraph } = require('./pair-selector');
const { buildThread } = require('./thread');

// Intensidade → faixa de intervalo BASE por mensagem (ms). Alvo aproximado: média
// 50-80 msgs/dia por número na janela padrão (8h-22h). A pausa entre threads é
// escalada pelo nº de mensagens da thread, então o volume médio se mantém.
const INTENSITY_DELAY = {
  baixa: [8 * 60_000, 16 * 60_000],
  media: [4 * 60_000, 9 * 60_000],
  alta: [2 * 60_000, 5 * 60_000],
};

function delayRange(intensity) {
  return INTENSITY_DELAY[intensity] || INTENSITY_DELAY.media;
}

class ProjectRunner {
  constructor(slug, config, members, ctx) {
    this.slug = slug;
    this.config = config || {};
    this.members = members || [];
    this.ctx = ctx;
    this.stopping = false;
    this.graph = new PairGraph(); // pesos estáveis por par (Fase 2)
  }

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
    if (s === e) return true;
    if (s < e) return h >= s && h < e;
    return h >= s || h < e;
  }

  /** Membros que estão vivos e CONNECTED agora (re-checa o estado ao vivo). */
  _liveConnected() {
    return this.members
      .map((m) => {
        const st = this.ctx.instances.get(m.slug);
        return st && st.status === 'CONNECTED' && st.sock
          ? {
              slug: m.slug,
              warming_only: !!m.warming_only,
              // Fração da rampa (Fase 3): 0.2..1.0; default 1 pra chip sem rampa.
              ramp_fraction: typeof m.ramp_fraction === 'number' ? m.ramp_fraction : 1,
            }
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
      let sentCount = 0;
      try {
        if (this._inWindow()) {
          const pair = this.graph.pick(this._liveConnected());
          // Rampa individual (Fase 3): um chip novo (fraction < 1) inicia menos
          // threads. Ex.: dia 1 (0.2) abre thread só em ~20% dos ciclos → ~20% do volume.
          const frac = pair && typeof pair.sender.ramp_fraction === 'number' ? pair.sender.ramp_fraction : 1;
          if (pair && Math.random() <= frac) sentCount = await this._runThread(pair);
        }
      } catch (err) {
        this.ctx.logger.warn({ project: this.slug, err: err.message }, 'warming: thread falhou');
      }

      // Pausa entre threads: base por mensagem (gauss) × nº de mensagens da thread,
      // dividida pela curva de atividade (mais curto nos picos, mais longo nos vales).
      const perMsg = behavior.gaussian(...delayRange(this.config.intensity));
      const activity = behavior.activityMultiplier(new Date().getHours());
      const wait = this._inWindow()
        ? Math.round((perMsg * Math.max(1, sentCount)) / activity)
        : 60_000;
      await this._sleep(wait);
    }
  }

  /**
   * Toca uma thread entre o par. Retorna quantas mensagens de texto saíram (pra
   * dimensionar a pausa seguinte).
   */
  async _runThread(pair) {
    const senderState = this.ctx.instances.get(pair.sender.slug);
    const receiverState = this.ctx.instances.get(pair.receiver.slug);
    if (!senderState?.sock || !receiverState?.sock) return 0;

    let senderJid;
    let receiverJid;
    try {
      senderJid = jidNormalizedUser(senderState.sock.user.id);
      receiverJid = jidNormalizedUser(receiverState.sock.user.id);
    } catch (_) {
      return 0;
    }

    // Papéis: A = quem abre (sender), B = receiver.
    const roleState = { A: senderState, B: receiverState };
    const roleSlug = { A: pair.sender.slug, B: pair.receiver.slug };
    const roleJid = { A: senderJid, B: receiverJid };

    const firstScript = scripts.getRandomScript();
    const target = behavior.randInt(3, 7);
    const turns = buildThread(scripts, firstScript, target);
    const emojiChance = firstScript.variations?.emoji_chance ?? 0.15;
    const [tmin, tmax] = firstScript.variations?.typing_delay_ms || [1500, 4500];

    let sent = 0;
    let last = null; // { id, byRole } — última mensagem de texto da thread

    for (const turn of turns) {
      if (this.stopping) break;

      const speaker = turn.from === 'B' ? 'B' : 'A';
      const listener = speaker === 'A' ? 'B' : 'A';
      const spState = roleState[speaker];
      const liState = roleState[listener];

      // Se qualquer ponta caiu no meio da thread, encerra a thread.
      if (spState.status !== 'CONNECTED' || !spState.sock || liState.status !== 'CONNECTED' || !liState.sock) {
        break;
      }

      const toJid = roleJid[listener];

      // ~15% das mensagens recebidas viram REACTION em vez de resposta em texto.
      if (last && last.byRole === listener && behavior.chance(0.15)) {
        try {
          const reactKey = { remoteJid: roleJid[listener], id: last.id, fromMe: false };
          await spState.sock.sendMessage(toJid, { react: { text: behavior.randReaction(), key: reactKey } });
          await this._report({ sender: roleSlug[speaker], receiver: roleSlug[listener], script_id: firstScript.id, status: 'sent' });
        } catch (_) { /* reaction é cosmética */ }
        await this._sleep(behavior.gaussian(4000, 12000));
        continue;
      }

      try {
        await this.ctx.throttle(roleSlug[speaker]); // anti-ban por sessão
        await behavior.showTyping(spState.sock, toJid, behavior.gaussian(tmin, tmax));

        const text = behavior.varyText(turn.text, emojiChance);
        const msg = await spState.sock.sendMessage(toJid, { text });
        sent += 1;
        last = { id: msg?.key?.id, byRole: speaker };

        // O outro lado lê depois de 5-40s (best-effort, fire-and-forget).
        const readKey = { remoteJid: roleJid[speaker], id: msg?.key?.id, fromMe: false };
        setTimeout(() => {
          try {
            liState.sock?.readMessages?.([readKey])?.catch?.(() => {});
          } catch (_) { /* ignora */ }
        }, behavior.randInt(5000, 40000));

        await this._report({ sender: roleSlug[speaker], receiver: roleSlug[listener], script_id: firstScript.id, status: 'sent' });
      } catch (err) {
        await this._report({ sender: roleSlug[speaker], receiver: roleSlug[listener], script_id: firstScript.id, status: 'failed', error: err.message });
        this.ctx.logger.warn({ project: this.slug, err: err.message }, 'warming: falha no envio');
        break; // provavelmente desconectou; encerra a thread
      }

      // Pausa curta e gaussiana entre mensagens da mesma thread.
      await this._sleep(behavior.gaussian(6000, 25000));
    }

    if (sent > 0) {
      this.ctx.logger.info(
        { project: this.slug, from: pair.sender.slug, to: pair.receiver.slug, messages: sent },
        'warming: thread concluída',
      );
    }
    return sent;
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

/**
 * warming/behavior.js — helpers de humanização (delay / presença / leitura /
 * variação textual / reactions / curva de atividade por horário).
 *
 * Sem dependências externas de propósito: dá pra testar isolado. As funções que
 * falam com o socket Baileys recebem o `sock` por parâmetro.
 */

'use strict';

/** Promise que resolve depois de `ms`. */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Inteiro aleatório uniforme em [min, max]. */
function randInt(min, max) {
  return Math.floor(min + Math.random() * (max - min + 1));
}

/** Delay uniforme dado um par [min, max] (ms). */
function uniformDelay(range) {
  const [min, max] = Array.isArray(range) ? range : [1000, 3000];
  return randInt(min, max);
}

/**
 * Delay GAUSSIANO (Fase 2) clampado em [min, max], concentrado no meio da faixa.
 * Evita o "intervalo exato" que denuncia bot. Box-Muller → N(0,1), comprimido.
 */
function gaussian(min, max) {
  if (max <= min) return Math.round(min);
  let u = 0;
  let v = 0;
  while (u === 0) u = Math.random();
  while (v === 0) v = Math.random();
  let n = Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v); // ~N(0,1)
  n /= 3.5; // comprime ~[-1, 1]
  const mid = (min + max) / 2;
  const half = (max - min) / 2;
  const val = mid + n * half;
  return Math.round(Math.max(min, Math.min(max, val)));
}

/** Item aleatório de um array. */
function sample(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/** true com probabilidade p (0..1). */
function chance(p) {
  return Math.random() < p;
}

/**
 * Multiplicador de atividade por hora (Fase 2): picos em horários sociais (~12h e
 * ~20h), vale no meio da tarde e de madrugada. Soma de gaussianas + base. Usado
 * pra encurtar a pausa entre conversas nos horários de pico (mais tráfego) e
 * alongá-la nos vales.
 */
function activityMultiplier(hour) {
  const peak = (h, c, w) => Math.exp(-((h - c) ** 2) / (2 * w * w));
  const m = 0.45 + 1.0 * peak(hour, 12, 2.2) + 1.1 * peak(hour, 20, 2.6) + 0.35 * peak(hour, 9, 2.0);
  return Math.max(0.35, Math.min(1.6, m));
}

// Emojis avulsos (anexados ao texto) e reactions (resposta com emoji).
const FILLER_EMOJIS = ['🙂', '😄', '👍', '😅', '🙌', '😂', '❤️', '👏', '🔥', '😉'];
const REACTIONS = ['👍', '❤️', '😂', '😮', '🙏', '👏'];

/** Emoji aleatório pra usar como reaction. */
function randReaction() {
  return REACTIONS[Math.floor(Math.random() * REACTIONS.length)];
}

/**
 * Variação textual determinística (Fase 2): aplica micro-variações ao texto do
 * template pra duas mensagens iguais quase nunca saírem idênticas. Alongamento de
 * vogal final ("dia" → "diaaa"), pontuação e emoji avulso. Best-effort e seguro
 * (nunca estoura). `emojiChance` vem das variations do script.
 */
function varyText(text, emojiChance = 0.15) {
  let t = String(text);
  const r = Math.random();

  if (r < 0.15) {
    // alonga a última vogal da última palavra
    t = t.replace(/([aeiouáéíóúãõ])(\s*)$/i, (_m, vowel, tail) => vowel.repeat(randInt(2, 4)) + tail);
  } else if (r < 0.3 && !/[.!?…]$/.test(t.trim())) {
    t = t.trim() + (Math.random() < 0.5 ? '!' : '');
  }

  if (Math.random() < emojiChance) {
    t = t.trim() + ' ' + FILLER_EMOJIS[Math.floor(Math.random() * FILLER_EMOJIS.length)];
  }

  return t;
}

/**
 * Simula "digitando…" por `ms` e volta pra paused. Best-effort: presença é
 * cosmética, então qualquer falha é silenciada pra não derrubar o ciclo.
 */
async function showTyping(sock, jid, ms) {
  try {
    await sock.sendPresenceUpdate('composing', jid);
    await sleep(ms);
    await sock.sendPresenceUpdate('paused', jid);
  } catch (_) {
    /* ignora */
  }
}

module.exports = {
  sleep,
  randInt,
  uniformDelay,
  gaussian,
  sample,
  chance,
  activityMultiplier,
  randReaction,
  varyText,
  showTyping,
  FILLER_EMOJIS,
  REACTIONS,
};

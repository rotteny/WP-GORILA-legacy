/**
 * warming/behavior.js — helpers de humanização (delay / presença / leitura).
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

/** Item aleatório de um array. */
function sample(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/** true com probabilidade p (0..1). */
function chance(p) {
  return Math.random() < p;
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

module.exports = { sleep, randInt, uniformDelay, sample, chance, showTyping };

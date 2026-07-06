/**
 * warming/pair-selector.js — escolhe o par (sender, receiver) de uma rodada de
 * aquecimento entre as instâncias CONNECTED de um projeto.
 *
 * Preferência (papel warming-only, card 86e246x6f): quando o projeto tem chips
 * warming-only, ~70% do tráfego é "produção → warming-only" — o chip de produção
 * conversa com o buffer barato, gastando menos reputação real. Os outros ~30% são
 * produção ↔ produção. Sem chips warming-only, cai pra produção ↔ produção.
 *
 * Função pura (rng injetável) — sem dependências, testável isolada.
 */

'use strict';

/** Fração do tráfego direcionada a um receiver warming-only, quando existir. */
const WARMING_ONLY_RECEIVER_BIAS = 0.7;

function sampleFrom(list, rng) {
  return list[Math.floor(rng() * list.length)];
}

/**
 * @param {Array<{slug:string, warming_only:boolean}>} connected  instâncias vivas do projeto
 * @param {() => number} rng  gerador [0,1) (default Math.random)
 * @returns {{sender:{slug,warming_only}, receiver:{slug,warming_only}}|null}
 */
function pickPair(connected, rng = Math.random) {
  if (!Array.isArray(connected) || connected.length < 2) return null;

  const prod = connected.filter((c) => !c.warming_only);
  const warm = connected.filter((c) => c.warming_only);

  // 70%: um chip de produção manda pra um warming-only.
  if (prod.length && warm.length && rng() < WARMING_ONLY_RECEIVER_BIAS) {
    return { sender: sampleFrom(prod, rng), receiver: sampleFrom(warm, rng) };
  }

  // Senão: dois distintos — preferindo produção; se não houver 2 de produção,
  // usa o pool inteiro (ex.: só warming-only conectados).
  const pool = prod.length >= 2 ? prod : connected;
  const sender = sampleFrom(pool, rng);
  let receiver = sampleFrom(pool, rng);
  let guard = 0;
  while (receiver.slug === sender.slug && guard++ < 20) {
    receiver = sampleFrom(pool, rng);
  }
  if (receiver.slug === sender.slug) return null;

  return { sender, receiver };
}

module.exports = { pickPair, WARMING_ONLY_RECEIVER_BIAS };

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

// ─────────────────────────────────────────────────────────────────────────────
// Fase 2 — grafo de peso variável entre pares.
//
// Em vez de escolher o par uniformemente, cada par não-ordenado tem um peso
// estável (uns conversam mais que outros — ex.: Alice↔Bob 40%, Alice↔Carol 10%),
// o que soa menos "robô". Pares que envolvem um chip warming-only recebem boost
// (mantém a preferência ~70% produção → warming-only). Direção: o chip
// warming-only é sempre o receiver; entre dois de produção, direção aleatória.
// ─────────────────────────────────────────────────────────────────────────────

/** Peso base determinístico e estável por par (0.2..1.0), a partir do nome do par. */
function stableUnit(str) {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return ((h >>> 0) % 10000) / 10000;
}

/** Boost aplicado a pares que envolvem um chip warming-only. */
const WARMING_ONLY_WEIGHT_BOOST = 3;

class PairGraph {
  constructor() {
    this.base = new Map(); // pairKey -> peso base estável
  }

  _key(a, b) {
    return [a, b].sort().join('|');
  }

  _weight(a, b) {
    const key = this._key(a.slug, b.slug);
    if (!this.base.has(key)) {
      this.base.set(key, 0.2 + stableUnit(key) * 0.8);
    }
    let w = this.base.get(key);
    if (a.warming_only || b.warming_only) w *= WARMING_ONLY_WEIGHT_BOOST;
    return w;
  }

  /**
   * Escolhe um par ponderado e define a direção.
   * @param {Array<{slug,warming_only}>} connected
   * @param {() => number} rng
   */
  pick(connected, rng = Math.random) {
    if (!Array.isArray(connected) || connected.length < 2) return null;

    // Candidatos: todos os pares distintos. Evita warming-only ↔ warming-only
    // (dois buffers conversando não protege ninguém); só cai nisso se não houver
    // nenhum par com produção.
    const all = [];
    const withProd = [];
    for (let i = 0; i < connected.length; i++) {
      for (let j = i + 1; j < connected.length; j++) {
        const a = connected[i];
        const b = connected[j];
        const entry = { a, b, w: this._weight(a, b) };
        all.push(entry);
        if (!a.warming_only || !b.warming_only) withProd.push(entry);
      }
    }
    const pool = withProd.length ? withProd : all;

    const total = pool.reduce((s, e) => s + e.w, 0);
    let r = rng() * total;
    let picked = pool[pool.length - 1];
    for (const e of pool) {
      r -= e.w;
      if (r <= 0) { picked = e; break; }
    }

    let sender;
    let receiver;
    if (picked.a.warming_only) {
      sender = picked.b;
      receiver = picked.a;
    } else if (picked.b.warming_only) {
      sender = picked.a;
      receiver = picked.b;
    } else {
      const flip = rng() < 0.5;
      sender = flip ? picked.a : picked.b;
      receiver = flip ? picked.b : picked.a;
    }
    return { sender, receiver };
  }
}

module.exports = { pickPair, PairGraph, WARMING_ONLY_RECEIVER_BIAS, WARMING_ONLY_WEIGHT_BOOST };

/**
 * warming/scripts.js — API de acesso ao catálogo estático de conversas do warming.
 *
 * O warming engine (Fase 1) consome SÓ este módulo. Ele lê a saída estática
 * `scripts.json`, produzida offline por `generate.js` a partir do conteúdo curado
 * em `topics.js`. Não há geração em runtime — o custo de produção é único.
 *
 * Fluxo de manutenção do catálogo:
 *   1. editar warming/topics.js   (conteúdo curado, revisável pelo time)
 *   2. node warming/generate.js   (regrava warming/scripts.json)
 *   3. commitar topics.js + scripts.json
 *
 * ┌── REGRA DE OURO DO CONTEÚDO ───────────────────────────────────────────────┐
 * │ Só cotidiano genérico. NUNCA produto, cliente, operação, ACCA ou trabalho.  │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Formato de cada conversa (script):
 *   {
 *     id, category,
 *     seed:     { from: 'A', text },              // 'A' sempre abre
 *     branches: [ { from:'B', text, followups?:[{from,text}] }, ... ],  // >= 2
 *     variations: { typos:[...], emoji_chance, typing_delay_ms:[min,max] },
 *   }
 * O engine mapeia A/B para duas instâncias CONNECTED distintas do projeto.
 */

'use strict';

const catalog = require('./scripts.json');

/** Categorias cobertas pelo catálogo. */
const CATEGORIES = Object.freeze(catalog.categories.slice());

/** Todos os scripts do catálogo. */
const SCRIPTS = catalog.scripts;

/** Índice category -> scripts, montado uma vez. */
const BY_CATEGORY = SCRIPTS.reduce((acc, s) => {
  (acc[s.category] || (acc[s.category] = [])).push(s);
  return acc;
}, {});

/** Retorna todos os scripts (cópia rasa, pra não mutar o catálogo). */
function all() {
  return SCRIPTS.slice();
}

/** Retorna os scripts de uma categoria. Categoria inválida -> []. */
function byCategory(category) {
  return (BY_CATEGORY[category] || []).slice();
}

/**
 * Sorteia um script. Se `category` for passada, sorteia só dentro dela; se a
 * categoria não existir ou estiver vazia, cai pro catálogo inteiro.
 * `rng` é opcional (default Math.random) pra permitir teste determinístico.
 */
function getRandomScript(category = null, rng = Math.random) {
  const pool = category && BY_CATEGORY[category] ? BY_CATEGORY[category] : SCRIPTS;
  const list = pool.length ? pool : SCRIPTS;
  return list[Math.floor(rng() * list.length)];
}

/**
 * Valida a integridade do catálogo (sanity check / teste):
 *   - ids únicos e não vazios
 *   - category dentro de CATEGORIES
 *   - seed.from === 'A' com texto
 *   - >= 2 branches, cada uma com from/text
 * Retorna { ok, errors: string[] }.
 */
function validate() {
  const errors = [];
  const seen = new Set();

  for (const s of SCRIPTS) {
    const tag = s && s.id ? s.id : '(sem id)';

    if (!s.id) errors.push(`${tag}: id vazio`);
    if (seen.has(s.id)) errors.push(`${tag}: id duplicado`);
    seen.add(s.id);

    if (!CATEGORIES.includes(s.category)) {
      errors.push(`${tag}: categoria inválida "${s.category}"`);
    }
    if (!s.seed || s.seed.from !== 'A' || !s.seed.text) {
      errors.push(`${tag}: seed inválido (precisa from:'A' + text)`);
    }
    if (!Array.isArray(s.branches) || s.branches.length < 2) {
      errors.push(`${tag}: precisa de >= 2 branches`);
    } else {
      s.branches.forEach((b, i) => {
        if (!b.from || !b.text) errors.push(`${tag}: branch #${i} sem from/text`);
      });
    }
  }

  return { ok: errors.length === 0, errors };
}

module.exports = {
  CATEGORIES,
  all,
  byCategory,
  getRandomScript,
  validate,
  count: SCRIPTS.length,
};

/**
 * warming/generate.js — gerador OFFLINE do catálogo de conversas do warming.
 *
 * Uso:  node warming/generate.js        (regrava warming/scripts.json)
 *
 * Modelo (ver card [Warming] Catálogo de templates):
 *   - Não roda em runtime. Gera uma vez, commita a SAÍDA ESTÁTICA (scripts.json).
 *   - A saída é reproduzível: PRNG semeado (mulberry32) => rodar de novo dá o mesmo
 *     arquivo, então o diff no git só muda quando o CONTEÚDO CURADO abaixo muda.
 *
 * ┌── REGRA DE OURO DO CONTEÚDO ───────────────────────────────────────────────┐
 * │ Só cotidiano genérico. NUNCA produto, cliente, operação, ACCA ou trabalho.  │
 * │ Todo texto abaixo é revisável pelo time antes do commit.                    │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Estrutura curada = TOPICS. Cada tópico:
 *   {
 *     key,                       // sufixo do id: <category>_<key>_NN
 *     category,                  // uma de CATEGORIES
 *     seeds: ['...', ...],       // rephrasings da MESMA abertura (>= 2)
 *     responses: [               // respostas plausíveis pra qualquer seed do tópico
 *       { text, followups?: ['A msg', 'B msg', ...] },  // followups alternam B/A/B...
 *       ...
 *     ],
 *     emoji_chance, delay: [min,max],
 *   }
 *
 * O gerador combina cada seed com subconjuntos (2-3) das responses do tópico,
 * garantindo >= 2 branches por conversa e ids únicos. `variations.typos` de cada
 * conversa recebe os OUTROS phrasings do mesmo tópico.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const CATEGORIES = [
  'cotidiano', 'humor', 'trivia', 'cumprimento', 'despedida', 'pergunta_solta', 'resposta_curta',
];

// PRNG determinístico (mulberry32) — saída reproduzível.
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0; a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rand = mulberry32(0x5eed1234);

function shuffle(arr) {
  const a = arr.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

/** Todos os subconjuntos de tamanho k dos índices 0..n-1. */
function combos(n, k) {
  const out = [];
  const pick = (start, acc) => {
    if (acc.length === k) { out.push(acc.slice()); return; }
    for (let i = start; i < n; i++) { acc.push(i); pick(i + 1, acc); acc.pop(); }
  };
  pick(0, []);
  return out;
}

// Quantas conversas gerar por seed de cada tópico. Com ~44 tópicos, ~3 seeds e
// 4 combos/seed => ~500+.
const PER_SEED = 4;

/**
 * Converte followups (lista de textos) em objetos {from,text}. O 1º followup é
 * sempre de 'A' (quem abriu retoma depois da resposta de 'B'), alternando.
 */
function buildFollowups(texts) {
  return texts.map((text, i) => ({ from: i % 2 === 0 ? 'A' : 'B', text }));
}

function expandTopic(topic) {
  const { key, category, seeds, responses, emoji_chance, delay } = topic;
  const scripts = [];

  // Candidatos de branch: subconjuntos de 2 e de 3 respostas, embaralhados.
  const cand = shuffle([...combos(responses.length, 2), ...combos(responses.length, Math.min(3, responses.length))]);

  let n = 0;
  seeds.forEach((seedText, si) => {
    // Offset por seed pra variar quais combos cada phrasing usa.
    for (let c = 0; c < PER_SEED && c < cand.length; c++) {
      const combo = cand[(si * PER_SEED + c) % cand.length];
      const branches = combo.map((ri) => {
        const r = responses[ri];
        const b = { from: 'B', text: r.text };
        if (r.followups && r.followups.length) b.followups = buildFollowups(r.followups);
        return b;
      });

      n += 1;
      scripts.push({
        id: `${category}_${key}_${String(n).padStart(2, '0')}`,
        category,
        seed: { from: 'A', text: seedText },
        branches,
        variations: {
          typos: seeds.filter((s) => s !== seedText),
          emoji_chance,
          typing_delay_ms: delay,
        },
      });
    }
  });

  return scripts;
}

// =============================================================================
// CONTEÚDO CURADO — tópicos por categoria (whatsapp-service/warming/topics.js).
// =============================================================================
const TOPICS = require('./topics');

function main() {
  const all = [];
  for (const topic of TOPICS) all.push(...expandTopic(topic));

  // Sanity: ids únicos, categoria válida, seed 'A', >= 2 branches.
  const seen = new Set();
  const errors = [];
  for (const s of all) {
    if (seen.has(s.id)) errors.push(`id duplicado: ${s.id}`);
    seen.add(s.id);
    if (!CATEGORIES.includes(s.category)) errors.push(`${s.id}: categoria inválida`);
    if (s.seed.from !== 'A' || !s.seed.text) errors.push(`${s.id}: seed inválido`);
    if (!Array.isArray(s.branches) || s.branches.length < 2) errors.push(`${s.id}: < 2 branches`);
  }
  if (errors.length) {
    console.error('FALHA na validação do catálogo gerado:');
    errors.slice(0, 20).forEach((e) => console.error('  - ' + e));
    process.exit(1);
  }

  const outPath = path.join(__dirname, 'scripts.json');
  const payload = {
    _generated_by: 'warming/generate.js',
    _note: 'NÃO editar à mão. Edite warming/topics.js e rode: node warming/generate.js',
    categories: CATEGORIES,
    count: all.length,
    scripts: all,
  };
  fs.writeFileSync(outPath, JSON.stringify(payload, null, 2) + '\n', 'utf8');

  const byCat = {};
  for (const s of all) byCat[s.category] = (byCat[s.category] || 0) + 1;
  console.log(`OK — ${all.length} conversas geradas em ${path.relative(process.cwd(), outPath)}`);
  console.log('Por categoria:', byCat);
}

main();

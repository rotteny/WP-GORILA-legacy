/**
 * warming/thread.js — monta uma THREAD de conversa (Fase 2).
 *
 * Em vez de mandar uma mensagem solta por ciclo, o engine agora toca uma série de
 * 3-7 mensagens entre o mesmo par e depois faz uma pausa longa. Cada script do
 * catálogo é uma micro-conversa coerente (seed → branch → followups): o branch já
 * é uma RESPOSTA CONTEXTUAL ao seed. Pra chegar no tamanho-alvo, encadeia mais de
 * um script (a conversa "muda de assunto", o que é natural).
 *
 * Retorna uma lista ordenada de turnos [{ from: 'A'|'B', text }]. 'A' é sempre
 * quem abre; o runner mapeia A → sender (quem abriu) e B → receiver.
 *
 * Função pura: recebe o objeto `scripts` (com getRandomScript) e um rng injetável.
 */

'use strict';

/** Acrescenta os turnos de um script (seed + 1 branch sorteado + followups). */
function appendScript(turns, script, rng) {
  if (!script || !script.seed || !Array.isArray(script.branches) || !script.branches.length) {
    return;
  }
  turns.push({ from: script.seed.from, text: script.seed.text });

  const branch = script.branches[Math.floor(rng() * script.branches.length)];
  turns.push({ from: branch.from, text: branch.text });

  for (const f of branch.followups || []) {
    turns.push({ from: f.from, text: f.text });
  }
}

/**
 * @param {{getRandomScript: Function}} scripts  catálogo (warming/scripts.js)
 * @param {object} firstScript  script que abre a thread
 * @param {number} target       tamanho-alvo (nº de mensagens), 3-7
 * @param {() => number} rng
 * @returns {Array<{from:'A'|'B', text:string}>}
 */
function buildThread(scripts, firstScript, target, rng = Math.random) {
  const turns = [];
  appendScript(turns, firstScript, rng);

  let guard = 0;
  while (turns.length < target && guard++ < 6) {
    appendScript(turns, scripts.getRandomScript(), rng);
  }

  return turns.slice(0, Math.max(2, target));
}

module.exports = { buildThread };

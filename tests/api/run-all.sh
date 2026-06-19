#!/usr/bin/env bash
# Roda todos os smoke tests em sequência.
# Para no primeiro que falhar (cada script já faz seu próprio exit code).

set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"

cd "$DIR"
FAIL_COUNT=0
for script in $(ls 0*.sh 2>/dev/null | sort); do
  printf "\n═══ %s ═══\n" "$script"
  bash "$script" || FAIL_COUNT=$((FAIL_COUNT+1))
done

if [ "$FAIL_COUNT" -gt 0 ]; then
  printf "\n\033[31m%d script(s) falhou(aram)\033[0m\n" "$FAIL_COUNT"
  exit 1
fi
printf "\n\033[32mTodos os scripts passaram.\033[0m\n"

#!/usr/bin/env bash
# Helpers comuns dos smoke tests.
# Carregue com: source "$(dirname "$0")/lib.sh"

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"
COOKIE_JAR="$SCRIPT_DIR/.cookies"

if [ ! -f "$ENV_FILE" ]; then
  echo "ERRO: $ENV_FILE não existe. Copie de .env.example e preencha." >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

: "${BASE_URL:?BASE_URL não definido}"
: "${LOGIN_EMAIL:?LOGIN_EMAIL não definido}"
: "${LOGIN_PASSWORD:?LOGIN_PASSWORD não definido}"
: "${SOURCE_SLUG:?SOURCE_SLUG não definido}"
: "${DEST_NUMBER:?DEST_NUMBER não definido — preencha em $ENV_FILE}"
: "${TEST_TAG:=[smoke]}"

# Contadores globais
PASS=0
FAIL=0
FAILED_TESTS=()

# Cores
if [ -t 1 ]; then
  C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YEL=$'\033[33m'
  C_BLUE=$'\033[34m'; C_DIM=$'\033[2m'; C_RST=$'\033[0m'
else
  C_RED=; C_GREEN=; C_YEL=; C_BLUE=; C_DIM=; C_RST=
fi

step()  { printf "\n${C_BLUE}▸ %s${C_RST}\n" "$*"; }
info()  { printf "  ${C_DIM}%s${C_RST}\n" "$*"; }
ok()    { PASS=$((PASS+1));  printf "  ${C_GREEN}✓${C_RST} %s\n" "$*"; }
fail()  { FAIL=$((FAIL+1));  FAILED_TESTS+=("$*"); printf "  ${C_RED}✗${C_RST} %s\n" "$*"; }

# Faz login e guarda a sessão em $COOKIE_JAR.
# Idempotente: se já tem cookie válido, reusa.
login() {
  step "Login em $BASE_URL"

  # 1) pega CSRF token da página de login (o Laravel define XSRF-TOKEN no cookie
  #    e expõe um <meta name="csrf-token"> + <input _token>).
  rm -f "$COOKIE_JAR"
  local login_page
  login_page=$(curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/login")
  local csrf
  csrf=$(printf '%s' "$login_page" | grep -oP 'name="_token"\s+value="\K[^"]+' | head -1)
  [ -n "$csrf" ] || { fail "não consegui extrair CSRF token da /login"; return 1; }

  # 2) POST credenciais. Laravel redireciona 302 pra / em sucesso, 200+erro em falha.
  local code
  code=$(curl -sS -o /dev/null -w "%{http_code}" \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
    -X POST "$BASE_URL/login" \
    -H "X-Requested-With: XMLHttpRequest" \
    --data-urlencode "_token=$csrf" \
    --data-urlencode "email=$LOGIN_EMAIL" \
    --data-urlencode "password=$LOGIN_PASSWORD")

  case "$code" in
    302|303) ok "login OK ($code, sessão em $COOKIE_JAR)" ;;
    200)     fail "login retornou 200 — provavelmente credencial inválida"; return 1 ;;
    *)       fail "login retornou HTTP $code"; return 1 ;;
  esac
}

# call METHOD PATH [extra curl args...]
# Resposta no stdout. Status no global LAST_STATUS.
call() {
  local method="$1"; shift
  local path="$1"; shift

  local tmp; tmp=$(mktemp)
  LAST_STATUS=$(curl -sS -o "$tmp" -w "%{http_code}" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -X "$method" "$BASE_URL$path" \
    -H "Accept: application/json" \
    "$@")
  LAST_BODY=$(cat "$tmp")
  rm -f "$tmp"
  printf '%s' "$LAST_BODY"
}

# assert_status EXPECTED ACTUAL "what"
assert_status() {
  local expected="$1" actual="$2" what="$3"
  if [ "$actual" = "$expected" ]; then
    ok "$what (HTTP $actual)"
  else
    fail "$what: esperava $expected, veio $actual — body: $(printf '%s' "$LAST_BODY" | head -c 200)"
  fi
}

# assert_json_has KEY "what"
assert_json_has() {
  local key="$1" what="$2"
  if printf '%s' "$LAST_BODY" | jq -e ".$key" >/dev/null 2>&1; then
    ok "$what (.${key})"
  else
    fail "$what: chave .${key} não está no JSON — body: $(printf '%s' "$LAST_BODY" | head -c 200)"
  fi
}

# assert_send_ok "what"
# A API tem dois shapes de sucesso:
#   - com slug:    { "ok": true, "id": "...", "to": "..." }
#   - fallback:    { "ok": true, "instance": "...", "result": { "ok": true, "id": "...", ... } }
# Considera sucesso se .ok == true E (.id OU .result.id) existir.
assert_send_ok() {
  local what="$1"
  local ok_val id_val
  ok_val=$(printf '%s' "$LAST_BODY" | jq -r '.ok // false' 2>/dev/null)
  id_val=$(printf '%s' "$LAST_BODY" | jq -r '.id // .result.id // empty' 2>/dev/null)
  if [ "$ok_val" = "true" ] && [ -n "$id_val" ]; then
    ok "$what (ok=true, id=$id_val)"
  else
    fail "$what: ok=$ok_val id=$id_val — body: $(printf '%s' "$LAST_BODY" | head -c 200)"
  fi
}

summary() {
  printf "\n${C_DIM}────────────────────────────────${C_RST}\n"
  if [ "$FAIL" -eq 0 ]; then
    printf "${C_GREEN}✓ %d passou, 0 falhou${C_RST}\n" "$PASS"
    exit 0
  else
    printf "${C_RED}✗ %d falhou${C_RST}, %d passou\n" "$FAIL" "$PASS"
    for t in "${FAILED_TESTS[@]}"; do printf "  ${C_RED}-${C_RST} %s\n" "$t"; done
    exit 1
  fi
}

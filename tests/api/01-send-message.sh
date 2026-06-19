#!/usr/bin/env bash
# Smoke: envio de mensagens texto via API
# - Com slug (/instances/{slug}/send-message)
# - Sem slug (fallback automático /send-message)
# - Casos de erro: instância inexistente, body sem 'message'

set -uo pipefail
source "$(dirname "$0")/lib.sh"

login || exit 1

NOW=$(date +%H:%M:%S)
MSG="$TEST_TAG send-message com slug $NOW"
MSG_FB="$TEST_TAG send-message fallback $NOW"

# ─── 1) Pré-checagem: a instância tá CONNECTED? ──────────────────────────────
step "Pré-checagem: status de $SOURCE_SLUG"
call GET "/api/whatsapp/instances/$SOURCE_SLUG/status" >/dev/null
assert_status 200 "$LAST_STATUS" "GET /status responde"
STATUS=$(printf '%s' "$LAST_BODY" | jq -r '.status // empty')
if [ "$STATUS" = "CONNECTED" ]; then
  ok "$SOURCE_SLUG está CONNECTED"
else
  fail "$SOURCE_SLUG está $STATUS (esperava CONNECTED) — abortando envio"
  summary
fi

# ─── 2) Envio com slug ───────────────────────────────────────────────────────
step "POST /instances/$SOURCE_SLUG/send-message"
call POST "/api/whatsapp/instances/$SOURCE_SLUG/send-message" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg n "$DEST_NUMBER" --arg m "$MSG" '{number:$n, message:$m}')" \
  >/dev/null
assert_status 200 "$LAST_STATUS" "envio com slug retorna 200"
assert_send_ok "envio com slug confirmado"

# ─── 3) Envio fallback (sem slug) ────────────────────────────────────────────
step "POST /send-message (fallback automático)"
call POST "/api/whatsapp/send-message" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg n "$DEST_NUMBER" --arg m "$MSG_FB" '{number:$n, message:$m}')" \
  >/dev/null
assert_status 200 "$LAST_STATUS" "fallback retorna 200"
assert_send_ok "envio fallback confirmado"

# ─── 4) Erro: instância inexistente ──────────────────────────────────────────
step "POST /instances/inexistente-xyz/send-message (deve falhar)"
call POST "/api/whatsapp/instances/inexistente-xyz/send-message" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg n "$DEST_NUMBER" '{number:$n, message:"x"}')" \
  >/dev/null
# Esperamos 404 (route model binding) ou 4xx em geral
case "$LAST_STATUS" in
  4*) ok "instância inexistente retorna $LAST_STATUS (4xx esperado)" ;;
  *)  fail "instância inexistente deveria dar 4xx, veio $LAST_STATUS" ;;
esac

# ─── 5) Erro: body sem 'message' ─────────────────────────────────────────────
step "POST /send-message com body inválido (sem message)"
call POST "/api/whatsapp/instances/$SOURCE_SLUG/send-message" \
  -H "Content-Type: application/json" \
  --data "$(jq -n --arg n "$DEST_NUMBER" '{number:$n}')" \
  >/dev/null
assert_status 422 "$LAST_STATUS" "validação dispara 422"

summary

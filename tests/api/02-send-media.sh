#!/usr/bin/env bash
# Smoke: envio de mídia (imagem) via API
# - Com slug (/instances/{slug}/send-media)
# - Sem slug (fallback /send-media)
# - Sem caption / com caption

set -uo pipefail
source "$(dirname "$0")/lib.sh"

login || exit 1

NOW=$(date +%H:%M:%S)
FIXTURE_DIR="$(dirname "$0")/fixtures"
IMG="$FIXTURE_DIR/test.png"

# Gera uma imagem PNG 1x1 vermelha se ainda não existe (evita dep externa)
if [ ! -f "$IMG" ]; then
  mkdir -p "$FIXTURE_DIR"
  # PNG mínimo 1x1 vermelho (84 bytes), base64-decoded
  base64 -d > "$IMG" <<'EOF'
iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM
IQAAAABJRU5ErkJggg==
EOF
  info "fixtura criada: $IMG"
fi

# ─── 1) Envio com slug + caption ─────────────────────────────────────────────
step "POST /instances/$SOURCE_SLUG/send-media (com caption)"
call POST "/api/whatsapp/instances/$SOURCE_SLUG/send-media" \
  -F "number=$DEST_NUMBER" \
  -F "caption=$TEST_TAG send-media com caption $NOW" \
  -F "file=@$IMG" \
  >/dev/null
assert_status 200 "$LAST_STATUS" "send-media (slug + caption) retorna 200"
assert_send_ok "send-media (slug + caption) confirmado"

# ─── 2) Envio com slug sem caption ───────────────────────────────────────────
step "POST /instances/$SOURCE_SLUG/send-media (sem caption)"
call POST "/api/whatsapp/instances/$SOURCE_SLUG/send-media" \
  -F "number=$DEST_NUMBER" \
  -F "file=@$IMG" \
  >/dev/null
assert_status 200 "$LAST_STATUS" "send-media sem caption retorna 200"

# ─── 3) Fallback (sem slug) ──────────────────────────────────────────────────
step "POST /send-media (fallback)"
call POST "/api/whatsapp/send-media" \
  -F "number=$DEST_NUMBER" \
  -F "caption=$TEST_TAG send-media fallback $NOW" \
  -F "file=@$IMG" \
  >/dev/null
assert_status 200 "$LAST_STATUS" "fallback retorna 200"

# ─── 4) Erro: sem file ───────────────────────────────────────────────────────
step "POST /send-media sem file (deve falhar)"
call POST "/api/whatsapp/instances/$SOURCE_SLUG/send-media" \
  -F "number=$DEST_NUMBER" \
  >/dev/null
assert_status 422 "$LAST_STATUS" "ausência de file dispara 422"

summary

#!/usr/bin/env bash
# =============================================================================
# setup.sh — integra o wp-gorila ao Laradock principal já existente.
#
# O QUE FAZ:
#   1. Valida que o Laradock principal existe
#   2. Anexa o whatsapp-service no docker-compose.yml do Laradock principal
#   3. Cria .env a partir do .env.example (se ainda não existir)
#   4. Garante a pasta auth_info para o Baileys
#   5. Sobe os containers whatsapp-service e reverb (se não estiverem rodando)
#
# PRÉ-REQUISITO:
#   - Laradock principal em ../../laradock (relativo a este projeto)
#   - Banco wp_gorila criado no postgres principal:
#       docker exec laradock-postgres-1 psql -U default -c "CREATE DATABASE wp_gorila OWNER default;"
#
# DEPOIS DESTE SCRIPT:
#   docker compose exec --user=laradock workspace bash
#     cd wp-gorila
#     composer install
#     php artisan key:generate
#     php artisan migrate
#     npm install && npm run build
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARADOCK_DIR="$(realpath "$ROOT/../../laradock")"
SNIPPET="$ROOT/laradock-snippets/docker-compose.snippet.yml"
MARKER="### WhatsApp Service — wp-gorila"

cyan()  { printf "\033[36m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
yellow(){ printf "\033[33m%s\033[0m\n" "$*"; }

# -----------------------------------------------------------------------------
# 1. Validar Laradock principal
# -----------------------------------------------------------------------------
if [ ! -f "$LARADOCK_DIR/docker-compose.yml" ]; then
  echo "ERRO: Laradock principal não encontrado em $LARADOCK_DIR" >&2
  exit 1
fi
cyan "[1/4] Laradock principal encontrado em $LARADOCK_DIR."

# -----------------------------------------------------------------------------
# 2. Anexar whatsapp-service no docker-compose.yml do Laradock principal
# -----------------------------------------------------------------------------
COMPOSE_FILE="$LARADOCK_DIR/docker-compose.yml"

cyan "[2/4] Verificando whatsapp-service em $COMPOSE_FILE..."
if grep -q "$MARKER" "$COMPOSE_FILE"; then
  yellow "    Snippet já presente — pulando."
else
  printf "\n" >> "$COMPOSE_FILE"
  cat "$SNIPPET" >> "$COMPOSE_FILE"
  green "    Snippet anexado."
fi

# -----------------------------------------------------------------------------
# 3. Criar .env a partir do .env.example
# -----------------------------------------------------------------------------
LARAVEL_ENV="$ROOT/.env"
LARAVEL_ENV_EXAMPLE="$ROOT/.env.example"

cyan "[3/4] Verificando .env..."
if [ -f "$LARAVEL_ENV" ]; then
  yellow "    .env já existe — pulando."
else
  if [ -f "$LARAVEL_ENV_EXAMPLE" ]; then
    cp "$LARAVEL_ENV_EXAMPLE" "$LARAVEL_ENV"
    green "    .env criado. Lembre-se: php artisan key:generate"
  else
    echo "AVISO: .env.example não encontrado." >&2
  fi
fi

# -----------------------------------------------------------------------------
# 4. Garantir pasta de sessões do Baileys
# -----------------------------------------------------------------------------
mkdir -p "$ROOT/whatsapp-service/auth_info"
cyan "[4/4] Pasta auth_info pronta."

# -----------------------------------------------------------------------------
# 5. Subir whatsapp-service e reverb (idempotente)
# -----------------------------------------------------------------------------
cyan "[5/5] Verificando containers whatsapp-service e reverb..."
cd "$LARADOCK_DIR"

WA_RUNNING=$(docker compose ps --services --filter "status=running" 2>/dev/null | grep "^whatsapp-service$" || true)
REVERB_RUNNING=$(docker compose ps --services --filter "status=running" 2>/dev/null | grep "^reverb$" || true)

if [ -n "$WA_RUNNING" ] && [ -n "$REVERB_RUNNING" ]; then
  yellow "    whatsapp-service e reverb já estão rodando — pulando."
else
  docker compose up -d whatsapp-service reverb
  green "    Containers iniciados."
fi

cd "$ROOT"

echo
green "Setup concluído!"
cat <<EOF

Banco de dados (se ainda não criado):
  docker exec laradock-postgres-1 psql -U default -c "CREATE DATABASE wp_gorila OWNER default;"

Instalar dependências (dentro do workspace do Laradock principal):
  docker compose exec --user=laradock workspace bash -c "
    cd wp-gorila
    composer install
    php artisan key:generate
    php artisan migrate
    npm install && npm run build
  "

Verificar Reverb (WebSocket server):
  docker logs wp_gorila_reverb --tail 20

Acesse: https://wp.local
EOF

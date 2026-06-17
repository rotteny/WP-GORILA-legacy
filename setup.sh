#!/usr/bin/env bash
# =============================================================================
# setup.sh — provisiona o ambiente Laradock para o WhatsApp Piloto da Gorila.
#
# O QUE FAZ:
#   1. Clona o Laradock (se ainda não existir)
#   2. Aplica nossas modificações no laradock/.env (portas + postgres + paths)
#   3. Anexa o serviço whatsapp-service no laradock/docker-compose.yml
#   4. Cria a pasta auth_info_baileys (vazia) para o bind mount
#
# DEPOIS DELE, ROTEIRO MANUAL (ver README.md):
#   - docker compose up -d nginx postgres whatsapp-service
#   - composer create-project laravel/laravel . (dentro do workspace)
#   - aplicar nossos arquivos por cima (instruções no README)
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARADOCK_DIR="$ROOT/laradock"
SNIPPET="$ROOT/laradock-snippets/docker-compose.snippet.yml"

cyan()  { printf "\033[36m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
yellow(){ printf "\033[33m%s\033[0m\n" "$*"; }

# -----------------------------------------------------------------------------
# 1. Clonar o Laradock
# -----------------------------------------------------------------------------
if [ -d "$LARADOCK_DIR/.git" ]; then
  yellow "[1/4] Laradock já clonado em $LARADOCK_DIR — pulando."
else
  cyan "[1/4] Clonando Laradock em $LARADOCK_DIR..."
  git clone https://github.com/laradock/laradock.git "$LARADOCK_DIR"
fi

# -----------------------------------------------------------------------------
# 2. Criar e ajustar laradock/.env
# -----------------------------------------------------------------------------
ENV_FILE="$LARADOCK_DIR/.env"

if [ ! -f "$ENV_FILE" ]; then
  if [ -f "$LARADOCK_DIR/.env.example" ]; then
    cp "$LARADOCK_DIR/.env.example" "$ENV_FILE"
  elif [ -f "$LARADOCK_DIR/env-example" ]; then
    cp "$LARADOCK_DIR/env-example" "$ENV_FILE"
  else
    echo "ERRO: nenhum .env.example encontrado no Laradock." >&2
    exit 1
  fi
fi

cyan "[2/4] Ajustando $ENV_FILE..."

# Substitui de forma idempotente: se a chave existe, troca o valor;
# se não existe, anexa no final.
patch_env() {
  local key="$1" value="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    echo "${key}=${value}" >> "$ENV_FILE"
  fi
}

patch_env APP_CODE_PATH_HOST                 "../laravel"
patch_env DATA_PATH_HOST                     "~/.laradock/data/whatsapp_piloto"
patch_env COMPOSE_PROJECT_NAME               "whatsapp_piloto"
patch_env PHP_VERSION                        "8.3"
patch_env NGINX_HOST_HTTP_PORT               "8088"
patch_env NGINX_HOST_HTTPS_PORT              "8448"
patch_env VARNISH_BACKEND_PORT               "8181"
patch_env WORKSPACE_SSH_PORT                 "2232"
patch_env WORKSPACE_BROWSERSYNC_HOST_PORT    "3010"
patch_env WORKSPACE_BROWSERSYNC_UI_HOST_PORT "3011"
patch_env WORKSPACE_VUE_CLI_SERVE_HOST_PORT  "8089"
patch_env WORKSPACE_VUE_CLI_UI_HOST_PORT     "8011"
patch_env WORKSPACE_ANGULAR_CLI_SERVE_HOST_PORT "4210"
patch_env WORKSPACE_VITE_PORT                "5183"
patch_env POSTGRES_VERSION                   "16-alpine"
patch_env POSTGRES_DB                        "whatsapp_piloto"
patch_env POSTGRES_USER                      "whatsapp"
patch_env POSTGRES_PASSWORD                  "secret"
patch_env POSTGRES_PORT                      "5433"

green "    .env ajustado."

# -----------------------------------------------------------------------------
# 3. Anexar whatsapp-service no docker-compose.yml
# -----------------------------------------------------------------------------
COMPOSE_FILE="$LARADOCK_DIR/docker-compose.yml"
MARKER="### WhatsApp Service (Baileys)"

cyan "[3/4] Anexando whatsapp-service em $COMPOSE_FILE..."
if grep -q "$MARKER" "$COMPOSE_FILE"; then
  yellow "    Snippet já presente — pulando."
else
  printf "\n" >> "$COMPOSE_FILE"
  cat "$SNIPPET" >> "$COMPOSE_FILE"
  green "    Snippet anexado."
fi

# -----------------------------------------------------------------------------
# 4. Garantir pastas de sessão do Baileys
# -----------------------------------------------------------------------------
mkdir -p "$ROOT/whatsapp-service/auth_info" "$ROOT/whatsapp-service/auth_info_baileys"
cyan "[4/4] Pastas auth_info (multi-sessão) e auth_info_baileys (legacy) garantidas."

echo
green "Setup concluído!"
cat <<EOF

Próximos passos (manual, fora do escopo deste script):

  cd laradock
  docker compose up -d nginx postgres whatsapp-service
  docker compose exec --user=laradock workspace bash
  # dentro do workspace:
  composer install                # ou create-project laravel/laravel .
  php artisan migrate
  npm install
  npm run build
  exit

Acesse: http://localhost:8088
EOF

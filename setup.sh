#!/usr/bin/env bash
# =============================================================================
# setup.sh — provisiona o wp-gorila integrado ao Laradock principal.
#
# O QUE FAZ:
#   1. Valida que o Laradock principal existe em ../../laradock
#   2. Builda a imagem Docker do whatsapp-service (whatsapp-service:local)
#   3. Anexa whatsapp-service e reverb no docker-compose.yml do Laradock (idempotente)
#   4. Cria .env a partir do .env.example (se ainda não existir)
#   5. Garante a pasta auth_info para o Baileys
#   6. Sobe os containers whatsapp-service e reverb
#
# PRÉ-REQUISITO:
#   - Laradock principal em ../../laradock (relativo a este projeto)
#   - Banco wp_gorila criado no postgres principal:
#       docker exec laradock-postgres-1 psql -U default -c "CREATE DATABASE wp_gorila OWNER default;"
#
# DEPOIS DESTE SCRIPT (dentro do workspace Laravel):
#   docker compose exec --user=laradock workspace bash
#     cd wp-gorila
#     composer install
#     php artisan key:generate
#     php artisan migrate
#     php artisan db:seed --class=AdminUserSeeder
#     php artisan db:seed --class=PilotoInstanceSeeder
#     npm install && npm run build
#
# NOTA: o index.js do whatsapp-service é copiado para dentro da imagem no build.
# Após editar o código Node, execute:
#   docker build -t whatsapp-service:local ./whatsapp-service/ && docker restart whatsapp-service
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
cyan "[1/5] Laradock principal encontrado em $LARADOCK_DIR."

# -----------------------------------------------------------------------------
# 2. Build da imagem do whatsapp-service
# -----------------------------------------------------------------------------
cyan "[2/5] Buildando imagem whatsapp-service:local..."
docker build -t whatsapp-service:local "$ROOT/whatsapp-service/"
green "    Imagem whatsapp-service:local pronta."

# -----------------------------------------------------------------------------
# 3. Anexar whatsapp-service e reverb no docker-compose.yml do Laradock
# -----------------------------------------------------------------------------
COMPOSE_FILE="$LARADOCK_DIR/docker-compose.yml"

cyan "[3/5] Verificando whatsapp-service em $COMPOSE_FILE..."
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

cyan "[4/5] Verificando .env..."
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
cyan "[5/5] Pasta auth_info pronta."

# -----------------------------------------------------------------------------
# 6. Subir whatsapp-service e reverb (idempotente)
# -----------------------------------------------------------------------------
cyan "[6/6] Verificando containers whatsapp-service e reverb..."
cd "$LARADOCK_DIR"

WA_RUNNING=$(docker ps --filter "name=^whatsapp-service$" --filter "status=running" -q || true)
REVERB_RUNNING=$(docker compose ps --services --filter "status=running" 2>/dev/null | grep "^reverb$" || true)

if [ -n "$WA_RUNNING" ]; then
  yellow "    whatsapp-service já rodando — recriando com imagem atualizada."
  docker stop whatsapp-service && docker rm whatsapp-service
fi
# O Node entrega webhook e consulta o warming no Laravel via nginx. Com Host "nginx"
# a requisição cai num vhost errado do laradock (não há default_server) => 404 em
# TUDO (webhook de conexão + /internal/warming-*). Mapeamos APP_HOST -> IP do nginx
# e mandamos o webhook por esse host, que casa o vhost certo (wp.local por padrão).
NET="$(basename "$LARADOCK_DIR")_backend"
APP_HOST="${WPG_APP_HOST:-wp.local}"
NGINX_CONTAINER=$(docker ps --format '{{.Names}}' | grep -i nginx | head -1 || true)
NGINX_IP=""
if [ -n "$NGINX_CONTAINER" ]; then
  NGINX_IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$NGINX_CONTAINER" 2>/dev/null || true)
fi
ADD_HOST=""
if [ -n "$NGINX_IP" ]; then
  ADD_HOST="--add-host $APP_HOST:$NGINX_IP"
else
  yellow "    aviso: IP do nginx não resolvido na rede $NET — o webhook/warming pode dar 404 (Host errado)."
fi

# shellcheck disable=SC2086  # $ADD_HOST precisa de word-splitting (vira 2 args ou vazio)
docker run -d \
  --name whatsapp-service \
  --network "$NET" \
  $ADD_HOST \
  -e LARAVEL_WEBHOOK_URL="http://$APP_HOST/api/whatsapp/webhook" \
  -v "$ROOT/whatsapp-service/auth_info:/usr/src/app/auth_info" \
  whatsapp-service:local
green "    whatsapp-service iniciado (webhook -> http://$APP_HOST)."

if [ -z "$REVERB_RUNNING" ]; then
  cd "$LARADOCK_DIR"
  docker compose up -d reverb
  green "    reverb iniciado."
  cd "$ROOT"
else
  yellow "    reverb já está rodando."
fi

cd "$ROOT"

echo
green "Setup concluído!"
cat <<EOF

──────────────────────────────────────────────────────────────
PRÓXIMOS PASSOS
──────────────────────────────────────────────────────────────

1. Criar banco (se ainda não existir):
   docker exec laradock-postgres-1 psql -U default -c "CREATE DATABASE wp_gorila OWNER default;"

2. Instalar dependências e migrar (dentro do workspace):
   docker exec -u laradock laradock-workspace-1 bash -c "
     cd /var/www/wp-gorila
     composer install
     php artisan key:generate
     php artisan migrate
     php artisan db:seed --class=AdminUserSeeder
     php artisan db:seed --class=PilotoInstanceSeeder
     npm install && npm run build
   "

3. Verificar serviços:
   docker logs whatsapp-service --tail 10
   docker logs wp_gorila_reverb --tail 10

4. Acessar: https://wp.local
   Login: admin@gorila.com / (ver ADMIN_SEED_PASSWORD no .env)

──────────────────────────────────────────────────────────────
REBUILD após editar o whatsapp-service:
  docker build -t whatsapp-service:local ./whatsapp-service/
  docker stop whatsapp-service && docker rm whatsapp-service
  docker run -d --name whatsapp-service --network laradock_backend \\
    -v "\$PWD/whatsapp-service/auth_info:/usr/src/app/auth_info" \\
    whatsapp-service:local
──────────────────────────────────────────────────────────────
EOF

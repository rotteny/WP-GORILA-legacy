# Alterações que o `setup.sh` aplica no `.env` do Laradock

Estas são as alterações concretas (a referência fica aqui pra quem
quiser entender o que o script faz, ou aplicar manualmente).

## Caminhos e identificação

| Chave | Valor |
|---|---|
| `APP_CODE_PATH_HOST` | `../laravel` |
| `DATA_PATH_HOST` | `~/.laradock/data/whatsapp_piloto` |
| `COMPOSE_PROJECT_NAME` | `whatsapp_piloto` |
| `PHP_VERSION` | `8.3` |

## Portas — evitam conflito com outros Laradocks na máquina

| Chave | Valor |
|---|---|
| `NGINX_HOST_HTTP_PORT` | `8088` |
| `NGINX_HOST_HTTPS_PORT` | `8448` |
| `VARNISH_BACKEND_PORT` | `8181` (mapeado em nginx:81; mudamos pra liberar 81) |
| `WORKSPACE_SSH_PORT` | `2232` |
| `WORKSPACE_BROWSERSYNC_HOST_PORT` | `3010` |
| `WORKSPACE_BROWSERSYNC_UI_HOST_PORT` | `3011` |
| `WORKSPACE_VUE_CLI_SERVE_HOST_PORT` | `8089` |
| `WORKSPACE_VUE_CLI_UI_HOST_PORT` | `8011` |
| `WORKSPACE_ANGULAR_CLI_SERVE_HOST_PORT` | `4210` |
| `WORKSPACE_VITE_PORT` | `5183` |
| `POSTGRES_PORT` | `5433` |

## Postgres

| Chave | Valor |
|---|---|
| `POSTGRES_VERSION` | `16-alpine` |
| `POSTGRES_DB` | `whatsapp_piloto` |
| `POSTGRES_USER` | `whatsapp` |
| `POSTGRES_PASSWORD` | `secret` |

## Variáveis do `.env` do Laravel (`laravel/.env`)

```dotenv
APP_NAME="WhatsApp Piloto Gorila"
APP_URL=http://localhost:8088

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432            # interno da rede docker — não muda
DB_DATABASE=whatsapp_piloto
DB_USERNAME=whatsapp
DB_PASSWORD=secret

WHATSAPP_SERVICE_URL=http://whatsapp-service:3000
```

## Porta exposta do `whatsapp-service`

No host: **`127.0.0.1:3020`** → porta 3000 dentro do container.
A escolha do 3020 evita conflito com `WORKSPACE_BROWSERSYNC_HOST_PORT=3010`.

# Alterações que o `setup.sh` aplica no `.env` do Laradock

Estas são as alterações concretas (a referência fica aqui pra quem
quiser entender o que o script faz, ou aplicar manualmente).

## Caminhos e identificação

| Chave | Valor |
|---|---|
| `APP_CODE_PATH_HOST` | `../laravel` |
| `DATA_PATH_HOST` | `~/.laradock/data/wp_gorila` |
| `COMPOSE_PROJECT_NAME` | `wp_gorila` |
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

O banco **não** sobe no Laradock próprio do wp-gorila. Usa o `laradock-postgres-1`
do Laradock principal (já rodando na porta `5432` do host).

O banco `wp_gorila` precisa existir no postgres principal antes do `migrate`.
Criação manual (uma vez só):
```sql
CREATE DATABASE wp_gorila OWNER default;
```

As chaves abaixo são apenas referência — o postgres do wp-gorila não é iniciado.

| Chave | Valor |
|---|---|
| `POSTGRES_VERSION` | `16-alpine` |
| `POSTGRES_DB` | `wp_gorila` |
| `POSTGRES_USER` | `default` |
| `POSTGRES_PASSWORD` | `secret` |
| `POSTGRES_PORT` | `5433` (não usado) |

## Variáveis do `.env` do Laravel (`laravel/.env`)

```dotenv
APP_NAME="WhatsApp Piloto Gorila"
APP_URL=http://localhost:8088

DB_CONNECTION=pgsql
DB_HOST=host.docker.internal   # Laradock principal, acessado via host
DB_PORT=5432
DB_DATABASE=wp_gorila
DB_USERNAME=default
DB_PASSWORD=secret

WHATSAPP_SERVICE_URL=http://whatsapp-service:3000
```

## Porta exposta do `whatsapp-service`

No host: **`127.0.0.1:3020`** → porta 3000 dentro do container.
A escolha do 3020 evita conflito com `WORKSPACE_BROWSERSYNC_HOST_PORT=3010`.

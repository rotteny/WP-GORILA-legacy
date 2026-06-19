# wp-gorila — Piloto interno da API WhatsApp da Gorila

API piloto pra automatizar envios e recebimentos no WhatsApp usando a biblioteca
open-source **Baileys**. Projeto interno, baixa escala, foco em "configurar e esquecer".

## Arquitetura

```
┌──────────────┐  long poll 3-5s ┌─────────────────────┐   webhook   ┌─────────────────────┐
│   Vue SPA    │ ──────────────▶ │  Laravel (Nginx)    │ ◀────────── │  whatsapp-service   │
│  (multi-tela)│ ◀── JSON ────── │  https://wp.local   │             │  Node + Baileys     │
└──────────────┘                 │  Postgres (laradock)│             │  http://:3020       │
                                 └─────────────────────┘             └─────────────────────┘
                                          │                                    │
                                          ▼                                    ▼
                                  Tabela `instances`              Map<slug, InstanceState>
                                  (uma linha por projeto)        auth_info/{slug}/ no disco
```

**Multi-sessão:** o sistema gerencia N sessões WhatsApp simultâneas (uma por projeto), cada uma
identificada por um **slug** (`piloto`, `acca`, etc.). Cada projeto tem sua própria pasta de
credenciais Baileys (`auth_info/{slug}/`) e seu próprio buffer de conversas.

- **whatsapp-service** (Node + Express + Baileys) — micro-serviço que mantém um
  `Map<slug, InstanceState>` em memória; no startup, varre `auth_info/*/` e reativa todas as
  sessões automaticamente. Dispara webhook pro Laravel em cada evento com `instance_id` no payload.
- **Laravel 13** — guarda o estado das instâncias em Postgres (`instances`), expõe API REST com
  prefixo `/api/whatsapp/` e proxia cada chamada pro Node.
- **Vue 3** — três telas:
  - `/` → `InstancesScreen.vue` (lista de projetos com cards)
  - `/p/{slug}/qr` → `WhatsAppConnect.vue` (escanear QR)
  - `/p/{slug}/chat` → `ChatScreen.vue` (chat estilo WhatsApp Web)

## Stack

| Camada | Tecnologia |
|---|---|
| Infra | Docker + Laradock principal (Nginx, PHP-FPM 8.3, Postgres 16) |
| Micro-serviço | Node 20 + Express + `@whiskeysockets/baileys` |
| Backend | Laravel 13 + Postgres |
| Frontend | Vue 3 + Vite + Tailwind |

## Pré-requisitos

- Laradock principal já rodando em `../../laradock` (relativo a este projeto)
- Banco `wp_gorila` criado no Postgres principal:
  ```bash
  docker exec laradock-postgres-1 psql -U default -c "CREATE DATABASE wp_gorila OWNER default;"
  ```
- `wp.local` apontando para `127.0.0.1` no `/etc/hosts`

---

## Estrutura

```
wp-gorila/
├── app/                            # Laravel — controllers, models, providers
│   └── Http/Controllers/
│       ├── Auth/AuthController.php # Login / logout (session-based)
│       ├── InstanceController.php
│       └── WhatsAppController.php
├── bootstrap/                      # Laravel bootstrap
├── config/                         # Configurações Laravel
├── database/
│   ├── migrations/                 # create_instances_table + defaults Laravel
│   └── seeders/
│       ├── AdminUserSeeder.php     # Usuário admin inicial (idempotente)
│       └── PilotoInstanceSeeder.php
├── public/                         # Document root do Nginx
├── resources/
│   ├── js/components/              # Vue 3: InstancesScreen, WhatsAppConnect, ChatScreen
│   └── views/
│       ├── auth/login.blade.php    # Tela de login
│       ├── instances.blade.php
│       ├── whatsapp.blade.php
│       └── chat.blade.php
├── routes/
│   ├── web.php                     # Rotas protegidas por auth + /login
│   └── api.php                     # /webhook (público) + demais (auth:web)
├── storage/
├── tests/
├── whatsapp-service/               # Micro-serviço Node + Baileys
│   ├── Dockerfile
│   ├── index.js
│   ├── package.json
│   └── auth_info/                  # bind-mount; uma subpasta por slug
├── laradock-snippets/              # Patches para integrar ao Laradock principal
│   ├── docker-compose.snippet.yml  # Bloco do whatsapp-service (4-space indent)
│   └── env.changes.md              # Documentação das variáveis necessárias
├── setup.sh                        # Provisionamento automatizado
├── .env.example
└── README.md
```

---

## Setup — do zero ao QR code

> **Para IAs e agentes de código:** o arquivo `setup.sh` na raiz do projeto é o ponto de entrada
> único para provisionamento. Ele configura toda a integração com o Laradock principal, cria o
> `.env` e prepara o `whatsapp-service`. **Execute-o primeiro** antes de qualquer outra tarefa de
> configuração de ambiente. Não tente configurar manualmente o que o `setup.sh` já automatiza.

### 1. Clonar o repositório

```bash
git clone git@github.com:rotteny/wp-gorila.git
# posicionar em ../../ relativo ao Laradock principal, ex:
# /home/user/workspace/wp-gorila/  (Laradock em /home/user/laradock/)
```

### 2. Rodar o setup.sh

```bash
./setup.sh
```

O script faz tudo automaticamente:
1. Valida que o Laradock principal existe em `../../laradock`
2. Anexa o `whatsapp-service` no `docker-compose.yml` do Laradock principal (idempotente)
3. Cria `.env` a partir do `.env.example` (não sobrescreve se já existir)
4. Garante a pasta `whatsapp-service/auth_info/`

### 3. Subir o whatsapp-service

```bash
cd ../../laradock
docker compose up -d whatsapp-service
```

A primeira execução builda a imagem (instala Baileys via npm) — demora 2 a 4 min.

### 4. Instalar dependências e migrar

```bash
docker compose exec --user=laradock workspace bash
cd wp-gorila
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
php artisan db:seed --class=PilotoInstanceSeeder
npm install && npm run build
exit
```

### 5. Acessar

Abra **https://wp.local** no navegador. Você será redirecionado para o login.

---

## Autenticação

O sistema usa autenticação **session-based** nativa do Laravel.

### Credenciais padrão (desenvolvimento)

| Campo | Valor |
|---|---|
| E-mail | `admin@gorila.com` |
| Senha | `gorila@2025` |

A senha é lida do `.env` via `ADMIN_SEED_PASSWORD`. Antes de rodar o seeder, adicione ao `.env`:

```dotenv
ADMIN_SEED_PASSWORD=gorila@2025
```

Para recriar o usuário admin (o seeder é idempotente — não duplica):

```bash
docker exec -u laradock laradock-workspace-1 bash -c \
  "cd /var/www/wp-gorila && php artisan db:seed --class=AdminUserSeeder"
```

### Rotas protegidas

- **Web** (`/`, `/p/{slug}/qr`, `/p/{slug}/chat`) — exigem `auth`, redirecionam para `/login`
- **API** (`/api/whatsapp/instances`, `/instances/{id}/...`) — exigem `auth:web`
- **Webhook** (`POST /api/whatsapp/webhook`) — **público** (chamado internamente pelo Node)

---

## Operação no dia-a-dia

| Comando | Quando usar |
|---|---|
| `docker compose up -d whatsapp-service` (em `laradock/`) | Subir o serviço Node |
| `docker compose down whatsapp-service` | Derrubar o serviço Node |
| `docker compose logs -f whatsapp-service` | Ver eventos Baileys (QR, conexão, msgs) |
| `docker compose exec --user=laradock workspace bash` | Entrar no workspace Laravel |
| `docker compose build whatsapp-service` | Rebuild após editar `index.js` |
| Botão "Gerar novo QR" na UI | Recomeçar pareamento de uma instância |

---

## Fluxo do Baileys

```
INITIALIZING ──► PENDING_QR ──► CONNECTED ──► (rede cai) ──► RECONNECTING
                     │              │                            │
                     │ scan failed  │ logout pelo celular        │
                     ▼              ▼                            │
                 LOGGED_OUT ◀───────┴────────────────────────────┘
                     │
                     │ apagar auth_info/{slug}/ + restart
                     ▼
                 INITIALIZING (novo QR)
```

A pasta `whatsapp-service/auth_info/{slug}/` é bind-mount — a sessão sobrevive a
`docker compose down`. Apagar o conteúdo dela força novo QR **só daquele projeto**.

---

## API — exemplos

Todos os endpoints de instância ficam em `/api/whatsapp/`. Requerem sessão autenticada
(exceto `/webhook`).

```bash
# Listar projetos
curl -b cookies.txt https://wp.local/api/whatsapp/instances | jq .

# Criar projeto
curl -b cookies.txt -X POST https://wp.local/api/whatsapp/instances \
  -H "Content-Type: application/json" \
  -d '{"slug":"acca","name":"Atendimento ACCA"}'

# Status de uma instância
curl -b cookies.txt https://wp.local/api/whatsapp/instances/piloto/status | jq .

# Enviar mensagem
curl -b cookies.txt -X POST https://wp.local/api/whatsapp/instances/piloto/send-message \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"Olá da Gorila!"}'

# Resetar sessão (gera novo QR)
curl -b cookies.txt -X POST https://wp.local/api/whatsapp/instances/piloto/reset

# Deletar instância
curl -b cookies.txt -X DELETE https://wp.local/api/whatsapp/instances/acca
```

---

## Produção — checklist

Antes de expor em produção, aplicar no `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.com

SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

ADMIN_SEED_PASSWORD=  # trocar por senha forte antes de rodar o seeder
```

Outros pontos:

- **Trocar a senha do admin** — nunca subir `gorila@2025` em produção. Altere via tinker:
  ```bash
  php artisan tinker --execute="App\Models\User::where('email','admin@gorila.com')->update(['password' => bcrypt('nova-senha')]);"
  ```
- **Remover o `User::factory()->create()` do `DatabaseSeeder`** antes de rodar `db:seed` em
  produção — ou proteger com `if (app()->isLocal())`.
- **HTTPS obrigatório** — `SESSION_SECURE_COOKIE=true` só funciona com HTTPS. Gere certificado
  SSL real para o domínio (Let's Encrypt, etc.).
- **`APP_DEBUG=false`** — nunca expor stack traces em produção.
- **Webhook interno** — `POST /api/whatsapp/webhook` permanece público por design (chamado
  pelo Node). Garantir que o `whatsapp-service` esteja na mesma rede Docker e nunca exposto
  externamente.

---

## Troubleshooting

**Tela branca em `https://wp.local`** — Vite não buildou. Entre no workspace e rode `npm run build`.

**`whatsapp-service` em loop de "Conexão fechada"** — sessão inválida. Use o botão
"Gerar novo QR" na UI ou via API (`POST /instances/{slug}/reset`). Último recurso:

```bash
docker stop whatsapp-service
rm -rf whatsapp-service/auth_info/piloto/*   # substitua pelo slug
docker start whatsapp-service
```

**Webhook 404 / 500** — Laravel não migrou ou `routes/api.php` não está registrado em
`bootstrap/app.php`. Verifique `storage/logs/laravel.log`.

**Login redireciona em loop** — `APP_KEY` não gerado. Rode `php artisan key:generate` dentro
do workspace.

**`SQLSTATE: could not connect to server`** — o Postgres do Laradock principal não está rodando.
Verifique com `docker ps | grep postgres`.

---

## Licença

Uso interno Gorila. Baileys é MIT (não afiliado oficialmente ao WhatsApp/Meta).

# wp-gorila — Plataforma WhatsApp da Gorila Software House

Solução completa para automação de envio e recebimento de mensagens WhatsApp, desenvolvida
internamente pela **Gorila Software House**. Construída sobre tecnologias modernas e open-source:

| Componente | Tecnologia | Papel |
|---|---|---|
| Protocolo WhatsApp | [`@whiskeysockets/baileys`](https://github.com/WhiskeySockets/Baileys) | Biblioteca Node.js que implementa o protocolo WebSocket do WhatsApp Web, sem depender de API oficial |
| Backend | **Laravel 13** (PHP 8.3) | API REST, persistência, autenticação e orquestração dos eventos |
| Banco de dados | **PostgreSQL 16** | Armazenamento de instâncias, mensagens e configurações |
| WebSocket | **Laravel Reverb** | Entrega de eventos em tempo real para o frontend (nova mensagem, reações, status) |
| Frontend | **Vue 3** | Interface web estilo WhatsApp Web — lista de conversas, chat, painel de webhooks |
| Infra | **Docker + Laradock** | Nginx, PHP-FPM, Postgres e Reverb rodando em containers |

O sistema gerencia **múltiplas instâncias WhatsApp simultâneas** (uma por projeto/cliente), cada
uma com sessão independente. Expõe API REST para integração com sistemas externos e um painel de
webhooks para encaminhar eventos em tempo real para qualquer URL configurada.

> Projeto interno, baixa escala, foco em estabilidade e facilidade de operação.

## Arquitetura

```
┌──────────────┐  WebSocket (Echo)  ┌──────────────────────┐   webhook   ┌─────────────────────┐
│   Vue SPA    │ ◀───────────────── │  Reverb (ws/:8080)   │ ◀────────── │  whatsapp-service   │
│  (multi-tela)│ ──── REST API ───▶ │  Laravel (Nginx)     │             │  Node + Baileys     │
└──────────────┘                    │  https://wp.local    │             │  http://:3020       │
                                    │  Postgres (laradock) │             └─────────────────────┘
                                    └──────────────────────┘
                                             │
                                             ▼
                                     Tabela `instances`
                                     (uma linha por projeto)
                                     auth_info/{slug}/ no disco
```

**Fluxo de tempo real:** o `whatsapp-service` envia evento (QR gerado, status mudou) via webhook → Laravel salva no banco e dispara `broadcast(new InstanceUpdated($instance))` → **Reverb** entrega via WebSocket → Vue atualiza a tela instantaneamente. O polling HTTP é mantido apenas como fallback caso o WebSocket não conecte.

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
| WebSocket | **Laravel Reverb** (container `wp_gorila_reverb`, porta 8080) |
| Frontend | Vue 3 + Vite + Tailwind + Laravel Echo + pusher-js |

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
2. Builda a imagem Docker do `whatsapp-service` localmente (`whatsapp-service:local`)
3. Anexa o `whatsapp-service` e `reverb` no `docker-compose.yml` do Laradock (idempotente)
4. Cria `.env` a partir do `.env.example` (não sobrescreve se já existir)
5. Garante a pasta `whatsapp-service/auth_info/`
6. Sobe os containers `whatsapp-service` e `reverb`

> **Importante:** o `index.js` do `whatsapp-service` é copiado para dentro da imagem no build
> (não é bind-mounted). Sempre que editar o código do Node, rebuilde a imagem com o `setup.sh`
> ou manualmente: `docker build -t whatsapp-service:local ./whatsapp-service/`

### 3. Instalar dependências e migrar

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

Verificar se Reverb subiu:
```bash
docker logs wp_gorila_reverb --tail 20
# Deve exibir: Starting server on 0.0.0.0:8080
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
| `docker compose up -d whatsapp-service reverb` (em `laradock/`) | Subir ambos os serviços |
| `docker compose down whatsapp-service reverb` | Derrubar ambos |
| `docker logs whatsapp-service -f` | Ver eventos Baileys (QR, conexão, msgs) |
| `docker logs wp_gorila_reverb -f` | Ver logs do WebSocket server |
| `docker compose exec --user=laradock workspace bash` | Entrar no workspace Laravel |
| `docker build -t whatsapp-service:local ./whatsapp-service/ && docker restart whatsapp-service` | Rebuild após editar `index.js` |
| `npm run build` (dentro do workspace) | Rebuild frontend após editar Vue/JS |
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

### Tipos de conversa (`chat_type`)

O Baileys identifica o tipo de origem de cada mensagem pelo sufixo do JID:

| Sufixo do JID | `chat_type` | Descrição |
|---|---|---|
| `@s.whatsapp.net` | `private` | Contato individual |
| `@g.us` | `group` | Grupo |
| `@broadcast` | `broadcast` | Lista de transmissão |
| `@newsletter` | `newsletter` | Canal do WhatsApp |
| `@lid` | `private_lid` | Identificador de dispositivo vinculado |

O campo `chat_type` é salvo no banco em cada mensagem e exibido como badge na lista de conversas.

### Campos do remetente

Cada mensagem recebida expõe:

- **`sender_name`** — nome de exibição do remetente (`pushName`), conforme cadastrado no WhatsApp dele.
- **`sender_phone`** — número extraído do JID do remetente (ex.: `5511999887766`). Em grupos, vem do `key.participant`; em conversas privadas, vem do `key.remoteJid`.

### Eventos em tempo real

O `whatsapp-service` escuta os seguintes eventos do Baileys e repassa via webhook ao Laravel, que faz broadcast via Reverb:

| Evento Baileys | Webhook `event` | Broadcast Laravel |
|---|---|---|
| `messages.upsert` | `message` | `MessageReceived` |
| `messages.delete` | `message_deleted` | `MessageDeleted` |
| `messages.reaction` | `message_reaction` | `MessageReaction` |
| `connection.update` | `connection` / `qr` | `InstanceUpdated` |

---

## API — referência rápida

Todos os endpoints ficam em `/api/whatsapp/` e requerem sessão autenticada (exceto `/webhook`).
Documentação completa de payloads em [`docs/mensagens.md`](docs/mensagens.md).

### Instâncias

```bash
GET    /api/whatsapp/instances                   # listar projetos
POST   /api/whatsapp/instances                   # criar projeto { slug, name }
DELETE /api/whatsapp/instances/{slug}            # remover projeto
GET    /api/whatsapp/instances/{slug}/status     # status atual
POST   /api/whatsapp/instances/{slug}/reset      # gerar novo QR
```

### Envio de mensagens

```bash
# Com slug específico (retorna 409 se não CONNECTED)
POST /api/whatsapp/instances/{slug}/send-message   { number, message }
POST /api/whatsapp/instances/{slug}/send-media     multipart: file, number, caption?

# Sem slug — fallback automático entre instâncias CONNECTED
POST /api/whatsapp/send-message                    { number, message }
POST /api/whatsapp/send-media                      multipart: file, number, caption?
```

### Conversas e mídia

```bash
GET /api/whatsapp/instances/{slug}/chats/{jid}/messages   # histórico de mensagens
GET /api/whatsapp/instances/{slug}/media/{message_id}     # download de mídia
```

### Webhooks de redirecionamento

```bash
GET    /api/whatsapp/instances/{slug}/webhooks            # listar configs
PUT    /api/whatsapp/instances/{slug}/webhooks            # salvar { event, url, active, secret? }
DELETE /api/whatsapp/instances/{slug}/webhooks/{event}    # remover config de um evento
```

Eventos disponíveis: `message`, `message_deleted`, `message_reaction`, `connection`.

### Exemplos curl

```bash
# Enviar mensagem com fallback automático
curl -b cookies.txt -X POST https://wp.local/api/whatsapp/send-message \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"Olá da Gorila!"}'

# Enviar arquivo
curl -b cookies.txt -X POST https://wp.local/api/whatsapp/send-media \
  -F "number=5511999999999" -F "file=@foto.jpg" -F "caption=Veja!"

# Configurar webhook para novas mensagens
curl -b cookies.txt -X PUT https://wp.local/api/whatsapp/instances/piloto/webhooks \
  -H "Content-Type: application/json" \
  -d '{"event":"message","url":"https://meu-sistema.com/hook","active":true}'
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

## WebSocket — Reverb

O Laravel Reverb serve como broker WebSocket. A Vue SPA conecta via **Laravel Echo** e ouve o canal `instance.{slug}` — quando o webhook chega, Laravel dispara `InstanceUpdated` e o Reverb entrega pro browser em tempo real.

### Variáveis de ambiente (`.env`)

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Gerar valores únicos para `REVERB_APP_ID/KEY/SECRET` antes de ir para produção.

### Container

O `reverb` está definido no `docker-compose.yml` do Laradock principal como `wp_gorila_reverb`. O snippet está em `laradock-snippets/docker-compose.snippet.yml` e é inserido automaticamente pelo `setup.sh`.

O Nginx proxia `/app` e `/apps` para `reverb:8080` com suporte a WebSocket (`Upgrade`).

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

**QR Code não atualiza em tempo real** — Reverb pode não estar rodando. Verifique:
```bash
docker ps | grep reverb
docker logs wp_gorila_reverb --tail 30
```
Se não estiver rodando: `cd ../../laradock && docker compose up -d reverb`. O Vue faz fallback para polling automático se o WS não conectar.

**WebSocket connection refused no console do browser** — verifique se as `VITE_REVERB_*` no `.env` batem com o container e se `npm run build` foi rodado após alterar o `.env`.

---

## Licença

Uso interno Gorila. Baileys é MIT (não afiliado oficialmente ao WhatsApp/Meta).

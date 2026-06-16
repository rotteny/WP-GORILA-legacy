# WP-GORILA — Piloto interno da API WhatsApp da Gorila

API piloto pra automatizar envios e recebimentos no WhatsApp usando a biblioteca
open-source **Baileys**. Projeto interno, baixa escala, foco em
"configurar e esquecer".

## Arquitetura

```
┌──────────────┐  long poll 3s   ┌─────────────────────┐   webhook   ┌─────────────────────┐
│   Vue SPA    │ ──────────────▶ │  Laravel (Nginx)    │ ◀────────── │  whatsapp-service   │
│  (qrcode.vue)│ ◀── JSON ────── │  http://:8088       │             │  Node + Baileys     │
└──────────────┘                 │  Postgres :5433     │             │  http://:3020       │
                                 └─────────────────────┘             └─────────────────────┘
```

- **whatsapp-service** (Node + Express + Baileys) — micro-serviço isolado que
  fala com o WhatsApp via WebSocket. Gera o QR, mantém a sessão e dispara
  webhooks pro Laravel quando o estado muda.
- **Laravel 13** — guarda o estado em Postgres na tabela `whatsapp_setups`,
  expõe `GET /api/whatsapp/status` para o front e proxia
  `POST /api/whatsapp/send-message` pro Node.
- **Vue 3** — componente único `WhatsAppConnect.vue` faz long polling de 3s
  no Laravel. Renderiza o QR via `qrcode.vue` ou mostra "Conectado".

## Stack

| Camada | Tecnologia |
|---|---|
| Infra | Docker + Laradock (Nginx, PHP-FPM 8.3, Postgres 16) |
| Micro-serviço | Node 20 + Express + `@whiskeysockets/baileys` |
| Backend | Laravel 13 + Postgres |
| Frontend | Vue 3 + Vite + Tailwind |

## Pré-requisitos

- Docker 20+ e Docker Compose plugin
- Git
- Portas livres na máquina host: **8088, 8181, 8448, 2232, 3010, 3011, 3020, 4210, 5183, 5433, 8011, 8089**
  (Se já tiver outro Laradock rodando, esses números foram escolhidos pra
  conviverem em paralelo. Veja `laradock-snippets/env.changes.md` se precisar mudar.)

---

## 🚀 Tutorial completo — do zero ao QR code

### Passo 1 — Clonar o repositório

```bash
git clone git@github.com:Mystic0112/WP-GORILA.git
cd WP-GORILA
git checkout helio   # ou a branch que você quer usar
```

### Passo 2 — Provisionar o Laradock

```bash
./setup.sh
```

O script:
1. Clona o Laradock oficial em `./laradock/`
2. Ajusta `.env` (portas, postgres, paths)
3. Anexa o serviço `whatsapp-service` no `docker-compose.yml`
4. Cria a pasta vazia `whatsapp-service/auth_info_baileys/`

### Passo 3 — Subir os containers

```bash
cd laradock
docker compose up -d nginx postgres whatsapp-service
cd ..
```

A primeira execução vai buildar a imagem do `whatsapp-service` (instala o
Baileys via npm). Demora 2 a 4 minutos. Depois fica em cache.

Verifica que tudo subiu:

```bash
docker ps --filter "name=whatsapp_piloto" --format "table {{.Names}}\t{{.Status}}"
```

Você deve ver `nginx-1`, `postgres-1`, `php-fpm-1`, `workspace-1`,
`docker-in-docker-1` e `whatsapp-service` todos `Up`.

### Passo 4 — Instalar o Laravel dentro do workspace

```bash
cd laradock
docker compose exec --user=laradock workspace bash
```

Já dentro do container:

```bash
# Instala as dependências PHP (se o composer.json já existe no repo)
composer install

# Ou, se for o primeiro setup do projeto Laravel:
# composer create-project laravel/laravel /tmp/fresh && \
#   cp -rn /tmp/fresh/* . && cp /tmp/fresh/.env.example .

# Gera chave da aplicação
cp .env.example .env
php artisan key:generate

# Cria a tabela whatsapp_setups
php artisan migrate --force

# Instala deps do front e builda
npm install
npm run build

exit
```

### Passo 5 — Acessar a aplicação

Abre no navegador: **http://localhost:8088**

Você verá o componente Vue carregando. Em ~3 segundos o long polling pega o
QR Code do Baileys e renderiza na tela.

Escaneia com o WhatsApp do celular em:
**Configurações → Aparelhos conectados → Conectar aparelho**

Quando parear, a tela muda automaticamente para "WhatsApp conectado".

---

## 🧪 Como testar manualmente

### Status do Baileys (raw)

```bash
curl http://localhost:3020/status | jq .
```

### Status persistido no Laravel

```bash
curl http://localhost:8088/api/whatsapp/status | jq .
```

### Enviar mensagem (com WhatsApp já conectado)

```bash
curl -X POST http://localhost:8088/api/whatsapp/send-message \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"Olá da Gorila!"}'
```

### Resetar a sessão (gerar novo QR sem mexer no shell)

Use o botão **"Gerar novo QR"** na interface, ou via API:

```bash
curl -X POST http://localhost:8088/api/whatsapp/reset
```

---

## 📁 Estrutura

```
WP-GORILA/
├── setup.sh                       # Provisionamento automatizado do Laradock
├── README.md                      # Este arquivo
│
├── whatsapp-service/              # Micro-serviço Baileys
│   ├── Dockerfile                 # node:20-alpine
│   ├── index.js                   # Endpoints: /status, /send-message, /reset, /health
│   └── package.json               # Baileys + Express + axios + qrcode + pino
│
├── laravel/                       # App Laravel 13
│   ├── app/Http/Controllers/WhatsAppController.php
│   ├── app/Models/WhatsappSetup.php
│   ├── bootstrap/app.php          # registra routes/api.php
│   ├── config/services.php        # bloco 'whatsapp' aqui
│   ├── database/migrations/2026_06_16_000000_create_whatsapp_setups_table.php
│   ├── resources/js/app.js        # monta WhatsAppConnect.vue
│   ├── resources/js/components/WhatsAppConnect.vue
│   ├── resources/views/whatsapp.blade.php
│   ├── routes/api.php             # /webhook, /status, /send-message, /reset
│   └── routes/web.php             # GET / → view whatsapp
│
├── laradock-snippets/             # Patches que o setup.sh aplica
│   ├── env.changes.md
│   └── docker-compose.snippet.yml
│
└── laradock/                      # ⚠️ Clonado pelo setup.sh, não versionado
```

---

## 🔁 Operação no dia-a-dia

| Comando | Quando usar |
|---|---|
| `docker compose up -d nginx postgres whatsapp-service` (em `laradock/`) | Subir tudo |
| `docker compose down` | Derrubar tudo |
| `docker compose logs -f whatsapp-service` | Ver eventos do Baileys (QR, conexão, mensagens) |
| `docker compose exec --user=laradock workspace bash` | Entrar no container do Laravel |
| `docker compose build whatsapp-service` | Rebuild após editar `index.js` do Node |
| Botão "Gerar novo QR" na UI | Recomeçar pareamento (apaga sessão) |

---

## 🧠 Fluxo do Baileys — em uma página

```
INITIALIZING ──► PENDING_QR ──► CONNECTED ──► (rede cai) ──► RECONNECTING
                     │              │                            │
                     │ scan failed  │ logout pelo celular        │
                     ▼              ▼                            │
                 LOGGED_OUT ◀───────┴────────────────────────────┘
                     │
                     │ apagar auth_info_baileys + restart
                     ▼
                 INITIALIZING (novo QR)
```

A pasta `whatsapp-service/auth_info_baileys/` é **bind-mount**, então a sessão
sobrevive a `docker compose down`. Apagar o conteúdo dela = forçar novo QR.

O Node dispara webhook para `http://nginx/api/whatsapp/webhook` (Laravel)
sempre que o estado muda — não há polling Laravel↔Node.

---

## 🐛 Troubleshooting

**"Port already allocated" ao subir o compose** — outro Laradock está
ocupando alguma porta. Edite `laradock/.env` e mude o número conflitante
(há sugestões em `laradock-snippets/env.changes.md`).

**Tela branca em http://localhost:8088** — o build do Vite não rodou.
Entra no workspace e roda `npm run build`. Verifique também que
`bootstrap/app.php` tem `api: __DIR__.'/../routes/api.php'` no `withRouting()`.

**`whatsapp-service` em loop de "Conexão fechada"** — sessão pode estar
inválida. Botão "Gerar novo QR" na UI ou:

```bash
docker stop whatsapp-service
rm -rf whatsapp-service/auth_info_baileys/*
docker start whatsapp-service
```

**Webhook tomando 404 / 500** — Laravel ainda não terminou de migrar ou
`routes/api.php` não está registrado. Veja `storage/logs/laravel.log`.

---

## Licença

Uso interno Gorila. Baileys é MIT (não é afiliado oficialmente ao WhatsApp/Meta).

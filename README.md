# WP-GORILA — Piloto interno da API WhatsApp da Gorila

API piloto pra automatizar envios e recebimentos no WhatsApp usando a biblioteca
open-source **Baileys**. Projeto interno, baixa escala, foco em
"configurar e esquecer".

## Arquitetura

```
┌──────────────┐  long poll 3-5s ┌─────────────────────┐   webhook   ┌─────────────────────┐
│   Vue SPA    │ ──────────────▶ │  Laravel (Nginx)    │ ◀────────── │  whatsapp-service   │
│  (multi-tela)│ ◀── JSON ────── │  http://:8088       │             │  Node + Baileys     │
└──────────────┘                 │  Postgres :5433     │             │  http://:3020       │
                                 └─────────────────────┘             └─────────────────────┘
                                          │                                    │
                                          ▼                                    ▼
                                  Tabela `instances`              Map<slug, InstanceState>
                                  (uma linha por projeto)        auth_info/{slug}/ no disco
```

**Multi-sessão:** o sistema gerencia N sessões WhatsApp simultâneas (uma por
projeto), cada uma identificada por um **slug** (`piloto`, `acca`, etc.). Cada
projeto tem sua própria pasta de credenciais Baileys (`auth_info/{slug}/`) e
seu próprio buffer de conversas.

- **whatsapp-service** (Node + Express + Baileys) — micro-serviço que mantém
  um `Map<slug, InstanceState>` em memória; no startup, varre `auth_info/*/`
  e reativa todas as sessões automaticamente. Dispara webhook pro Laravel
  em cada evento com `instance_id` no payload.
- **Laravel 13** — guarda o estado das instâncias em Postgres (`instances`),
  expõe API REST com prefixo `/api/whatsapp/instances/{slug}/...` e proxia
  cada chamada pro Node.
- **Vue 3** — três telas:
  - `/` → `InstancesScreen.vue` (lista de projetos com cards)
  - `/p/{slug}/qr` → `WhatsAppConnect.vue` (escanear QR)
  - `/p/{slug}/chat` → `ChatScreen.vue` (chat estilo WhatsApp Web)

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

# Cria as tabelas (instances + outras default do Laravel)
php artisan migrate --force

# Cria a instância "piloto" (primeira sessão WhatsApp)
php artisan db:seed --class=PilotoInstanceSeeder --force

# Instala deps do front e builda
npm install
npm run build

exit
```

### Passo 5 — Acessar a aplicação

Abre no navegador: **http://localhost:8088**

Você verá a tela de **listagem de projetos**. Já vai aparecer o projeto
"Piloto Inicial" criado pelo seeder. Clica nele:

- Se for a primeira vez (status `PENDING_QR`), abre a tela de QR. Escaneie em
  **Configurações → Aparelhos conectados → Conectar aparelho** no WhatsApp.
- Se já estiver conectado, abre direto o chat.

Pra criar novos projetos (novos números WhatsApp): botão **"+ Novo projeto"** no
canto superior direito.

---

## 🧪 Como testar manualmente

A API toda é prefixada por `/api/whatsapp/instances/{slug}/`. Use o slug
do projeto (`piloto`, `acca`, etc.) nos exemplos abaixo.

### Listar projetos

```bash
curl http://localhost:8088/api/whatsapp/instances | jq .
```

### Criar projeto novo

```bash
curl -X POST http://localhost:8088/api/whatsapp/instances \
  -H "Content-Type: application/json" \
  -d '{"slug":"acca","name":"Atendimento ACCA"}'
```

### Status de uma instância

```bash
curl http://localhost:8088/api/whatsapp/instances/piloto/status | jq .
```

### Enviar mensagem (com WhatsApp já conectado)

```bash
curl -X POST http://localhost:8088/api/whatsapp/instances/piloto/send-message \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"Olá da Gorila!"}'
```

### Enviar arquivo (imagem, áudio, PDF, etc.)

```bash
curl -X POST http://localhost:8088/api/whatsapp/instances/piloto/send-media \
  -F "number=5511999999999" \
  -F "caption=Olha esta foto" \
  -F "file=@/caminho/pra/arquivo.jpg"
```

### Resetar a sessão (gerar novo QR sem mexer no shell)

Use o botão **"Gerar novo QR"** na interface, ou via API:

```bash
curl -X POST http://localhost:8088/api/whatsapp/instances/piloto/reset
```

### Deletar uma instância

```bash
curl -X DELETE http://localhost:8088/api/whatsapp/instances/acca
```

---

## 📁 Estrutura

```
WP-GORILA/
├── setup.sh                       # Provisionamento automatizado do Laradock
├── README.md                      # Este arquivo
│
├── whatsapp-service/              # Micro-serviço Baileys (multi-sessão)
│   ├── Dockerfile                 # node:20-alpine
│   ├── index.js                   # Map<slug, InstanceState> + endpoints /instances/:id/...
│   ├── package.json               # Baileys + Express + multer + axios + qrcode + pino
│   └── auth_info/                 # bind-mount, uma subpasta por projeto
│       ├── piloto/                # auth do projeto "piloto"
│       └── acca/                  # (exemplo) auth do projeto "acca"
│
├── laravel/                       # App Laravel 13
│   ├── app/Http/Controllers/WhatsAppController.php
│   ├── app/Http/Controllers/InstanceController.php
│   ├── app/Models/Instance.php
│   ├── bootstrap/app.php          # registra routes/api.php
│   ├── config/services.php        # bloco 'whatsapp' aqui
│   ├── database/migrations/      # create_instances_table + default Laravel
│   ├── database/seeders/PilotoInstanceSeeder.php
│   ├── resources/js/app.js        # 3 mount points
│   ├── resources/js/components/   # InstancesScreen + WhatsAppConnect + ChatScreen
│   ├── resources/views/           # instances.blade, whatsapp.blade, chat.blade
│   ├── routes/api.php             # tudo prefixado por /instances/{instance}/
│   └── routes/web.php             # /, /p/{slug}/qr, /p/{slug}/chat
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
                     │ apagar auth_info/{slug}/ + restart
                     ▼
                 INITIALIZING (novo QR)
```

A pasta `whatsapp-service/auth_info/{slug}/` é **bind-mount**, então a sessão
sobrevive a `docker compose down`. Apagar o conteúdo dela = forçar novo QR
**daquele projeto** (não afeta os outros).

O Node dispara webhook para `http://nginx/api/whatsapp/webhook` (Laravel) com
`instance_id` no payload sempre que o estado de qualquer instância muda — não
há polling Laravel↔Node.

---

## 🐛 Troubleshooting

**"Port already allocated" ao subir o compose** — outro Laradock está
ocupando alguma porta. Edite `laradock/.env` e mude o número conflitante
(há sugestões em `laradock-snippets/env.changes.md`).

**Tela branca em http://localhost:8088** — o build do Vite não rodou.
Entra no workspace e roda `npm run build`. Verifique também que
`bootstrap/app.php` tem `api: __DIR__.'/../routes/api.php'` no `withRouting()`.

**`whatsapp-service` em loop de "Conexão fechada"** — sessão pode estar
inválida. Botão "Gerar novo QR" na UI **da instância em questão**, ou via API:

```bash
curl -X POST http://localhost:8088/api/whatsapp/instances/piloto/reset
```

Pra resetar manualmente (último caso):

```bash
docker stop whatsapp-service
rm -rf whatsapp-service/auth_info/piloto/*   # troque "piloto" pelo slug
docker start whatsapp-service
```

**Webhook tomando 404 / 500** — Laravel ainda não terminou de migrar ou
`routes/api.php` não está registrado. Veja `storage/logs/laravel.log`.

---

## Licença

Uso interno Gorila. Baileys é MIT (não é afiliado oficialmente ao WhatsApp/Meta).

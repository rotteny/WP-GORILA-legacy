# tests/api/bruno — Collection Bruno

Collection da API wp-gorila no formato [Bruno](https://www.usebruno.com).

## Como usar (visual)

1. Instalar o Bruno Desktop em https://www.usebruno.com
2. Abrir o app → **Open Collection** → selecionar a pasta `tests/api/bruno`
3. Sidebar → escolher o environment **local** ou **prod**
4. Editar `environments/local.bru` com seu `destNumber` e `slug`
5. Clicar em qualquer request → **Send**

## Como usar (CLI)

```bash
# Instalar Bruno CLI (uma vez)
npm install -g @usebruno/cli

# Rodar a collection inteira
cd tests/api/bruno
bru run --env local

# Rodar só um folder
bru run 03-send --env local

# Rodar só um request
bru run 03-send/01-send-message-slug.bru --env local
```

## Auth

A API é session-based. O fluxo é:

1. `01-auth/00-csrf.bru` → faz GET `/login`, extrai o `_token` do HTML e guarda em `bru.setVar("csrfToken")`.
2. `01-auth/01-login.bru` → faz POST `/login` com o `csrfToken` + email/senha. Bruno mantém o cookie de sessão automaticamente.
3. As demais requests reusam o cookie.

**Rode os dois primeiros antes** das outras (ou rode a collection inteira em ordem).

## Estrutura

```
tests/api/bruno/
├── bruno.json                       metadados da collection
├── environments/
│   ├── local.bru                    BASE_URL=http://wp.local
│   └── prod.bru                     vazio, preenche quando subir
├── 01-auth/
│   ├── 00-csrf.bru                  GET /login + extrai _token
│   ├── 01-login.bru                 POST /login com csrf
│   └── 02-logout.bru                POST /logout
├── 02-instances/
│   ├── 01-list.bru                  GET /instances
│   ├── 02-status.bru                GET /instances/{slug}/status
│   ├── 03-create.bru                POST /instances
│   ├── 04-delete.bru                DELETE /instances/{slug}
│   └── 05-reset.bru                 POST /instances/{slug}/reset
├── 03-send/
│   ├── 01-send-message-slug.bru     POST /instances/{slug}/send-message
│   ├── 02-send-message-fallback.bru POST /send-message
│   ├── 03-send-message-no-message.bru   espera 422
│   └── 04-send-media-slug.bru       POST /instances/{slug}/send-media (multipart)
├── 04-webhooks/
│   ├── 01-list.bru                  GET /instances/{slug}/webhooks
│   ├── 02-upsert.bru                PUT /instances/{slug}/webhooks
│   ├── 03-delete.bru                DELETE /instances/{slug}/webhooks/{event}
│   └── 04-inbound-webhook-from-node.bru POST /api/whatsapp/webhook (público)
└── 05-chats/
    └── 01-list-chats.bru            GET /instances/{slug}/chats
```

## Adicionando um request novo

1. Escolha o folder (ou crie novo) com prefixo numérico
2. Crie `NN-meu-request.bru`
3. Use as variáveis `{{baseUrl}}`, `{{slug}}`, `{{destNumber}}`, `{{testTag}}` definidas no environment
4. Bloco `tests { ... }` no final pra validar status/body com Chai (`expect`)

## Diferenças vs `tests/api/*.sh`

| | smoke `*.sh` | bruno |
|---|---|---|
| Roda em CI | ✅ bash | ✅ `bru run` |
| Roda visualmente | ❌ | ✅ |
| Versionado no Git | ✅ | ✅ |
| Edição rápida | bash | UI do Bruno |
| Manipulação de cookies | manual (curl -c -b) | automática |

Os dois cobrem casos parecidos. O smoke é mais robusto em CI sem deps (só `curl + jq`); o Bruno é mais agradável pra explorar a API e mostrar pro time.

# tests/api — Smoke tests

Bateria de smoke tests em bash. Dispara requisições HTTP reais contra a API
e valida HTTP code + chaves do JSON de resposta.

> ⚠️ **Envia WhatsApp de verdade.** Use um número-cobaia em `DEST_NUMBER`.

## Pré-requisitos

- `curl`, `jq` (`sudo apt install jq`)
- O app rodando em `BASE_URL`
- Pelo menos uma instância CONNECTED

## Setup (uma vez)

```bash
cp tests/api/.env.example tests/api/.env
# edite tests/api/.env e preencha DEST_NUMBER
```

`tests/api/.env` é ignorado pelo git (não vaza credenciais).

## Rodar

```bash
# tudo:
bash tests/api/run-all.sh

# script específico:
bash tests/api/01-send-message.sh
bash tests/api/02-send-media.sh
```

## Estrutura

```
tests/api/
├── .env.example       # template — copie pra .env e edite
├── .env               # (gitignored) credenciais reais
├── .cookies           # (gitignored) sessão Laravel após login
├── lib.sh             # helpers: login(), call(), assert_status(), summary()
├── run-all.sh         # roda todos os 0*.sh em ordem
├── 01-send-message.sh # texto com slug, fallback, erros
├── 02-send-media.sh   # imagem com slug, fallback, erros
└── fixtures/          # arquivos de teste (PNG 1x1 gerado on-demand)
```

## Adicionar um teste novo

1. Crie `tests/api/NN-meu-teste.sh` (NN = número crescente)
2. Comece com:
   ```bash
   #!/usr/bin/env bash
   set -uo pipefail
   source "$(dirname "$0")/lib.sh"
   login || exit 1
   ```
3. Use `call METHOD PATH ...args curl...` e os helpers de assert.
4. Termine com `summary`.

## Saída

Verde `✓` = OK. Vermelho `✗` = falhou.
Exit code 0 se tudo passa, 1 caso contrário (CI-friendly).

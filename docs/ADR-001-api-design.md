# ADR-001 — Design da API pública v1 (WhatsApp Gorila)

- **Status:** Aceito
- **Data:** 2026-06-18
- **Escopo:** Chunk 1 — Foundation da API formal
- **Autores:** Time de plataforma (Hélio + Escanor)

## Contexto

O piloto WhatsApp da Gorila já tem multi-sessão funcional (M1-M5): uma UI Vue interna controla
instâncias, exibe QR Code e envia mensagens diretamente, batendo nas rotas `/api/whatsapp/*`
do Laravel, que por sua vez proxia para o serviço Node (Baileys).

A próxima evolução exige expor essa capacidade para **outros sistemas internos da Gorila**
(CRM, sistema de cobrança, futuros bots), com um contrato estável, autenticado, versionado e
documentável. Os requisitos são diferentes do uso interno:

| Aspecto | UI interna | Sistemas consumidores |
|---|---|---|
| Autenticação | Mesma origem, sem token | Header HTTP obrigatório |
| Contrato | Pode mudar a qualquer hora | Precisa de versionamento explícito |
| Validação | Confiamos no front | Form Requests rigorosos |
| Output | JSON cru basta | Resources com formato estável |
| Documentação | Não precisa | OpenAPI / Scribe |

## Decisões

### 1. Dois conjuntos de rotas separadas, em paralelo

- **`/api/whatsapp/*`** — rotas internas, sem autenticação, contrato livre. Continuam servindo
  a UI Vue da Gorila e o webhook do Node (`/api/whatsapp/webhook`).
- **`/api/v1/*`** — rotas públicas, autenticação obrigatória, contrato estável, versionado.

**Por quê?** Misturar os dois acopla preocupações que evoluem em ritmos diferentes. A UI
interna precisa de agilidade e iteração rápida; a API pública precisa de estabilidade. Manter
em paralelo permite mudar a UI sem quebrar integrações externas, e vice-versa. Não há
duplicação relevante: os controllers v1 são finos e delegam para os Models / serviços de
domínio compartilhados.

### 2. Autenticação via API key estática no header `X-API-Key`

Optamos por API key simples em vez de OAuth2 ou Laravel Sanctum.

**Trade-off aceito:**
- **Prós:** zero overhead de implementação no consumidor; sem fluxo de refresh; sem dependência
  de cookies/sessões; alinhado com o perfil dos consumidores (sistemas server-to-server da
  mesma organização, não usuários finais).
- **Contras:** não há escopo granular por key (por enquanto); revogação é binária
  (`active=false`); não há identidade de usuário.

O modelo de consumidor é **trusted internal service**, então OAuth seria over-engineering.
Quando aparecer o primeiro consumidor externo de terceiros, evoluímos para algo mais robusto
(Sanctum personal access tokens ou Passport).

### 3. Armazenamento da key como SHA-256, nunca em plaintext

A tabela `api_keys` guarda apenas `key_hash = sha256(raw)`. A key crua só existe no momento da
geração (comando `whatsapp:api-key:create`) e é exibida **uma única vez** no terminal.

**Por quê?** Mesmo em ambiente interno, vazamento de dump de banco não deve comprometer keys
ativas. SHA-256 sem salt é aceitável aqui porque a key crua tem entropia alta
(`wpg_` + 24 bytes aleatórios = 48 hex chars), tornando ataque de dicionário/rainbow inviável.
Não usamos bcrypt porque o lookup precisa ser O(1) por hash — bcrypt exigiria varrer toda a
tabela em cada request.

### 4. Versionamento explícito via prefixo `/v1`

APIs evoluem. Quando precisarmos quebrar contrato (mudar formato de response, renomear campo,
mudar semântica), criamos `/v2` mantendo `/v1` rodando em deprecação por um período.

**Alternativa rejeitada:** versionamento por header (`Accept: application/vnd.gorila.v1+json`).
Mais purista REST, mas menos amigável para debugging via curl e dificulta cache HTTP. Prefixo
em path é mais explícito e operacionalmente mais simples.

### 5. Form Requests + API Resources para contrato estável

- **API Resources** (`InstanceResource`, `MessageResource`): isolam o formato JSON de saída do
  schema do banco. Permite renomear coluna, adicionar campo interno (`raw_payload`, `qr_code`)
  sem vazar para o consumidor. Campo sensível de debug (`raw_payload`) e específico da UI
  (`qr_code`, `qr_data_url`) **não** são expostos na v1.
- **Form Requests:** virão completos no Chunk 3 (junto com `POST /messages`). No Chunk 1, os
  endpoints são read-only ou stub, então validação ainda é mínima.

### 6. Modelo de retry / idempotência via `client_message_id`

A tabela `messages` já contempla `client_message_id` (id que o consumidor envia) e
`whatsapp_message_id` (id do Baileys). Isso permite:

- O consumidor mandar a mesma mensagem com mesmo `client_message_id` em caso de timeout — o
  backend detecta e retorna a mensagem original em vez de duplicar.
- Conciliar webhooks de status (delivered, read) com a mensagem original via
  `whatsapp_message_id`, que tem `UNIQUE(instance_id, whatsapp_message_id)`.

A lógica de idempotência será implementada no Chunk 3; a estrutura de dados está pronta no
Chunk 1 para evitar nova migration depois.

## Consequências

### Positivas

- Contrato público desacoplado da UI interna → evolução independente.
- Segurança razoável para o perfil de uso (server-to-server interno).
- Estrutura pronta para Scribe / OpenAPI no Chunk 5.
- `messages` e `webhook_endpoints` já modelados antecipando Chunks 2-4.

### Negativas / dívida assumida

- Sem escopo por key (toda key tem acesso total). Aceitável para o piloto; adicionar
  `scopes jsonb` na tabela `api_keys` quando necessário.
- Sem rate limiting por key ainda. Vem no Chunk 5 via `throttle` middleware do Laravel.
- Sem rotação automática de keys. Operação manual via comando artisan (`active=false` + nova
  key) por enquanto.
- `last_used_at` atualizado sincronamente em cada request autenticada — escrita extra no
  banco. Aceitável no volume do piloto; movemos para job assíncrono ou Redis se virar
  gargalo.

## Referências

- Plano do Chunk 1 (mensagem original do PR)
- Laravel 13 Routing & Middleware docs
- OWASP API Security Top 10 — API2:2023 Broken Authentication

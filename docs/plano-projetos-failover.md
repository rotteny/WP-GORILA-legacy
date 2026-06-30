# Plano — Agrupamento de telefones por projeto + Failover

> Status: **proposta** (aguardando aprovação para implementação)
> Data: 2026-06-26
> Autor: Yan + Claude

## 1. Problema

Hoje cada **instância** (`instances`) é uma conexão WhatsApp isolada (um telefone). Não há
nada que agrupe instâncias, e a API key + webhook são presos a uma instância específica
(`api_keys.instance_slug`, `webhook_configs.instance_id`).

Consequência: se o WhatsApp banir um número, o consumidor externo (ex.: o **TikBot**)
precisaria trocar a configuração de key/URL para apontar pro número novo.

Objetivo: poder cadastrar N telefones por **projeto** (ex.: `tikbot` → tik1, tik2, tik3),
definir qual é o **ativo**, e fazer failover quando o ativo cair — de forma **transparente**
pro consumidor.

## 2. Decisões tomadas

| # | Decisão | Escolha |
|---|---|---|
| 1 | Endereçamento do envio | **Por projeto** (failover transparente). Mantém envio por instância como opção. |
| 2 | Failover quando o ativo é banido (`LOGGED_OUT`) | **Automático + aviso** (promove sozinho e notifica). |
| 3 | Um telefone pode estar em vários projetos? | **Não — exclusivo** de um projeto. |
| 4 | Instâncias existentes (`piloto`) | **Opt-in.** Instância sem `project_id` funciona como hoje; nenhuma migração forçada. |

Confirmado que `acca-homolog` / `n8n-marketing` eram apenas exemplos de doc — não há legado real
além da instância `piloto` (seed) e de instâncias criadas manualmente.

## 3. Modelo de dados

### 3.1 Nova tabela `projects`
| coluna | tipo | nota |
|---|---|---|
| `id` | PK | |
| `slug` | string(32) unique | ex.: `tikbot` |
| `name` | string | "TikBot" |
| `active_instance_id` | FK → `instances.id`, nullable, `nullOnDelete` | telefone atual do projeto |
| `created_at` / `updated_at` | timestamps | |

### 3.2 Alterações em `instances`
- `project_id` → FK → `projects.id`, nullable, `nullOnDelete` (telefone exclusivo de 1 projeto)
- `priority` → unsigned int, default 0 → ordem de failover dentro do projeto (1=tik1, 2=tik2...)

> `active_instance_id` em `projects` e `project_id` em `instances` criam referência circular.
> Resolver com 2 migrations (cria `projects` sem a FK → adiciona `project_id` em `instances`
> → adiciona a FK `active_instance_id`), ou FKs adicionadas em passo separado.

### 3.3 Relacionamentos (models)
- `Project hasMany Instance` (`instances.project_id`)
- `Project belongsTo activeInstance` (`active_instance_id`)
- `Instance belongsTo Project`

## 4. Envio transparente por projeto

- Nova rota autenticada por API key:
  `POST /api/v1/projects/{project}/send-message`
  → resolve `project.active_instance` → delega pro fluxo de envio atual
  (`WhatsAppController::_doSendMessage`) usando o slug da instância ativa.
- Se `active_instance` for nula ou não estiver `CONNECTED`:
  - tentar failover na hora (promover próximo `CONNECTED` por `priority`);
  - se nenhum disponível → 409 "projeto sem telefone disponível".
- API key ganha escopo de **projeto** (além do escopo por instância já existente):
  - adicionar `project_id` (nullable) em `api_keys`;
  - `ApiKeyAuth` passa a aceitar key escopada a projeto na rota de projeto.
- Rota por instância (`/instances/{slug}/send-message`) permanece **intacta** para forçar
  um número específico.

## 5. Failover automático + aviso

> **Validado no código (2026-06-26).** Caminho confirmado ponta a ponta:
> - Node: `connection.update` → ban (`DisconnectReason.loggedOut`) →
>   `setInstanceState(instance, {status:'LOGGED_OUT'}, 'disconnected')` →
>   `notifyLaravel` POST `/api/whatsapp/webhook` com `event:'disconnected'`, `status:'LOGGED_OUT'`,
>   `instance_id:<slug>` (`whatsapp-service/index.js:236-277`, `:84-94`).
> - Laravel: o evento `disconnected` cai no **bloco catch-all** de `WhatsAppController::webhook`
>   (`app/Http/Controllers/WhatsAppController.php:142-161`), que faz
>   `$instance->fill(['status'=>$data['status'], ...])->save()` na **linha 155**.
>
> **Ponto de enganche do failover: logo após o `->save()` da linha 155.** Não existe handler
> dedicado de conexão — é esse catch-all que persiste o status. O sinal autoritativo é o campo
> `status` virar `LOGGED_OUT` (o nome do evento é `disconnected`, não `connection`).

Fluxo ao receber `LOGGED_OUT` de uma instância:
1. Se a instância **é** a `active_instance` de algum projeto:
2. buscar próxima instância do projeto com `status = CONNECTED`, ordenada por `priority` asc;
3. atualizar `project.active_instance_id` (promove o backup);
4. disparar **aviso**:
   - novo evento de webhook (`failover` / `instance_changed`) para a URL do projeto;
   - log estruturado.
5. Se nenhum backup `CONNECTED` → projeto fica sem ativo + alerta "sem telefone disponível".

Promoção manual reusa o mesmo serviço de promoção (botão "tornar atual" na UI).

## 6. UI / gestão (escopo mínimo)
- CRUD de projetos.
- Associar/desassociar instância a um projeto + editar `priority`.
- Indicador de qual é o telefone ativo + botão "tornar atual".
- Exibir avisos de failover.

## 7. Fases de entrega

**Fase 1 — base testável isoladamente** ✅ CONCLUÍDA (2026-06-27)
- ✅ Migrations: `projects`, `project_id`+`priority` em `instances`, `project_id` em `api_keys`.
- ✅ Models + relacionamentos (`Project`, `Instance::project`, `ApiKey::project`).
- ✅ Serviço `ProjectFailoverService` (`promote` + `failover`) — usado por manual e automático.
- ✅ Rotas `POST /api/v1/whatsapp/projects/{project}/send-message` e `/send-media` + escopo de key por projeto no `ApiKeyAuth`.
- ✅ `ProjectController` (web): CRUD, attach/detach de instância, promote. `ApiKeyController` cria keys de projeto.
- ✅ Testes: 12 novos (envio resolve a ativa, failover na hora do envio, escopo de key A↔B, 409 sem ativo, promoção, serviço). Suite Feature: 54/54.

**UI Vue** ✅ código-completo (build pendente de ambiente)
- ✅ `ProjectsScreen.vue` (criar/excluir projeto, atribuir/remover telefone com prioridade, "tornar ativo"), view `projects.blade.php`, rota `GET /projetos`, mount no `app.js`, link na tela de Instâncias.
- ⚠️ `npm run build` não roda no host: `node_modules` tem binário do rolldown `linux-arm64` (instalado no Docker), host é darwin. Buildar dentro do container ou `npm install` no host. Ver [[ambientes-divergentes]].

**Fase 2 — automação** ✅ CONCLUÍDA (2026-06-27)
- ✅ Failover enganchado após o `->save()` do webhook (status `LOGGED_OUT` + instância é a ativa do projeto).
- ✅ Aviso `failover` (webhook do projeto via `projects.failover_webhook_url` + HMAC opcional + log). Cobre promoção (from→to) e "sem backup" (to=null).
- ✅ Failover também na hora do envio (rede de segurança, reason `send_time`).
- ✅ Testes: 4 novos (ativo cai→promove+avisa; backup cai→no-op; sem backup→alerta; instância avulsa→no-op). Suite Feature: 58/58.

## 8. Riscos / pontos de atenção
- **Referência circular** de FK entre `projects` e `instances` (ver 3.2) — ordenar migrations.
- **Concorrência**: duas promoções simultâneas → usar transação/lock ao atualizar `active_instance_id`.
- **Compatibilidade**: garantir que instância sem `project_id` continua 100% como hoje.
- ~~**whatsapp-service**: confirmar onde o status `LOGGED_OUT` chega no Laravel.~~
  **Resolvido** (ver seção 5): enganche após o `->save()` em `WhatsAppController.php:155`, evento `disconnected`.
- **Reuso de número entre projetos** fica explicitamente fora de escopo (decisão 3).

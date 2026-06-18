# Auditoria do servidor de homolog

Histórico de duas verificações: o servidor mudou entre uma auditoria e outra
(parece que migramos de `gorillasdev` para `GTSystem` após o chefe expandir
recursos / mover o projeto). Esse documento mantém o "antes vs depois" pra
referência.

## TL;DR (estado atual em 18/06/2026)

✅ **Disco resolvido** — 88 GB livres (vs 533 MB críticos antes)
✅ **CPU dobrada** — 16 cores
✅ **Sem conflito de porta** com o WP-GORILA
⚠️ **Servidor compartilhado** com outro Laradock (projeto "gtsystem", user `gattai`)
⚠️ **Sem Redis** no homolog ainda — precisamos subir o nosso

## Capacidade (homolog atual: `GTSystem`)

| Recurso | Total | Em uso | WP-GORILA precisa | Sobra após deploy |
|---|---|---|---|---|
| RAM | 15 GiB | 1.8 GiB (12%) | ~1.5 GiB (6 sessões) | ~11.6 GiB |
| CPU | 16 cores | load 0.68 | <1 core | 15+ cores |
| **Disco** | **195 GB** | **98 GB (53%)** | **~3 GB** | **~85 GB** |

## Comparação com auditoria anterior (`gorillasdev`, 17/06/2026)

| Recurso | gorillasdev (17/06) | GTSystem (18/06) | Mudança |
|---|---|---|---|
| CPU | 8 cores | 16 cores | dobrou |
| RAM total | 15 GiB | 15 GiB | igual |
| Disco total | 15 GB | 195 GB | **+1200%** |
| Disco livre | 533 MB (97% cheio) | 88 GB (53% usado) | desbloqueado |
| Distro | Debian 12 | (não coletado, provável Debian) | — |

## Inventário Docker atual no homolog

### Containers rodando
| Container | RAM | Porta no host |
|---|---|---|
| laradock-nginx-1 | 7 MiB | 80, 81, 443 |
| laradock-php-fpm-1 | 56 MiB | — |
| laradock-php-worker-1 | 392 MiB | — (rodando `queue:work` do projeto `gtsystem`) |
| laradock-workspace-1 | 14 MiB | 22→2222, 3000, 3001, 4200, 5173, 8001, 8080 |
| laradock-postgres-1 | 61 MiB | 127.0.0.1:5432 |
| laradock-docker-in-docker-1 | 64 MiB | — |

**Total Docker (RAM):** ~600 MiB

### Disco
- Imagens: 19.24 GB (45 imagens; **6.52 GB recuperáveis** como dangling)
- Containers: 257 MB
- Volumes: 4 MB
- Build cache: 7.77 GB (**1.42 GB recuperáveis**)
- **Total Docker:** ~27 GB
- **Liberação fácil possível:** ~8 GB sem prejuízo

### Portas em uso no host
```
21 (ftp), 22 (ssh), 80/81/443 (nginx), 2222 (workspace ssh),
3000, 3001, 4200, 5173 (workspace front), 5432 (postgres local),
8001, 8080 (workspace), 10050 (zabbix agent)
```

## Conflito com portas do WP-GORILA: **NENHUM**

| Porta planejada WP-GORILA | Status |
|---|---|
| 8088 (Nginx) | ✅ livre |
| 8181 (Varnish backend) | ✅ livre |
| 8448 (HTTPS) | ✅ livre |
| 5433 (Postgres) | ✅ livre |
| 3020 (whatsapp-service) | ✅ livre |
| 6379 (Redis) | ✅ livre |
| 2232 (workspace SSH) | ✅ livre |
| 3010, 3011, 4210, 5183, 8011, 8089 | ✅ todas livres |

## Decisões para o deploy

### 1. Laradock dedicado (não reusar o existente)
O Laradock do `gtsystem` já está rodando e tem worker do projeto deles ativo.
**Não vamos reaproveitar** — risco de impacto cruzado.

Vamos clonar nosso próprio Laradock em `/home/gattai/wp-gorila/laradock/`
(ou pasta equivalente que tenha permissão), com `COMPOSE_PROJECT_NAME=whatsapp_piloto`
pra isolar redes e nomes de container.

### 2. Subir nossos containers próprios
- `whatsapp-service` (Node + Baileys) — porta interna 3000, host 3020
- `whatsapp-worker` (queue:work do Laravel) — sem porta
- `whatsapp_piloto-postgres-1` — porta host 5433 (Postgres separado do gtsystem)
- `whatsapp_piloto-redis-1` — porta host 6379 (não existe Redis no homolog)
- `whatsapp_piloto-nginx-1` — porta host 8088
- `whatsapp_piloto-php-fpm-1` e `workspace-1` — sem conflito (project name diferente)

### 3. Storage dos `auth_info/{slug}/`
Bind mount em `/home/gattai/wp-gorila/whatsapp-service/auth_info/`.
Backup diário esse caminho (task Apoio do Clickup).

### 4. Cleanup opcional antes do deploy
```bash
docker builder prune -a -f    # ~1.4 GB
docker image prune -a -f      # ~6.5 GB
```

## Passo a passo de deploy (resumido)

1. SSH em `gattai@GTSystem`
2. `cd ~ && git clone git@github.com:rotteny/WP-GORILA.git wp-gorila && cd wp-gorila`
3. `./setup.sh` (clona Laradock + ajusta .env + anexa snippet)
4. `cd laradock && docker compose up -d nginx postgres redis whatsapp-service whatsapp-worker`
5. Entrar no workspace e rodar: composer install, migrate, seeder, npm install, npm run build
6. Validar via curl: `http://localhost:8088/api/v1/instances` (com X-API-Key)
7. Configurar nginx do host (laradock-nginx-1 do gtsystem) ou Caddy pra rotear domínio externo
8. Backup automatizado de `auth_info/`

## Conversa com infra — o que ainda precisamos

- [x] Espaço em disco (FEITO — chefe expandiu pra 195 GB)
- [ ] Confirmar permissão do user `gattai` pra rodar Docker em pasta dedicada
- [ ] Domínio externo (https://wp-gorila.gorila.tech ou subdomínio?)
- [ ] Política de backup atual cobre `/home/gattai/wp-gorila/`?
- [ ] Coordenar deploy/restart com responsável do gtsystem (não derrubar produção)

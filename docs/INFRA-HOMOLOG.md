# Auditoria do servidor de homolog `gorillasdev` (45.178.177.3)

Data: 2026-06-17
Objetivo: validar se o servidor suporta o piloto WP-GORILA (5-6 sessões WhatsApp simultâneas).

## TL;DR

✅ **CPU e RAM com folga grande** — servidor está ocioso, sobra recurso.
❌ **Disco crítico (97% cheio)** — bloqueador antes do deploy.
✅ **Já tem Laradock + Redis + Postgres rodando** — dá pra reusar em vez de subir cópias.

## Capacidade

| Recurso | Capacidade total | Em uso hoje | WP-GORILA precisa | Sobra |
|---|---|---|---|---|
| RAM | 15 GiB | 1.9 GiB (13%) | ~1.5 GiB (6 sessões) | 11.6 GiB |
| CPU | 8 cores | load 0.02 | <1 core | 7+ cores |
| **Disco** | **15 GB** | **13 GB (97%)** | **~3 GB** | **0.5 GB** |

## Inventário Docker atual

| Container | RAM em uso | Porta exposta |
|---|---|---|
| laradock-postgres-postgis-1 | 285 MiB | 5432 |
| laradock-nginx-1 | 15 MiB | 80, 443, 81 |
| laradock-redis-1 | 12 MiB | 6379 |
| laradock-php-fpm-1 | 169 MiB | — |
| laradock-php-worker-1 | 266 MiB | — |
| laradock-workspace-1 | 707 MiB | 22→2222, 3000, 3001, 4200, 5173, 8001, 8080 |
| laradock-docker-in-docker-1 | 47 MiB | — |
| laradock-mailpit-1 | 18 MiB | 1025, 8025 |

Imagens: 9.2 GB. Build cache: 1.2 GB (~1 GB recuperável).

## Decisões propostas

### 1. Reutilizar Laradock existente
Em vez de clonar outro Laradock isolado, plugar o WP-GORILA no Laradock que já roda. Adicionar apenas:
- container `whatsapp-service` (Node + Baileys) — ~400 MB de RAM, ~400 MB de imagem
- migration nova no Postgres existente (cria tabela `instances`)

Economia: ~5 GB de imagens Docker que NÃO precisam ser duplicadas.

### 2. Reusar Redis e Postgres existentes
- Redis: pro Chunk 4 (queue) e Chunk 5 (rate limit)
- Postgres: criar database `whatsapp_piloto` separado dentro do mesmo container

Risco compartilhado: se algum desenvolvedor mexe na infra do Laradock, afeta WP-GORILA também. Mitigação: nomear bem a database e configurar permissões por usuário.

### 3. Domínio + Reverse proxy
Configurar Nginx do Laradock pra rotear:
```
wp-gorila.gorila.local   →  proxy_pass http://workspace-laravel-do-wp:80
```

## Pré-requisitos pra deploy (em ordem)

### 🔴 Crítico (bloqueia deploy)
1. **Liberar 5+ GB de disco** no root (`/`). Opções:
   - `docker builder prune -a -f` (~1 GB)
   - `docker image prune -a -f` (~1-3 GB)
   - Configurar log rotation no daemon Docker
   - Expandir LVM `template12--vg-root` se as limpezas não bastarem

### 🟡 Importante (antes de produção)
2. Validar quais ports do Laradock conflitam com WP-GORILA:
   - WP-GORILA quer: 8088, 8181, 8448, 2232, 3010, 3011, 3020, 4210, 5183, 5433, 8011, 8089
   - Laradock atual já usa: 80, 81, 443, 1025, 2222, 3000, 3001, 4200, 5173, 5432, 6379, 8001, 8025, 8080
   - **Conflitos previstos:** nenhum (as portas que escolhemos pro WP-GORILA já estão fora dessa lista)
3. Criar volume/diretório dedicado pro `auth_info/` do WP-GORILA — credenciais Baileys precisam persistir
4. Plano de backup das `auth_info/{slug}/` (sem isso, perder o servidor = todos os WhatsApp deslogam)

### 🟢 Bom ter
5. Monitoring: Prometheus/Grafana ou métricas básicas via `docker stats`
6. Alerta quando disco passar de 80% de uso
7. Documentar quem é responsável pelo Laradock compartilhado (ev. troca de senha, restart, etc.)

## Conversa com infra — agenda sugerida

1. **Expansão de disco?** Estamos com 13 GB de 15 GB usados. Aumentar pra 50 GB cobre 1-2 anos de WP-GORILA com folga.
2. **Existe outro servidor de homolog disponível?** Caso seja arriscado mexer no `gorillasdev`.
3. **Política de backup atual** — saber se vai cobrir nossa pasta `auth_info`.
4. **Quem mais usa esse Laradock?** Para coordenar deploys e evitar conflitos.
5. **Acesso ao Docker daemon?** Confirmar que o `gorillas` (ou usuário do deploy) tem permissão sudo no docker.

## Comandos úteis pro pessoal de infra

```bash
# Espaço total do servidor
df -h /

# Quanto o Docker tá consumindo
docker system df

# Maiores logs de container
du -sh /var/lib/docker/containers/*/*-json.log 2>/dev/null | sort -h | tail -10

# Quem tá usando RAM
ps aux --sort=-%mem | head -10
```

# wp-gorila-agent

Daemon Python que roda no PC dedicado da empresa, consome tarefas de repair do wp-gorila via HTTP polling e executa a sequência ADB no celular cockpit pra reparear sessões WhatsApp que caíram.

Ver documento completo: `../docs/repair-agent-python-plan.md`

**Celular alvo:** Samsung Galaxy A70 (1080×2340, One UI). Coordenadas em `coordinates/samsung_galaxy_a70.yaml` — calibrar com `scripts/calibrate.py` antes de tirar do `dry_run`.

## Setup rápido

```bash
python3.11 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

cp config.example.yaml config.yaml
cp .env.example .env
# Editar config.yaml e .env com valores reais.

python agent.py
```

## Testes

```bash
pytest tests/
```

## Serviço systemd

```bash
sudo cp systemd/wp-gorila-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now wp-gorila-agent
```

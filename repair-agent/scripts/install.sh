#!/usr/bin/env bash
# Setup automatizado do wp-gorila-agent no PC dedicado.
# Target: Ubuntu Server 22.04 LTS (mais tolerante a Celeron antigo que 24.04).
# Python 3.11 vem via deadsnakes PPA — o default do 22.04 e 3.10.
set -euo pipefail

sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:deadsnakes/ppa
sudo apt update
sudo apt install -y \
    python3.11 python3.11-venv python3.11-dev python3-pip \
    android-tools-adb \
    tesseract-ocr tesseract-ocr-por \
    git curl

python3.11 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install -r requirements.txt

if [ ! -f config.yaml ]; then
  cp config.example.yaml config.yaml
  echo "config.yaml criado a partir do exemplo — edite antes de rodar."
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo ".env criado a partir do exemplo — preencha os secrets."
fi

echo "Setup OK. Rode:  source .venv/bin/activate && python agent.py"

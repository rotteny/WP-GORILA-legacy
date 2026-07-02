"""Fixtures compartilhadas."""
from __future__ import annotations

from pathlib import Path

import pytest

from src.models import (
    Account,
    AgentConfig,
    AppConfig,
    BehaviorConfig,
    DeviceConfig,
    OcrConfig,
    ServerConfig,
)


@pytest.fixture
def sample_config(tmp_path: Path) -> AgentConfig:
    return AgentConfig(
        hostname="cockpit-test",
        server=ServerConfig(
            base_url="https://example.test",
            api_key="k",
            hmac_secret="s",
        ),
        behavior=BehaviorConfig(),
        devices=[
            DeviceConfig(
                id="device-A",
                model="xiaomi_redmi_note_12",
                apps={
                    "whatsapp": AppConfig(
                        package="com.whatsapp",
                        accounts=[
                            Account(slug="acca-tik1", phone="+5511999991111", slot=1),
                            Account(slug="acca-tik2", phone="+5511999992222", slot=2),
                        ],
                    ),
                    "whatsapp_business": AppConfig(
                        package="com.whatsapp.w4b",
                        accounts=[
                            Account(slug="acca-tik3", phone="+5511999993333", slot=1),
                        ],
                    ),
                },
            )
        ],
        log_dir=tmp_path / "logs",
        screenshot_dir=tmp_path / "shots",
        ocr=OcrConfig(),
    )

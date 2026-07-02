"""Pydantic models — config + payloads HTTP."""
from __future__ import annotations

import os
from pathlib import Path
from typing import Literal

import yaml
from pydantic import BaseModel, Field, HttpUrl


class Account(BaseModel):
    slug: str
    phone: str
    slot: int = Field(ge=1, le=2)


class AppConfig(BaseModel):
    package: str
    accounts: list[Account]


class DeviceConfig(BaseModel):
    id: str
    model: str
    apps: dict[str, AppConfig]


class ServerConfig(BaseModel):
    base_url: HttpUrl
    api_key: str
    hmac_secret: str
    request_timeout_seconds: int = 30
    poll_interval_seconds: int = 5
    heartbeat_interval_seconds: int = 30


class BehaviorConfig(BaseModel):
    dry_run: bool = False
    max_concurrent_repairs: int = 3
    task_timeout_seconds: int = 90
    circuit_breaker_failures: int = 3
    circuit_breaker_pause_minutes: int = 30


class OcrConfig(BaseModel):
    enabled: bool = False
    tesseract_lang: str = "por"


class AgentConfig(BaseModel):
    hostname: str
    server: ServerConfig
    behavior: BehaviorConfig
    devices: list[DeviceConfig]
    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"
    log_dir: Path
    screenshot_dir: Path
    screenshot_retention_days: int = 30
    log_retention_days: int = 90
    ocr: OcrConfig = Field(default_factory=OcrConfig)

    @classmethod
    def load(cls, path: Path) -> "AgentConfig":
        text = path.read_text()
        expanded = os.path.expandvars(text)
        data = yaml.safe_load(expanded)
        return cls(**data)


class RepairTask(BaseModel):
    id: int
    instance_slug: str
    pairing_code: str

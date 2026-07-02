from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock, patch

import pytest
import yaml

from src.executor import RepairExecutor
from src.models import RepairTask


@pytest.fixture
def coords_file(tmp_path, monkeypatch):
    coords_dir = tmp_path / "coordinates"
    coords_dir.mkdir()
    (coords_dir / "xiaomi_redmi_note_12.yaml").write_text(
        yaml.safe_dump({
            "menu_button":     {"x": 100, "y": 100},
            "switch_account":  {"x": 100, "y": 200},
            "account_slot_1":  {"x": 100, "y": 300},
            "account_slot_2":  {"x": 100, "y": 400},
            "linked_devices":  {"x": 100, "y": 500},
            "link_device_btn": {"x": 100, "y": 600},
            "link_via_number": {"x": 100, "y": 700},
        })
    )
    monkeypatch.chdir(tmp_path)
    return coords_dir


async def test_dry_run_returns_success(sample_config):
    sample_config.behavior.dry_run = True
    executor = RepairExecutor(sample_config)
    task = RepairTask(id=1, instance_slug="acca-tik1", pairing_code="1234-5678")

    result = await executor.execute(task)

    assert result["status"] == "success"
    assert result["dry_run"] is True


async def test_execute_success_flow(sample_config, coords_file, monkeypatch):
    fake_adb = AsyncMock()
    fake_adb.is_connected = AsyncMock(return_value=True)

    # zerar os sleeps pra teste rodar rapido
    monkeypatch.setattr("src.executor.asyncio.sleep", AsyncMock())

    with patch("src.executor.AdbClient", return_value=fake_adb):
        executor = RepairExecutor(sample_config)
        task = RepairTask(id=42, instance_slug="acca-tik2", pairing_code="1234-5678")
        result = await executor.execute(task)

    assert result["status"] == "success"
    # slot=2, entao devia trocar de conta
    assert fake_adb.tap.await_count >= 6
    fake_adb.type_text.assert_awaited_with("12345678")


async def test_execute_reports_failure_on_disconnected_device(
    sample_config, coords_file, monkeypatch
):
    fake_adb = AsyncMock()
    fake_adb.is_connected = AsyncMock(return_value=False)
    monkeypatch.setattr("src.executor.asyncio.sleep", AsyncMock())

    with patch("src.executor.AdbClient", return_value=fake_adb):
        executor = RepairExecutor(sample_config)
        task = RepairTask(id=7, instance_slug="acca-tik1", pairing_code="1234-5678")
        result = await executor.execute(task)

    assert result["status"] == "failed"
    assert "not booted" in result["error"].lower()

from __future__ import annotations

from pathlib import Path
from unittest.mock import AsyncMock, call, patch

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
            "settings_menu":   {"x": 100, "y": 150},
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


async def test_kill_app_is_called_before_launch_app(
    sample_config, coords_file, monkeypatch
):
    """Bug 1: garantir force-stop antes de trazer a activity, senao o app pode
    abrir numa tela intermediaria e todos os taps saem desalinhados."""
    fake_adb = AsyncMock()
    fake_adb.is_connected = AsyncMock(return_value=True)
    monkeypatch.setattr("src.executor.asyncio.sleep", AsyncMock())

    call_order: list[str] = []
    fake_adb.kill_app.side_effect = lambda *a, **kw: call_order.append("kill_app")
    fake_adb.launch_app.side_effect = lambda *a, **kw: call_order.append("launch_app")

    with patch("src.executor.AdbClient", return_value=fake_adb):
        executor = RepairExecutor(sample_config)
        task = RepairTask(id=101, instance_slug="acca-tik1", pairing_code="1111-2222")
        result = await executor.execute(task)

    assert result["status"] == "success"
    fake_adb.kill_app.assert_awaited_once_with("com.whatsapp")
    fake_adb.launch_app.assert_awaited_once_with("com.whatsapp")
    # ordem importa: kill precede launch
    assert call_order.index("kill_app") < call_order.index("launch_app")


async def test_slot_switch_taps_menu_then_settings_then_account(
    sample_config, coords_file, monkeypatch
):
    """Bug 2 (v2.26+): sequencia multi-conta passa por Configuracoes antes de
    tocar no nome do usuario que abre o bottom sheet."""
    fake_adb = AsyncMock()
    fake_adb.is_connected = AsyncMock(return_value=True)
    monkeypatch.setattr("src.executor.asyncio.sleep", AsyncMock())

    with patch("src.executor.AdbClient", return_value=fake_adb):
        executor = RepairExecutor(sample_config)
        # acca-tik2 esta em slot=2 na fixture -> exercita o ramo multi-conta
        task = RepairTask(id=202, instance_slug="acca-tik2", pairing_code="9999-8888")
        result = await executor.execute(task)

    assert result["status"] == "success"

    tap_calls = fake_adb.tap.await_args_list
    # os 4 primeiros taps devem ser: menu -> settings -> switch_account -> slot_2
    assert tap_calls[0] == call(x=100, y=100)  # menu_button
    assert tap_calls[1] == call(x=100, y=150)  # settings_menu (novo passo)
    assert tap_calls[2] == call(x=100, y=200)  # switch_account (nome do usuario)
    assert tap_calls[3] == call(x=100, y=400)  # account_slot_2

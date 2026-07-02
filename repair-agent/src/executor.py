"""Orquestrador da sequencia de repair — coracao do agent."""
from __future__ import annotations

import asyncio
from pathlib import Path

import structlog
import yaml

from src.adb import AdbClient
from src.audit import save_screenshot
from src.constants import (
    DELAY_AFTER_HOME,
    DELAY_AFTER_TYPE,
    DELAY_APP_LAUNCH,
    DELAY_LINK_BTN,
    DELAY_LINK_VIA_NUMBER,
    DELAY_LINKED_DEVICES,
    DELAY_MENU_OPEN,
    DELAY_SWITCH_ACCOUNT,
    DELAY_WAIT_CONNECTION,
)
from src.models import AgentConfig, RepairTask
from src.router import ResolvedAccount, Router
from src.validator import Validator

logger = structlog.get_logger()


class RepairExecutor:
    def __init__(self, config: AgentConfig):
        self.config = config
        self.router = Router(config)
        self.validator = Validator(config)

    async def execute(self, task: RepairTask) -> dict:
        resolved = self.router.resolve(task.instance_slug)
        adb = AdbClient(resolved.device.id)

        if self.config.behavior.dry_run:
            logger.info("executor.dry_run", task_id=task.id)
            return {"status": "success", "dry_run": True}

        coords_path = Path("coordinates") / f"{resolved.device.model}.yaml"
        coords = yaml.safe_load(coords_path.read_text())

        try:
            await self._pre_flight(adb)
            await self._open_correct_account(adb, resolved, coords)
            await self._navigate_to_linked_devices(adb, coords)
            await self._enter_pairing_code(adb, task.pairing_code)
            await self._wait_for_connection()
            screenshot = await self._final_screenshot(adb, task)
            return {"status": "success", "screenshot": screenshot.name}
        except Exception as e:
            logger.exception("executor.failed", task_id=task.id)
            screenshot = await self._final_screenshot(adb, task, suffix="failure")
            return {
                "status": "failed",
                "error": str(e),
                "screenshot": screenshot.name,
            }

    async def _pre_flight(self, adb: AdbClient) -> None:
        if not await adb.is_connected():
            raise RuntimeError("Device not booted")
        await adb.unlock_screen()
        await adb.key("HOME")
        await asyncio.sleep(DELAY_AFTER_HOME)

    async def _open_correct_account(
        self, adb: AdbClient, resolved: ResolvedAccount, coords: dict
    ) -> None:
        await adb.launch_app(resolved.package)
        await asyncio.sleep(DELAY_APP_LAUNCH)

        if resolved.account.slot > 1:
            await adb.tap(**coords["menu_button"])
            await asyncio.sleep(DELAY_MENU_OPEN)
            await adb.tap(**coords["switch_account"])
            await asyncio.sleep(DELAY_MENU_OPEN)
            await adb.tap(**coords[f"account_slot_{resolved.account.slot}"])
            await asyncio.sleep(DELAY_SWITCH_ACCOUNT)

    async def _navigate_to_linked_devices(self, adb: AdbClient, coords: dict) -> None:
        await adb.tap(**coords["menu_button"])
        await asyncio.sleep(DELAY_MENU_OPEN)
        await adb.tap(**coords["linked_devices"])
        await asyncio.sleep(DELAY_LINKED_DEVICES)
        await adb.tap(**coords["link_device_btn"])
        await asyncio.sleep(DELAY_LINK_BTN)
        await adb.tap(**coords["link_via_number"])
        await asyncio.sleep(DELAY_LINK_VIA_NUMBER)

    async def _enter_pairing_code(self, adb: AdbClient, code: str) -> None:
        clean = code.replace("-", "")
        await adb.type_text(clean)
        await asyncio.sleep(DELAY_AFTER_TYPE)

    async def _wait_for_connection(self) -> None:
        # Idealmente valida por OCR na Fase 4. Por enquanto, espera fixa.
        await asyncio.sleep(DELAY_WAIT_CONNECTION)

    async def _final_screenshot(
        self, adb: AdbClient, task: RepairTask, suffix: str = "success"
    ) -> Path:
        self.config.screenshot_dir.mkdir(parents=True, exist_ok=True)
        path = self.config.screenshot_dir / f"task-{task.id}-{suffix}.png"
        await adb.screencap(path)
        save_screenshot(task.id, path)
        return path

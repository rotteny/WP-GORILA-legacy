"""Wrapper subprocess pro adb. Toda chamada shell passa por aqui."""
from __future__ import annotations

import asyncio
import shlex
from pathlib import Path

import structlog

from src.constants import ADB_DEFAULT_TIMEOUT
from src.errors import AdbError

logger = structlog.get_logger()


class AdbClient:
    def __init__(self, device_id: str):
        self.device_id = device_id

    async def _run(self, args: list[str], timeout: float = ADB_DEFAULT_TIMEOUT) -> str:
        cmd = ["adb", "-s", self.device_id] + args
        logger.debug("adb.exec", cmd=" ".join(shlex.quote(c) for c in cmd))
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        try:
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout)
        except asyncio.TimeoutError:
            proc.kill()
            raise AdbError(f"Timeout running {cmd}")

        if proc.returncode != 0:
            raise AdbError(f"adb failed: {stderr.decode().strip()}")

        return stdout.decode().strip()

    async def tap(self, x: int, y: int) -> None:
        await self._run(["shell", "input", "tap", str(x), str(y)])

    async def swipe(self, x1: int, y1: int, x2: int, y2: int, duration_ms: int = 300) -> None:
        await self._run([
            "shell", "input", "swipe",
            str(x1), str(y1), str(x2), str(y2), str(duration_ms),
        ])

    async def type_text(self, text: str) -> None:
        # `input text` nao aceita espacos literais — usa %s.
        safe = text.replace(" ", "%s")
        await self._run(["shell", "input", "text", safe])

    async def key(self, keyname: str) -> None:
        """Envia keyevent. Ex: 'HOME', 'BACK', 'MENU'."""
        await self._run(["shell", "input", "keyevent", f"KEYCODE_{keyname}"])

    async def launch_app(self, package: str) -> None:
        await self._run([
            "shell", "monkey",
            "-p", package,
            "-c", "android.intent.category.LAUNCHER",
            "1",
        ])

    async def kill_app(self, package: str) -> None:
        await self._run(["shell", "am", "force-stop", package])

    async def screencap(self, path: Path) -> None:
        remote = f"/sdcard/wpg-{self.device_id}.png"
        await self._run(["shell", "screencap", "-p", remote])
        await self._run(["pull", remote, str(path)])
        await self._run(["shell", "rm", remote])

    async def unlock_screen(self) -> None:
        await self.key("WAKEUP")
        await asyncio.sleep(0.5)
        await self.key("MENU")

    async def is_connected(self) -> bool:
        try:
            out = await self._run(["shell", "getprop", "sys.boot_completed"])
            return out.strip() == "1"
        except AdbError:
            return False


def list_devices() -> list[str]:
    """Sync version pra contextos nao-async (ex: heartbeat)."""
    import subprocess
    try:
        result = subprocess.run(
            ["adb", "devices"],
            capture_output=True, text=True, timeout=5,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return []
    lines = result.stdout.strip().split("\n")[1:]  # skip header
    return [line.split()[0] for line in lines if "\tdevice" in line]

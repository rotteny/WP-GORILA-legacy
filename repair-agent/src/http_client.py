"""Cliente httpx autenticado + retry + verificacao HMAC."""
from __future__ import annotations

from typing import Optional

import httpx
import structlog
from tenacity import (
    AsyncRetrying,
    retry_if_exception_type,
    stop_after_attempt,
    wait_exponential,
)

from src.errors import InvalidSignature
from src.hmac_verifier import verify_hmac
from src.models import AgentConfig, RepairTask

logger = structlog.get_logger()


class AgentHttpClient:
    def __init__(self, config: AgentConfig):
        self.config = config
        self._client = httpx.AsyncClient(
            base_url=str(config.server.base_url),
            timeout=config.server.request_timeout_seconds,
            headers={
                "Authorization": f"Bearer {config.server.api_key}",
                "User-Agent": f"wp-gorila-agent/0.1 ({config.hostname})",
                "Accept": "application/json",
            },
        )

    async def close(self) -> None:
        await self._client.aclose()

    async def fetch_task(self) -> Optional[RepairTask]:
        resp: Optional[httpx.Response] = None
        async for attempt in AsyncRetrying(
            stop=stop_after_attempt(3),
            wait=wait_exponential(multiplier=0.5, max=5),
            retry=retry_if_exception_type(httpx.HTTPError),
            reraise=True,
        ):
            with attempt:
                resp = await self._client.get("/api/whatsapp/agent/tasks")

        assert resp is not None
        resp.raise_for_status()
        if not resp.content:
            return None

        payload = resp.text
        signature = resp.headers.get("X-Wpg-Signature")

        if not verify_hmac(payload, signature, self.config.server.hmac_secret):
            logger.error("task.invalid_signature", signature=signature)
            raise InvalidSignature("Invalid HMAC signature on task payload")

        data = resp.json()
        return RepairTask(**data)

    async def report_result(self, task_id: int, result: dict) -> None:
        async for attempt in AsyncRetrying(
            stop=stop_after_attempt(5),
            wait=wait_exponential(multiplier=1, max=30),
            retry=retry_if_exception_type(httpx.HTTPError),
            reraise=True,
        ):
            with attempt:
                resp = await self._client.post(
                    "/api/whatsapp/agent/result",
                    json={"task_id": task_id, **result},
                )
                resp.raise_for_status()

    async def send_heartbeat(self) -> None:
        resp = await self._client.post(
            "/api/whatsapp/agent/heartbeat",
            json={
                "hostname": self.config.hostname,
                "devices_online": self._devices_online(),
            },
        )
        resp.raise_for_status()

    def _devices_online(self) -> list[str]:
        from src.adb import list_devices

        return list_devices()

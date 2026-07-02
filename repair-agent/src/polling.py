"""Loop principal — polling, dispatch de tarefas, heartbeat."""
from __future__ import annotations

import asyncio

import structlog

from src.audit import audit_task
from src.executor import RepairExecutor
from src.http_client import AgentHttpClient
from src.models import AgentConfig, RepairTask

logger = structlog.get_logger()


class PollingLoop:
    def __init__(self, config: AgentConfig):
        self.config = config
        self.client = AgentHttpClient(config)
        self.executor = RepairExecutor(config)
        self._semaphore = asyncio.Semaphore(config.behavior.max_concurrent_repairs)

    async def run(self, stop_event: asyncio.Event) -> None:
        heartbeat_task = asyncio.create_task(self._heartbeat_loop(stop_event))
        poll_task = asyncio.create_task(self._poll_loop(stop_event))

        await asyncio.gather(heartbeat_task, poll_task)
        await self.client.close()

    async def _heartbeat_loop(self, stop_event: asyncio.Event) -> None:
        while not stop_event.is_set():
            try:
                await self.client.send_heartbeat()
                logger.debug("heartbeat.sent")
            except Exception as e:
                logger.warning("heartbeat.failed", error=str(e))

            try:
                await asyncio.wait_for(
                    stop_event.wait(),
                    timeout=self.config.server.heartbeat_interval_seconds,
                )
            except asyncio.TimeoutError:
                pass

    async def _poll_loop(self, stop_event: asyncio.Event) -> None:
        while not stop_event.is_set():
            try:
                task = await self.client.fetch_task()
                if task:
                    asyncio.create_task(self._handle_task(task))
            except Exception as e:
                logger.warning("poll.failed", error=str(e))

            try:
                await asyncio.wait_for(
                    stop_event.wait(),
                    timeout=self.config.server.poll_interval_seconds,
                )
            except asyncio.TimeoutError:
                pass

    async def _handle_task(self, task: RepairTask) -> None:
        async with self._semaphore:
            logger.info("task.started", task_id=task.id, instance=task.instance_slug)
            try:
                result = await asyncio.wait_for(
                    self.executor.execute(task),
                    timeout=self.config.behavior.task_timeout_seconds,
                )
            except asyncio.TimeoutError:
                logger.error("task.timeout", task_id=task.id)
                result = {"status": "failed", "error": "timeout"}
            except Exception as e:
                logger.exception("task.crashed", task_id=task.id)
                result = {"status": "failed", "error": str(e)}

            try:
                await self.client.report_result(task.id, result)
            except Exception:
                logger.exception("task.report_failed", task_id=task.id)

            audit_task(task, result)

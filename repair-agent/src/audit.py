"""Setup do structlog + persistencia de screenshots + audit log."""
from __future__ import annotations

import logging
from datetime import datetime, timezone
from pathlib import Path

import structlog


def setup_logging(level: str, log_dir: Path) -> None:
    log_dir = Path(log_dir)
    log_dir.mkdir(parents=True, exist_ok=True)

    log_file = log_dir / f"agent-{datetime.now(timezone.utc):%Y%m%d}.jsonl"

    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.processors.StackInfoRenderer(),
            structlog.processors.format_exc_info,
            structlog.processors.JSONRenderer(),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(_level_to_int(level)),
        logger_factory=structlog.WriteLoggerFactory(file=log_file.open("a")),
    )


def _level_to_int(level: str) -> int:
    return getattr(logging, level.upper())


def audit_task(task, result: dict) -> None:
    logger = structlog.get_logger()
    logger.info(
        "task.completed",
        task_id=task.id,
        instance=task.instance_slug,
        status=result.get("status"),
        error=result.get("error"),
    )


def save_screenshot(task_id: int, path: Path) -> None:
    if path.exists():
        structlog.get_logger().info(
            "screenshot.saved", task_id=task_id, path=str(path)
        )

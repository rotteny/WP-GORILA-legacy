#!/usr/bin/env python3
"""wp-gorila-agent — daemon de repair automático via ADB."""

import asyncio
import signal
import sys
from pathlib import Path

import structlog

from src.audit import setup_logging
from src.models import AgentConfig
from src.polling import PollingLoop


async def main() -> int:
    config = AgentConfig.load(Path("config.yaml"))
    setup_logging(config.log_level, config.log_dir)

    logger = structlog.get_logger()
    logger.info("agent.starting", hostname=config.hostname, version="0.1.0")

    loop = PollingLoop(config)

    stop = asyncio.Event()

    def _handle_signal(sig, _frame):
        logger.info("agent.stopping", signal=sig)
        stop.set()

    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT, _handle_signal)

    try:
        await loop.run(stop_event=stop)
    except Exception:
        logger.exception("agent.crashed")
        return 1

    logger.info("agent.stopped_cleanly")
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))

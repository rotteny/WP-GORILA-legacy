"""Screenshot + OCR — opcional na Fase 1, obrigatorio na Fase 4."""
from __future__ import annotations

from pathlib import Path

import structlog

from src.models import AgentConfig

logger = structlog.get_logger()


class Validator:
    def __init__(self, config: AgentConfig):
        self.config = config
        self._pytesseract = None
        if config.ocr.enabled:
            import pytesseract  # type: ignore

            self._pytesseract = pytesseract

    def confirm_success(self, screenshot: Path) -> bool:
        """
        Retorna True se a screenshot mostra sinais de sucesso.
        Heuristica: sem OCR, confia no timeout do WhatsApp.
        Com OCR, procura por texto positivo/negativo.
        """
        if not self._pytesseract:
            return True

        from PIL import Image  # type: ignore

        img = Image.open(screenshot)
        text = self._pytesseract.image_to_string(
            img, lang=self.config.ocr.tesseract_lang
        ).lower()

        positive = ["conectado", "aparelhos vinculados", "aparelhos conectados"]
        negative = ["nao foi possivel", "não foi possível", "erro", "expirou", "codigo invalido", "código inválido"]

        if any(neg in text for neg in negative):
            logger.warning("validator.negative_signal", found=text[:200])
            return False

        if any(pos in text for pos in positive):
            logger.info("validator.positive_signal")
            return True

        logger.warning("validator.ambiguous", extract=text[:200])
        return False

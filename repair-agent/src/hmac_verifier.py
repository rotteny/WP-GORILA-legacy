"""Validacao de assinatura HMAC-SHA256 do payload recebido do servidor."""
from __future__ import annotations

import hashlib
import hmac


def verify_hmac(payload: str, signature: str | None, secret: str) -> bool:
    if not signature:
        return False

    if not signature.startswith("sha256="):
        return False
    expected = signature.removeprefix("sha256=")

    computed = hmac.new(
        secret.encode(),
        payload.encode(),
        hashlib.sha256,
    ).hexdigest()

    return hmac.compare_digest(computed, expected)

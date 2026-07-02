import hashlib
import hmac

from src.hmac_verifier import verify_hmac


SECRET = "super-secret"


def _sign(payload: str) -> str:
    digest = hmac.new(SECRET.encode(), payload.encode(), hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def test_valid_signature_passes():
    payload = '{"task_id":1}'
    assert verify_hmac(payload, _sign(payload), SECRET) is True


def test_missing_signature_fails():
    assert verify_hmac("payload", None, SECRET) is False


def test_wrong_prefix_fails():
    assert verify_hmac("payload", "md5=whatever", SECRET) is False


def test_tampered_payload_fails():
    payload = '{"task_id":1}'
    sig = _sign(payload)
    assert verify_hmac('{"task_id":2}', sig, SECRET) is False


def test_wrong_secret_fails():
    payload = '{"task_id":1}'
    sig = _sign(payload)
    assert verify_hmac(payload, sig, "another-secret") is False

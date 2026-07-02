import pytest

from src.errors import AccountNotFound
from src.router import Router


def test_resolves_known_slug(sample_config):
    router = Router(sample_config)
    resolved = router.resolve("acca-tik2")

    assert resolved.device.id == "device-A"
    assert resolved.package == "com.whatsapp"
    assert resolved.account.slot == 2


def test_resolves_business_package(sample_config):
    router = Router(sample_config)
    resolved = router.resolve("acca-tik3")

    assert resolved.package == "com.whatsapp.w4b"


def test_raises_on_unknown_slug(sample_config):
    router = Router(sample_config)
    with pytest.raises(AccountNotFound):
        router.resolve("does-not-exist")

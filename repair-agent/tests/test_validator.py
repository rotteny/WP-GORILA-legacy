from src.validator import Validator


def test_validator_without_ocr_returns_true(sample_config, tmp_path):
    sample_config.ocr.enabled = False
    fake_shot = tmp_path / "fake.png"
    fake_shot.write_bytes(b"")

    v = Validator(sample_config)
    assert v.confirm_success(fake_shot) is True

"""Mapeamento instance_slug -> (device, package, account)."""
from __future__ import annotations

from src.errors import AccountNotFound
from src.models import Account, AgentConfig, DeviceConfig


class ResolvedAccount:
    def __init__(self, device: DeviceConfig, package: str, account: Account):
        self.device = device
        self.package = package
        self.account = account


class Router:
    def __init__(self, config: AgentConfig):
        self._index: dict[str, ResolvedAccount] = {}
        for device in config.devices:
            for _app_name, app in device.apps.items():
                for account in app.accounts:
                    if account.slug in self._index:
                        raise ValueError(f"Duplicate slug in config: {account.slug}")
                    self._index[account.slug] = ResolvedAccount(
                        device=device,
                        package=app.package,
                        account=account,
                    )

    def resolve(self, instance_slug: str) -> ResolvedAccount:
        if instance_slug not in self._index:
            raise AccountNotFound(instance_slug)
        return self._index[instance_slug]

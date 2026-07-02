"""Exceptions customizadas."""


class AgentError(Exception):
    """Base class for agent errors."""


class AdbError(AgentError):
    """Falha em subprocess do adb."""


class AccountNotFound(AgentError):
    """instance_slug nao encontrado em nenhum device."""


class InvalidSignature(AgentError):
    """HMAC invalido no payload recebido do servidor."""

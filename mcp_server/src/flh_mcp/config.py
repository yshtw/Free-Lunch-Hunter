"""Environment configuration. The token is read once and never logged."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from urllib.parse import urlsplit

LOOPBACK = {"localhost", "127.0.0.1", "::1"}


class ConfigError(ValueError):
    pass


@dataclass(frozen=True)
class Settings:
    base_url: str
    token: str = field(repr=False)
    timeout_seconds: float = 10.0

    @classmethod
    def from_env(cls, env: dict[str, str] | None = None) -> Settings:
        env = dict(os.environ if env is None else env)
        base_url = env.get("FLH_BASE_URL", "").strip()
        token = env.get("FLH_API_TOKEN", "").strip()
        if not base_url or not token:
            raise ConfigError("Set FLH_BASE_URL (for example http://localhost:8080) and FLH_API_TOKEN.")
        parts = urlsplit(base_url)
        if parts.scheme not in {"http", "https"} or not parts.hostname:
            raise ConfigError("FLH_BASE_URL must be an http(s) URL.")
        if parts.scheme == "http" and parts.hostname not in LOOPBACK:
            # A bearer token over plaintext HTTP is readable by anyone on the network path.
            raise ConfigError("Refusing to send the API token over plain HTTP to a non-loopback host; use HTTPS.")
        try:
            timeout = float(env.get("FLH_TIMEOUT_SECONDS", "10"))
        except ValueError as error:
            raise ConfigError("FLH_TIMEOUT_SECONDS must be a number.") from error
        if not 1 <= timeout <= 60:
            raise ConfigError("FLH_TIMEOUT_SECONDS must be between 1 and 60.")
        return cls(base_url=base_url, token=token, timeout_seconds=timeout)

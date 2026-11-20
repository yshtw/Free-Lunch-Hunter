"""stdio entry point: `uv run flh-mcp` or `python -m flh_mcp`.

stdout carries the MCP protocol, so all logging goes to stderr.
"""

from __future__ import annotations

import asyncio
import logging
import sys

from .client import ApiError, FlhApiClient
from .config import ConfigError, Settings
from .server import build_server
from .usecases import ActivityUseCases, PointsUseCases

log = logging.getLogger("flh_mcp")


async def serve(settings: Settings) -> None:
    client = FlhApiClient(settings.base_url, settings.token, timeout=settings.timeout_seconds)
    try:
        # Fail fast on a bad token, and learn the scopes that decide which tools exist.
        session = await client.session()
        scopes = set(session["scopes"])
        log.info("authenticated as user %s with scopes %s", session["user"]["user_id"], sorted(scopes))
        app = build_server(ActivityUseCases(client), PointsUseCases(client), scopes)
        await app.run_stdio_async()
    finally:
        await client.aclose()


def main() -> None:
    logging.basicConfig(stream=sys.stderr, level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    try:
        settings = Settings.from_env()
        asyncio.run(serve(settings))
    except ConfigError as error:
        log.error("%s", error)
        sys.exit(2)
    except ApiError as error:
        log.error("startup failed: %s", error.message)
        sys.exit(1)
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()

"""The backend capabilities use cases depend on. `FlhApiClient` implements this; tests use a fake."""

from __future__ import annotations

from typing import Any, Protocol


class FlhGateway(Protocol):
    async def activities(
        self, query: str = "", date_from: str | None = None, date_to: str | None = None, limit: int = 20
    ) -> dict[str, Any]: ...

    async def activity(self, activity_id: int) -> dict[str, Any] | None: ...

    async def schedule(self) -> list[dict[str, Any]]: ...

    async def set_schedule(self, activity_id: int, action: str) -> dict[str, Any]: ...

    async def wallet(self) -> dict[str, Any]: ...

    async def membership_offer(self) -> dict[str, Any]: ...

    async def create_membership_order(self, idempotency_key: str) -> dict[str, Any]: ...


class UseCaseError(Exception):
    """A request the use case refuses before contacting the backend. `str()` is shown to the agent."""

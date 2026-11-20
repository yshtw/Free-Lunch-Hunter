"""HTTP adapter for the Free Lunch Hunter JSON API (`api.php`).

This is the only module that knows about URLs, headers, status codes, and retry timing.
Every write this client performs is safe to repeat, which is what makes retrying it safe:

- schedule changes are desired-state updates (`add`/`remove` twice is a no-op);
- membership orders carry an `Idempotency-Key`, and the backend replays the original order.
"""

from __future__ import annotations

import asyncio
import logging
from typing import Any

import httpx

from .errors import ApiError, UnavailableError, error_for_status

log = logging.getLogger(__name__)

RETRYABLE_STATUS = frozenset({502, 503, 504})


class FlhApiClient:
    def __init__(
        self,
        base_url: str,
        token: str,
        *,
        timeout: float = 10.0,
        max_attempts: int = 3,
        backoff_seconds: float = 0.25,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        if max_attempts < 1:
            raise ValueError("max_attempts must be at least 1")
        self._max_attempts = max_attempts
        self._backoff = backoff_seconds
        self._http = httpx.AsyncClient(
            base_url=base_url.rstrip("/") + "/",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json", "User-Agent": "flh-mcp/0.1"},
            timeout=timeout,
            transport=transport,
            follow_redirects=False,
        )

    async def aclose(self) -> None:
        await self._http.aclose()

    # Reads -----------------------------------------------------------------------------------

    async def session(self) -> dict[str, Any]:
        return await self._request("GET", "session")

    async def activities(
        self, query: str = "", date_from: str | None = None, date_to: str | None = None, limit: int = 20
    ) -> dict[str, Any]:
        params = {"q": query, "limit": str(limit)}
        if date_from:
            params["from"] = date_from
        if date_to:
            params["to"] = date_to
        return await self._request("GET", "activities", params=params)

    async def activity(self, activity_id: int) -> dict[str, Any] | None:
        """One upcoming activity, or None if it does not exist or has ended."""
        rows = await self._request("GET", "activities", params={"activity_id": str(activity_id), "limit": "1"})
        return rows["activities"][0] if rows["activities"] else None

    async def schedule(self) -> list[dict[str, Any]]:
        return (await self._request("GET", "schedule"))["activities"]

    async def wallet(self) -> dict[str, Any]:
        return await self._request("GET", "wallet")

    async def membership_offer(self) -> dict[str, Any]:
        return await self._request("GET", "membership-offer")

    # Writes (all idempotent) -----------------------------------------------------------------

    async def set_schedule(self, activity_id: int, action: str) -> dict[str, Any]:
        return await self._request("POST", "schedule", json={"activity_id": activity_id, "action": action})

    async def create_membership_order(self, idempotency_key: str) -> dict[str, Any]:
        """Returns `{"order": {...}, "replayed": bool}`. Reusing a key never debits twice."""
        return await self._request(
            "POST",
            "membership-orders",
            json={"product_code": "premium_lifetime"},
            headers={"Idempotency-Key": idempotency_key},
        )

    # Transport -------------------------------------------------------------------------------

    async def _request(
        self,
        method: str,
        resource: str,
        *,
        params: dict[str, str] | None = None,
        json: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> Any:
        query = {"resource": resource, **(params or {})}
        last_error: ApiError | None = None
        for attempt in range(1, self._max_attempts + 1):
            try:
                response = await self._http.request(method, "api.php", params=query, json=json, headers=headers)
            except httpx.TransportError as error:
                # Includes timeouts after the request was sent: the write may have committed, and the
                # retry relies on the idempotency guarantees described in the module docstring.
                last_error = UnavailableError(f"無法連線到 Free Lunch Hunter（{type(error).__name__}）。")
            else:
                if response.status_code not in RETRYABLE_STATUS:
                    return self._parse(response)
                last_error = self._error(response)
            if attempt < self._max_attempts:
                log.warning(
                    "retrying %s %s after %s (attempt %d)", method, resource, type(last_error).__name__, attempt
                )
                await asyncio.sleep(self._backoff * 2 ** (attempt - 1))
        assert last_error is not None
        raise last_error

    def _parse(self, response: httpx.Response) -> Any:
        if response.is_success:
            try:
                return response.json()
            except ValueError as error:
                raise UnavailableError("後端回傳了非 JSON 內容。", status=response.status_code) from error
        raise self._error(response)

    @staticmethod
    def _error(response: httpx.Response) -> ApiError:
        request_id = response.headers.get("X-Request-ID")
        try:
            message = response.json().get("error") or response.reason_phrase
        except (ValueError, AttributeError):
            # Redirects to login.php, proxies, and HTML error pages end up here.
            message = f"後端回應 HTTP {response.status_code}。"
        return error_for_status(response.status_code, str(message), request_id)

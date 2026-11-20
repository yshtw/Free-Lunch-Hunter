import json

import httpx
import pytest

from flh_mcp.client import (
    AuthenticationError,
    ConflictError,
    FlhApiClient,
    InvalidRequestError,
    PermissionDeniedError,
    UnavailableError,
)
from flh_mcp.config import ConfigError, Settings

TOKEN = "flh_" + "a" * 16 + "_" + "b" * 64


def client_with(handler, **kwargs):
    return FlhApiClient(
        "http://127.0.0.1:8080", TOKEN, transport=httpx.MockTransport(handler), backoff_seconds=0, **kwargs
    )


async def test_sends_bearer_token_and_resource_query():
    seen = []

    def handler(request):
        seen.append(request)
        return httpx.Response(200, json={"activities": [], "has_more": False})

    await client_with(handler).activities("lunch", "2026-12-01", None, 5)
    request = seen[0]
    assert request.headers["Authorization"] == f"Bearer {TOKEN}"
    assert request.url.path == "/api.php"
    assert dict(request.url.params) == {"resource": "activities", "q": "lunch", "limit": "5", "from": "2026-12-01"}


@pytest.mark.parametrize(
    ("status", "error_type"),
    [
        (401, AuthenticationError),
        (403, PermissionDeniedError),
        (409, ConflictError),
        (422, InvalidRequestError),
        (500, UnavailableError),
    ],
)
async def test_maps_status_to_typed_errors_with_request_id(status, error_type):
    def handler(_):
        return httpx.Response(status, json={"error": "說明", "request_id": "x"}, headers={"X-Request-ID": "abc123"})

    with pytest.raises(error_type) as caught:
        await client_with(handler).wallet()
    assert (caught.value.message, caught.value.request_id) == ("說明", "abc123")


async def test_does_not_retry_definitive_failures():
    calls = []

    def handler(request):
        calls.append(request)
        return httpx.Response(500, json={"error": "bug"})

    with pytest.raises(UnavailableError):
        await client_with(handler).wallet()
    assert len(calls) == 1


async def test_retries_order_after_timeout_with_the_same_idempotency_key():
    keys = []

    def handler(request):
        keys.append(request.headers["Idempotency-Key"])
        if len(keys) == 1:
            raise httpx.ReadTimeout("response lost after commit")
        return httpx.Response(200, json={"order": {"order_id": "o"}, "replayed": True})

    result = await client_with(handler).create_membership_order("flhq_same_key_123456")
    assert result["replayed"] is True
    assert keys == ["flhq_same_key_123456", "flhq_same_key_123456"]


async def test_retries_are_bounded_on_503():
    calls = []

    def handler(request):
        calls.append(request)
        return httpx.Response(503, text="<html>maintenance</html>")

    with pytest.raises(UnavailableError, match="503"):
        await client_with(handler, max_attempts=3).schedule()
    assert len(calls) == 3


async def test_redirects_to_login_are_errors_not_followed():
    def handler(_):
        return httpx.Response(303, headers={"Location": "login.php"})

    with pytest.raises(Exception) as caught:
        await client_with(handler).wallet()
    assert "303" in str(caught.value)


async def test_schedule_write_body():
    bodies = []

    def handler(request):
        bodies.append(json.loads(request.content))
        return httpx.Response(200, json={"activity_id": 3, "joined": False})

    await client_with(handler).set_schedule(3, "remove")
    assert bodies == [{"activity_id": 3, "action": "remove"}]


# Configuration -----------------------------------------------------------------------------------


def test_requires_url_and_token():
    with pytest.raises(ConfigError):
        Settings.from_env({"FLH_BASE_URL": "http://localhost:8080"})


@pytest.mark.parametrize("url", ["http://example.com", "ftp://localhost", "localhost:8080"])
def test_refuses_plain_http_to_remote_hosts_and_bad_schemes(url):
    with pytest.raises(ConfigError):
        Settings.from_env({"FLH_BASE_URL": url, "FLH_API_TOKEN": TOKEN})


def test_accepts_https_and_loopback_and_hides_token_from_repr():
    for url in ["https://flh.example.com", "http://127.0.0.1:8080", "http://localhost:8080"]:
        settings = Settings.from_env({"FLH_BASE_URL": url, "FLH_API_TOKEN": TOKEN})
        assert TOKEN not in repr(settings)

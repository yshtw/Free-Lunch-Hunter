"""Typed failures from the Free Lunch Hunter HTTP API.

The use-case and MCP layers branch on these types, never on raw HTTP status codes.
"""


class ApiError(Exception):
    """A request the backend rejected or could not complete. `message` is safe to show to users."""

    def __init__(self, message: str, *, status: int | None = None, request_id: str | None = None) -> None:
        super().__init__(message)
        self.message = message
        self.status = status
        self.request_id = request_id


class AuthenticationError(ApiError):
    """401: the token is invalid, expired, or revoked."""


class PermissionDeniedError(ApiError):
    """403: the token lacks the scope for this operation."""


class NotFoundError(ApiError):
    """404: the referenced activity or resource does not exist (or has ended)."""


class ConflictError(ApiError):
    """409: the request is valid but the current state forbids it (e.g. insufficient points)."""


class InvalidRequestError(ApiError):
    """400/422: the backend rejected an argument."""


class UnavailableError(ApiError):
    """Transport failure, timeout, or 5xx after bounded retries. The outcome of a write may be unknown."""


_BY_STATUS: dict[int, type[ApiError]] = {
    400: InvalidRequestError,
    401: AuthenticationError,
    403: PermissionDeniedError,
    404: NotFoundError,
    409: ConflictError,
    422: InvalidRequestError,
}


def error_for_status(status: int, message: str, request_id: str | None) -> ApiError:
    error_type = _BY_STATUS.get(status, UnavailableError if status >= 500 else ApiError)
    return error_type(message, status=status, request_id=request_id)

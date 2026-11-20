"""Outermost layer: the HTTP adapter for the Free Lunch Hunter backend."""

from .api_client import FlhApiClient
from .errors import (
    ApiError,
    AuthenticationError,
    ConflictError,
    InvalidRequestError,
    NotFoundError,
    PermissionDeniedError,
    UnavailableError,
)

__all__ = [
    "ApiError",
    "AuthenticationError",
    "ConflictError",
    "FlhApiClient",
    "InvalidRequestError",
    "NotFoundError",
    "PermissionDeniedError",
    "UnavailableError",
]

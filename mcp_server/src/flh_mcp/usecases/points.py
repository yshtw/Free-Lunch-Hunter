"""Points wallet and Premium redemption use cases.

Redemption is two-step. `preview` asks the backend for its eligibility decision and issues a short-lived
confirmation code. `redeem` accepts only a code this process issued and uses it as the order's
Idempotency-Key, so a retry after a timeout replays the original order instead of debiting twice.
"""

from __future__ import annotations

import secrets
import time
from collections.abc import Callable
from dataclasses import dataclass
from decimal import Decimal

from ..client.errors import ConflictError
from ..domain.models import PointsOverview, PremiumOffer, UpgradePreview, UpgradeResult, WalletEntry
from ..domain.rules import HOW_TO_EARN
from .ports import FlhGateway, UseCaseError

CONFIRMATION_TTL_SECONDS = 600


@dataclass(frozen=True)
class _Quote:
    issued_at: float
    price: Decimal


class ConfirmationStore:
    """In-memory, per-process. A restart invalidates codes, which fails closed (the agent must preview again)."""

    def __init__(self, ttl_seconds: int = CONFIRMATION_TTL_SECONDS, clock: Callable[[], float] = time.monotonic):
        self._ttl = ttl_seconds
        self._clock = clock
        self._quotes: dict[str, _Quote] = {}

    def issue(self, price: Decimal) -> str:
        self._expire()
        # Matches the backend Idempotency-Key format [A-Za-z0-9_-]{16,80}.
        code = "flhq_" + secrets.token_urlsafe(24)
        self._quotes[code] = _Quote(self._clock(), price)
        return code

    def get(self, code: str) -> _Quote | None:
        self._expire()
        return self._quotes.get(code)

    @property
    def ttl_seconds(self) -> int:
        return self._ttl

    def _expire(self) -> None:
        now = self._clock()
        for code in [code for code, quote in self._quotes.items() if now - quote.issued_at > self._ttl]:
            del self._quotes[code]


class PointsUseCases:
    def __init__(self, gateway: FlhGateway, confirmations: ConfirmationStore | None = None) -> None:
        self._gateway = gateway
        self._confirmations = confirmations or ConfirmationStore()

    async def overview(self, recent: int = 10) -> PointsOverview:
        if not 0 <= recent <= 50:
            raise UseCaseError("recent must be between 0 and 50.")
        wallet = await self._gateway.wallet()
        offer = await self._gateway.membership_offer()
        return PointsOverview(
            balance=Decimal(wallet["balance"]),
            membership_level=offer["membership_level"],
            premium_offer=_offer(offer),
            recent_entries=[
                WalletEntry(
                    amount=Decimal(e["amount"]),
                    reason=e["action"],
                    reference=str(e["reference"]),
                    created_at=e["created_at"],
                )
                for e in wallet["entries"][:recent]
            ],
            how_to_earn=list(HOW_TO_EARN),
        )

    async def preview_upgrade(self) -> UpgradePreview:
        offer = await self._gateway.membership_offer()
        price, balance = Decimal(offer["price"]), Decimal(offer["balance"])
        if not offer["eligible"]:
            ask = (
                "You are already a Premium (or admin) member; no upgrade is needed."
                if offer["reason"] == "already_member"
                else f"Premium costs {price:.2f} points and you have {balance:.2f}. "
                f"You need {Decimal(offer['points_needed']):.2f} more points."
            )
            return UpgradePreview(
                eligible=False,
                reason=offer["reason"],
                price=price,
                balance=balance,
                balance_after=None,
                confirmation_code=None,
                expires_in_seconds=None,
                ask_user=ask,
            )
        return UpgradePreview(
            eligible=True,
            reason=None,
            price=price,
            balance=balance,
            balance_after=balance - price,
            confirmation_code=self._confirmations.issue(price),
            expires_in_seconds=self._confirmations.ttl_seconds,
            ask_user=f"Redeem {price:.2f} community points for lifetime Premium? Your balance will go from "
            f"{balance:.2f} to {balance - price:.2f}. Points have no cash value and this cannot be undone.",
        )

    async def confirmation_prompt(self, confirmation_code: str) -> str | None:
        """Text for a human approval dialog, built from fresh server numbers.

        None means no dialog is needed because the server will refuse or replay the order anyway.
        """
        quote = self._quote(confirmation_code)
        offer = await self._gateway.membership_offer()
        if not offer["eligible"]:
            return None
        price, balance = Decimal(offer["price"]), Decimal(offer["balance"])
        if price != quote.price:
            raise UseCaseError("The price changed since the preview. Call preview_premium_upgrade again.")
        return (
            f"Redeem {price:.2f} community points for lifetime Premium? Balance {balance:.2f} → "
            f"{balance - price:.2f}. This cannot be undone."
        )

    async def redeem_upgrade(self, confirmation_code: str, approved: bool | None = None) -> UpgradeResult:
        """`approved` is the human dialog outcome: False blocks the order; None means no dialog was available."""
        self._quote(confirmation_code)
        if approved is False:
            return UpgradeResult(
                status="declined_by_user",
                order_id=None,
                amount=None,
                balance_after=None,
                message="The user declined in the confirmation dialog. No points were spent.",
            )
        try:
            result = await self._gateway.create_membership_order(confirmation_code)
        except ConflictError as error:
            raise UseCaseError(f"Not redeemed: {error.message} No points were spent.") from error
        order = result["order"]
        wallet = await self._gateway.wallet()
        replayed = bool(result["replayed"])
        return UpgradeResult(
            status="already_completed" if replayed else "completed",
            order_id=order["order_id"],
            amount=Decimal(order["amount"]),
            balance_after=Decimal(wallet["balance"]),
            message="This confirmation code was already redeemed; the original order is returned and nothing was "
            "charged again."
            if replayed
            else "Premium is active.",
        )

    def _quote(self, confirmation_code: str) -> _Quote:
        quote = self._confirmations.get(confirmation_code)
        if quote is None:
            raise UseCaseError(
                "Unknown or expired confirmation_code. Call preview_premium_upgrade, show the user its ask_user "
                "text, and use the new code only after they agree."
            )
        return quote


def _offer(raw: dict) -> PremiumOffer:
    return PremiumOffer(
        product_code=raw["product_code"],
        price=Decimal(raw["price"]),
        eligible=raw["eligible"],
        reason=raw["reason"],
        points_needed=Decimal(raw["points_needed"]),
    )

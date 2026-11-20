"""In-memory stand-in for the backend that mirrors api.php semantics (idempotent writes, server-side offer)."""

from __future__ import annotations

from datetime import date, timedelta
from decimal import Decimal
from typing import Any

from flh_mcp.client.errors import ConflictError, NotFoundError

PRICE = Decimal("30")


def activity(activity_id: int, *, days: int = 1, start: str = "12:00", end: str = "13:00", **extra: Any) -> dict:
    return {
        "activity_id": activity_id,
        "title": extra.get("title", f"Event {activity_id}"),
        "content": extra.get("content", "Talk and lunch."),
        "act_date": (date(2026, 12, 1) + timedelta(days=days)).isoformat(),
        "start_time": start,
        "end_time": end,
        "place": extra.get("place", "Library"),
        "lunch": extra.get("lunch", "Bento"),
        "url": extra.get("url"),
        "joined": False,
    }


class FakeGateway:
    def __init__(self, activities: list[dict] | None = None, balance: str = "0", level: int = 0) -> None:
        self.rows = {row["activity_id"]: row for row in activities or []}
        self.joined: set[int] = set()
        self.balance = Decimal(balance)
        self.level = level
        self.orders: dict[str, dict] = {}
        self.calls: list[tuple] = []

    def _view(self, row: dict) -> dict:
        return dict(row, joined=row["activity_id"] in self.joined)

    async def activities(self, query="", date_from=None, date_to=None, limit=20):
        self.calls.append(("activities", query, date_from, date_to, limit))
        rows = [
            self._view(r)
            for r in sorted(self.rows.values(), key=lambda r: (r["act_date"], r["start_time"], r["activity_id"]))
            if query.lower() in (r["title"] + r["place"] + r["content"]).lower()
            and (date_from is None or r["act_date"] >= date_from)
            and (date_to is None or r["act_date"] <= date_to)
        ]
        return {"activities": rows[:limit], "has_more": len(rows) > limit}

    async def activity(self, activity_id):
        self.calls.append(("activity", activity_id))
        row = self.rows.get(activity_id)
        return self._view(row) if row else None

    async def schedule(self):
        self.calls.append(("schedule",))
        return [
            self._view(self.rows[i])
            for i in sorted(self.joined, key=lambda i: (self.rows[i]["act_date"], self.rows[i]["start_time"]))
        ]

    async def set_schedule(self, activity_id, action):
        self.calls.append(("set_schedule", activity_id, action))
        if action == "add":
            if activity_id not in self.rows:
                raise NotFoundError("活動不存在或已結束。", status=404, request_id="req-404")
            self.joined.add(activity_id)
        else:
            self.joined.discard(activity_id)
        return {"activity_id": activity_id, "joined": action == "add"}

    async def wallet(self):
        self.calls.append(("wallet",))
        return {
            "balance": f"{self.balance:.2f}",
            "unit": "community_points",
            "entries": [
                {
                    "entry_id": 1,
                    "amount": "5.00",
                    "action": "post",
                    "reference": "7",
                    "created_at": "2026-11-20 12:00:00",
                }
            ],
        }

    async def membership_offer(self):
        self.calls.append(("membership_offer",))
        needed = max(Decimal(0), PRICE - self.balance)
        reason = "already_member" if self.level else ("insufficient_points" if needed > 0 else None)
        return {
            "product_code": "premium_lifetime",
            "price": "30.00",
            "unit": "community_points",
            "balance": f"{self.balance:.2f}",
            "membership_level": ["regular", "premium", "admin"][self.level],
            "eligible": reason is None,
            "reason": reason,
            "points_needed": f"{needed:.2f}",
        }

    async def create_membership_order(self, idempotency_key):
        self.calls.append(("create_membership_order", idempotency_key))
        if idempotency_key in self.orders:
            return {"order": self.orders[idempotency_key], "replayed": True}
        if self.level:
            raise ConflictError("目前會員等級無需再次升級。", status=409)
        if self.balance < PRICE:
            raise ConflictError("點數不足，升級需要 30 點。", status=409)
        self.balance -= PRICE
        self.level = 1
        order = {
            "order_id": f"{len(self.orders) + 1:032x}",
            "product_code": "premium_lifetime",
            "amount": "30.00",
            "status": "completed",
            "created_at": "2026-11-20 12:00:00",
        }
        self.orders[idempotency_key] = order
        return {"order": order, "replayed": False}

    def count(self, name: str) -> int:
        return sum(1 for call in self.calls if call[0] == name)

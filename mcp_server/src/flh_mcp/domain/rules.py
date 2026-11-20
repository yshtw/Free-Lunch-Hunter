"""Deterministic rules the agent should never be asked to compute itself."""

from __future__ import annotations

from collections.abc import Iterable

from .models import Activity

# Mirrors the reward rules enforced by app/services.php. Informational only: the backend is authoritative.
HOW_TO_EARN = (
    "Publish a review post: +5 points.",
    "Comment on a post: +3 points.",
    "Rate a post for the first time: +1 point (changing the rating later earns nothing).",
)


def overlaps(a: Activity, b: Activity) -> bool:
    """Same day and the half-open intervals [start, end) intersect. Back-to-back events do not overlap."""
    return a.date == b.date and a.start_time < b.end_time and b.start_time < a.end_time


def conflicts(candidate: Activity, schedule: Iterable[Activity]) -> list[Activity]:
    return [item for item in schedule if item.activity_id != candidate.activity_id and overlaps(candidate, item)]


def overlapping_pairs(schedule: list[Activity]) -> list[tuple[int, int]]:
    return [
        (a.activity_id, b.activity_id)
        for index, a in enumerate(schedule)
        for b in schedule[index + 1 :]
        if overlaps(a, b)
    ]

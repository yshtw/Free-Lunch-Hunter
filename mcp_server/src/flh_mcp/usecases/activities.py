"""Intent-level activity use cases. Each one may combine several backend calls into one agent step."""

from __future__ import annotations

import asyncio
from datetime import date

from ..domain import rules
from ..domain.models import (
    Activity,
    ActivityMatch,
    FindActivitiesResult,
    ScheduleChange,
    ScheduleConflict,
    ScheduleResult,
)
from .ports import FlhGateway, UseCaseError

MAX_RESULTS = 20


class ActivityUseCases:
    def __init__(self, gateway: FlhGateway) -> None:
        self._gateway = gateway

    async def find(
        self,
        query: str = "",
        date_from: date | None = None,
        date_to: date | None = None,
        only_fits_my_schedule: bool = False,
        limit: int = 10,
    ) -> FindActivitiesResult:
        if not 1 <= limit <= MAX_RESULTS:
            raise UseCaseError(f"limit must be between 1 and {MAX_RESULTS}.")
        if date_from and date_to and date_to < date_from:
            raise UseCaseError("date_to must not be earlier than date_from.")
        # Fetch extra rows when filtering, so hiding conflicts does not silently shrink the page.
        fetch = min(50, limit * 3) if only_fits_my_schedule else limit
        found, schedule = await asyncio.gather(
            self._gateway.activities(
                query.strip(),
                date_from.isoformat() if date_from else None,
                date_to.isoformat() if date_to else None,
                fetch,
            ),
            self._schedule(),
        )
        matches = [
            ActivityMatch(
                **activity.model_dump(),
                conflicts_with=[ScheduleConflict.of(item) for item in rules.conflicts(activity, schedule)],
            )
            for activity in map(Activity.from_api, found["activities"])
        ]
        hidden = 0
        if only_fits_my_schedule:
            fitting = [match for match in matches if not match.conflicts_with and not match.in_my_schedule]
            hidden = len(matches) - len(fitting)
            matches = fitting
        return FindActivitiesResult(
            activities=matches[:limit],
            has_more=found["has_more"] or len(matches) > limit,
            hidden_because_of_conflicts=hidden,
        )

    async def my_schedule(self) -> ScheduleResult:
        schedule = await self._schedule()
        return ScheduleResult(activities=schedule, overlapping=rules.overlapping_pairs(schedule))

    async def add(self, activity_id: int, allow_overlap: bool = False) -> ScheduleChange:
        schedule = await self._schedule()
        if any(item.activity_id == activity_id for item in schedule):
            return ScheduleChange(
                activity_id=activity_id,
                status="already_in_schedule",
                in_my_schedule=True,
                conflicts_with=[],
                message="Already in the schedule; nothing changed.",
            )
        candidate = await self._find_by_id(activity_id)
        clashes = [ScheduleConflict.of(item) for item in rules.conflicts(candidate, schedule)]
        if clashes and not allow_overlap:
            return ScheduleChange(
                activity_id=activity_id,
                status="not_added_overlap",
                in_my_schedule=False,
                conflicts_with=clashes,
                message="Not added: it overlaps the activities listed. Ask the user, then retry with allow_overlap=true "
                "if they still want it.",
            )
        await self._gateway.set_schedule(activity_id, "add")
        return ScheduleChange(
            activity_id=activity_id,
            status="added",
            in_my_schedule=True,
            conflicts_with=clashes,
            message="Added to the schedule." + (" It overlaps the listed activities." if clashes else ""),
        )

    async def remove(self, activity_id: int) -> ScheduleChange:
        schedule = await self._schedule()
        present = any(item.activity_id == activity_id for item in schedule)
        if present:
            await self._gateway.set_schedule(activity_id, "remove")
        return ScheduleChange(
            activity_id=activity_id,
            status="removed" if present else "not_in_schedule",
            in_my_schedule=False,
            conflicts_with=[],
            message="Removed from the schedule."
            if present
            else "It was not in the upcoming schedule; nothing changed.",
        )

    async def _schedule(self) -> list[Activity]:
        return [Activity.from_api(row) for row in await self._gateway.schedule()]

    async def _find_by_id(self, activity_id: int) -> Activity:
        row = await self._gateway.activity(activity_id)
        if row is None:
            raise UseCaseError(f"Activity {activity_id} does not exist or has already ended. Use find_activities.")
        return Activity.from_api(row)

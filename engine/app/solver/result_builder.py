"""Transform a CP-SAT solution into a ScheduleOutputSchema-compatible dict.

The builder reads the solved boolean variables ``x[team, venue, day, slot]``,
merges them with pre-placed HARD locked slots, and produces manager-readable
diagnostics for any post-solve issues it detects.
"""

from __future__ import annotations

from collections import defaultdict
from datetime import datetime, timezone
from typing import Any, Mapping

from ortools.sat.python import cp_model

from app.solver.model import SLOT_MINUTES, ScheduleCpModel


def build_result(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
    *,
    status: Any | None = None,
) -> dict[str, Any]:
    """Transform a CP-SAT solution into a dict matching ``ScheduleOutputSchema``.

    Args:
        model_data: The original input data (dict or Pydantic model).
        solver: The OR-Tools ``CpSolver`` instance after solving.
        model: The ``ScheduleCpModel`` containing variables and locked slots.
        status: Optional solver status. If omitted, inferred from the solver.

    Returns:
        A dictionary that validates against ``ScheduleOutputSchema``.
    """
    if status is not None:
        solver_status = status
    elif hasattr(solver, "_checked_response") and hasattr(solver._checked_response, "status"):
        solver_status = solver._checked_response.status
    else:
        solver_status = cp_model.UNKNOWN

    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        schema_status = "completed"
    else:
        schema_status = "failed"

    slots: list[dict[str, Any]] = []
    diagnostics: list[dict[str, Any]] = []

    # Preserve HARD locked slots regardless of solver status.
    for locked in model.locked_slots:
        slots.append(_locked_slot_to_dict(locked))

    # Add solver-placed slots when the problem was solved successfully.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        solver_slots = _build_solver_slots(model_data, solver, model)
        slots.extend(solver_slots)

    # Always run diagnostic checks.
    diagnostics.extend(_generate_diagnostics(model_data, solver_status, slots))

    score: int | None = None
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        score = int(solver.ObjectiveValue())

    return {
        "status": schema_status,
        "score": score,
        "slots": slots,
        "diagnostics": diagnostics,
    }


def _locked_slot_to_dict(locked: Mapping[str, Any] | Any) -> dict[str, Any]:
    """Convert a normalized HARD locked slot into an output slot dict."""
    team_id = str(_get(locked, "team_id", "teamId"))
    venue_id = str(_get(locked, "venue_id", "venueId"))
    day_of_week = int(_get(locked, "day_of_week", "dayOfWeek"))
    start_time = str(_get(locked, "start_time", "startTime"))
    duration = int(_get(locked, "duration_minutes", "durationMinutes", default=SLOT_MINUTES))
    coach_id = _get(locked, "coach_id", "coachId", default=None)
    temporary_lock = bool(_get(locked, "temporary_lock", "temporaryLock", default=False))
    temporary_lock_for = _get(locked, "temporary_lock_for", "temporaryLockFor", default=None)
    temporary_min_sessions_override = _get(
        locked, "temporary_min_sessions_override", "temporaryMinSessionsOverride", default=None
    )
    pending_constraint_suggestion = _get(
        locked, "pending_constraint_suggestion", "pendingConstraintSuggestion", default=None
    )

    return {
        "id": f"slot-{team_id}-{venue_id}-{day_of_week}-{start_time}",
        "teamId": team_id,
        "venueId": venue_id,
        "coachId": coach_id,
        "dayOfWeek": day_of_week,
        "startTime": start_time,
        "durationMinutes": duration,
        "lockLevel": "HARD",
        "temporaryLock": temporary_lock,
        "temporaryLockFor": temporary_lock_for,
        "temporaryMinSessionsOverride": temporary_min_sessions_override,
        "pendingConstraintSuggestion": pending_constraint_suggestion,
    }


def _build_solver_slots(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
) -> list[dict[str, Any]]:
    """Build output slots from CP-SAT boolean variables set to 1."""
    slots: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        if solver.Value(var) != 1:
            continue
        team_id, venue_id, day_of_week, slot_start = slot_key
        coach_id = _find_coach_for_team(model_data, team_id)

        slots.append({
            "id": f"slot-{team_id}-{venue_id}-{day_of_week}-{slot_start}",
            "teamId": team_id,
            "venueId": venue_id,
            "coachId": coach_id,
            "dayOfWeek": day_of_week,
            "startTime": slot_start,
            "durationMinutes": SLOT_MINUTES,
            "lockLevel": "NONE",
            "temporaryLock": False,
            "temporaryLockFor": None,
            "temporaryMinSessionsOverride": None,
            "pendingConstraintSuggestion": None,
        })
    return slots


def _generate_diagnostics(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Run post-solve checks and return manager-readable diagnostics."""
    diagnostics: list[dict[str, Any]] = []
    diagnostics.extend(_diagnose_unplaced(model_data, slots))
    diagnostics.extend(_diagnose_soft_lock_moved(model_data, slots))
    diagnostics.extend(_diagnose_coach_overload(model_data, slots))
    diagnostics.extend(_diagnose_conflicts(solver_status, slots))
    return diagnostics


def _diagnose_unplaced(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag teams that have no sessions in the final schedule."""
    diagnostics: list[dict[str, Any]] = []
    team_ids = _team_ids(model_data)
    placed_team_ids = {slot["teamId"] for slot in slots}

    for team_id in team_ids:
        if team_id not in placed_team_ids:
            diagnostics.append({
                "id": f"diag-unplaced-{team_id}",
                "type": "unplaced",
                "severity": "high",
                "teamId": team_id,
                "message": (
                    f"Team {team_id} could not be placed in the schedule. "
                    "No available slot matched the team's constraints."
                ),
                "suggestions": [
                    "Add more venue availability or relax hard constraints.",
                    "Check that the team has at least one feasible time slot.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })
    return diagnostics


def _diagnose_soft_lock_moved(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn when a SOFT locked template did not survive in the solution."""
    diagnostics: list[dict[str, Any]] = []
    for template in _slot_templates(model_data):
        lock_level = str(_get(template, "lock_level", "lockLevel", default="")).upper()
        if lock_level != "SOFT":
            continue

        team_id = str(_get(template, "team_id", "teamId"))
        venue_id = str(_get(template, "venue_id", "venueId"))
        day_of_week = int(_get(template, "day_of_week", "dayOfWeek"))
        start_time = str(_get(template, "start_time", "startTime"))

        found = any(
            slot["teamId"] == team_id
            and slot["venueId"] == venue_id
            and slot["dayOfWeek"] == day_of_week
            and slot["startTime"] == start_time
            for slot in slots
        )
        if not found:
            diagnostics.append({
                "id": f"diag-soft-moved-{team_id}-{day_of_week}-{start_time}",
                "type": "soft_lock_moved",
                "severity": "medium",
                "teamId": team_id,
                "venueId": venue_id,
                "message": (
                    f"The preferred slot for team {team_id} at {venue_id} "
                    f"on day {day_of_week} starting at {start_time} was moved. "
                    "The solver found a better overall fit by shifting this session."
                ),
                "suggestions": [
                    "Review the new time and confirm it still works for the team.",
                    "If the original time is essential, consider raising the lock to HARD.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })
    return diagnostics


def _diagnose_coach_overload(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag coaches whose session count exceeds a safe threshold."""
    diagnostics: list[dict[str, Any]] = []
    coach_counts: dict[str, int] = defaultdict(int)
    for slot in slots:
        coach_id = slot.get("coachId")
        if coach_id:
            coach_counts[coach_id] += 1

    for coach_id, count in coach_counts.items():
        threshold = _coach_threshold(model_data, coach_id)
        if count > threshold:
            diagnostics.append({
                "id": f"diag-overload-{coach_id}",
                "type": "coach_overload",
                "severity": "medium",
                "coachId": coach_id,
                "message": (
                    f"Coach {coach_id} is assigned {count} sessions, "
                    f"which is above the recommended limit of {threshold}. "
                    "This may lead to fatigue or scheduling conflicts."
                ),
                "suggestions": [
                    "Redistribute some sessions to another coach.",
                    "Review the coach's maximum days setting in their profile.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })
    return diagnostics


def _diagnose_conflicts(
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Report infeasibility or detected double-bookings."""
    diagnostics: list[dict[str, Any]] = []

    if solver_status == cp_model.INFEASIBLE:
        diagnostics.append({
            "id": "diag-infeasible",
            "type": "conflict",
            "severity": "high",
            "message": (
                "The schedule could not be generated because the current constraints "
                "are impossible to satisfy together. No valid assignment exists."
            ),
            "suggestions": [
                "Remove or soften some HARD constraints.",
                "Increase venue availability or add more coaches.",
                "Check for conflicting locked slots between teams.",
            ],
            "createdAt": datetime.now(timezone.utc).isoformat(),
        })
        return diagnostics

    # Post-solve safety check: venue double-booking.
    venue_bookings: dict[tuple[str, int, str], list[str]] = defaultdict(list)
    for slot in slots:
        key = (slot["venueId"], slot["dayOfWeek"], slot["startTime"])
        venue_bookings[key].append(slot["teamId"])

    for (venue_id, day_of_week, start_time), team_ids in venue_bookings.items():
        if len(team_ids) > 1:
            diagnostics.append({
                "id": f"diag-conflict-venue-{venue_id}-{day_of_week}-{start_time}",
                "type": "conflict",
                "severity": "high",
                "venueId": venue_id,
                "message": (
                    f"Venue {venue_id} is double-booked on day {day_of_week} at {start_time} "
                    f"for teams {', '.join(team_ids)}."
                ),
                "suggestions": [
                    "Move one of the sessions to a different time or venue.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })

    # Post-solve safety check: coach double-booking.
    coach_bookings: dict[tuple[str, int, str], list[str]] = defaultdict(list)
    for slot in slots:
        coach_id = slot.get("coachId")
        if not coach_id:
            continue
        key = (coach_id, slot["dayOfWeek"], slot["startTime"])
        coach_bookings[key].append(slot["teamId"])

    for (coach_id, day_of_week, start_time), team_ids in coach_bookings.items():
        if len(team_ids) > 1:
            diagnostics.append({
                "id": f"diag-conflict-coach-{coach_id}-{day_of_week}-{start_time}",
                "type": "conflict",
                "severity": "high",
                "coachId": coach_id,
                "message": (
                    f"Coach {coach_id} is assigned to multiple teams on day {day_of_week} "
                    f"at {start_time}: {', '.join(team_ids)}."
                ),
                "suggestions": [
                    "Split the sessions or assign a different coach to one team.",
                ],
                "createdAt": datetime.now(timezone.utc).isoformat(),
            })

    return diagnostics


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _get(source: Mapping[str, Any] | Any, *names: str, default: Any = None) -> Any:
    """Read the first available field from a dict or object."""
    for name in names:
        if isinstance(source, Mapping):
            if name in source and source[name] is not None:
                return source[name]
            continue
        if hasattr(source, name):
            value = getattr(source, name)
            if value is not None:
                return value
    return default


def _team_ids(model_data: Mapping[str, Any] | Any) -> set[str]:
    """Return all team IDs found in the input data."""
    ids: set[str] = set()
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            ids.add(str(team_id))
    return ids


def _slot_templates(model_data: Mapping[str, Any] | Any) -> list[Any]:
    """Return all slot templates from the input data."""
    return list(_collection(model_data, "slot_templates", "slotTemplates", "slots"))


def _collection(source: Mapping[str, Any] | Any, *names: str) -> list[Any]:
    """Extract a list-like collection from a dict or object by field name."""
    for name in names:
        values = _get(source, name, default=None)
        if values is None:
            continue
        if isinstance(values, (list, tuple)):
            return list(values)
        raise TypeError(f"{name} must be a list-like collection")
    return []


def _find_coach_for_team(model_data: Mapping[str, Any] | Any, team_id: str) -> str | None:
    """Return the first coach_id found in slot templates for the given team."""
    for template in _slot_templates(model_data):
        template_team_id = _get(template, "team_id", "teamId")
        if template_team_id is not None and str(template_team_id) == team_id:
            coach_id = _get(template, "coach_id", "coachId")
            if coach_id is not None:
                return str(coach_id)
    return None


def _coach_threshold(model_data: Mapping[str, Any] | Any, coach_id: str) -> int:
    """Return the recommended maximum session count for a coach."""
    for coach in _collection(model_data, "coaches"):
        cid = _get(coach, "id", "coach_id", "coachId")
        if cid is not None and str(cid) == coach_id:
            override = _get(coach, "max_days_override", "maxDaysOverride")
            if override is not None:
                return max(1, int(override))
    return 5

"""Transform a CP-SAT solution into a ScheduleOutputSchema-compatible dict.

The builder reads the solved boolean variables ``x[team, venue, day, slot]``,
merges them with pre-placed HARD locked slots, and produces manager-readable
diagnostics for any post-solve issues it detects.
"""

from __future__ import annotations

import contextlib
import uuid
from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from importlib.metadata import version
from typing import Any

from ortools.sat.python import cp_model

from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    SLOT_MINUTES,
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from app.solver.objective import SCORE_FORMULA_VERSION


def build_result(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
    *,
    status: Any | None = None,
    fallback_used: bool = False,
    constraint_version: str | None = None,
) -> dict[str, Any]:
    """Transform a CP-SAT solution into a dict matching ``ScheduleOutputSchema``.

    Args:
        model_data: The original input data (dict or Pydantic model).
        solver: The OR-Tools ``CpSolver`` instance after solving.
        model: The ``ScheduleCpModel`` containing variables and locked slots.
        status: Optional solver status. If omitted, inferred from the solver.
        fallback_used: True when Pass 1 was INFEASIBLE and Pass 2 (without
            coach rest day + salarie distribution constraints) was used instead.

    Returns:
        A dictionary that validates against ``ScheduleOutputSchema``.
    """
    if status is not None:
        solver_status = status
    elif hasattr(solver, "_checked_response") and hasattr(solver._checked_response, "status"):
        solver_status = solver._checked_response.status
    else:
        solver_status = cp_model.UNKNOWN

    schema_status = "completed" if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else "failed"

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
    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    diagnostics.extend(
        _generate_diagnostics(
            model_data, solver_status, slots, slot_capacities=slot_capacities, fallback_used=fallback_used
        )
    )

    unplaced = _unplaced_team_ids(model_data, slots)

    score: int | None = None
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        score = int(solver.ObjectiveValue())

    metrics = {
        "solver_version": version("ortools"),
        "nb_variables": int(model.NumVariables()),
        "nb_constraints": len(model.Proto().constraints),
        "wall_time_ms": round(solver.WallTime() * 1000),
        # Determinism identifiers — the backend persists these on the Schedule.
        "score_formula_version": SCORE_FORMULA_VERSION,
        "constraint_version": constraint_version,
    }

    return {
        "status": schema_status,
        "score": score,
        "metrics": metrics,
        "unplaced": unplaced,
        "slots": slots,
        "diagnostics": diagnostics,
    }


def _locked_slot_to_dict(locked: Mapping[str, Any] | Any) -> dict[str, Any]:
    """Convert a normalized HARD locked slot into an output slot dict."""
    team_id = str(_get(locked, "team_id", "teamId"))
    venue_id = str(_get(locked, "venue_id", "venueId"))
    day_of_week = int(_get(locked, "day_of_week", "dayOfWeek"))
    start_time = str(_get(locked, "start_time", "startTime"))[:5]  # normalize "HH:MM:SS" → "HH:MM"
    duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
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
        "id": _slot_id(team_id, venue_id, day_of_week, start_time),
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
    """Build output slots from CP-SAT boolean variables set to 1.

    Consecutive variables for the same (team, venue, day) are merged into a
    single slot. Duration per variable comes from ``model.slot_durations``
    (the training-slot's declared duration) with a fallback to SLOT_MINUTES
    for backward-compatible 15-min granularity.
    """
    from collections import defaultdict

    slot_durations: dict[Any, int] = getattr(model, "slot_durations", {})

    def _slot_dur(v_id: str, dow: int, start_min: int) -> int:
        return slot_durations.get((v_id, dow, _format_time(start_min)), SLOT_MINUTES)

    # Collect all active (team, venue, day, start_minutes) tuples
    active: dict[tuple[str, str, int], list[int]] = defaultdict(list)
    for slot_key, var in model.x.items():
        if solver.Value(var) != 1:
            continue
        team_id, venue_id, day_of_week, slot_start = slot_key
        start_minutes = _time_to_minutes(slot_start)
        active[(team_id, venue_id, day_of_week)].append(start_minutes)

    slots: list[dict[str, Any]] = []
    for (team_id, venue_id, day_of_week), starts in active.items():
        starts_sorted = sorted(starts)
        coach_id = _find_coach_for_team(model_data, team_id)

        # Merge consecutive variables into contiguous blocks.
        # Two variables are contiguous when the next start equals the end of the
        # current block (i.e., no gap between them regardless of duration).
        if not starts_sorted:
            continue
        block_start = starts_sorted[0]
        block_end = starts_sorted[0] + _slot_dur(venue_id, day_of_week, starts_sorted[0])

        for s in starts_sorted[1:]:
            if s == block_end:
                # contiguous — extend block
                block_end = s + _slot_dur(venue_id, day_of_week, s)
            else:
                # gap — emit previous block and start a new one
                duration = block_end - block_start
                slots.append(
                    {
                        "id": _slot_id(team_id, venue_id, day_of_week, _format_time(block_start)),
                        "teamId": team_id,
                        "venueId": venue_id,
                        "coachId": coach_id,
                        "dayOfWeek": day_of_week,
                        "startTime": _format_time(block_start),
                        "durationMinutes": duration,
                        "lockLevel": "NONE",
                        "temporaryLock": False,
                        "temporaryLockFor": None,
                        "temporaryMinSessionsOverride": None,
                        "pendingConstraintSuggestion": None,
                    }
                )
                block_start = s
                block_end = s + _slot_dur(venue_id, day_of_week, s)

        # Emit the last block
        duration = block_end - block_start
        slots.append(
            {
                "id": _slot_id(team_id, venue_id, day_of_week, _format_time(block_start)),
                "teamId": team_id,
                "venueId": venue_id,
                "coachId": coach_id,
                "dayOfWeek": day_of_week,
                "startTime": _format_time(block_start),
                "durationMinutes": duration,
                "lockLevel": "NONE",
                "temporaryLock": False,
                "temporaryLockFor": None,
                "temporaryMinSessionsOverride": None,
                "pendingConstraintSuggestion": None,
            }
        )
    return slots


def _slot_id(team_id: str, venue_id: str, day_of_week: int, start_time: str) -> str:
    return str(uuid.uuid5(uuid.NAMESPACE_URL, f"clubscheduler-slot:{team_id}:{venue_id}:{day_of_week}:{start_time}"))


def _generate_diagnostics(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
    fallback_used: bool = False,
) -> list[dict[str, Any]]:
    """Run post-solve checks and return manager-readable diagnostics."""
    diagnostics: list[dict[str, Any]] = []
    # ENG-22: per-team "unplaced because slots were occupied/incompatible" reasons only make
    # sense for a real solve (OPTIMAL/FEASIBLE). On INFEASIBLE the demand-vs-supply message
    # explains it; on UNKNOWN/timeout the solver simply didn't finish — claiming every slot
    # was "already occupied" would be a lie. _diagnose_conflicts owns those two cases.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        diagnostics.extend(_diagnose_unplaced(model_data, slots))
    diagnostics.extend(_diagnose_soft_lock_moved(model_data, slots))
    diagnostics.extend(_diagnose_coach_overload(model_data, slots))
    diagnostics.extend(_diagnose_session_below_effective_min(model_data, slots))
    diagnostics.extend(_diagnose_conflicts(model_data, solver_status, slots, slot_capacities=slot_capacities))
    diagnostics.extend(_diagnose_unused_slots(model_data, slots))
    if fallback_used:
        diagnostics.extend(_diagnose_coach_rest_days(model_data, slots))
    return diagnostics


def _diagnose_unplaced(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag teams that have no sessions in the final schedule (who + why)."""
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    placed_team_ids = {slot["teamId"] for slot in slots}

    # Total training-slot supply — used to distinguish "no slots at all" from
    # "slots exist but were all taken / incompatible".
    total_available_slots = sum(
        len(_collection(venue, "training_slots", "trainingSlots")) for venue in _collection(model_data, "venues")
    )
    teams_by_id = {
        str(_get(team, "id", "team_id", "teamId")): team
        for team in _collection(model_data, "teams")
        if _get(team, "id", "team_id", "teamId") is not None
    }
    # Which venues actually declare at least one slot (for forced-venue reason).
    venues_with_slots = {
        str(_get(venue, "id", "venue_id", "venueId"))
        for venue in _collection(model_data, "venues")
        if _collection(venue, "training_slots", "trainingSlots")
    }

    for team_id in _team_ids(model_data):
        if team_id in placed_team_ids:
            continue

        team_label = _label(team_id, team_names)
        team = teams_by_id.get(team_id)
        forced_venue_id = _get(team, "forced_venue_id", "forcedVenueId", default=None) if team is not None else None

        if total_available_slots == 0:
            reason = "aucun créneau d'entraînement n'est déclaré dans les gymnases."
            suggestions = ["Ajoutez des créneaux de disponibilité sur au moins un gymnase."]
        elif forced_venue_id is not None and str(forced_venue_id) not in venues_with_slots:
            venue_label = _label(forced_venue_id, venue_names)
            reason = (
                f"son gymnase imposé ({venue_label}) n'a aucun créneau disponible "
                "(gymnase fermé ou sans horaires déclarés)."
            )
            suggestions = [
                f"Ajoutez des créneaux au gymnase {venue_label}, ou retirez le gymnase imposé pour cette équipe.",
            ]
        else:
            reason = (
                "tous les créneaux compatibles étaient déjà occupés par des équipes plus "
                "prioritaires, ou en conflit avec ses contraintes (coach indisponible, "
                "gymnase fermé, jour interdit)."
            )
            suggestions = [
                "Ajoutez de la disponibilité de gymnase ou assouplissez une contrainte dure de cette équipe.",
                "Vérifiez que l'équipe dispose d'au moins un créneau réellement libre.",
            ]

        diagnostics.append(
            {
                "id": f"diag-unplaced-{team_id}",
                "type": "unplaced",
                "severity": "ERROR",
                "teamId": team_id,
                "message": f"L'équipe {team_label} n'a pas pu être placée : {reason}",
                "suggestions": suggestions,
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _unplaced_team_ids(model_data: Mapping[str, Any] | Any, slots: list[dict[str, Any]]) -> list[str]:
    placed_team_ids = {slot["teamId"] for slot in slots}
    return [team_id for team_id in sorted(_team_ids(model_data)) if team_id not in placed_team_ids]


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
            diagnostics.append(
                {
                    "id": f"diag-soft-moved-{team_id}-{day_of_week}-{start_time}",
                    "type": "soft_lock_moved",
                    "severity": "WARNING",
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
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _diagnose_coach_overload(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag coaches working more DAYS than their recommended maximum."""
    diagnostics: list[dict[str, Any]] = []
    coach_names = _coach_name_map(model_data)
    # ENG-24: the threshold (_coach_threshold = maxDaysOverride) is a number of DAYS, so
    # count distinct working days per coach — NOT 15-min blocks nor raw session counts
    # (two 90-min sessions on the same day = 1 day worked, not 12 blocks / 2 sessions).
    coach_days: dict[str, set[int]] = defaultdict(set)
    for slot in slots:
        coach_id = slot.get("coachId")
        if coach_id and slot.get("dayOfWeek") is not None:
            coach_days[coach_id].add(int(slot["dayOfWeek"]))

    for coach_id, days in coach_days.items():
        count = len(days)
        threshold = _coach_threshold(model_data, coach_id)
        if count > threshold:
            diagnostics.append(
                {
                    "id": f"diag-overload-{coach_id}",
                    "type": "coach_overload",
                    "severity": "WARNING",
                    "coachId": coach_id,
                    "message": (
                        f"Le coach {_label(coach_id, coach_names)} intervient sur {count} jours, "
                        f"au-dessus de la limite recommandée de {threshold} : "
                        "risque de fatigue ou de conflits d'agenda."
                    ),
                    "suggestions": [
                        "Répartissez certaines séances sur un autre coach.",
                        "Vérifiez le nombre de jours maximum dans le profil du coach.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _diagnose_session_below_effective_min(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn when a team's placed session units fall below its effective minimum."""
    diagnostics: list[dict[str, Any]] = []

    tier_min: dict[int, int] = {}
    for tier in _collection(model_data, "priorityTiers", "priority_tiers"):
        tid = _get(tier, "id")
        default_min = _get(tier, "defaultMinSessions", "default_min_sessions")
        if tid is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tid)] = int(default_min)

    for constraint in _collection(model_data, "constraints"):
        if not isinstance(constraint, Mapping):
            continue
        if constraint.get("type") != "PRIORITY_TIER":
            continue
        metadata = constraint.get("metadata") or {}
        tier_id = metadata.get("id")
        default_min = metadata.get("defaultMinSessions")
        if tier_id is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tier_id)] = int(default_min)

    # Count SESSIONS (one placed slot = one session), not 15-min units. The
    # comparison is against sessionsPerWeek / tier default_min, both expressed
    # in sessions — counting units (duration // 15) would make a single 90-min
    # session look like 6 and hide a genuinely missing session.
    placed_counts: dict[str, int] = defaultdict(int)
    for slot in slots:
        team_id = slot.get("teamId")
        if team_id:
            placed_counts[str(team_id)] += 1

    teams: dict[str, Any] = {}
    team_names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = str(_get(team, "id", "team_id", "teamId"))
        teams[team_id] = team
        team_names[team_id] = str(_get(team, "name", "team_name", default=team_id))

    for team_id, team in teams.items():
        spw_raw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        if spw_raw is None:
            continue
        spw = int(spw_raw)

        tier_id_raw = _get(team, "priority_tier_id", "priorityTierId", default=None)
        effective_min = spw
        if tier_id_raw is not None and tier_min:
            try:
                tier_key = int(tier_id_raw)
            except (TypeError, ValueError):
                tier_key = None
            if tier_key is not None and tier_key in tier_min:
                effective_min = min(spw, tier_min[tier_key])

        placed = placed_counts.get(team_id, 0)
        # Warn whenever fewer sessions were placed than the team REQUESTED
        # (sessionsPerWeek), even if its tier floor (effective_min) is met — the
        # manager still needs to know a requested session is missing. Below the
        # tier floor is the more severe case (the guaranteed minimum was missed).
        if placed < spw:
            team_name = team_names.get(team_id, team_id)
            below_floor = placed < effective_min
            severity = "ERROR" if below_floor else "WARNING"
            if below_floor:
                # "cible", not "garanti" : le minimum de séances est appliqué en
                # objectif soft (bonus), pas en plancher dur (ENG-18) — le solveur
                # ne le garantit pas, il le vise. Dire "garanti" serait faux.
                reason = f"en-dessous de son minimum cible de {effective_min} (créneaux de gymnase insuffisants)."
            else:
                reason = "faute de créneau de gymnase disponible."
            diagnostics.append(
                {
                    "id": f"diag-session-below-min-{team_id}",
                    "type": "session_below_effective_min",
                    "severity": severity,
                    "teamId": team_id,
                    "message": (
                        f"L'équipe {team_name} : {spw} séance(s) demandée(s) par semaine, "
                        f"seulement {placed} placée(s) — {reason}"
                    ),
                    "suggestions": [
                        "Ajoutez de la disponibilité de gymnase ou un créneau supplémentaire pour cette équipe.",
                        "Vérifiez le tier de priorité et le nombre de séances/semaine de l'équipe.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


def _diagnose_conflicts(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
) -> list[dict[str, Any]]:
    """Report infeasibility or detected double-bookings — who, when, why.

    ``slot_capacities`` maps ``(venue_id, day_of_week, start_time)`` to the
    maximum number of teams allowed simultaneously.  When provided, a venue
    booking is only flagged as a conflict when the number of teams exceeds the
    slot's declared capacity (supporting multi-team training slots with
    capacity > 1).  When absent, the legacy threshold of 1 is used.
    """
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    coach_names = _coach_name_map(model_data)

    if solver_status == cp_model.INFEASIBLE:
        diagnostics.append(
            {
                "id": "diag-infeasible",
                "type": "conflict",
                "severity": "ERROR",
                "message": _infeasible_message(model_data),
                "suggestions": [
                    "Assouplissez ou retirez une contrainte dure (jour/heure imposé, gymnase forcé).",
                    "Ajoutez de la disponibilité de gymnase ou un coach supplémentaire.",
                    "Vérifiez les créneaux verrouillés (LOCK) qui se chevauchent entre équipes.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # ENG-22: UNKNOWN / MODEL_INVALID — the solver stopped WITHOUT a solution and WITHOUT
        # proving infeasibility. Almost always the time budget ran out on a hard instance. Say
        # so explicitly, instead of a silent "failed" the manager cannot act on.
        diagnostics.append(
            {
                "id": "diag-timeout",
                "type": "conflict",
                "severity": "ERROR",
                "message": (
                    "Le solveur n'a pas trouvé de planning dans le temps imparti (problème trop "
                    "complexe). Aucune infaisabilité prouvée — une solution existe peut-être avec "
                    "plus de temps ou moins de contraintes."
                ),
                "suggestions": [
                    "Réduisez la taille du problème (équipes / gymnases) ou le nombre de contraintes.",
                    "Relancez la génération : le solveur peut aboutir sur un nouvel essai.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    _caps: dict[Any, int] = slot_capacities or {}

    # Post-solve safety check: venue over-capacity.
    venue_bookings: dict[tuple[str, int, str], list[str]] = defaultdict(list)
    venue_durations: dict[tuple[str, int, str], int] = {}
    for slot in slots:
        key = (slot["venueId"], slot["dayOfWeek"], slot["startTime"])
        venue_bookings[key].append(slot["teamId"])
        venue_durations[key] = max(venue_durations.get(key, 0), int(slot.get("durationMinutes") or 0))

    for (venue_id, day_of_week, start_time), booked in venue_bookings.items():
        # Distinct teams only: at a fixed (venue, day, start), the same team twice
        # is the duplicate-slot artifact, not over-capacity (audit ENG-09).
        team_ids = list(dict.fromkeys(booked))
        capacity = _caps.get((venue_id, day_of_week, start_time), 1)
        if len(team_ids) > capacity:
            when = f"{_day_label(day_of_week)} {_time_range(start_time, venue_durations.get((venue_id, day_of_week, start_time)))}"
            diagnostics.append(
                {
                    "id": f"diag-conflict-venue-{venue_id}-{day_of_week}-{start_time}",
                    "type": "conflict",
                    "severity": "ERROR",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": str(start_time)[:5],
                    "message": (
                        f"Le gymnase {_label(venue_id, venue_names)} accueille {len(team_ids)} équipes "
                        f"en même temps le {when} alors que sa capacité est de {capacity} : "
                        f"{_named_list(team_ids, team_names)}."
                    ),
                    "suggestions": [
                        "Déplacez l'une des séances sur un autre horaire ou un autre gymnase.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    # Post-solve safety check: coach double-booking.
    # Dedupe by (team, venue) — NOT team alone: the coach key excludes venue, so
    # the SAME team in two different gyms at once with one coach is a REAL
    # conflict (the coach can't be in two places). Only a same-team+same-venue
    # repeat (the duplicate-template artifact) must collapse.
    coach_bookings: dict[tuple[str, int, str], list[tuple[str, str]]] = defaultdict(list)
    coach_durations: dict[tuple[str, int, str], int] = {}
    for slot in slots:
        coach_id = slot.get("coachId")
        if not coach_id:
            continue
        key = (coach_id, slot["dayOfWeek"], slot["startTime"])
        coach_bookings[key].append((str(slot["teamId"]), str(slot["venueId"])))
        coach_durations[key] = max(coach_durations.get(key, 0), int(slot.get("durationMinutes") or 0))

    for (coach_id, day_of_week, start_time), coach_booked in coach_bookings.items():
        distinct: list[tuple[str, str]] = list(dict.fromkeys(coach_booked))
        team_ids = [team for team, _venue in distinct]
        if len(distinct) > 1:
            when = f"{_day_label(day_of_week)} {_time_range(start_time, coach_durations.get((coach_id, day_of_week, start_time)))}"
            diagnostics.append(
                {
                    "id": f"diag-conflict-coach-{coach_id}-{day_of_week}-{start_time}",
                    "type": "conflict",
                    "severity": "ERROR",
                    "coachId": coach_id,
                    "dayOfWeek": day_of_week,
                    "startTime": str(start_time)[:5],
                    "message": (
                        f"Le coach {_label(coach_id, coach_names)} est affecté à plusieurs équipes "
                        f"en même temps le {when} : {_named_list(team_ids, team_names)}."
                    ),
                    "suggestions": [
                        "Séparez les séances ou affectez un autre coach à l'une des équipes.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


def _infeasible_message(model_data: Mapping[str, Any] | Any) -> str:
    """Explain infeasibility in manager terms, hinting at capacity shortfall."""
    demand = 0
    for team in _collection(model_data, "teams"):
        spw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        try:
            demand += int(spw) if spw is not None else 0
        except (TypeError, ValueError):
            continue
    supply = sum(
        len(_collection(venue, "training_slots", "trainingSlots")) for venue in _collection(model_data, "venues")
    )

    base = (
        "Le planning n'a pas pu être généré : les contraintes actuelles sont impossibles à satisfaire toutes ensemble."
    )
    if demand and supply and demand > supply:
        return (
            f"{base} Il faut placer {demand} séance(s) par semaine pour seulement "
            f"{supply} créneau(x) de gymnase déclaré(s) : la capacité est insuffisante."
        )
    return (
        f"{base} Aucune affectation valide n'existe — cherchez des contraintes dures "
        "qui se contredisent (jour/heure imposés, gymnase forcé, créneaux verrouillés)."
    )


_DAY_NAMES = {
    0: "Sunday",
    1: "Monday",
    2: "Tuesday",
    3: "Wednesday",
    4: "Thursday",
    5: "Friday",
    6: "Saturday",
}


def _diagnose_unused_slots(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn about available training slots that received no team assignment.

    Only slots that were *available* (declared in ``venues[].trainingSlots``)
    but not used by any placed session are reported. Venue closures and coach
    unavailability are excluded because those slots are not in the available
    set the solver could use.
    """
    diagnostics: list[dict[str, Any]] = []

    used: set[tuple[str, int, str]] = {
        (str(slot["venueId"]), int(slot["dayOfWeek"]), str(slot["startTime"])) for slot in slots
    }

    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id"))
        venue_name = str(_get(venue, "name", default=venue_id))
        for ts in _collection(venue, "training_slots", "trainingSlots"):
            day_of_week = int(_get(ts, "day_of_week", "dayOfWeek"))
            start_time = str(_get(ts, "start_time", "startTime"))
            duration = int(_get(ts, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))

            if (venue_id, day_of_week, start_time) in used:
                continue

            start_minutes = _time_to_minutes(start_time)
            end_minutes = start_minutes + duration
            end_time = _format_time(end_minutes)
            day_name = _DAY_NAMES.get(day_of_week, str(day_of_week))

            diagnostics.append(
                {
                    "id": f"diag-unused-slot-{venue_id}-{day_of_week}-{start_time}",
                    "type": "unused_slot",
                    "severity": "WARNING",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": start_time,
                    "durationMinutes": duration,
                    "message": f"{venue_name} {day_name} {start_time}-{end_time}: no team assigned",
                    "suggestions": [],
                    "teamId": None,
                    "coachId": None,
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


def _diagnose_coach_rest_days(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Emit a WARNING for each coach who has coaching assignments on all 5 weekdays (Mon-Fri).

    Only called when the fallback pass was used (``fallback_used=True``), meaning
    the coach rest day constraint was dropped to achieve feasibility.
    """
    diagnostics: list[dict[str, Any]] = []

    coach_names: dict[str, str] = {}
    for coach in _collection(model_data, "coaches"):
        coach_id = _get(coach, "id", "coach_id", "coachId")
        if coach_id is not None:
            coach_names[str(coach_id)] = str(_get(coach, "name", "coach_name", default=str(coach_id)))

    coach_days: dict[str, set[int]] = defaultdict(set)
    for slot in slots:
        coach_id = slot.get("coachId")
        if not coach_id:
            continue
        day = slot.get("dayOfWeek")
        if day is not None and int(day) in range(1, 6):
            coach_days[str(coach_id)].add(int(day))

    for coach_id, days in coach_days.items():
        if len(days) == 5:
            name = coach_names.get(coach_id, coach_id)
            diagnostics.append(
                {
                    "id": f"diag-coach-no-rest-day-{coach_id}",
                    "type": "coach_no_rest_day",
                    "severity": "WARNING",
                    "coachId": coach_id,
                    "message": f"Coach {name} n'a pas de jour de repos (lundi-vendredi)",
                    "suggestions": [
                        "Reduce the number of sessions assigned to this coach.",
                        "Add coach unavailability constraints for at least one weekday.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_FR_DAYS = {
    1: "lundi",
    2: "mardi",
    3: "mercredi",
    4: "jeudi",
    5: "vendredi",
    6: "samedi",
    7: "dimanche",
}


def _day_label(day_of_week: Any) -> str:
    """Human day name (French). Falls back to 'jour N' for unknown values."""
    try:
        return _FR_DAYS.get(int(day_of_week), f"jour {int(day_of_week)}")
    except (TypeError, ValueError):
        return f"jour {day_of_week}"


def _time_range(start_time: str, duration_minutes: int | None) -> str:
    """Return 'HH:MM–HH:MM' from a start time and duration (start only if unknown)."""
    start = str(start_time)[:5]
    if not duration_minutes:
        return start
    try:
        end = _format_time(_time_to_minutes(start) + int(duration_minutes))
    except (TypeError, ValueError):
        return start
    return f"{start}–{end}"


def _team_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            names[str(team_id)] = str(_get(team, "name", "team_name", default=str(team_id)))
    return names


def _venue_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for venue in _collection(model_data, "venues"):
        venue_id = _get(venue, "id", "venue_id", "venueId")
        if venue_id is not None:
            names[str(venue_id)] = str(_get(venue, "name", default=str(venue_id)))
    return names


def _coach_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for coach in _collection(model_data, "coaches"):
        coach_id = _get(coach, "id", "coach_id", "coachId")
        if coach_id is None:
            continue
        full = _get(coach, "name", "coach_name", default=None)
        if full is None:
            first = _get(coach, "first_name", "firstName", default="")
            last = _get(coach, "last_name", "lastName", default="")
            full = f"{first} {last}".strip() or str(coach_id)
        names[str(coach_id)] = str(full)
    return names


def _label(entity_id: Any, names: Mapping[str, str]) -> str:
    """'Name' when known, else the raw id — never bare so the manager can act."""
    return names.get(str(entity_id), str(entity_id))


def _named_list(ids: list[str], names: Mapping[str, str]) -> str:
    return ", ".join(_label(i, names) for i in ids)


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
    return 10**9

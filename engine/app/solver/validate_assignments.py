from __future__ import annotations

import logging
from typing import Any, cast

from ortools.sat.python import cp_model

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.constraints import (
    ParsedConstraints,
    add_level_1_hard_constraints,
    add_time_window_constraints,
    diagnose_candidate_conflicts,
    parse_v2_constraints,
    resolve_implicit_rules,
)
from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    HARD_LOCK_LEVEL,
    ScheduleCpModel,
    SlotKey,
    _format_time,
    _time_to_minutes,
    build_model,
)

logger = logging.getLogger("engine.validate_assignments")


def _coach_label(coach: dict[str, Any]) -> str:
    first = str(coach.get("first_name") or coach.get("firstName") or "").strip()
    last = str(coach.get("last_name") or coach.get("lastName") or "").strip()
    full = f"{first} {last}".strip()
    return full or str(coach.get("id"))


def _build_assignments(
    model: ScheduleCpModel,
    team_coach_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
) -> list[dict[str, Any]]:
    """Assignments over the full model.x — identical shape to ``main._solve`` —
    with ``fixed=True`` on the frozen baseline (consumed by ``add_fixed_slots``)."""
    assignments: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        team_id_str = str(slot_key[0])
        venue_id_str = str(slot_key[1])
        day_of_week = slot_key[2]
        slot_start = slot_key[3]
        vsk = (venue_id_str, day_of_week, slot_start)
        duration = model.slot_durations.get(vsk, DEFAULT_SESSION_MINUTES)
        start_minutes = _time_to_minutes(slot_start)
        team_coaches = team_coach_map.get(team_id_str) or []
        assignments.append(
            {
                "var": var,
                "team_id": team_id_str,
                "venue_id": venue_id_str,
                "slot_id": f"{day_of_week}:{slot_start}",
                "start": start_minutes,
                "end": start_minutes + duration,
                "coach_id": team_coaches[0] if team_coaches else None,
                "fixed": slot_key in frozen_keys,
            }
        )
    return assignments


def _apply_hard(
    model: ScheduleCpModel,
    assignments: list[dict[str, Any]],
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
) -> None:
    """The generation model's HARD layer, minus objective and session caps —
    ``add_fixed_slots`` (inside) freezes the baseline; nothing here relaxes.

    Parité génération ⇄ verdict : le même réglage ``implicitRules`` s'applique. Un cran
    HARD bloque le déplacement qui le casse ; un cran PREFERRED ne bloque pas (ses
    littéraux de violation sont posés mais sans objectif ici — feasibility check seul)."""
    min_by_team: dict[str, int] = {str(t.get("id")): 0 for t in data.get("teams", []) if t.get("id")}
    add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        forbidden_assignments=parsed["forbidden_assignments"],
        coach_unavailability=parsed["coach_unavailability"],
        forced_venues=parsed["forced_venues"],
        priority_tiers=parsed.get("priority_tiers", {}),
        min_sessions_by_team=min_by_team or None,
        implicit_rules=resolve_implicit_rules(data.get("implicitRules")),
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
    )
    add_time_window_constraints(model, model.x, parsed["time_windows"])


def _solve(model: ScheduleCpModel, *, timeout_seconds: int, seed: int) -> tuple[int, cp_model.CpSolver]:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(timeout_seconds)
    # Mono-candidat, baseline entierement figee : pas de portefeuille, 1 worker
    # rend le verdict reproductible d'un appel a l'autre sur la meme entree.
    solver.parameters.num_search_workers = 1
    solver.parameters.random_seed = seed
    return solver.Solve(model), solver


def _baseline_is_feasible(
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    *,
    timeout_seconds: int,
    seed: int,
) -> bool:
    """Le planning courant, FIGE mais SANS le candidat, est-il faisable pour le
    moteur ? Utilise seulement sur le chemin rare « infaisable + rien de nomme »
    pour distinguer un candidat fautif d'une baseline deja invalide (condition
    d'arret fondateur : figer un planning pourtant valide ne doit pas conclure
    « non » a tout)."""
    model = build_model(data)
    model.team_coach_map = team_coach_map
    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)
    status, _ = _solve(model, timeout_seconds=timeout_seconds, seed=seed)
    return status in (cp_model.OPTIMAL, cp_model.FEASIBLE)


def validate_assignment(
    input_data: ValidateAssignmentsInputSchema,
    *,
    contract_version: str | None = None,
) -> dict[str, Any]:
    """Verdict moteur sur UN candidat de deplacement (P2-2 F2a).

    Le reste du planning est FIGE via ``add_fixed_slots`` ; on epingle le candidat
    et on demande au moteur si le modele HARD reste faisable. La reponse booleenne
    vient donc du SOLVEUR (« ce que le solveur applique vraiment ») ; les regles
    cassees sont ensuite NOMMEES pour l'UI. Sans le gel de baseline, le solveur
    pourrait tout redeplacer et le verdict ne voudrait plus rien dire.
    """
    data: dict[str, Any] = input_data.model_dump(by_alias=True)
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    model = build_model(data)
    model.team_coach_map = team_coach_map

    candidate = input_data.candidate
    c_team = str(candidate.team_id)
    c_venue = str(candidate.venue_id)
    c_day = int(candidate.day_of_week)
    c_start_min = _time_to_minutes(candidate.start_time)
    c_start_text = _format_time(c_start_min)
    c_end_min = c_start_min + int(candidate.duration_minutes)
    candidate_key: SlotKey = (c_team, c_venue, c_day, c_start_text)

    team_names = {str(t.get("id")): str(t.get("name") or t.get("id")) for t in data.get("teams", [])}
    coach_names = {str(c.get("id")): _coach_label(c) for c in data.get("coaches", [])}
    venue_names = {str(v.get("id")): str(v.get("name") or v.get("id")) for v in data.get("venues", [])}

    # Baseline: the current schedule. HARD locks stay pre-placed occupancy (as in
    # /generate); every non-HARD placement whose slot has a variable is FROZEN.
    # baseline_slots (for the naming layer) carries ALL current placements — a
    # candidate clashing with a locked session's coach must still be named.
    frozen_keys: set[SlotKey] = set()
    baseline_slots: list[dict[str, Any]] = []
    for tmpl in data.get("slotTemplates", []) or []:
        t_team = str(tmpl.get("teamId") or tmpl.get("team_id") or "")
        t_venue = str(tmpl.get("venueId") or tmpl.get("venue_id") or "")
        t_day = int(tmpl.get("dayOfWeek") or tmpl.get("day_of_week") or 0)
        t_start_min = _time_to_minutes(tmpl.get("startTime") or tmpl.get("start_time"))
        t_start_text = _format_time(t_start_min)
        t_duration = int(tmpl.get("durationMinutes") or tmpl.get("duration_minutes") or DEFAULT_SESSION_MINUTES)
        baseline_slots.append(
            {
                "team_id": t_team,
                "venue_id": t_venue,
                "day": t_day,
                "start": t_start_min,
                "end": t_start_min + t_duration,
                "start_time": t_start_text,
            }
        )
        lock_level = str(tmpl.get("lockLevel") or tmpl.get("lock_level") or "").upper()
        if lock_level != HARD_LOCK_LEVEL:
            key: SlotKey = (t_team, t_venue, t_day, t_start_text)
            if key in model.x:
                frozen_keys.add(key)

    metrics = {
        "solver_version": "cp-sat",
        "nb_variables": 0,
        "nb_constraints": 0,
        "wall_time_ms": 0,
        "constraint_version": contract_version,
    }

    # The target must be a real, currently-empty slot: no variable = it is not an
    # available training slot, or a HARD lock already holds it. Either way the move
    # is impossible — a named verdict, no solve needed.
    if candidate_key not in model.x:
        return {
            "valid": False,
            "violations": [
                {
                    "rule": "slot_unavailable",
                    "message": (
                        f"{venue_names.get(c_venue, c_venue)} à {c_start_text} n'est pas un créneau "
                        f"libre pour {team_names.get(c_team, c_team)} (créneau inexistant ou déjà verrouillé)."
                    ),
                    "team_id": c_team,
                    "venue_id": c_venue,
                    "day_of_week": c_day,
                    "start_time": c_start_text,
                }
            ],
            "metrics": metrics,
        }

    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    # Le candidat est epingle SEPAREMENT du gel de baseline (model.Add, pas
    # fixed=True) : neutraliser le gel libere le reste du planning MAIS garde le
    # candidat epingle — sans quoi le solveur mettrait tout a 0, verdict toujours
    # « valide » (falsification 2).
    cast(Any, model).Add(model.x[candidate_key] == 1)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)

    status, solver = _solve(model, timeout_seconds=input_data.solver_timeout_seconds, seed=input_data.solver_seed)
    valid = status in (cp_model.OPTIMAL, cp_model.FEASIBLE)

    metrics["nb_variables"] = model.NumVariables()
    metrics["nb_constraints"] = len(model.Proto().constraints)
    metrics["wall_time_ms"] = int(solver.wall_time * 1000)

    violations: list[dict[str, Any]] = []
    if not valid:
        violations = diagnose_candidate_conflicts(
            candidate={
                "team_id": c_team,
                "venue_id": c_venue,
                "day": c_day,
                "start": c_start_min,
                "end": c_end_min,
                "start_time": c_start_text,
            },
            baseline_slots=baseline_slots,
            parsed=parsed,
            coaches=data.get("coaches", []),
            slot_capacities=model.slot_capacities,
            team_names=team_names,
            coach_names=coach_names,
            venue_names=venue_names,
        )
        if not violations:
            # Infaisable, mais aucun mirror n'a su l'attribuer : distinguer une
            # baseline deja invalide (condition d'arret) d'un conflit HARD reel
            # mais non nomme — jamais un « non » nu.
            baseline_ok = _baseline_is_feasible(
                data,
                parsed,
                team_coach_map,
                team_player_map,
                frozen_keys,
                timeout_seconds=input_data.solver_timeout_seconds,
                seed=input_data.solver_seed,
            )
            if not baseline_ok:
                violations = [
                    {
                        "rule": "baseline_infeasible",
                        "message": (
                            "le planning courant est déjà infaisable pour le moteur : le verdict ne "
                            "peut rien conclure sur ce déplacement."
                        ),
                    }
                ]
            else:
                violations = [
                    {
                        "rule": "unknown_hard_conflict",
                        "message": "ce déplacement casse une règle du moteur qui n'a pas pu être nommée.",
                    }
                ]

    logger.info(
        "validate club=%s team=%s -> %s valid=%s violations=%d",
        input_data.club_id,
        c_team,
        solver.status_name(status),
        valid,
        len(violations),
    )

    return {"valid": valid, "violations": violations, "metrics": metrics}

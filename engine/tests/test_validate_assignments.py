"""P2-2 F2a — verdict moteur sur un candidat de deplacement (mono-candidat).

Le SOLVE (baseline figee via add_fixed_slots + candidat epingle) rend le verdict
booleen ; le socle ici garde la faisabilite d'un deplacement legitime, le
determinisme (mono-candidat, 1 worker) et le cas « creneau cible impossible ».
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_team, make_venue, team_coach


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))


def _base_payload(*, candidate: dict[str, Any], slot_templates: list[dict[str, Any]], **over: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": over.get(
            "venues",
            [make_venue("A", [(4, "18:00"), (4, "20:00")]), make_venue("B", [(4, "20:00")])],
        ),
        "teams": over.get("teams", [make_team("U13"), make_team("U15")]),
        "coaches": over.get("coaches", []),
        "constraints": over.get("constraints", []),
        "slotTemplates": slot_templates,
        "candidate": candidate,
    }
    return payload


def test_empty_free_slot_is_valid() -> None:
    """U13 vers un creneau vide sans aucun conflit -> valide, zero violation."""
    result = _run(
        _base_payload(
            slot_templates=[],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is True
    assert result["violations"] == []


def test_verdict_is_deterministic() -> None:
    """Meme entree, meme verdict d'un appel a l'autre (pas de portefeuille)."""
    payload = _base_payload(
        slot_templates=[
            {"id": "s1", "teamId": "U15", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        ],
        candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        constraints=[team_coach("tc13", "U13", "C"), team_coach("tc15", "U15", "C")],
    )
    first = _run(payload)
    second = _run(payload)
    assert first["valid"] == second["valid"]
    assert first["violations"] == second["violations"]


def test_target_slot_that_does_not_exist_is_named() -> None:
    """Un creneau cible inexistant (aucune variable) -> non, motif slot_unavailable."""
    result = _run(
        _base_payload(
            slot_templates=[],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is False
    assert [v["rule"] for v in result["violations"]] == ["slot_unavailable"]


def test_full_capacity_slot_is_refused_and_named() -> None:
    """Creneau capacite 1 deja occupe par une autre equipe -> venue_capacity."""
    result = _run(
        _base_payload(
            venues=[make_venue("A", [(4, "20:00")], capacity=1)],
            slot_templates=[
                {
                    "id": "s1",
                    "teamId": "U15",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            ],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is False
    assert "venue_capacity" in {v["rule"] for v in result["violations"]}

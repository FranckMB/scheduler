"""NR — plusieurs gymnases préférés par équipe (PR B, retour fondateur 2026-08-06).

« Quand je suis à Jean Vilar, Camus, Tonkin ou l'Annexe, je veux que les créneaux
soient privilégiés » : les règles PREFERRED d'une même équipe se CUMULENT en un
ensemble (bonus si la séance tombe dans l'un d'eux). L'ancien parse était
last-wins : seule la DERNIÈRE règle donnait un bonus, les autres mouraient avec
un simple INFO — le planning ignorait des préférences saisies.

Les règles DURES (`forced_venues`) restent mono-gymnase : deux « impose » sur la
même équipe sont une contradiction, toujours signalée (témoin ci-dessous).
"""

from __future__ import annotations

from typing import Any

from app.solver.constraints import parse_v2_constraints
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint


def _venues_of(result: dict[str, Any], team_id: str) -> set[str]:
    return {slot["venueId"] for slot in result["slots"] if slot["teamId"] == team_id}


def _preferred(constraint_id: str, team_id: str, venue_id: str) -> dict[str, Any]:
    return team_constraint(
        constraint_id=constraint_id, team_id=team_id, family="FACILITY",
        rule_type="PREFERRED", config={"preferredVenueId": venue_id},
    )


def test_two_preferred_venues_accumulate_into_a_set() -> None:
    constraints = [_preferred("c1", "t1", "v1"), _preferred("c2", "t1", "v2")]

    parsed = parse_v2_constraints(constraints)

    assert parsed["preferred_venues"] == {"t1": {"v1", "v2"}}
    # Deux préférences ne sont PAS une contradiction : aucun INFO « la dernière
    # remplace la précédente » ne doit sortir (c'était le symptôme fondateur).
    assert parsed["parse_warnings"] == []


def test_conflicting_hard_venues_still_warn() -> None:
    # Témoin : le last-wins + warning reste la sémantique des règles DURES.
    hard = [
        team_constraint(constraint_id="c1", team_id="t1", family="FACILITY",
                        rule_type="HARD", config={"preferredVenueId": "v1"}),
        team_constraint(constraint_id="c2", team_id="t1", family="FACILITY",
                        rule_type="HARD", config={"preferredVenueId": "v2"}),
    ]

    parsed = parse_v2_constraints(hard)

    assert parsed["forced_venues"] == {"t1": "v2"}
    assert any("la dernière remplace" in w["message"] for w in parsed["parse_warnings"])


def test_sessions_land_in_the_preferred_set() -> None:
    # 3 gymnases, 1 créneau chacun ; l'équipe joue 2 séances et préfère v1 ET v2 :
    # les deux séances tombent dans {v1, v2}, jamais à v3. Témoin : sans préférence,
    # rien n'empêche v3 (mêmes créneaux offerts) — le bonus est bien la cause.
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=2)],
        venues=[
            make_venue("v1", [(1, "18:00")]),
            make_venue("v2", [(3, "18:00")]),
            make_venue("v3", [(5, "18:00")]),
        ],
        constraints=[_preferred("c1", "t1", "v1"), _preferred("c2", "t1", "v2")],
    )

    result = solve_payload(payload)

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    venues = _venues_of(result, "t1")
    assert venues == {"v1", "v2"}, f"les séances doivent tomber dans l'ensemble préféré, obtenu : {venues}"
    # Aucun INFO de remplacement sur des préférences cumulées.
    assert not any(
        "la dernière remplace" in d["message"] for d in result.get("diagnostics") or []
    ), "deux PREFERRED ne doivent plus émettre le diagnostic de remplacement"

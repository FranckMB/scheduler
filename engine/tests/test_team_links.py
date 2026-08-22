"""Lot PASSERELLES PR-1 — le bloc `teamLinks` VOYAGE jusqu'au moteur et y est ACCEPTÉ,
mais il n'est PAS consommé (la consommation est PR-2).

Preuve d'inertie (patron `sharedTrainings`/`previousAssignments`) : un payload PORTANT des
passerelles produit exactement le même planning et le même score qu'un payload SANS — aucun
`y` posé, goldens et score inchangés. Et un bloc absent/vide ⇒ chemin byte-identique.

Deux directions de garde :
  * ACCEPTÉ : un payload avec `teamLinks` (des deux intensités) valide et solve sans erreur ;
  * INERTE : le résultat est identique, octet pour octet, à celui sans le bloc.
"""

from __future__ import annotations

from typing import Any

from app.schemas.input_schema import ScheduleInputSchema
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _link(link_id: str, team_a: str, team_b: str, intensity: str) -> dict[str, Any]:
    return {"id": link_id, "teamAId": team_a, "teamBId": team_b, "intensity": intensity}


def _fixture() -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
    venues = [make_venue("vA", [(1, "18:00"), (2, "18:00")], capacity=2)]
    return teams, venues


class TestSchemaAccepts:
    def test_a_payload_with_team_links_validates(self) -> None:
        teams, venues = _fixture()
        payload = make_payload(teams=teams, venues=venues)
        payload["teamLinks"] = [
            _link("l1", "t1", "t2", "PREFERRED"),
            _link("l2", "t1", "t2", "MANDATORY"),
        ]
        parsed = ScheduleInputSchema.model_validate(payload)
        assert len(parsed.team_links) == 2
        assert parsed.team_links[0].intensity == "PREFERRED"
        assert parsed.team_links[1].intensity == "MANDATORY"

    def test_intensity_defaults_to_preferred_when_absent(self) -> None:
        parsed = ScheduleInputSchema.model_validate(
            make_payload(teams=_fixture()[0], venues=_fixture()[1])
            | {"teamLinks": [{"id": "l", "teamAId": "t1", "teamBId": "t2"}]}
        )
        assert parsed.team_links[0].intensity == "PREFERRED"


class TestInertness:
    def test_empty_team_links_block_matches_no_block(self) -> None:
        teams, venues = _fixture()
        without = solve_payload(make_payload(teams=teams, venues=venues))
        with_empty_payload = make_payload(teams=teams, venues=venues)
        with_empty_payload["teamLinks"] = []
        with_empty = solve_payload(with_empty_payload)
        assert without["slots"] == with_empty["slots"]
        assert without["score"] == with_empty["score"]

    def test_populated_team_links_do_not_change_the_solve(self) -> None:
        teams, venues = _fixture()
        without = solve_payload(make_payload(teams=teams, venues=venues))
        with_links_payload = make_payload(teams=teams, venues=venues)
        with_links_payload["teamLinks"] = [
            _link("l1", "t1", "t2", "PREFERRED"),
            _link("l2", "t1", "t2", "MANDATORY"),
        ]
        with_links = solve_payload(with_links_payload)
        # Inertie stricte : même planning, même score qu'en l'absence du bloc (PR-1).
        assert without["slots"] == with_links["slots"]
        assert without["score"] == with_links["score"]

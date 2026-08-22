"""NR (axe §7.1 constraint semantics) — une passerelle PREFERRED ne SUPPRIME jamais une séance.

Le malus de chevauchement d'une passerelle PREFERRED (``TEAM_LINK_TIER_WEIGHTS``, ≤ 8) est
volontairement SOUS le plancher d'une séance nue (tier D 1 + session_count 20 = 21), et le
plancher réel d'une suppression sous quota est ``missing_session`` (−1000). Le solveur SÉPARE
quand il peut, mais préfère TOUJOURS un chevauchement pénalisé à un trou.

Deux gardes falsifiables :
  * un cas déterministe PIRE-CAS où séparer est IMPOSSIBLE (une seule case partageable) : le
    malus est inévitable, et pourtant aucune séance ne tombe — même empilé (plusieurs passerelles
    sur la même séance) ;
  * un invariant hypothesis : sur des grilles aléatoires, le nombre de séances placées est
    IDENTIQUE avec et sans passerelles PREFERRED (elles ne déplacent que des ex æquo, jamais un
    volume).
"""

from __future__ import annotations

from collections import Counter
from typing import Any

from hypothesis import given, settings
from hypothesis import strategies as st

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _link(link_id: str, team_a: str, team_b: str) -> dict[str, Any]:
    return {"id": link_id, "teamAId": team_a, "teamBId": team_b, "intensity": "PREFERRED"}


def _placed_by_team(result: dict[str, Any]) -> Counter[str]:
    return Counter(str(s["teamId"]) for s in result["slots"])


def test_preferred_penalty_never_drops_a_session_when_separation_is_impossible() -> None:
    """PIRE-CAS déterministe : une SEULE case (cap 2) pour deux équipes tier S liées PREFERRED.

    Séparer est impossible (aucune autre fenêtre) ; le malus tier S (8, le plus fort) est donc
    inévitable. Le solveur GARDE les deux séances (co-localisées, malus payé) plutôt que d'en
    supprimer une. Falsifié à la main : un poids > 21 + 1000 ferait tomber une séance."""
    teams = [
        make_team("t1", sessions_per_week=1, priority_tier_id=1),
        make_team("t2", sessions_per_week=1, priority_tier_id=1),
    ]
    venues = [make_venue("v", [(1, "18:00")], capacity=2)]
    payload = make_payload(teams=teams, venues=venues)
    payload["teamLinks"] = [_link("l", "t1", "t2")]
    result = solve_payload(payload, timeout=15)
    assert result["status"] == "completed"
    placed = _placed_by_team(result)
    assert placed["t1"] == 1 and placed["t2"] == 1, f"aucune séance ne doit tomber sous le malus; got {placed}"


def test_stacked_preferred_penalties_never_drop_a_session() -> None:
    """EMPILEMENT : trois équipes tier D deux-à-deux liées PREFERRED sur une seule case (cap 3).

    La séance de chaque équipe porte alors DEUX chevauchements pénalisés ; l'empilement reste très
    sous le plancher ``missing_session``. Les trois séances tiennent (le résiduel k≥2 de la preuve
    d'empilement de ``TEAM_LINK_TIER_WEIGHTS``)."""
    teams = [make_team(f"t{i}", sessions_per_week=1, priority_tier_id=5) for i in (1, 2, 3)]
    venues = [make_venue("v", [(1, "18:00")], capacity=3)]
    payload = make_payload(teams=teams, venues=venues)
    payload["teamLinks"] = [
        _link("l12", "t1", "t2"),
        _link("l13", "t1", "t3"),
        _link("l23", "t2", "t3"),
    ]
    result = solve_payload(payload, timeout=15)
    assert result["status"] == "completed"
    placed = _placed_by_team(result)
    assert placed["t1"] == 1 and placed["t2"] == 1 and placed["t3"] == 1, (
        f"empilement ne doit rien supprimer; got {placed}"
    )


@settings(max_examples=15, deadline=None)
@given(
    n_teams=st.integers(min_value=2, max_value=4),
    n_days=st.integers(min_value=1, max_value=3),
    capacity=st.integers(min_value=1, max_value=2),
    link_pairs=st.lists(st.tuples(st.integers(0, 3), st.integers(0, 3)), min_size=1, max_size=3),
)
def test_preferred_links_never_change_the_placed_count(
    n_teams: int, n_days: int, capacity: int, link_pairs: list[tuple[int, int]]
) -> None:
    """Invariant : le VOLUME placé est identique avec et sans passerelles PREFERRED.

    Une passerelle PREFERRED n'arbitre que des ex æquo (elle sépare quand c'est GRATUIT) ; elle ne
    peut jamais faire placer MOINS de séances. On compare le total placé des deux solves."""
    teams = [make_team(f"t{i}", sessions_per_week=1, priority_tier_id=3) for i in range(n_teams)]
    slots = [(d, "18:00") for d in range(1, n_days + 1)]
    venues = [make_venue("v", slots, capacity=capacity)]

    without = solve_payload(make_payload(teams=teams, venues=venues), timeout=15)
    if without["status"] != "completed":
        return  # scénario infaisable pour une raison étrangère aux passerelles — rien à comparer.

    links = []
    for idx, (a, b) in enumerate(link_pairs):
        if a != b and a < n_teams and b < n_teams:
            links.append(_link(f"l{idx}", f"t{a}", f"t{b}"))

    payload = make_payload(teams=teams, venues=venues)
    payload["teamLinks"] = links
    with_links = solve_payload(payload, timeout=15)
    assert with_links["status"] == "completed"
    assert len(with_links["slots"]) == len(without["slots"]), "une passerelle PREFERRED a changé le volume placé"

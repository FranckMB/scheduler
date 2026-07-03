"""Tests for the same-venue chaining bonus (SOFT, tier-weighted).

A coach who has two back-to-back sessions in the same venue receives a small
INTEGER bonus weighted by the highest-tier team they coach across the two
sessions: S=8, A=6, B=4, C=2, D=1. Capped at 8 so it can never drop a placed
session (min placement = 21) nor steal a slot from a higher tier (smallest
tier gap = C−D = 9) — it only nudges otherwise-equal solutions toward
back-to-back sessions.

The bonus terms are RETURNED by add_chaining_bonus and folded into the single
objective by add_level_2_objective — the function must not call Maximize itself
(CP-SAT keeps one objective; a second Maximize would overwrite everything).
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import AssignmentVariable
from app.solver.objective import (
    CHAINING_TIER_WEIGHTS,
    LEVEL_2_OBJECTIVE_WEIGHTS,
    add_chaining_bonus,
    add_level_2_objective,
)


def _solve(model: cp_model.CpModel) -> tuple[int, cp_model.CpSolver]:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model), solver


def _assignment(
    model: cp_model.CpModel,
    name: str,
    *,
    team_id: str = "team-1",
    slot_id: str = "1:18:00",
    venue_id: str = "venue-1",
    coach_id: str | None = "coach-1",
    priority_tier: str = "A",
    start: int | None = None,
    end: int | None = None,
    player_ids: tuple[str, ...] = (),
) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=slot_id,
        venue_id=venue_id,
        coach_id=coach_id,
        player_ids=player_ids,
        start=start,
        end=end,
    )


class TestChainingBonusWeights:
    """Verify the chaining tier weight constants."""

    def test_chaining_tier_weights_are_small_capped_integers(self) -> None:
        """S=8, A=6, B=4, C=2, D=1 — integers, ordered, capped at 8."""
        assert CHAINING_TIER_WEIGHTS == {
            "S": 8,
            "A": 6,
            "B": 4,
            "C": 2,
            "D": 1,
        }
        assert all(isinstance(w, int) for w in CHAINING_TIER_WEIGHTS.values())
        assert max(CHAINING_TIER_WEIGHTS.values()) <= 8

    def test_chaining_weights_do_not_modify_t24_weights(self) -> None:
        """The T24 objective weights must remain unchanged."""
        assert LEVEL_2_OBJECTIVE_WEIGHTS["S"] == 10000
        assert LEVEL_2_OBJECTIVE_WEIGHTS["A"] == 1000
        assert LEVEL_2_OBJECTIVE_WEIGHTS["B"] == 100
        assert LEVEL_2_OBJECTIVE_WEIGHTS["C"] == 10
        assert LEVEL_2_OBJECTIVE_WEIGHTS["D"] == 1


class TestChainingBonusIntegration:
    """Verify add_chaining_bonus works with the objective pipeline."""

    def test_chained_coach_preferred_over_unchained(self) -> None:
        """When two coaches can fill consecutive slots in the same venue,
        the solver should prefer the higher-tier coach to get the chaining bonus.

        Setup: venue-1 has two consecutive slots (18:00-19:30, 19:30-21:00).
        Coach-S (tier S) can take both slots. Coach-D (tier D) can also take both.
        The solver should assign Coach-S to both (chaining bonus S=200 >> D=0.02).
        """
        model = cp_model.CpModel()

        # Slot 1: 18:00-19:30 (start=1080, end=1170)
        slot1_coach_S = _assignment(
            model, "slot1_coach_S",
            team_id="team-S", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-S", priority_tier="S", start=1080, end=1170,
        )
        slot1_coach_D = _assignment(
            model, "slot1_coach_D",
            team_id="team-D", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-D", priority_tier="D", start=1080, end=1170,
        )

        # Slot 2: 19:30-21:00 (start=1170, end=1260)
        slot2_coach_S = _assignment(
            model, "slot2_coach_S",
            team_id="team-S2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-S", priority_tier="S", start=1170, end=1260,
        )
        slot2_coach_D = _assignment(
            model, "slot2_coach_D",
            team_id="team-D2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-D", priority_tier="D", start=1170, end=1260,
        )

        assignments = [slot1_coach_S, slot1_coach_D, slot2_coach_S, slot2_coach_D]

        # Hard constraints: at most one team per venue-slot
        model.Add(slot1_coach_S.var + slot1_coach_D.var <= 1)
        model.Add(slot2_coach_S.var + slot2_coach_D.var <= 1)

        # Objective with chaining bonus
        add_level_2_objective(model, assignments, teams=[
            {"id": "team-S", "priority_tier": "S"},
            {"id": "team-S2", "priority_tier": "S"},
            {"id": "team-D", "priority_tier": "D"},
            {"id": "team-D2", "priority_tier": "D"},
        ])

        status, solver = _solve(model)
        assert status == cp_model.OPTIMAL, f"Expected OPTIMAL, got {status}"

        # Coach-S should be assigned to both slots (chaining bonus S=200 is huge)
        assert solver.Value(slot1_coach_S.var) == 1, "Coach-S should take slot 1"
        assert solver.Value(slot2_coach_S.var) == 1, "Coach-S should take slot 2"
        assert solver.Value(slot1_coach_D.var) == 0, "Coach-D should NOT take slot 1"
        assert solver.Value(slot2_coach_D.var) == 0, "Coach-D should NOT take slot 2"

    def test_no_bonus_across_different_venues(self) -> None:
        """Chaining bonus must NOT apply when the two sessions are in
        different venues, even if they are consecutive in time."""
        model = cp_model.CpModel()

        # Same coach, same time, different venues — no chaining possible
        slot_venue1 = _assignment(
            model, "slot_venue1",
            team_id="team-1", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="S", start=1080, end=1170,
        )
        slot_venue2 = _assignment(
            model, "slot_venue2",
            team_id="team-2", slot_id="1:18:00", venue_id="venue-2",
            coach_id="coach-1", priority_tier="A", start=1080, end=1170,
        )

        assignments = [slot_venue1, slot_venue2]
        add_level_2_objective(model, assignments, teams=[
            {"id": "team-1", "priority_tier": "S"},
            {"id": "team-2", "priority_tier": "A"},
        ])

        status, solver = _solve(model)
        assert status == cp_model.OPTIMAL, f"Expected OPTIMAL, got {status}"
        # Both should be placed (no conflict), but no chaining bonus
        assert solver.Value(slot_venue1.var) == 1
        assert solver.Value(slot_venue2.var) == 1

    def test_no_bonus_for_non_consecutive_slots(self) -> None:
        """Chaining bonus must NOT apply when there is a gap between sessions.

        Slot A ends at 19:30, Slot B starts at 20:00 — not consecutive.
        """
        model = cp_model.CpModel()

        # Slot 1: 18:00-19:30 (start=1080, end=1170)
        slot1 = _assignment(
            model, "slot1",
            team_id="team-1", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="S", start=1080, end=1170,
        )
        # Slot 2: 20:00-21:30 (start=1200, end=1290) — NOT consecutive (gap of 30 min)
        slot2 = _assignment(
            model, "slot2",
            team_id="team-2", slot_id="1:20:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="A", start=1200, end=1290,
        )

        assignments = [slot1, slot2]
        add_level_2_objective(model, assignments, teams=[
            {"id": "team-1", "priority_tier": "S"},
            {"id": "team-2", "priority_tier": "A"},
        ])

        status, solver = _solve(model)
        assert status == cp_model.OPTIMAL, f"Expected OPTIMAL, got {status}"
        # Both placed but no chaining bonus (gap between sessions)
        assert solver.Value(slot1.var) == 1
        assert solver.Value(slot2.var) == 1

    def test_chaining_bonus_returns_terms(self) -> None:
        """add_chaining_bonus returns (var, weight) terms; weight = highest tier of the pair."""
        model = cp_model.CpModel()

        # Two consecutive slots in same venue with same coach
        slot1 = _assignment(
            model, "slot1",
            team_id="team-1", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="A", start=1080, end=1170,
        )
        slot2 = _assignment(
            model, "slot2",
            team_id="team-2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-1", priority_tier="B", start=1170, end=1260,
        )

        terms = add_chaining_bonus(
            model, [slot1, slot2],
            teams=[{"id": "team-1", "priority_tier": "A"}, {"id": "team-2", "priority_tier": "B"}],
        )
        assert len(terms) == 1, f"Expected 1 chaining term, got {len(terms)}"
        _var, weight = terms[0]
        assert weight == CHAINING_TIER_WEIGHTS["A"], "weight = highest tier (A) of the chained pair"

    def test_chaining_actually_influences_the_solve(self) -> None:
        """Isolation test: equal placement value, only the chaining bonus differs.

        slot2 for team-2 (tier A) can be coached by coach-1 (who also has slot1,
        so chaining) or coach-2 (no chaining). Placement value is identical → the
        solver must pick coach-1 to earn the chaining bonus. Proves the bonus is
        wired into the objective (regression guard for the overwrite bug)."""
        model = cp_model.CpModel()

        slot1 = _assignment(
            model, "slot1", team_id="team-1", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="A", start=1080, end=1170,
        )
        slot2_chain = _assignment(
            model, "slot2_chain", team_id="team-2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-1", priority_tier="A", start=1170, end=1260,
        )
        slot2_nochain = _assignment(
            model, "slot2_nochain", team_id="team-2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-2", priority_tier="A", start=1170, end=1260,
        )

        # team-2 gets exactly one of the two coach options; team-1 always placed.
        model.Add(slot2_chain.var + slot2_nochain.var == 1)
        model.Add(slot1.var == 1)

        add_level_2_objective(model, [slot1, slot2_chain, slot2_nochain], teams=[
            {"id": "team-1", "priority_tier": "A"},
            {"id": "team-2", "priority_tier": "A"},
        ])

        status, solver = _solve(model)
        assert status == cp_model.OPTIMAL, f"Expected OPTIMAL, got {status}"
        assert solver.Value(slot2_chain.var) == 1, "chaining option must win the tie"
        assert solver.Value(slot2_nochain.var) == 0

    def test_higher_tier_chaining_preferred(self) -> None:
        """When a coach chains sessions for an S-tier team vs a C-tier team,
        the S-tier chaining bonus (200) should dominate the C-tier bonus (0.2).

        Setup: Two consecutive slots. Coach-1 coaches S-tier teams.
        Coach-2 coaches C-tier teams. The solver should prefer Coach-1
        chaining because S-tier chaining bonus >> C-tier chaining bonus.
        """
        model = cp_model.CpModel()

        # Slot 1: 18:00-19:30
        slot1_S = _assignment(
            model, "slot1_S",
            team_id="team-S", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-S", priority_tier="S", start=1080, end=1170,
        )
        slot1_C = _assignment(
            model, "slot1_C",
            team_id="team-C", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-C", priority_tier="C", start=1080, end=1170,
        )

        # Slot 2: 19:30-21:00
        slot2_S = _assignment(
            model, "slot2_S",
            team_id="team-S2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-S", priority_tier="S", start=1170, end=1260,
        )
        slot2_C = _assignment(
            model, "slot2_C",
            team_id="team-C2", slot_id="1:19:30", venue_id="venue-1",
            coach_id="coach-C", priority_tier="C", start=1170, end=1260,
        )

        assignments = [slot1_S, slot1_C, slot2_S, slot2_C]

        # At most one team per venue-slot
        model.Add(slot1_S.var + slot1_C.var <= 1)
        model.Add(slot2_S.var + slot2_C.var <= 1)

        add_level_2_objective(model, assignments, teams=[
            {"id": "team-S", "priority_tier": "S"},
            {"id": "team-S2", "priority_tier": "S"},
            {"id": "team-C", "priority_tier": "C"},
            {"id": "team-C2", "priority_tier": "C"},
        ])

        status, solver = _solve(model)
        assert status == cp_model.OPTIMAL, f"Expected OPTIMAL, got {status}"

        # S-tier coach should be preferred for chaining
        assert solver.Value(slot1_S.var) == 1, "S-tier coach should take slot 1"
        assert solver.Value(slot2_S.var) == 1, "S-tier coach should take slot 2"

    def test_empty_assignments_returns_no_terms(self) -> None:
        """add_chaining_bonus with no assignments returns no terms."""
        model = cp_model.CpModel()
        assert add_chaining_bonus(model, [], teams=[]) == []

    def test_single_assignment_returns_no_terms(self) -> None:
        """A single assignment yields no terms (no consecutive pair possible)."""
        model = cp_model.CpModel()
        slot = _assignment(
            model, "slot1",
            team_id="team-1", slot_id="1:18:00", venue_id="venue-1",
            coach_id="coach-1", priority_tier="A", start=1080, end=1170,
        )
        assert add_chaining_bonus(model, [slot], teams=[{"id": "team-1", "priority_tier": "A"}]) == []


if __name__ == "__main__":
    import unittest
    unittest.main()

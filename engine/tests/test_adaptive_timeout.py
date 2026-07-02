"""Unit tests for the size-adaptive solver timeout (capped by the payload)."""

from app.main import _adaptive_timeout


class TestAdaptiveTimeout:
    def test_small_problem_gets_60s(self) -> None:
        # complexity = 5 * 4 = 20 ≤ 50
        assert _adaptive_timeout(5, 4, payload_cap=650) == 60

    def test_boundary_50_stays_in_first_tier(self) -> None:
        assert _adaptive_timeout(10, 5, payload_cap=650) == 60

    def test_medium_problem_gets_180s(self) -> None:
        # complexity = 10 * 10 = 100 (51..200)
        assert _adaptive_timeout(10, 10, payload_cap=650) == 180

    def test_boundary_200_stays_in_second_tier(self) -> None:
        assert _adaptive_timeout(20, 10, payload_cap=650) == 180

    def test_large_problem_gets_600s(self) -> None:
        # complexity = 30 * 10 = 300 > 200
        assert _adaptive_timeout(30, 10, payload_cap=650) == 600

    def test_payload_cap_is_the_ceiling(self) -> None:
        # Large problem would want 600 s, but the manager asked for 120 s max.
        assert _adaptive_timeout(30, 10, payload_cap=120) == 120

    def test_cap_below_first_tier(self) -> None:
        assert _adaptive_timeout(5, 4, payload_cap=30) == 30

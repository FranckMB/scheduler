"""P5-10 — deterministic tests for the RSS sampler used to fill peak_rss_mb.

The sampler reads VmRSS from /proc while the solve holds a worker thread. We
monkeypatch the proc read (``_read_vmrss_mb``) so the test never depends on real
memory or on sleep timing: ``_sample()`` is driven directly, one call per stubbed
reading, which pins first/peak exactly.
"""

from __future__ import annotations

import pytest

from app import main


def test_sampler_tracks_first_reading_and_running_peak(monkeypatch: pytest.MonkeyPatch) -> None:
    readings = iter([100.0, 150.0, 120.0, 200.0, 180.0])
    monkeypatch.setattr(main, "_read_vmrss_mb", lambda: next(readings))

    sampler = main._RssSampler()
    for _ in range(5):
        sampler._sample()

    # first_mb is the BASELINE (first sample), never overwritten by later readings.
    assert sampler.first_mb == 100.0
    # peak_mb is the MAX across all samples — it grew above the baseline.
    assert sampler.peak_mb == 200.0
    assert sampler.peak_mb > sampler.first_mb


def test_flat_readings_give_peak_equal_to_baseline(monkeypatch: pytest.MonkeyPatch) -> None:
    # Falsification of the growth assertion above: with a stub that does NOT grow,
    # peak == first. If the sampler wrongly always reported growth this would fail.
    monkeypatch.setattr(main, "_read_vmrss_mb", lambda: 128.0)

    sampler = main._RssSampler()
    for _ in range(4):
        sampler._sample()

    assert sampler.first_mb == 128.0
    assert sampler.peak_mb == 128.0


def test_sampler_ignores_unreadable_proc(monkeypatch: pytest.MonkeyPatch) -> None:
    # Off Linux / no /proc: _read_vmrss_mb returns None → the fields stay None
    # (the schema keeps them optional), never a crash and never a bogus 0.
    monkeypatch.setattr(main, "_read_vmrss_mb", lambda: None)

    sampler = main._RssSampler()
    sampler._sample()

    assert sampler.first_mb is None
    assert sampler.peak_mb is None

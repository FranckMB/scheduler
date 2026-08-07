"""ENG-26 — le harnais de test ne doit pas parler un contrat que la prod refuse.

`tests/support/pipeline.py` promet, docstring à l'appui, « la forme exacte que le
backend émet ». Il envoyait pourtant ``version: "1.0"`` — un payload que
``POST /generate`` rejette en **422** (le garde compare le MAJOR à
``CONTRACT_VERSION``). Comme ``solve_payload`` court-circuite la couche FastAPI,
le garde ne tournait jamais en test : toute la suite sémantique validait une
enveloppe que personne, en production, n'accepterait.

Ce test rattache le harnais à la source de vérité. Il ne teste pas le solveur : il
teste que ce sur quoi le solveur est testé est réel.
"""

from __future__ import annotations

from app.main import read_contract_version
from tests.support.pipeline import make_payload


def test_the_harness_payload_announces_the_current_contract() -> None:
    payload = make_payload(venues=[], teams=[])

    assert payload["version"] == read_contract_version(), (
        "le harnais annonce une version de contrat différente de CONTRACT_VERSION : "
        "les tests sémantiques tournent alors sur une enveloppe que POST /generate "
        "refuserait en 422 (ENG-26)."
    )


def test_that_version_would_pass_the_production_gate() -> None:
    """La propriété qui compte vraiment : le MAJOR, seul critère du garde."""
    payload = make_payload(venues=[], teams=[])

    assert str(payload["version"]).split(".")[0] == read_contract_version().split(".")[0], (
        "MAJOR différent de celui de l'engine : POST /generate rendrait 422 sur ce payload."
    )

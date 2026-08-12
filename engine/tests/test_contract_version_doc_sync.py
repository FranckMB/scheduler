"""DOC-04 — l'inventaire engine ne peut plus mentir sur la version de contrat.

Cinquième récidive du même motif : `engine/CONTRACT_VERSION` bump, et le doc de
second rang qui le cite reste à l'ancienne valeur. Ce n'est pas une coquette : ce
projet est piloté par des agents, et `specs/courantes/engine-inventory.md` est lu
comme la source de vérité quand on planifie un changement de contrat. Un doc qui
annonce 2.1 alors que le fichier dit 2.2 fait partir un plan à contresens — c'est
exactement ce qui s'est produit entre le 2026-08-03 (bump 2.2) et le 2026-08-07.

Corriger la valeur ne corrige que le CAS ; ce test corrige la RÈGLE : la prochaine
fois, c'est la CI qui le dit, pas un audit trois semaines plus tard.

Volontairement bête : on ne vérifie pas la prose, seulement que la version en
vigueur est CITÉE et qu'aucune autre version `2.x` ne traîne comme « version
active ». Un test plus malin serait un test à entretenir.
"""

from __future__ import annotations

import pathlib
import re

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTRACT_VERSION_FILE = REPO_ROOT / "engine" / "CONTRACT_VERSION"
INVENTORY_DOC = REPO_ROOT / "specs" / "courantes" / "engine-inventory.md"

# D-37 (2026-08-08) — le garde ne surveillait QUE l'inventaire, et sept autres documents ont
# derive sous son nez : ils annoncaient encore le contrat 2.1 alors que le fichier disait 2.2,
# dont `project-map.md`, qui avait recu un tampon « verifie » le matin meme. Un garde trop
# etroit est un garde qui rassure a tort : on le croit couvrant, il ne tient qu'un fichier.
#
# Tout document qui se prononce sur la version EN VIGUEUR entre ici. Les mentions
# HISTORIQUES restent libres (« bump 2.1 -> 2.2 », « 2.1 = fenetres coach ») : la regle est
# seulement que la version courante soit citee, jamais qu'une ancienne disparaisse.
DOCS_QUOTING_THE_ACTIVE_VERSION = (
    INVENTORY_DOC,
    REPO_ROOT / "docs" / "project-map.md",
    REPO_ROOT / "docs" / "glossary.md",
    REPO_ROOT / "engine" / "README.md",
    REPO_ROOT / "engine" / "docs" / "nominal-flow.md",
    REPO_ROOT / "engine" / "docs" / "solver-errors.md",
    REPO_ROOT / "backend" / "docs" / "schedule-generation-guide.md",
    # Rattrapage 2026-08-12 : ces deux-là ont dérivé DEUX fois (2.2 au bump 2.4, 2.4 au bump
    # 2.5) — précisément parce qu'ils n'étaient pas dans cette liste. L'index agent et la
    # règle de zone citent la version : ils la citent juste, ou ils rougissent ici.
    REPO_ROOT / "CLAUDE.md",
    REPO_ROOT / ".claude" / "rules" / "engine.md",
)


def _current_version() -> str:
    return CONTRACT_VERSION_FILE.read_text(encoding="utf-8").strip()


def test_inventory_doc_quotes_the_current_contract_version() -> None:
    version = _current_version()
    doc = INVENTORY_DOC.read_text(encoding="utf-8")

    assert version in doc, (
        f"specs/courantes/engine-inventory.md ne cite pas CONTRACT_VERSION={version}. "
        "Le doc est lu comme source de vérité par les agents : le laisser sur une version "
        "périmée fait partir un plan à contresens (DOC-04, 5 récidives). Mettre à jour le "
        "doc dans le MÊME commit que le bump."
    )


def test_the_doc_does_not_still_present_an_older_version_as_active() -> None:
    """Le piège du correctif partiel : ajouter la nouvelle version SANS retirer
    l'ancienne, qui continue de s'annoncer comme la version en vigueur."""
    version = _current_version()
    doc = INVENTORY_DOC.read_text(encoding="utf-8")

    # « Version contrat active : **`"2.1"`** » et « fichier = `2.1` » sont les deux
    # tournures par lesquelles la dérive est passée les fois précédentes.
    stale = [
        found
        for found in re.findall(r"(?:[Vv]ersion contrat active\s*:\s*\*\*`\"([\d.]+)\"`|fichier\s*=\s*`([\d.]+)`)", doc)
        for found in found
        if found and found != version
    ]

    assert not stale, (
        f"le doc annonce encore {stale} comme version en vigueur alors que "
        f"CONTRACT_VERSION={version}. Une version historique se cite au passé "
        "(« 2.1 = fenêtres coach »), jamais comme la version active."
    )


def test_every_doc_quoting_the_contract_cites_the_current_version() -> None:
    """D-37 — la meme regle, etendue a tous les documents qui annoncent la version.

    Volontairement bete, comme le test ci-dessus : on exige seulement que la version en
    vigueur soit CITEE quelque part. Un doc qui ne parle que d'une version passee est donc
    en faute, un doc qui cite la courante ET son historique est correct.
    """
    version = _current_version()

    stale = []
    for doc in DOCS_QUOTING_THE_ACTIVE_VERSION:
        text = doc.read_text(encoding="utf-8")
        # Le doc parle-t-il du contrat ? (sinon il n'a rien a citer)
        if not re.search(r"CONTRACT_VERSION|contrat\s+`?\"?2\.", text):
            continue
        if version not in text:
            stale.append(str(doc.relative_to(REPO_ROOT)))

    assert not stale, (
        f"ces documents parlent du contrat sans citer la version en vigueur ({version}) : "
        f"{stale}. Un agent qui les lit part sur une version perimee — c'est exactement ce "
        "qui s'est produit entre le bump 2.2 et l'audit du 2026-08-08, sur SEPT fichiers. "
        "Mettre a jour le doc dans le MEME commit que le bump."
    )


# D-42 — les paliers du budget solveur sont cités par SEPT documents.
SOLVER_TIERS_DOCS = (
    REPO_ROOT / "CLAUDE.md",
    REPO_ROOT / "docs" / "architecture" / "adr-0001-single-pass-solve.md",
    REPO_ROOT / "docs" / "glossary.md",
    REPO_ROOT / "docs" / "project-map.md",
)


def _adaptive_tiers() -> list[str]:
    """Les paliers reellement codes dans `_adaptive_timeout`, en ordre croissant."""
    main = (REPO_ROOT / "engine" / "app" / "main.py").read_text(encoding="utf-8")
    tiers = sorted(int(v) for v in re.findall(r"^\s+adaptive = (\d+)$", main, re.M))

    assert tiers, "les paliers ont disparu de `_adaptive_timeout` — ce test doit suivre, pas se taire"

    return [str(t) for t in tiers]


def test_docs_quoting_the_solver_tiers_cite_the_real_values() -> None:
    """D-42 — un doc qui annonce un budget solveur perime fait planifier a contresens.

    Ces chiffres decident ce qu'un agent croit possible (« le solveur a 600 s »). Ils sont
    ecrits dans sept documents et nulle part derives. Le test ne juge pas la prose : il
    exige seulement qu'un doc qui CITE la suite de paliers cite la VRAIE.
    """
    expected = "/".join(_adaptive_tiers())

    stale = []
    for doc in SOLVER_TIERS_DOCS:
        text = doc.read_text(encoding="utf-8")
        # Ancre sur l'unite : sans elle, une DATE (« 01/02/04 ») passe pour des paliers.
        quoted = re.findall(r"\b(\d{2,3}/\d{2,3}/\d{2,3})\s*s\b", text)
        for suite in quoted:
            if suite != expected:
                stale.append(f"{doc.relative_to(REPO_ROOT)} annonce {suite}")

    assert not stale, (
        f"ces documents annoncent des paliers de budget solveur qui ne sont plus ceux du "
        f"moteur ({expected}) : {stale}. Un agent qui les lit planifie sur un budget qui "
        "n'existe pas — meme motif que la version de contrat (D-37)."
    )

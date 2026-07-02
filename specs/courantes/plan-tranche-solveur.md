# Plan — Tranche solveur (alignement sur arbitrages métier)

> Plan de travail (temporaire, retiré à la clôture). Décidé avec le PO le 2026-07-02.
> Périmètre **solveur uniquement** : `engine/` (Python OR-Tools) + `backend/` (ScheduleConstraintBuilder). **Frontend intouché.**

## Arbitrages (source de vérité = le code, confronté par 2 agents)

1. **Groupe = tags** (pas de scope CATEGORY 1ʳᵉ classe). Rien à faire.
2. **Divisibilité = propriété salle → créneau** : `Venue.canSplit` gouverne ; `!canSplit ⇒ slot.capacity = 1`. Capacité reste **au créneau** (l'engine la respecte déjà). Garde-fou backend.
3. **Supprimer `orToolsWeight`** (envoyé mais ignoré ; poids tiers restent hardcodés S=10000/A=1000/B=100/C=10/D=1, garantie de priorité stricte).
4. **Supprimer les objectifs morts** `grouping`/`max_days`/`SOFT`/`pref_link`/`opt_link`. **Garder** `matchDay` (champ) + poids `rest` = paire future (repos après match), à câbler plus tard.
5. **Coach** : MAIN = dur (présent à toutes les séances, via no-overlap) ; ASSISTANT = mou/optionnel (ne bloque plus). Aujourd'hui l'engine traite les deux en dur → à corriger.
6. **Timeout adaptatif** : `complexité = nb_équipes × nb_salles` → **≤50 : 60s · ≤200 : 180s · sinon 600s** ; `solver_timeout_seconds` du payload = **plafond**. Corriger l'incohérence builder 300s vs schéma 650s.

## Lots (commit + vérif chacun)

- **S1 — Nettoyage objectif** (`engine/app/solver/objective.py`) : retirer `grouping`/`max_days`/`SOFT`/`pref_link`/`opt_link` de `LEVEL_2_OBJECTIVE_WEIGHTS` + refs mortes. Garder `rest`. **Bump `SCORE_FORMULA_VERSION`** (V3→V4). MAJ tests objectif + main.py (version passée).
- **S2 — Coach main/assistant** (`constraints.py` `parse_v2_constraints`) : `team_coach_map` alimenté **uniquement role MAIN** (`metadata.role`). Tests : équipe plaçable si assistant occupé ailleurs ; pas si main occupé.
- **S3 — Timeout adaptatif** (`main.py`) : calcul complexité → paliers 60/180/600 ; `min(adaptatif, payload)`. Backend : builder arrête de hardcoder 300 (`ScheduleConstraintBuilder`).
- **S4 — Backend** (`ScheduleConstraintBuilder.php`) : retirer `orToolsWeight` du payload ; garde-fou `canSplit` dans `buildTrainingSlots` (`!canSplit ⇒ capacity 1`). Tests.
- **S5 — Diagnostics précis (aider le gestionnaire à comprendre)** — le retour du solveur n'est pas assez précis. Ex actuel : « 2 équipes occupent le créneau » **sans dire lesquelles ni quel créneau**. Objectif : chaque diagnostic répond **Qui ? Quand ? Comment ? Pourquoi ça plante / pourquoi le placement souhaité a échoué ?**
  - Enrichir les diagnostics (`engine/app/solver/result_builder.py`, `_diagnose_conflicts` / conflit capacité / unplaced / INFEASIBLE) : **nommer les équipes concernées, la salle, le jour + l'heure**, et la **raison** (quelle contrainte a bloqué, quelle ressource manquait).
  - INFEASIBLE : au-delà de `diag-infeasible` générique, pointer la/les contrainte(s) ou équipe(s) en cause si identifiable.
  - Unplaced : dire **pourquoi** l'équipe n'a pas pu être placée (aucun créneau libre / coach indispo / salle forcée saturée…).
  - Adapter le schéma de sortie (`output_schema.py` `DiagnosticSchema.suggestions`/champs) si besoin de porter ces détails (équipes, salle, créneau) — **impact contrat** possible → vérifier `ContractSchemaTest` + `CONTRACT_VERSION`.
  - Texte en **langage gestionnaire** (pas de jargon solveur).

## Vérification

- `cd engine && make test` (pytest + ruff + mypy) par lot engine.
- Backend : CS-Fixer + PHPStan(8) + PHPUnit.
- **`backend/scripts/smoke-solver.sh` → COMPLETED** (obligatoire, zone engine+backend).
- `ContractSchemaTest` vert (retrait `orToolsWeight` = métadonnée `extra="ignore"` → a priori pas de bump de contrat ; sinon `engine/CONTRACT_VERSION` + sync).

## Doc à MAJ (clôture)

`specs/evolution/contraintes-modele-cible.md` + `roadmap.md §1` (statuts) · `engine/doc/business.md` (coach main/assistant, objectif nettoyé) · ce fichier retiré.

## Hors scope

Frontend, câblage `matchDay`/repos-après-match (plus tard), `allow_shared_court`, scope CATEGORY, familles ALLOCATION_PRIORITY/DISTRIBUTION, liste fermée de types.

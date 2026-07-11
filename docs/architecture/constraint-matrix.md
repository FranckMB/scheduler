# Matrice contrainte UI ↔ engine

> **Règle de maintenance (P0.1 audit 2026-07-06)** : toute évolution de l'offre du wizard
> (`FAMILIES`/`RULES`/configs de `ConstraintsStep.tsx`) exige de mettre à jour
> **`engine/tests/semantic/constraint_matrix.py`** (la représentation machine, source du test
> paramétré `test_constraint_matrix.py`) **et** ce document. Le test Vitest
> `ConstraintsStep.test.tsx` fige l'offre côté UI — les deux verrous se tiennent.
> Origine : ENG-10/11/12/13 — le motif « contrainte saisie ≠ contrainte honorée » renaissait à
> chaque nouvelle option UI non câblée côté solveur.

Statuts : **dure** = jamais violée (sur-contraint → non placé + diagnostic, jamais une violation
silencieuse) · **soft** = orientée par l'objectif, ne bloque jamais la faisabilité · **warning** =
diagnostic `constraint_not_honored` explicite · **non proposé** = absent de l'UI (verrouillé par le
test Vitest).

## Offre du wizard (après P0.1)

| Famille · config | HARD (Obligatoire) | LOCK (Verrouillé) | PREFERRED (Préféré) |
|---|---|---|---|
| TIME `minStartTime`/`maxStartTime` | dure | dure (fenêtre figée) | soft |
| TIME `maxEndTime` | dure — mode **« Fini avant »** (fin = début + durée du créneau), toujours HARD (pas de sélecteur) *(ALIGN-04)* | — | — *(le chemin soft `preferredTime` ne lit que min/maxStartTime → une préférence serait un placebo)* |
| DAY `forbiddenDays` | dure | dure | **soft « éviter ces jours »** *(fix ENG-10 — était un placebo)* |
| DAY `allowedDays` | dure — mode **« uniquement »** (whitelist : l'engine interdit tous les autres jours), toujours HARD (pas de sélecteur) | — | — |
| FACILITY `preferredVenueId` | dure (salle forcée) | **dure** *(fix ENG-12 — était mort)* | soft |
| FACILITY `forcedVenueId` | dure — mode **« impose »** (doit se dérouler ici), toujours HARD (pas de sélecteur) | — | — |
| FACILITY `minAtVenueId` + `minAtVenueCount` | dure — mode **« au moins N »** (plancher de séances dans ce gymnase, ≠ forçage), toujours HARD (pas de sélecteur) *(ALIGN-05)* ; plancher inatteignable → **fail-soft** (diagnostic `venue_minimum_unreachable` ERROR, pas INFEASIBLE) ; le backend refuse `N > séances/semaine` avant génération | — | — |
| FACILITY `forbiddenVenueId` | dure | dure | **soft « éviter ce gymnase »** *(fix ENG-11 — était escaladé en dur → INFEASIBLE possible sur une préférence)* |
| COACH_AVAILABILITY `unavailableDays` | mode « indisponible » — dure + **union multi-contraintes** *(fix ENG-13)* | — l'UI force **Obligatoire** | — |
| COACH_AVAILABILITY `availableDays` | mode « disponible uniquement » — dure (whitelist, **intersection** multi) *(ALIGN — l'UI expose la capacité engine)* | — l'UI force **Obligatoire** | — |

- **BONUS retiré de l'offre** *(ENG-12 : aucune sémantique définie nulle part)*. Les lignes BONUS
  déjà en base sont **normalisées en PREFERRED par l'engine** (honorées soft, jamais droppées).
- **Cibles** : équipe (TEAM) · groupe (tag → expansion backend en N contraintes TEAM) ·
  **« Toutes les équipes » (CLUB) → expansion backend en N contraintes TEAM** *(fix P0.1 — la case
  était un no-op silencieux)*. Une contrainte TIME/DAY/FACILITY sans cible qui atteindrait quand
  même l'engine produit un **warning** (filet).
- COACH_AVAILABILITY non-HARD reçu (legacy) : appliqué dur + diagnostic INFO.

## Vocabulaire compris par l'engine mais jamais émis par le wizard (« non proposé »)

`forcedDays` (engine-only : « au moins une séance ces jours-là » — ≠ « uniquement » ; le wizard émet `allowedDays`, cf. ENG-16) · `preferredDays` (lu par l'objectif, jamais émis — la racine d'ENG-10) ·
`FACILITY_CAPACITY.maxTeams` (pass-through : n'atteint l'engine que si une contrainte explicite est stockée en base — le backend n'en émet **pas** depuis `canSplit`, la divisibilité voyage dans `trainingSlots[].capacity`). L'onglet « Réserver » passe par
`slotTemplates` (verrou HARD), hors matrice constraints.

> **MàJ 2026-07-08** : `allowedDays` et `forcedVenueId` sont **émis par le wizard**
> (modes « uniquement »/« impose », toujours HARD) pour que l'édition des contraintes fixtures
> (`SM4 → Jean Vilar`, `Veterans vendredi uniquement`) fasse un aller-retour fidèle sans
> rétrograder en préférence. Les deux cellules passent `NOT_OFFERED → HONORED_HARD`.
> **Correctif ENG-16** : « uniquement » émet `allowedDays` (whitelist réelle), **pas** `forcedDays`
> (qui ne veut dire QUE « au moins une séance ces jours-là » et laissait les autres jours ouverts).
>
> **MàJ 2026-07-08 (angles morts d'alignement)** : trois capacités engine désormais alignées.
> `maxEndTime` (**ALIGN-04**, mode « Fini avant ») et `minAtVenueId`+`minAtVenueCount` (**ALIGN-05**,
> mode « au moins N ») deviennent **émis par le wizard** (toujours HARD). **ALIGN-06** ajoute une
> **règle implicite soft** : espacement des jours d'entraînement (poids `spacing = −2`, malus sur
> deux séances consécutives d'une même équipe) — activée pour toutes les équipes, ne bloque jamais
> (soft). `SCORE_FORMULA_VERSION` **bumpé V6→V7** (nouveau poids `spacing`).

## Verrous

| Verrou | Fichier |
|---|---|
| Matrice machine (source du test) | `engine/tests/semantic/constraint_matrix.py` |
| Test sémantique paramétré (NR §7.1) | `engine/tests/semantic/test_constraint_matrix.py` |
| Gel de l'offre UI | `frontend/src/features/wizard/steps/ConstraintsStep.test.tsx` |
| Expansion CLUB→équipes | `backend/tests/Unit/Service/ScheduleConstraintBuilderTest.php` |

Contrat backend↔engine **inchangé** (config = dict opaque, warnings via `diagnostics` existants) —
pas de bump `CONTRACT_VERSION`. `SCORE_FORMULA_VERSION` **bumpé V5→V6** : nouveau poids
`avoided_venue = −60` (vrai malus sur le créneau du gymnase évité — un bonus-complément sur les
autres gymnases biaisait l'arbitrage inter-équipes). Sémantiques d'agrégation : indispos coach =
**union des blacklists ∩ des whitelists** ; plusieurs « éviter tel jour » soft = **union par équipe**
(deux compléments indépendants s'annulaient) ; double règle de gymnase sur une équipe → diagnostic
INFO (last-wins signalé).

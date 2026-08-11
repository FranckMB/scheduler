# Matrice contrainte UI ↔ engine

> **Règle de maintenance (P0.1 audit 2026-07-06)** : toute évolution de l'offre du wizard
> (`FAMILIES`/`RULES`/configs de `ConstraintsStep.tsx`) exige de mettre à jour
> **`engine/tests/semantic/constraint_matrix.py`** (la représentation machine, source du test
> paramétré `test_constraint_matrix.py`) **et** ce document. Le test Vitest
> `ConstraintsStep.test.tsx` fige l'offre côté UI — les deux verrous se tiennent.
> Origine : ENG-10/11/12/13 — le motif « contrainte saisie ≠ contrainte honorée » renaissait à
> chaque nouvelle option UI non câblée côté solveur.

Statuts : **dure** = jamais violée *par le solveur* (sur-contraint → non placé + diagnostic, jamais une
violation silencieuse — mais lire la section « Le verrou HARD est SOUVERAIN » plus bas : face à un
**verrou**, « dure » ne tient pas) · **soft** = orientée par l'objectif, ne bloque jamais la
faisabilité · **warning** = diagnostic `constraint_not_honored` explicite · **non proposé** = absent
de l'UI (verrouillé par le test Vitest).

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

## Le verrou HARD est SOUVERAIN — et depuis P2-9 il le dit (2026-07-28)

Un créneau **verrouillé** (onglet « Réserver », verrou manuel → `slotTemplates` `lockLevel=HARD`) est
**pré-placé HORS du solveur** : `model.py` ne crée jamais la variable `x[équipe, gymnase, jour, heure]`
correspondante. Or **toute** contrainte de cette matrice agit en forçant cette variable à 0 — sans
variable, il n'y a rien à forcer. La contrainte n'est pas *battue*, elle est **INATTEIGNABLE**.

Mesuré avant correctif, même payload, seule différence le verrou : sans verrou, SM1 est placée mardi
(coach indisponible le samedi, respecté) ; avec verrou, SM1 est placée **samedi**, `diagnostics` vide,
statut `completed`. Le produit affirmait avoir respecté une contrainte qu'il avait laissé tomber.

- **Ce qui n'a PAS changé** : le verrou reste souverain (décision fondateur **ALIGN-07**, non
  rouverte). Il prime sur tout, y compris une contrainte « dure » de la matrice ci-dessus.
- **Ce qui a changé** : le silence. `diagnose_locked_slot_violations`
  (`engine/app/solver/constraints.py`, appelée depuis `main.py`) croise les verrous avec les
  contraintes **SAISIES par le gestionnaire** — indisponibilité coach, fenêtres horaires, règles de
  jours (unies par équipe), gymnase interdit — et émet un `constraint_not_honored` **INFO** qui nomme
  la contrainte, l'équipe, le coach, le gymnase, le jour et l'heure. INFO et jamais ERROR : le
  gestionnaire a le droit d'épingler, il a le droit de savoir ce que son épingle a écrasé. La
  détection **réplique exactement** les règles d'application (intervalle coach comparé au début de
  créneau, min/max start des fenêtres, paire équipe+gymnase des interdits) — toute dérive entre les
  deux ferait mentir le diagnostic sur ce que le solveur a réellement fait.
- **Périmètre volontaire** : uniquement le SAISI. Les règles **structurelles** qu'un verrou contourne
  aussi (un coach dans deux gymnases à la même heure) décrivent une impossibilité physique, pas une
  préférence : elles bloqueront la génération au lieu d'avertir, dans un lot dédié.
- **Second effet ALIGN-07** : un verrou HARD prend le **créneau entier**, divisible ou non
  (`blocked_venue_slots`, `model.py`) — partager un créneau `capacity>1` se déclare en **co-épinglant**
  les N équipes. Détail : `backend/docs/constraint-coverage.md`.

Verrous de non-régression : `engine/tests/semantic/test_hard_lock_announces_violations.py` (avec un
TÉMOIN explicite — sans lui, constater que SM1 joue le samedi n'accuserait pas le verrou) et
`engine/tests/semantic/test_hard_lock_divisible_slot.py`.

## Règles structurelles JAMAIS saisies — et ce que l'écran en montre (P4-55, 2026-08-11)

`add_level_1_hard_constraints` (`engine/app/solver/constraints.py:153`) pose une douzaine de
règles que **personne n'entre nulle part**. Elles ne sont ni dans le wizard, ni dans le
`config` d'une contrainte, ni dans le payload : elles sont le modèle lui-même. Le gestionnaire
ne savait donc pas ce qu'il obtient gratuitement, ni pourquoi un placement « qui aurait dû
passer » est refusé.

**Six sont montrées** dans un encart replié, **lecture seule**, en tête de l'étape Contraintes
(`frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx`) :

| Affiché | Fonction moteur | Nuance qui compte |
|---|---|---|
| Un gymnase ne dépasse jamais sa capacité | `add_room_at_most_one:284` | « au plus la CAPACITÉ », pas « une seule équipe » — la capacité se règle par créneau |
| Un coach n'est jamais dans deux gymnases à la fois | `add_coach_at_most_one:311` | **venue-aware** : le MÊME gymnase est AUTORISÉ (D-14, arbitrage fondateur 2026-08-09) |
| Une personne ne peut pas encadrer et jouer en même temps | `add_coach_player_non_overlap:374` | coach-joueur, les deux rôles |
| Une équipe n'a jamais deux séances en même temps | `add_team_no_overlap:745` | — |
| Au plus une séance par jour et par équipe | `add_one_session_per_day_constraints:1590` | ⚠ l'exception `allowMultipleSessionsPerDay` est **inatteignable** (voir ci-dessous) |
| Chaque coach garde un jour de repos | `add_coach_rest_day_constraints:452` | lundi→vendredi ; le week-end ne compte pas |

**Trois sont TUES, délibérément** (décision fondateur 2026-08-11) : `add_age_ascending_constraints`
(les jeunes avant les grands, même gymnase+jour), `add_salarie_distribution_constraints` (au moins
un salarié chaque jour ouvré) et `add_max_consecutive_sessions_constraints` (pas trois créneaux
consécutifs pour un coach). Détails d'implémentation ou règles de confort : les énoncer coûterait
plus de confusion qu'il n'apporte.

⚠ **`allowMultipleSessionsPerDay` est un levier MORT** : le moteur le lit (`:1590`, `:1642`) et le
backend le sérialise (`ScheduleConstraintBuilder.php:680`), mais le champ est **absent de
`TeamInput`** — aucune route, aucun écran ne l'écrit ; seule la bascule de saison en recopie la
valeur. Il vaut donc `false` partout. L'encart n'annonce **pas** l'exception, qui enverrait le
gestionnaire chercher un réglage inexistant. Tracé en **P4-79**.

⚠ **Le docstring d'`add_level_1_hard_constraints` a menti longtemps** : il décrivait un
« two-pass fallback » abandonnant repos-coach et distribution-salariés sur INFEASIBLE. Ce chemin
n'existe pas — ADR-0001 pose un solve **single-pass sans relaxation**. Corrigé au même lot.

**Le garde anti-mensonge, dans les deux zones** : `ConstraintsStep.test.tsx` gèle le texte des six
règles côté écran, et `engine/tests/semantic/test_implicit_rules_are_still_applied.py` vérifie que
les six fonctions sont **toujours appelées sans condition** — retirer une règle du moteur sans
toucher l'écran fait rougir la CI, en nommant l'intitulé affiché. Sans ce second verrou, le gel
Vitest figerait un texte que plus personne n'honore.

## Verrous

| Verrou | Fichier |
|---|---|
| Matrice machine (source du test) | `engine/tests/semantic/constraint_matrix.py` |
| Test sémantique paramétré (NR §7.1) | `engine/tests/semantic/test_constraint_matrix.py` |
| Gel de l'offre UI | `frontend/src/features/wizard/steps/ConstraintsStep.test.tsx` |
| Expansion CLUB→équipes | `backend/tests/Unit/Service/ScheduleConstraintBuilderTest.php` |

Contrat backend↔engine **inchangé** (config = dict opaque, warnings via `diagnostics` existants) —
pas de bump `CONTRACT_VERSION`. **`SCORE_FORMULA_VERSION` actuel : V7** (`engine/app/solver/objective.py`)
— V5→V6 : nouveau poids `avoided_venue = −60` (vrai malus sur le créneau du gymnase évité — un
bonus-complément sur les autres gymnases biaisait l'arbitrage inter-équipes) ; V6→V7 : poids
`spacing` (ALIGN-06). Sémantiques d'agrégation : indispos coach =
**union des blacklists ∩ des whitelists** ; plusieurs « éviter tel jour » soft = **union par équipe**
(deux compléments indépendants s'annulaient) ; double règle de gymnase sur une équipe : les
PREFERRED se **cumulent en ensemble** (bonus si la séance tombe dans l'un d'eux — PR B 2026-08-06),
seules les règles DURES (`forced_venues`) restent last-wins avec diagnostic INFO.

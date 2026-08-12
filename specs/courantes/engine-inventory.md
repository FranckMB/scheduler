# Engine Inventory — Backward Spec

Last verified @ 2026-08-13 (stamp recalé — le commit du contenu du 2026-08-12 a franchi minuit : PR #536 mergée à 00:03 ; contenu inchangé depuis) (recalé ce jour : gymnase imposé dans le diagnostic de verrou + dimension `lock_silence` de la matrice ; précédemment : 2026-08-12 (CONTRACT_VERSION **2.4** = troisième endpoint `/validate-assignments`, verdict moteur sur un candidat, P2-2 F2a — bump recalé ici et dans les docs gardés ; 2.3 = retrait de `maxDaysOverrideConfirmed`, P4-51 ; 2.2 = second problème `/place-matches`, P1-4 PR D · 2.1 = fenêtres horaires coach #195 · bornes payload A10 #156 · P2-9 volet 1 : diagnostics de verrou, #317))

> Inventaire BACKWARD de l'existant engine. Reflète le code lu au SHA ci-dessus, pas les features futures.
> Source de vérité : `engine/app/main.py`, `engine/app/schemas/input_schema.py`, `engine/app/schemas/output_schema.py`, `engine/app/solver/{model,constraints,objective,result_builder}.py`, `engine/app/core/config.py`.

---

## 1. Architecture Engine

- **Runtime** : Python 3.12.
- **Framework HTTP** : FastAPI (app construite dans `engine/app/main.py` via `get_settings()` → `app_name`/`app_version`).
- **Solver** : Google OR-Tools CP-SAT (`from ortools.sat.python import cp_model`).
- **Validation** : Pydantic v2 (`BaseModel`, `ConfigDict`, `Field`, `populate_by_name=True`).
- **Settings** : `pydantic-settings` (`engine/app/core/config.py`), prefix env `ENGINE_`, `.env` lu. Defaults : `app_name="engine"`, `app_version="1.0"`, `contract_version="2.0"`, `environment="dev"`, `log_level="info"`.
- **Contract version** : lu depuis `engine/CONTRACT_VERSION` (**fichier = `2.5`** — source de vérité, `main.py:104-108`), fallback `settings.contract_version` (default `2.0`) si le fichier manque. Cinq bumps depuis 2.0 : **2.1** = fenêtres horaires d'indisponibilité coach (lot C, #195) ; **2.2** = second problème `/place-matches` (P1-4 PR D, ADR-0003) ; **2.3** = retrait de `maxDaysOverrideConfirmed` (P4-51 : un drapeau que rien ne lisait, schéma `forbid` donc rupture de recevabilité) ; **2.4** = troisième endpoint `/validate-assignments` (P2-2 F2a : verdict moteur sur un candidat, baseline figée via `add_fixed_slots`) ; **2.5** = retrait de `allowMultipleSessionsPerDay` (P4-79 : jumeau de 2.3 — un levier de solveur que rien n'écrivait, schéma `forbid` donc rupture de recevabilité) — **UN SEUL contrat pour les TROIS endpoints**, tous vérifient le même MAJOR.
- **Structure interne** :
  - `app/main.py` — endpoints FastAPI + pipeline solver.
  - `app/core/config.py` — settings.
  - `app/schemas/input_schema.py` — `ScheduleInputSchema`.
  - `app/schemas/output_schema.py` — `ScheduleOutputSchema`.
  - `app/solver/model.py` — `ScheduleCpModel` (variables booléennes `x[team, venue, day, slot]`).
  - `app/solver/constraints.py` — contraintes Level-1 (hard) + `parse_v2_constraints`.
  - `app/solver/objective.py` — objectif Level-2 (poids fixes T24).
  - `app/solver/result_builder.py` — solution → `ScheduleOutputSchema` + diagnostics.
  - `app/solver/match_placement.py` — le SECOND problème (placement de matchs datés, ADR-0003).
  - `app/schemas/match_input_schema.py` / `match_output_schema.py` — ses schémas dédiés.
- **Port** : 8000 (conteneur Docker `engine`).
- **Commandes** : tout via `engine/Makefile` dans le conteneur (`make test`, `make lint`, `make exec`).

---

## 2. Endpoints Engine

**Cinq** endpoints exposés par `app/main.py` :

| Endpoint | Méthode | Rôle | Response model |
|----------|---------|------|----------------|
| `/` | GET | Health + `contract_version` | `{"status":"ok","contract_version":...}` |
| `/health` | GET | Health simple | `{"status":"ok"}` |
| `/generate` | POST | **Principal** — résout un planning hebdomadaire | `ScheduleOutputSchema` |
| `/place-matches` | POST | **Second problème** — place des matchs DATÉS (P1-4 PR D, ADR-0003) | `MatchPlacementOutputSchema` |
| `/validate-assignments` | POST | **Verdict sur UN candidat** (P2-2 F2a) — « puis-je mettre cette équipe sur ce créneau ? ». Baseline **entièrement figée** via `add_fixed_slots`, candidat épinglé à part : le solve ne fait qu'un test de faisabilité. ⚠ **Le gel EST le verdict** — baseline non figée, le solveur déplace la séance en conflit et rend `valid=True` (falsifié). Mono-candidat ⇒ 1 worker ⇒ déterministe. Budget 2 s par défaut, plafond 10 s ; mesuré **~500 ms** sur 49 équipes (le build du modèle domine, pas le solve). Un « non » **nomme les règles cassées** (`diagnose_candidate_conflicts`) ; `baseline_infeasible` distingue une baseline déjà invalide d'un conflit non nommé | `ValidateAssignmentOutputSchema` |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine | `JSONResponse` (200 synchronized / 409 desynchronized) |

### POST /place-matches

Le **second problème CP-SAT**, distinct du solve hebdomadaire (ADR-0003 ; comportement produit :
[`module-matchs.md`](module-matchs.md) §Solveur de placement). Ce qui est propre à l'engine :

- **Handler** : `place_matches(input_data: MatchPlacementInputSchema)` (`main.py:526-545`). Même
  garde de contrat que `/generate` — MAJOR seul, 422 sinon : **un seul `CONTRACT_VERSION` pour les
  deux endpoints**.
- **Verrou par club PRÉFIXÉ** (`f"matches:{club_id}"`) : un solve hebdomadaire long ne bloque pas
  un placement de 3 s, alors qu'un même verrou l'aurait fait. Le sémaphore global
  (`_solve_semaphore`) borne quand même le CPU.
- **Solve** : `solve_match_placement(input_data)` dans un thread worker
  (`app/solver/match_placement.py`). Best-effort à poids dominant : aucune HARD violée, le
  non-plaçable ressort **nommé**.
- **Schémas dédiés** : `app/schemas/match_input_schema.py` / `match_output_schema.py` (§3 bis).

### POST /generate

- **Handler** : `generate_schedule(input_data: ScheduleInputSchema)`. **ENG-14** : rejette (422) un payload dont le **MAJOR de contrat** diffère de `read_contract_version()` (ex. `version` "1.x" alors que l'engine parle "2.x") — garde-fou du contrat manuel backend↔engine avant tout solve. **ENG-06** : un handler d'exception global (`_unhandled_exception_handler`) logge toute erreur non gérée (traceback serveur) et renvoie un 500 JSON propre sans fuite.
- **Isolation** : acquiert un `asyncio.Lock` par `club_id` (voir §5) avant de lancer `build_schedule`.
- **Pipeline** (`build_schedule` → `_solve`) :
  0. `build_schedule` lance `_solve` dans un **thread worker** (`await asyncio.to_thread(...)`) sous un `_solve_semaphore` global (`ENGINE_MAX_CONCURRENT_SOLVES`, défaut 1) : la boucle d'événements reste réactive pendant un solve (`/health` répond), la contention CPU reste bornée (ENG-03 corrigé).
  1. `input_data.model_dump(by_alias=True)` → dict.
  2. `build_model(data)` — crée `ScheduleCpModel`, variables `x`, extrait HARD locks.
  3. `parse_v2_constraints(data["constraints"])` — règle v2 → collections solver.
  4. Calcul `hard_satisfied_team_ids` (teams dont `sessionsPerWeek` est couvert par locks HARD → exclus du penalty unplaced).
  5. `adjusted_min_by_team` — min sessions mis à 0 pour teams sans assignments disponibles ou en conflit forcedDays/forbiddenDays.
  6. Construction `assignments` avec start/end pour contraintes consécutives.
  7. `add_level_1_hard_constraints(...)` — toutes les contraintes hard en un seul pass.
  8. `add_time_window_constraints(...)` — TIME/DAY hard windows + conflits.
  8 bis. `add_venue_minimum_constraints(...)` — planchers `minAtVenueId` (ALIGN-05) + diagnostics `venue_minimum_unreachable` quand le plancher est prouvablement inatteignable.
  8 ter. **`diagnose_locked_slot_violations(...)` — P2-9 volet 1 (livré 2026-07-28, PR #317).** Un verrou HARD est pré-placé hors solveur : `model.py` ne crée **pas** sa variable `x[...]`, donc aucune contrainte (qui s'applique en forçant cette variable à 0) ne peut l'atteindre — le verrou ne bat pas la contrainte, il la rend inatteignable. Cette fonction recroise `model.locked_slots` avec les contraintes **saisies** — indisponibilité coach (intervalle testé sur l'heure de début, pour chaque coach requis), fenêtres `minStartTime`/`maxStartTime`/`maxEndTime` (cette dernière mesurée sur la durée **du verrou**, pas du créneau de grille), règles DAY évaluées sur l'**UNION par équipe** (dont `forcedDays`, qu'un verrou posé un autre jour peut rendre insatisfaisable), paires (équipe, gymnase) interdites — et émet un `constraint_not_honored` de sévérité **INFO** par (contrainte, équipe, verrou), en nommant la règle réellement fautive. Le verrou reste **SOUVERAIN** : ALIGN-07 n'est pas rouvert, seul le silence disparaît. Hors périmètre volontaire : les règles structurelles (coach dans deux gymnases à la même heure) doivent bloquer, pas avertir. Gardé par `engine/tests/semantic/test_hard_lock_announces_violations.py` — axe structurant « sémantique des contraintes » (CLAUDE.md §7.1). ⚑ **Portée ÉTENDUE le 2026-08-12** : le **gymnase imposé** (`forced_venues`) entre dans le diagnostic — un verrou plaçant une équipe hors de son gymnase imposé était **totalement silencieux** (`COMPLETED`, zéro diagnostic), confirmé par reproduction. ⚠ **`venue_minimums` reste délibérément EXCLU** : appliquée en dur, ses seules issues sont honoré / `failed` / `venue_minimum_unreachable` **ERROR** — elle ne peut pas dériver en silence, et la déclarer surveillée serait précisément le mensonge que le docstring interdit (« *any drift between the two would make this lie about what the solver did* »). **Le trou est désormais structurellement fermé** : `constraint_matrix.py` porte une dimension **`lock_silence`** (`DIAGNOSED` / `UNBYPASSABLE` + raison / `SOFT`), **obligatoire et sans défaut** — une cellule qui l'oublie lève `TypeError` et fait rougir la suite entière. Le test généré rejoue un scénario **verrou-contre-règle par cellule** : classer une famille « diagnostiquée » sans qu'elle le soit **échoue** (falsifié).
  9. `remaining_sessions` : `sum(team_vars) <= max(0, sessionsPerWeek - locked_count)`.
  10. Termes soft : `add_preferred_day_bonus` + `add_preferred_time_bonus` + `add_match_day_rest_bonus` + `add_spacing_penalty` (plus les termes `preferred` / `avoided_venue` construits inline), puis `add_level_2_objective(..., apply_chaining=False)` — objectif Level-2 **placement seul** (les termes de chaînage sont construits mais exclus de l'objectif de phase 1).
  11. **Solve en 2 phases** (voir ci-dessous) → `(status, solver, model, conflicts)`.
  12. `build_result(..., constraint_version=read_contract_version())` → dict → `ScheduleOutputSchema.model_validate(...)`.
- **Solve en 2 phases** (`_solve`) :
  - **Timeout adaptatif** (`_adaptive_timeout`) : `complexity = n_teams * n_venues` → ≤50 : 60 s · ≤200 : 180 s · sinon 600 s ; plafonné par `input_data.solver_timeout_seconds` (le budget payload reste le plafond dur).
  - **Phase 1 — placement** : `CpSolver` avec `max_time_in_seconds = timeout adaptatif`, `random_seed = input_data.solver_seed`, `num_search_workers = workers adaptatifs` (1 ou 8 selon la complexité, cf. §5). Objectif = placement uniquement (sans chaînage), pour ne pas polluer la preuve d'optimalité.
  - **Phase 2 — chaînage** (uniquement si phase 1 OPTIMAL/FEASIBLE et termes de chaînage présents) : verrouille la qualité de placement (`placement_expression >= optimum phase 1`), **warm-start** via `AddHint` sur la solution de phase 1, puis maximise `placement + chaining` sous un cap dur `CHAINING_PHASE_MAX_SECONDS = 10 s` (best-effort : si le cap tombe, le résultat de phase 1 est conservé).
- **Pas de fallback de relaxation** : toutes les contraintes HARD restent actives dans les deux phases. Si INFEASIBLE, `build_result` produit `status="failed"` avec diagnostics de conflit — pas de relaxation silencieuse. Le message d'échec (`_infeasible_message`) compte les **places** (capacités dédupliquées par triplet, miroir de `model.slot_capacities`) et non les créneaux, et nomme le gymnase dont les « au moins » dépassent les places non verrouillées (`_saturated_venue_minimum`, PR A 2026-08-06).

---

## 3. Schemas Pydantiques

### ScheduleInputSchema (`engine/app/schemas/input_schema.py`)

Version contrat active : **`"2.5"`** (fichier `CONTRACT_VERSION`, source de vérité). ⚠ Le default Pydantic du champ `version` vaut **`"2.0"`** (`input_schema.py:149`) et n'a jamais suivi les bumps : c'est un repli pour un payload qui n'annonce rien, pas la version parlée. `ConfigDict(extra="forbid", populate_by_name=True)`.

**Bornes A10** (#156, anti-bombe de génération) : la plupart des listes portent un `max_length` (rejet **422** avant CP-SAT) — `teams` ≤200 · `venues` ≤50 · `coaches` ≤200 · `slot_templates` ≤2000 · `priority_tiers` ≤20 · `trainingSlots` ≤1000/gymnase ; plus un `model_validator` bornant le **total** des créneaux à ≤3000 (empêche 50×1000). **`constraints` n'a PAS de cap engine** (ENG-23 corrigé) : le backend éclate 1 règle CLUB en N rangées/équipe, donc la taille étendue = brut(≤500)×équipes(≤200) — aucun nombre fixe ne peut à la fois borner une bombe et ne jamais faux-bloquer un club légitime ; les vraies bornes sont le cap **brut** backend (≤500) + la limite de body nginx (20 m) + le timeout solveur. Le backend (`GenerationComplexityGuard`) pré-vérifie teams/venues/coaches/contraintes permanentes/total créneaux (=3000) **plus** `teams×venues` ≤2000, **avant dispatch**. ⚠ Ce durcissement de validation (#156) n'a **pas** bumpé `CONTRACT_VERSION` : politique — un `max_length` resserre l'enveloppe acceptée sans changer forme/type ni MAJOR ; un bump n'est requis que pour un changement de forme/sémantique (champ/type/alias). Les bumps depuis 2.0 : **2.1** (#195, fenêtres horaires coach — nouveaux champs), **2.2** (P1-4 PR D, second endpoint `/place-matches` — nouveaux schémas) et **2.3** (P4-51, retrait de `maxDaysOverrideConfirmed` — un champ que rien ne lisait ; schéma `forbid`, donc sa présence dans un vieux payload devient un 422 : rupture de recevabilité, bump requis).

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `version` | — | `str` | `"2.0"` (repli — cf. ci-dessus) |
| `club_id` | `clubId` | `str` | requis |
| `season_id` | `seasonId` | `str` | requis |
| `schedule_name` | `scheduleName` | `str \| None` | `None` |
| `solver_seed` | `solverSeed` | `int` | `42` |
| `solver_timeout_seconds` | `solverTimeoutSeconds` | `int` | `650` |
| `venues` | — | `list[VenueSchema]` | `[]` |
| `teams` | — | `list[TeamSchema]` | `[]` |
| `coaches` | — | `list[CoachSchema]` | `[]` |
| `constraints` | — | `list[ConstraintV2Schema]` | `[]` |
| `slot_templates` | `slotTemplates` | `list[ScheduleSlotTemplateSchema]` | `[]` |
| `priority_tiers` | `priorityTiers` | `list[PriorityTierSchema]` | `[]` |

Sous-schemas clés :
- **VenueSchema** : `id`, `name`, `isExternal`, `color`, `latitude`, `longitude`, `source`, `externalRef`, `isActive`, `parentVenueId`, `trainingSlots: list[VenueTrainingSlotSchema]`.
- **VenueTrainingSlotSchema** : `dayOfWeek`, `startTime` (str `"19:00"`), `durationMinutes`, `capacity` (≥1, default 1).
- **TeamSchema** : `id`, `sportCategoryId`, `ageMin`, `ageMax`, `priorityTierId`, `name`, `gender`, `level`, `sessionsPerWeek`, `minSessionsOverride`, `matchDay`, `forcedVenueId`, `isActive`, `parentTeamId`, `ffbbTeamId`, `tags`.
- **CoachSchema** : `id`, `firstName`, `lastName`, `email`, `phone`, `maxDaysOverride`, `maxDaysOverrideConfirmed`, `acceptableLateMinutes`, `isActive`, `parentCoachId`, `isEmployee`.
- **ConstraintV2Schema** : unifié v2/legacy. `ConfigDict(extra="ignore")`. Champs v2 : `scope`, `scopeTargetId`, `family`, `ruleType`, `name`, `config`, `sortOrder`, `isActive`. Champs legacy v1 : `teamId`, `type`, `severity`, `value`, `metadata`.
- **ScheduleSlotTemplateSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `temporaryLock`, `temporaryLockFor`, `temporaryMinSessionsOverride`, `pendingConstraintSuggestion`.
- **PriorityTierSchema** : `id`, `label`, `orToolsWeight`, `defaultMinSessions`.

### Schémas du placement de matchs (`match_input_schema.py` / `match_output_schema.py`)

Contrat **2.5** (le MÊME que `/generate` — un seul contrat pour les deux endpoints), les schémas hebdomadaires n'étant pas réutilisés
(le problème n'a ni créneau récurrent ni séance) :

- **`MatchPlacementInputSchema`** : `version`, `clubId`, `seasonId`, `matches`, `venues`, `teams`,
  `coaches`… Sous-schémas : **`MatchVenueSchema`** (`matchWindows: list[MatchAccessWindowSchema]`
  = jour + plage `start`/`end` d'accès à la salle, `unavailabilities` datées),
  **`MatchTeamSchema`** (`leagueWindows: list[LeagueKickoffWindowSchema]` = jour +
  `kickoffMin`/`kickoffMax` imposés par la ligue, `habits: list[TeamHabitSchema]` ≤7 = jour +
  heure-point + gymnase optionnel, `coaches: list[TeamCoachRefSchema]` ≤20 avec `role`
  MAIN/ASSISTANT).
- **`MatchPlacementOutputSchema`** : `status`, `placements: list[MatchPlacementSchema]`
  (`matchId`, `venueId`, `kickoff`), **`unplaced: list[UnplacedMatchSchema]`** (`matchId`,
  `reason`, `message` — le non-plaçable sort NOMMÉ, c'est le produit), `diagnostics`
  (mêmes `DiagnosticSchema` que le solve hebdo), `metrics`.

### ScheduleOutputSchema (`engine/app/schemas/output_schema.py`)

`ConfigDict(extra="forbid", populate_by_name=True)`.

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `status` | — | `Literal["queued","generating","completed","failed"]` | requis |
| `score` | — | `int \| None` | `None` |
| `metrics` | — | `SolverMetricsSchema` | requis |
| `unplaced` | — | `list[str]` | `[]` |
| `slots` | — | `list[ScheduleSlotSchema]` | `[]` |
| `diagnostics` | — | `list[DiagnosticSchema]` | `[]` |

- **SolverMetricsSchema** : `solverVersion: str`, `nbVariables: int`, `nbConstraints: int`, `wallTimeMs: int`, plus les identifiants de déterminisme (optionnels, `None` accepté pour les anciens payloads) : `scoreFormulaVersion: str | None` (formule T24 qui a produit le score) et `constraintVersion: str | None` (version de contrat backend↔engine).
- **ScheduleSlotSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `temporaryLock`, `temporaryLockFor`, `temporaryMinSessionsOverride`, `pendingConstraintSuggestion`.
- **DiagnosticSchema** : `id`, `type`, `severity`, `teamId`, `coachId`, `venueId`, `dayOfWeek`, `startTime`, `durationMinutes`, `message`, `suggestions: list[str]`, `createdAt`.
  - Types valides : `unplaced`, `soft_lock_moved`, `coach_overload`, `session_below_effective_min`, `conflict`, `unused_slot`, `coach_no_rest_day`, `day_constraint_conflict`, `venue_minimum_unreachable`, `constraint_not_honored`. Catalogue commenté (causes + action corrective) : `engine/docs/solver-errors.md`.
  - **`constraint_not_honored`** (`_not_honored_warning`, `constraints.py`) : émis quand une contrainte saisie ne peut pas être honorée. **Deux producteurs** — (1) `parse_v2_constraints` au parse, en `WARNING`, quand la règle n'est pas traduisible en terme solver (sans équipe cible, dispo coach reçue en non-HARD, règle de gymnase écrasée) — audit P0.1, traçabilité UI↔engine ; (2) `diagnose_locked_slot_violations` après construction du modèle, en **INFO**, quand un verrou HARD a rendu la contrainte inatteignable (P2-9, cf. §2). Les deux rejoignent `diagnostics[]` via `main.py`. Cf. `docs/architecture/constraint-matrix.md` et `engine/docs/constraint-vocabulary.md`.

---

## 4. Contraintes

### 4.1 Niveaux de règle (`ruleType`)

| Niveau | Sémantique | Traitement solver |
|--------|-----------|-------------------|
| `HARD` | Impératif — faisabilité | Contrainte CP-SAT (`model.Add(...)`) |
| `PREFERRED` | Souhait — optimisation | Bonus objectif Level-2 (pas de contrainte hard) |
| `LOCK` | Règle « figée » | Traité **exactement comme `HARD`** : TIME/DAY → `time_windows` ; FACILITY → `forced_venues` / `venue_minimums`. La collection `fixed_slots` n'est alimentée par **aucune** branche de `parse_v2_constraints` (chemin résiduel). ⚠ Ne pas confondre avec `slotTemplates[].lockLevel`, autre mécanisme (cf. §5 Hard locks) |

> `BONUS` **n'existe plus** (audit P0.1 ENG-12) : l'UI ne le propose plus ; les lignes legacy `BONUS` sont normalisées en `PREFERRED` à l'entrée de `parse_v2_constraints` (`constraints.py` — plus honnête que de les dropper en silence).

### 4.2 Family & Scope

- **`family`** : catégorie de règle. Valeurs reconnues (`_KNOWN_FAMILIES`, `constraints.py`) : `TIME`, `DAY`, `FACILITY`, `COACH_AVAILABILITY`. Types legacy reconnus (`_KNOWN_TYPES`) : `TEAM_COACH`, `COACH_PLAYER_UNAVAILABILITY`, `PRIORITY_TIER`. Une contrainte dont **ni** la famille **ni** le type n'est reconnu est loggée comme dérive de contrat.
- **`scope`** : cible de la règle. Valeur vue : `TEAM`. (D'autres scopes peuvent exister mais ne sont pas traités différemment dans le code lu.)
- **`scopeTargetId`** : ID de la cible (team, coach, venue selon family/scope).

### 4.3 Mapping `parse_v2_constraints` (constraints[] → collections solver)

| Condition de match | Collection alimentée |
|--------------------|---------------------|
| `ruleType == "LOCK"` + `family in ("TIME","DAY")` | `time_windows` (traité comme `HARD` par `add_time_window_constraints`) |
| `ruleType == "LOCK"` + `family == "FACILITY"` | même traitement que `HARD` (`forced_venues` / `venue_minimums`) |
| `type == "TEAM_COACH"` (legacy) | `team_coach_map[teamId]` → coachIds (MAIN seuls — un ASSISTANT n'est pas une ressource exclusive). **Posée sur le modèle** (`model.team_coach_map`, `main.py`) : depuis ENG-17 (2026-08-07) c'est elle qui nomme le `coachId` des créneaux GÉNÉRÉS — avant, seuls les `slotTemplates` étaient consultés et les diagnostics coach restaient muets sur le chemin dominant |
| `type == "COACH_PLAYER_UNAVAILABILITY"` (legacy) | `team_player_map[teamId]` → coachIds |
| `family == "COACH_AVAILABILITY"` | `coach_unavailability[scopeTargetId]` → `unavailableDays` |
| `family == "FACILITY"` + `preferredVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `preferredVenueId` |
| `family == "FACILITY"` + `forcedVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `forcedVenueId` |
| `family == "FACILITY"` + `preferredVenueId` + `PREFERRED` + `scope=TEAM` | `preferred_venues[scopeTargetId]` → **ensemble** de gymnases (PR B 2026-08-06 : les préférences se CUMULENT, bonus si la séance tombe dans l'un d'eux ; le last-wins + INFO ne reste que sur `forced_venues`) |
| `family == "FACILITY"` + `forbiddenVenueId` | `forbidden_assignments` → `[{scope_target_id, venue_id}]` |
| `family == "FACILITY"` + `forbiddenVenueId` + `PREFERRED` + cible | `avoided_venues` → `[{scope_target_id, venue_id}]` (malus objectif, poids `avoided_venue`). **Même clé** que l'interdiction dure : c'est le `ruleType` qui décide dur/soft (il n'existe **pas** de clé `avoidedVenueId`) |
| `family == "FACILITY"` + `minAtVenueId` (+ `minAtVenueCount`, défaut 1) + HARD/LOCK + `scope=TEAM` | `venue_minimums` → plancher `somme(vars équipe@gymnase) ≥ N` (ALIGN-05) |
| contrainte reconnue mais inapplicable (sans équipe cible, dispo coach reçue en non-HARD, règle de gymnase écrasée par une autre) | `parse_warnings` → diagnostics `constraint_not_honored` |
| `type == "PRIORITY_TIER"` (legacy) | `priority_tiers[tierId]` = `defaultMinSessions` |
| `family in ("TIME","DAY")` | `time_windows` (traité par `add_time_window_constraints`) |

### 4.4 Contraintes Hard Level-1 (`add_level_1_hard_constraints`)

Familles de contraintes comptées dans `HardConstraintStats` (liste exhaustive : dataclass dans `app/solver/constraints.py`) :

| # | Nom | Rôle |
|---|-----|------|
| 1 | `room_at_most_one` | Une salle accueille ≤ `capacity` équipes par créneau |
| 2 | `coach_at_most_one` | Un coach encadre ≤ 1 équipe par créneau (time_key + interval overlap) |
| 3 | `coach_player_non_overlap` | Un coach-joueur ne peut pas être aux deux endroits simultanément |
| 3b | `coach_rest_day` | Chaque coach a ≥ 1 jour de repos (Mon-Fri) — skip si `maxDaysOverride ≤ 4` |
| 3c | `salarie_distribution` | ≥ 1 coach salarié (`isEmployee=True`) présent chaque jour Mon-Fri — skip si < 2 salariés |
| 3d | `max_consecutive_sessions` | Un coach ne peut pas être dans les 3 slots d'un triple consécutif (cross-venue) |
| 4 | `team_no_overlap` | Une équipe ne peut pas avoir 2 sessions au même créneau |
| 5 | `fixed_slots` | Slots pré-placés (LOCK) forcés à 1 |
| 6 | `forbidden_assignments` | Variables interdites forcées à 0 (ID ou pair team+venue) |
| 7 | `coach_unavailability` | Slots coach indisponible forcés à 0 |
| 8 | `min_sessions` | **Câblé SOFT-ONLY (ENG-18)** : `_solve` passe un plancher **0** pour chaque équipe, donc aucune contrainte dure n'est posée. La cible est portée par le bonus objectif `session_count` + les diagnostics `session_below_effective_min`. La fonction reste *capable* d'un plancher dur, non utilisé en production |
| 9 | `forced_venues` | Si salle forcée, autres salles exclues (forcées à 0) |
| 10 | `one_session_per_day` | ≤ 1 session/jour/équipe (sans exception — le drapeau `allowMultipleSessionsPerDay`, jamais écrit, retiré en P4-79) |
| 11 | `age_ascending` | Teams plus jeunes entraînées plus tôt (même venue+jour) — exempt si `ageMin=None` ou HARD-locked |

Stubs (toujours satisfaits, 0 contraintes) : `travel_feasibility`, `required_bridge`.

### 4.5 Time windows (`add_time_window_constraints`)

- `family == "TIME"` + `ruleType == "HARD"` : force `var == 0` si `startTime` hors `[minStartTime, maxStartTime]`.
- `family == "TIME"` + `maxEndTime` (HARD only, ALIGN-04) : force `var == 0` si `début du créneau + sa durée > maxEndTime`. Le chemin soft (`add_preferred_time_bonus`) ne lit **que** min/maxStartTime.
- `family == "DAY"` + `ruleType == "HARD"` : `forcedDays` (≥ 1 session sur ces jours), `forbiddenDays` (vars à 0), `allowedDays` (liste blanche : tout jour praticable hors liste est interdit ; liste vide = « non configuré », aucune restriction).
- `family == "TIME"`/`"DAY"` + `ruleType == "PREFERRED"` : **bonus soft dans l'objectif** (`add_preferred_time_bonus` / `add_preferred_day_bonus`, poids `preferred_time`/`preferred_day` = 30) — pas de contrainte hard. Cf. commentaire `constraints.py` « PREFERRED TIME is a soft bonus handled in the objective ».
- Conflit → diagnostic `day_constraint_conflict` (severity ERROR), toutes vars team à 0. **Deux formes** : `forcedDays ∩ forbiddenDays` non vide, OU une liste blanche `allowedDays` dont **tous** les jours sont explicitement interdits (les deux sont testées contre le `forbiddenDays` d'origine, pas contre le complément de la whitelist, pour que le diagnostic soit explicite).

---

## 5. Solver

- **Bibliothèque** : Google OR-Tools CP-SAT (`cp_model.CpModel`, `cp_model.CpSolver`).
- **Variables** : booléennes `x[team_id, venue_id, day_of_week, slot_start]` (type `SlotKey = tuple[str, str, int, str]`).
- **Granularité** : `SLOT_MINUTES = 15` (model.py).
- **Durée session default** : `DEFAULT_SESSION_MINUTES = 90`.
- **Timeout solver** : adaptatif (`_adaptive_timeout`, voir §2) — `n_teams × n_venues` ≤50 : 60 s · ≤200 : 180 s · sinon 600 s, plafonné par `solver_timeout_seconds` du payload (default **650 s** dans `ScheduleInputSchema`). Phase 2 (chaînage) plafonnée en plus par `CHAINING_PHASE_MAX_SECONDS = 10`.
- **Seed** : `solver.parameters.random_seed = input_data.solver_seed` (default 42) — les deux phases.
- **Déterminisme (ENG-25, 2026-08-07)** : les agrégations par équipe itèrent sur des clés `str`,
  dont le hash est randomisé PAR PROCESSUS. `add_preferred_day_bonus` **trie** désormais
  (`objective.py`) — sans quoi l'ordre d'ajout des termes soft, donc le chemin de recherche de
  CP-SAT, changeait d'un run à l'autre : même payload, même `solverSeed`, planning différent (de
  valeur d'objectif identique). ⚠ `PYTHONHASHSEED` n'est **délibérément pas figé** : ce serait
  traiter le symptôme, et le figer désarme la protection contre les collisions de hash. L'ordre se
  décide là où il compte. Gardé par `tests/test_deterministic_term_order.py`.
- **Harnais de test (ENG-26, 2026-08-07)** : `tests/support/pipeline.py` annonce la version lue
  depuis `CONTRACT_VERSION`. Elle était figée à `"1.0"` — un payload que `POST /generate` refuse en
  422 : `solve_payload` court-circuitant la couche FastAPI, le garde ne tournait jamais, et toute la
  suite sémantique validait une enveloppe que personne n'accepterait en production. Gardé par
  `tests/test_harness_speaks_the_real_contract.py`.
- **Workers** : `num_search_workers` **adaptatif** (`_adaptive_workers`, main.py) — complexité `n_teams×n_venues` ≤200 → **1** (déterministe, dont dépendent les goldens petits) · else → **8** (le worker unique trouve l'optimum en ~2s sur les problèmes denses riches en soft mais ne le prouve pas — 612s de blocage sur BCCL ; le portfolio 8 workers ferme la preuve en ~2s, même valeur d'objectif, assignation non-déterministe mais valeur stable). Appliqué aux deux phases.
  - ⚠️ **Réconciliation spec** : `specs/initiales/…contraintes_v2.md §2` promet « même entrée + même `solver_seed` + même version → planning **exactement** identique ». Depuis les workers adaptatifs, cette garantie n'est plus **exacte** qu'en dessous du seuil (≤200 complexité, 1 worker) ; au-dessus, seule la **valeur d'objectif** (score) est reproductible, pas l'arrangement exact (décision produit 2026-07-07, cf. roadmap §1 — le gestionnaire ajuste de toute façon). Les initiales étant gelées, la réconciliation vit ici.
- **Objectif Level-2** : `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V7"`. Maximise somme pondérée. Poids fixes (`LEVEL_2_OBJECTIVE_WEIGHTS`, objective.py — source de vérité, ne pas figer d'autres valeurs ici) :

| Critère | Poids |
|---------|-------|
| Tier S | 10 000 |
| Tier A | 1 000 |
| Tier B | 100 |
| `session_count` | 20 |
| `preferred` | 60 |
| `avoided_venue` | −60 (malus soft, audit P0.1 ENG-11) |
| `preferred_day` | 30 |
| `preferred_time` | 30 |
| Tier C | 10 |
| Tier D | 1 |
| `rest` | 3 |
| `spacing` | −2 (malus soft, ALIGN-06 : deux séances d'une même équipe sur des jours consécutifs) |

- **Contraintes v2 effectives** (série ENGINE, 2026-07-03) : `parse_v2_constraints` → `ParsedConstraints` (TypedDict). Indispo coach par jour (COACH_AVAILABILITY `unavailableDays`/`availableDays`, jours int — la CIBLE est le `scopeTargetId`, `config.coachId` supprimé le 2026-08-08) appliquée ; ~~FACILITY_CAPACITY (`maxTeams`)~~ **famille retirée le 2026-08-08** (SEC-13 PR C : honorée par le moteur, créable par personne — la capacité vit sur `trainingSlots[].capacity`) ; LOCK TIME/DAY = HARD ; `allowedDays` = whitelist ; `forcedDays`/`forbiddenDays`/min-maxStartTime HARD. `preferred_time` (soft) + repos lendemain de match (règle implicite, `matchDay` → jour+1 libre, poids `rest`).

- `UNPLACED_PENALTY = 100 000` (par team non placée, sauf `hard_satisfied_team_ids`).
- **Chaining bonus** (phase 2 uniquement) : `CHAINING_TIER_WEIGHTS = {S:8, A:6, B:4, C:2, D:1}` — bonus entier pour sessions back-to-back même venue même coach, poids du tier le plus haut de la paire. Plafonné à 8 par construction : < 21 (valeur minimale d'une session placée) pour ne jamais sacrifier un placement, et ≤ 8 (écart C−D = 9) pour ne jamais voler un slot à un tier supérieur.
- **Hard locks** : `HARD_LOCK_LEVEL = "HARD"` (model.py). Slots `lockLevel == "HARD"` → variable forcée à 1, venue bloquée pour autres teams sur ces créneaux. `blocked_venue_slots` retire le `(venue,day,start)` pour **toutes** les autres équipes : un verrou prend le **créneau entier**, même divisible (`capacity>1`) — ALIGN-07, comportement assumé (décision gestionnaire). Partager un créneau divisible = co-épingler explicitement les N équipes ; le diagnostic over-capacity (`result_builder.py`) ne se déclenche qu'au-delà de `capacity`. Gardé par `engine/tests/semantic/test_hard_lock_divisible_slot.py`.

### Per-club asyncio locks

- `_club_locks: dict[str, asyncio.Lock]` + `_club_locks_guard: asyncio.Lock` (module-level, `main.py`).
- `get_club_lock(club_id)` : crée/récupère un `asyncio.Lock` par `club_id` sous le guard.
- `generate_schedule` : `async with lock: await build_schedule(input_data)` — empêche la génération concurrente pour le même club. Différents clubs peuvent être résolus en parallèle.

---

## 6. Communication Backend ↔ Engine

- **Backend → Engine** : HTTP POST `http://engine:8000/generate` depuis `GenerateScheduleHandler` (backend Symfony). Payload = `ScheduleInputSchema` (tout le contexte : venues, teams, coaches, constraints, slotTemplates, priorityTiers).
- **Engine → Backend** : **jamais**. L'engine est purement réactif — il ne contacte pas le backend.
- **Frontend → Engine** : **jamais directement**. Le frontend passe toujours par le backend (`/api/*`).
- **Réponse** : `ScheduleOutputSchema` retourné au backend, qui persiste les slots et publie sur Mercure.
- **Isolation tenant** : `clubId` + `seasonId` dans le payload ; lock asyncio par `club_id`.
- **Endpoint auxiliaire** : `POST /implicit-constraints` permet au backend de vérifier la synchronisation des règles implicites (200 synchronized / 409 desynchronized avec `missing_in_engine` / `missing_in_backend`).

---

## 7. Tests & Fixtures

- **Fixtures golden** (`engine/tests/fixtures/`) : scénarios JSON (liste : `ls engine/tests/fixtures/`) — dont `simple_club`, `medium_club`, `dense_club`, `bccl_regression`, `impossible`, `age_order_club`, `consecutive_emerick`, `no_rest_enzo`, `overlap_anna`, `overlap_nicolas`, `score_hard_only_teams`, `vacation_week`.
- **Suites** (emplacements — liste vivante via `ls engine/tests/`) : `tests/golden/`, `tests/invariants/`, `tests/perf/`, **`tests/semantic/`** (matrice de contraintes audit P0.1 — `constraint_matrix.py` = source unique UI↔engine, `test_constraint_matrix.py`, `test_diagnostics.py`, `test_features.py`, `test_semantic_smoke.py`, `test_hard_lock_divisible_slot.py` = ALIGN-07, `test_hard_lock_announces_violations.py` = P2-9 volet 1), `tests/test_result_builder.py`, plus tests spécialisés (age order, chaining bonus, coach rest day, salarié distribution, max consecutive sessions, adaptive timeout, capacity slots, time/day constraints, objective, generate contract…).
- **Toolchain tests** : `pytest` + `pytest-timeout` + `hypothesis`.
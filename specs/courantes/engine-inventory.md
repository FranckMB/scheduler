# Engine Inventory — Backward Spec

Last verified @ 51201005 2026-06-30

> Inventaire BACKWARD de l'existant engine. Reflète le code lu au SHA ci-dessus, pas les features futures.
> Source de vérité : `engine/app/main.py`, `engine/app/schemas/input_schema.py`, `engine/app/schemas/output_schema.py`, `engine/app/solver/{model,constraints,objective,result_builder}.py`, `engine/app/core/config.py`.

---

## 1. Architecture Engine

- **Runtime** : Python 3.12.
- **Framework HTTP** : FastAPI (app construite dans `engine/app/main.py` via `get_settings()` → `app_name`/`app_version`).
- **Solver** : Google OR-Tools CP-SAT (`from ortools.sat.python import cp_model`).
- **Validation** : Pydantic v2 (`BaseModel`, `ConfigDict`, `Field`, `populate_by_name=True`).
- **Settings** : `pydantic-settings` (`engine/app/core/config.py`), prefix env `ENGINE_`, `.env` lu. Defaults : `app_name="engine"`, `app_version="1.0"`, `contract_version="2.0"`, `environment="dev"`, `log_level="info"`.
- **Contract version** : lu depuis `engine/CONTRACT_VERSION` (fichier = `2.0`), fallback `settings.contract_version`.
- **Structure interne** :
  - `app/main.py` — endpoints FastAPI + pipeline solver.
  - `app/core/config.py` — settings.
  - `app/schemas/input_schema.py` — `ScheduleInputSchema`.
  - `app/schemas/output_schema.py` — `ScheduleOutputSchema`.
  - `app/solver/model.py` — `ScheduleCpModel` (variables booléennes `x[team, venue, day, slot]`).
  - `app/solver/constraints.py` — contraintes Level-1 (hard) + `parse_v2_constraints`.
  - `app/solver/objective.py` — objectif Level-2 (poids fixes T24).
  - `app/solver/result_builder.py` — solution → `ScheduleOutputSchema` + diagnostics.
- **Port** : 8000 (conteneur Docker `engine`).
- **Commandes** : tout via `engine/Makefile` dans le conteneur (`make test`, `make lint`, `make exec`).

---

## 2. Endpoints Engine

Quatre endpoints exposés par `app/main.py` :

| Endpoint | Méthode | Rôle | Response model |
|----------|---------|------|----------------|
| `/` | GET | Health + `contract_version` | `{"status":"ok","contract_version":...}` |
| `/health` | GET | Health simple | `{"status":"ok"}` |
| `/generate` | POST | **Principal** — résout un planning | `ScheduleOutputSchema` |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine | `JSONResponse` (200 synchronized / 409 desynchronized) |

### POST /generate

- **Handler** : `generate_schedule(input_data: ScheduleInputSchema)`.
- **Isolation** : acquiert un `asyncio.Lock` par `club_id` (voir §5) avant de lancer `build_schedule`.
- **Pipeline** (`build_schedule` → `_solve`) :
  1. `input_data.model_dump(by_alias=True)` → dict.
  2. `build_model(data)` — crée `ScheduleCpModel`, variables `x`, extrait HARD locks.
  3. `parse_v2_constraints(data["constraints"])` — règle v2 → collections solver.
  4. Calcul `hard_satisfied_team_ids` (teams dont `sessionsPerWeek` est couvert par locks HARD → exclus du penalty unplaced).
  5. `adjusted_min_by_team` — min sessions mis à 0 pour teams sans assignments disponibles ou en conflit forcedDays/forbiddenDays.
  6. Construction `assignments` avec start/end pour contraintes consécutives.
  7. `add_level_1_hard_constraints(...)` — toutes les contraintes hard en un seul pass.
  8. `add_time_window_constraints(...)` — TIME/DAY hard windows + conflits.
  9. `remaining_sessions` : `sum(team_vars) <= max(0, sessionsPerWeek - locked_count)`.
  10. `add_preferred_day_bonus(...)` + `add_level_2_objective(...)` — objectif Level-2.
  11. `cp_model.CpSolver().Solve(model)` avec `max_time_in_seconds = input_data.solver_timeout_seconds`, `random_seed = input_data.solver_seed`, `num_search_workers = 1`.
  12. `build_result(...)` → dict → `ScheduleOutputSchema.model_validate(...)`.
- **Pas de fallback two-pass** : un seul pass avec toutes les contraintes HARD. Si INFEASIBLE, `build_result` produit `status="failed"` avec diagnostics de conflit — pas de relaxation silencieuse.

---

## 3. Schemas Pydantiques

### ScheduleInputSchema (`engine/app/schemas/input_schema.py`)

Version contrat : `"2.0"` (default). `ConfigDict(extra="forbid", populate_by_name=True)`.

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `version` | — | `str` | `"2.0"` |
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
- **TeamSchema** : `id`, `sportCategoryId`, `ageMin`, `ageMax`, `priorityTierId`, `name`, `gender`, `level`, `sessionsPerWeek`, `minSessionsOverride`, `matchDay`, `allowMultipleSessionsPerDay`, `forcedVenueId`, `isActive`, `parentTeamId`, `ffbbTeamId`, `tags`.
- **CoachSchema** : `id`, `firstName`, `lastName`, `email`, `phone`, `maxDaysOverride`, `maxDaysOverrideConfirmed`, `acceptableLateMinutes`, `isActive`, `parentCoachId`, `isEmployee`.
- **ConstraintV2Schema** : unifié v2/legacy. `ConfigDict(extra="ignore")`. Champs v2 : `scope`, `scopeTargetId`, `family`, `ruleType`, `name`, `config`, `sortOrder`, `isActive`. Champs legacy v1 : `teamId`, `type`, `severity`, `value`, `metadata`.
- **ScheduleSlotTemplateSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `temporaryLock`, `temporaryLockFor`, `temporaryMinSessionsOverride`, `pendingConstraintSuggestion`.
- **PriorityTierSchema** : `id`, `label`, `orToolsWeight`, `defaultMinSessions`.

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

- **SolverMetricsSchema** : `solverVersion: str`, `nbVariables: int`, `nbConstraints: int`, `wallTimeMs: int`.
- **ScheduleSlotSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `temporaryLock`, `temporaryLockFor`, `temporaryMinSessionsOverride`, `pendingConstraintSuggestion`.
- **DiagnosticSchema** : `id`, `type`, `severity`, `teamId`, `coachId`, `venueId`, `dayOfWeek`, `startTime`, `durationMinutes`, `message`, `suggestions: list[str]`, `createdAt`.
  - Types valides (commentaire code) : `unplaced`, `soft_lock_moved`, `coach_overload`, `session_below_effective_min`, `conflict`, `unused_slot`, `coach_no_rest_day`, `day_constraint_conflict`.

---

## 4. Contraintes

### 4.1 Niveaux de règle (`ruleType`)

| Niveau | Sémantique | Traitement solver |
|--------|-----------|-------------------|
| `HARD` | Impératif — faisabilité | Contrainte CP-SAT (`model.Add(...)`) |
| `PREFERRED` | Souhait — optimisation | Bonus objectif Level-2 (pas de contrainte hard) |
| `BONUS` | Bonus — optimisation | Bonus objectif Level-2 |
| `LOCK` | Slot pré-placé fixé | `fixed_slots` → variable forcée à 1 |

### 4.2 Family & Scope

- **`family`** : catégorie de règle. Valeurs vues dans `parse_v2_constraints` : `TIME`, `DAY`, `COACH_AVAILABILITY`, `FACILITY`.
- **`scope`** : cible de la règle. Valeur vue : `TEAM`. (D'autres scopes peuvent exister mais ne sont pas traités différemment dans le code lu.)
- **`scopeTargetId`** : ID de la cible (team, coach, venue selon family/scope).

### 4.3 Mapping `parse_v2_constraints` (constraints[] → collections solver)

| Condition de match | Collection alimentée |
|--------------------|---------------------|
| `ruleType == "LOCK"` | `fixed_slots` (IDs forcés à 1) |
| `type == "TEAM_COACH"` (legacy) | `team_coach_map[teamId]` → coachIds |
| `type == "COACH_PLAYER_UNAVAILABILITY"` (legacy) | `team_player_map[teamId]` → coachIds |
| `family == "COACH_AVAILABILITY"` | `coach_unavailability[scopeTargetId]` → `unavailableDays` |
| `family == "FACILITY"` + `dateStart` | `venue_closures[scopeTargetId]` → config |
| `family == "FACILITY"` + `preferredVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `preferredVenueId` |
| `family == "FACILITY"` + `forcedVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `forcedVenueId` |
| `family == "FACILITY"` + `preferredVenueId` + `PREFERRED` + `scope=TEAM` | `preferred_venues[scopeTargetId]` = `preferredVenueId` |
| `family == "FACILITY"` + `forbiddenVenueId` | `forbidden_assignments` → `[{scope_target_id, venue_id}]` |
| `type == "PRIORITY_TIER"` (legacy) | `priority_tiers[tierId]` = `defaultMinSessions` |
| `family in ("TIME","DAY")` | `time_windows` (traité par `add_time_window_constraints`) |

### 4.4 Contraintes Hard Level-1 (`add_level_1_hard_constraints`)

17 contraintes comptées dans `HardConstraintStats` :

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
| 8 | `venue_closures` | Slots salle fermée forcés à 0 |
| 9 | `min_sessions` | Chaque équipe a ≥ son minimum effectif de sessions |
| 10 | `forced_venues` | Si salle forcée, autres salles exclues (forcées à 0) |
| 11 | `one_session_per_day` | ≤ 1 session/jour/équipe sauf `allowMultipleSessionsPerDay=True` |
| 12 | `age_ascending` | Teams plus jeunes entraînées plus tôt (même venue+jour) — exempt si `ageMin=None` ou HARD-locked |

Stubs (toujours satisfaits, 0 contraintes) : `travel_feasibility`, `required_bridge`.

### 4.5 Time windows (`add_time_window_constraints`)

- `family == "TIME"` + `ruleType == "HARD"` : force `var == 0` si `startTime` hors `[minStartTime, maxStartTime]`.
- `family == "DAY"` + `ruleType == "HARD"` : `forcedDays` (≥ 1 session sur ces jours), `forbiddenDays` (vars à 0).
- `family == "TIME"` + `ruleType == "PREFERRED"` : **non implémenté** (TODO dans le code, `continue`).
- Conflit `forcedDays ∩ forbiddenDays` → diagnostic `day_constraint_conflict` (severity ERROR), toutes vars team à 0.

---

## 5. Solver

- **Bibliothèque** : Google OR-Tools CP-SAT (`cp_model.CpModel`, `cp_model.CpSolver`).
- **Variables** : booléennes `x[team_id, venue_id, day_of_week, slot_start]` (type `SlotKey = tuple[str, str, int, str]`).
- **Granularité** : `SLOT_MINUTES = 15` (model.py).
- **Durée session default** : `DEFAULT_SESSION_MINUTES = 90`.
- **Timeout solver** : `solver.parameters.max_time_in_seconds = input_data.solver_timeout_seconds` (default **650s** dans `ScheduleInputSchema`). **Note** : `engine/AGENTS.md` et `engine/README.md` mentionnent 10s hardcoded, mais le code lu utilise le champ input `solver_timeout_seconds` (default 650) — l'AGENTS.md est stale sur ce point.
- **Seed** : `solver.parameters.random_seed = input_data.solver_seed` (default 42).
- **Workers** : `solver.parameters.num_search_workers = 1`.
- **Objectif Level-2** : `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V3"`. Maximise somme pondérée. Poids fixes :

| Critère | Poids |
|---------|-------|
| Tier S | 10 000 |
| Tier A | 1 000 |
| SOFT | 800 |
| Tier B | 100 |
| `session_count` | 20 |
| `pref_link` | 80 |
| `preferred` | 60 |
| `grouping` | 50 |
| `preferred_day` | 30 |
| Tier C | 10 |
| `max_days` | 8 |
| `opt_link` | 5 |
| Tier D | 1 |
| `rest` | 3 |

- `UNPLACED_PENALTY = 100 000` (par team non placée, sauf `hard_satisfied_team_ids`).
- **Chaining bonus** : `CHAINING_TIER_WEIGHTS = {S:200, A:20, B:2, C:0.2, D:0.02}` — bonus pour sessions back-to-back même venue même coach.
- **Hard locks** : `HARD_LOCK_LEVEL = "HARD"` (model.py). Slots `lockLevel == "HARD"` → variable forcée à 1, venue bloquée pour autres teams sur ces créneaux.

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

- **Fixtures golden** (`engine/tests/fixtures/`) : 12 scénarios — `simple_club`, `medium_club`, `dense_club`, `bccl_regression` (155 KB), `impossible`, `age_order_club`, `consecutive_emerick`, `no_rest_enzo`, `overlap_anna`, `overlap_nicolas`, `score_hard_only_teams`, `vacation_week`.
- **Suites** : `tests/golden/`, `tests/invariants/`, `tests/test_result_builder.py`, plus tests spécialisés (`test_age_order`, `test_chaining_bonus`, `test_coach_rest_day`, `test_salarie_distribution`, `test_max_consecutive_sessions`, `test_time_constraints`, `test_constraints`, `test_objective`, `test_generate_contract`, etc.).
- **Toolchain tests** : `pytest` + `pytest-timeout` + `hypothesis`.
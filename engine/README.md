# ClubScheduler — Engine

> Moteur d'optimisation Python (FastAPI + OR-Tools CP-SAT). Calcule les plannings optimaux.

## Rôle dans l'architecture

L'**engine** est un microservice Python qui reçoit un contexte complet (clubs, équipes, salles, entraîneurs, contraintes) et résout le problème d'optimisation via **CP-SAT** (OR-Tools). Il retourne un planning optimisé avec diagnostics.

```
┌─────────────┐     POST /generate      ┌─────────────┐
│   Backend   │ ───────────────────────▶ │   Engine    │
│  (Symfony)  │   ScheduleInputSchema    │  (FastAPI)  │
│             │                          │             │
│             │ ◀──────────────────────  │             │
│             │   ScheduleOutputSchema   │             │
└─────────────┘                          └─────────────┘
```

## Communication inter-services

### Engine → Backend
- L'engine **ne contacte jamais le backend directement**. Il est purement réactif.
- Le backend envoie un POST `/generate` avec tout le contexte nécessaire
- L'engine retourne le résultat et le backend met à jour ses entités

### Backend → Engine
- Le backend (via `GenerateScheduleHandler`) envoie un POST à `http://engine:8000/generate`
- Le payload contient toutes les données du club : `venues`, `teams`, `coaches`, `constraints`, `slotTemplates`
- L'engine utilise `clubId` et `seasonId` pour l'isolation des tenants

### Frontend → Engine
- Le frontend **ne contacte jamais l'engine directement**. Il passe toujours par le backend.
- Le backend nginx expose l'engine sur `/api/engine/*` (si configuré), mais le frontend utilise `/api` → backend.

## API Endpoints

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/` | GET | Health check + version du contrat |
| `/health` | GET | Health check simple |
| `/generate` | POST | **Principal** — résout un planning et retourne les créneaux |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine (200 synchronized / 409 desynchronized) |

### `POST /generate`

**Request** : `ScheduleInputSchema` (contract `"2.0"`)

```json
{
  "version": "2.0",
  "clubId": "uuid",
  "seasonId": "uuid",
  "scheduleName": "Saison 2026-2027",
  "solverSeed": 42,
  "venues": [...],
  "teams": [...],
  "coaches": [...],
  "constraints": [...],
  "slotTemplates": [...]
}
```

**Response** : `ScheduleOutputSchema`

```json
{
  "status": "completed",
  "score": 12345,
  "slots": [
    {
      "id": "slot-1",
      "teamId": "team-1",
      "venueId": "venue-1",
      "coachId": "coach-1",
      "dayOfWeek": 1,
      "startTime": "18:00",
      "durationMinutes": 120,
      "lockLevel": "NONE"
    }
  ],
  "diagnostics": [
    {
      "type": "unplaced",
      "severity": "high",
      "teamId": "team-2",
      "message": "Équipe non placée",
      "suggestions": [...]
    }
  ]
}
```

## Commandes principales

```bash
# Toutes les commandes s'exécutent DANS le conteneur engine
# Le Makefile les lance automatiquement dans le conteneur

make test             # ruff + mypy + pytest
make lint             # ruff + mypy
make format           # ruff --fix (auto-format)
make exec             # Entrer dans le conteneur engine

# Dans le conteneur :
pytest tests/                    # Tests complets
pytest tests/golden/             # Solves complets sur fixtures de club (golden)
pytest tests/invariants/         # Invariants post-solve (pas d'overlap, capacité, locks)
```

> ⚠️ Commandes engine = **dans Docker** (le Makefile enveloppe `docker compose exec`). Elles échouent sur l'hôte.

## Architecture interne

```
engine/
├── app/
│   ├── main.py              # FastAPI entry point + endpoints
│   ├── schemas/
│   │   ├── input_schema.py  # ScheduleInputSchema
│   │   └── output_schema.py # ScheduleOutputSchema
│   └── solver/
│       ├── model.py         # Construction du modèle CP-SAT
│       ├── constraints.py   # Contraintes hard Level-1 + parse_v2_constraints
│       ├── objective.py     # Fonction objectif (Level 2)
│       └── result_builder.py # Transformation solution → output
├── tests/
│   ├── fixtures/            # Jeux de données "golden" (liste : ls tests/fixtures/)
│   ├── golden/ invariants/ perf/ semantic/  # suites (semantic = matrice contrainte P0.1)
│   └── ...
├── Dockerfile
└── Makefile
```

## Pipeline du solver

```
1. Reçoit POST /generate avec ScheduleInputSchema
2. model.py          Crée variables booléennes x[team, venue, day, slot]
3. constraints.py    Applique les contraintes hard Level-1 (liste : constraints.py / engine-inventory §4.4)
4. objective.py      Maximise le score pondéré (Level 2) — poids réels dans LEVEL_2_OBJECTIVE_WEIGHTS :
                      - Tiers S: 10000, A: 1000, B: 100, C: 10, D: 1
                      - preferred: 60 · avoided_venue: -60 · preferred_day/time: 30
                      - session_count: 20 · rest: 3
                      (source de vérité = objective.py ; ne pas figer d'autres valeurs)
5. OR-Tools CP-SAT   Solve en 2 phases (placement puis chaînage borné 10s), warm-start.
                      timeout adaptatif plafonné par solver_timeout_seconds (défaut 650s) ; seed = solver_seed (42)
6. result_builder.py   Transforme solution → ScheduleOutputSchema
                       + génère diagnostics (unplaced, soft_lock_moved, coach_overload, conflict)
```

> **Pass unique, pas de fallback silencieux.** INFEASIBLE → `status="failed"` + diagnostics (décision : [`docs/architecture/adr-0001-single-pass-solve.md`](../docs/architecture/adr-0001-single-pass-solve.md)). Le timeout et le seed viennent du payload (`solver_timeout_seconds` / `solver_seed`), pas codés en dur.

## Contraintes CP-SAT

> Liste exhaustive et à jour : `constraints.py` (`add_level_1_hard_constraints` / `add_time_window_constraints`) et **`specs/courantes/engine-inventory.md` §4** (la seule vue maintenue — pas de décompte figé ici, il périmerait).

### Hard (Level 1) — Impératives
Salle at-most-one (capacité), coach at-most-one, coach-joueur non-overlap, repos coach / distribution salariés / max consécutifs, fixed slots (LOCK), forbidden assignments, indispo coach, fermetures salle, min sessions, forced venues, une session/jour, âge croissant. Détail : engine-inventory §4.4.

### Soft (Level 2) — Optimisées
Tiers S>A>B>C>D, `preferred`, `avoided_venue` (malus), `preferred_day`, `preferred_time`, `session_count`, `rest`. Poids réels : `objective.py` (`LEVEL_2_OBJECTIVE_WEIGHTS`).

## Pour aller plus loin (docs structurantes)

Le métier du solveur vit dans `engine/docs/` — à lire avant de toucher au solveur :

| Doc | Contenu |
|-----|---------|
| [`docs/business.md`](docs/business.md) | **Cœur métier** — concepts (équipe, salle, coach, contrainte : scopes/familles/règles, tiers de priorité, contraintes implicites, niveaux de lock). |
| [`docs/nominal-flow.md`](docs/nominal-flow.md) | Flux nominal d'une requête de bout en bout — structure du payload v2.0, négociation de version, locks par club, étapes du pipeline, schéma de sortie. |
| [`docs/solver-errors.md`](docs/solver-errors.md) | Erreurs & diagnostics — erreurs HTTP, statuts solveur, types de diagnostics, scénarios d'infaisabilité, lecture du score, guide de debug. |
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent (conventions ruff/mypy/pytest, gotchas, quick-reference). |

Contrat backend↔engine : version dans `engine/CONTRACT_VERSION`, synchronisé **à la main** (pas de codegen), gardé par `backend/tests/.../ContractSchemaTest`.

## Environnement

- **Python** : 3.12
- **Framework** : FastAPI
- **Solver** : Google OR-Tools CP-SAT
- **Port** : 8000 (exposé via docker-compose)
- **Isolation** : Per-club asyncio locks (pas de génération concurrente pour le même club)

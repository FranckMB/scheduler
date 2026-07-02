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

### `POST /generate`

**Request** : `ScheduleInputSchema`

```json
{
  "version": "1.0",
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
│       ├── constraints.py   # 11 contraintes hard (Level 1)
│       ├── objective.py     # Fonction objectif (Level 2)
│       └── result_builder.py # Transformation solution → output
├── tests/
│   ├── fixtures/            # 5 jeux de données "golden"
│   └── test_golden.py       # Tests de validation
├── Dockerfile
└── Makefile
```

## Pipeline du solver

```
1. Reçoit POST /generate avec ScheduleInputSchema
2. model.py          Crée variables booléennes x[team, venue, day, slot]
3. constraints.py    Applique 11 contraintes hard (Level 1)
                      - Room at-most-one
                      - Coach at-most-one
                      - Coach-player non-overlap
                      - Fixed slots
                      - Forbidden assignments
                      - Coach unavailability
                      - Venue closures
                      - Min sessions
                      - Forced venues
                      - ...
4. objective.py      Maximise le score pondéré (Level 2)
                      - Tier S: 10000, A: 1000, B: 100, C: 10, D: 1
                      - Soft constraints: 800
                      - Preferred slots: 60
                      - Grouping: 50
                      - Max days: 8
                      - Rest: 3
5. OR-Tools CP-SAT   Solve(max_time = solver_timeout_seconds, défaut 650s ; seed = solver_seed, défaut 42)
6. result_builder.py   Transforme solution → ScheduleOutputSchema
                       + génère diagnostics (unplaced, soft_lock_moved, coach_overload, conflict)
```

> **Pass unique, pas de fallback silencieux.** INFEASIBLE → `status="failed"` + diagnostics (décision : [`docs/architecture/adr-0001-single-pass-solve.md`](../docs/architecture/adr-0001-single-pass-solve.md)). Le timeout et le seed viennent du payload (`solver_timeout_seconds` / `solver_seed`), pas codés en dur.

## Contraintes CP-SAT

### Hard (Level 1) — Impératives
1. **Room at-most-one** : Une salle accueille max 1 équipe par créneau
2. **Coach at-most-one** : Un entraîneur encadre max 1 équipe par créneau
3. **Coach-player non-overlap** : Un entraîneur-joueur ne peut pas être aux deux endroits
4. **Fixed slots** : Les locks HARD sont forcés
5. **Forbidden assignments** : Les créneaux interdits sont exclus
6. **Coach unavailability** : Les indisponibilités entraîneurs sont respectées
7. **Venue closures** : Les fermetures de salles sont respectées
8. **Min sessions** : Chaque équipe a ≥ son minimum de sessions
9. **Forced venues** : Si une salle est forcée, les autres sont exclus

### Soft (Level 2) — Optimisées
- **Priority tiers** : S (10000) > A (1000) > B (100) > C (10) > D (1)
- **Soft constraints** : Respect des préférences (800)
- **Preferred slots** : Créneaux préférés (60)
- **Grouping** : Regroupement d'équipes (50)
- **Max days** : Respect du max de jours par entraîneur (8)
- **Rest** : Temps de repos (3)

## Pour aller plus loin (docs structurantes)

Le métier du solveur vit dans `engine/doc/` — à lire avant de toucher au solveur :

| Doc | Contenu |
|-----|---------|
| [`doc/business.md`](doc/business.md) | **Cœur métier** — concepts (équipe, salle, coach, contrainte : scopes/familles/règles, tiers de priorité, contraintes implicites, niveaux de lock). |
| [`doc/nominal-flow.md`](doc/nominal-flow.md) | Flux nominal d'une requête de bout en bout — structure du payload v2.0, négociation de version, locks par club, étapes du pipeline, schéma de sortie. |
| [`doc/solver-errors.md`](doc/solver-errors.md) | Erreurs & diagnostics — erreurs HTTP, statuts solveur, types de diagnostics, scénarios d'infaisabilité, lecture du score, guide de debug. |
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent (conventions ruff/mypy/pytest, gotchas, quick-reference). |

Contrat backend↔engine : version dans `engine/CONTRACT_VERSION`, synchronisé **à la main** (pas de codegen), gardé par `backend/tests/.../ContractSchemaTest`.

## Environnement

- **Python** : 3.12
- **Framework** : FastAPI
- **Solver** : Google OR-Tools CP-SAT
- **Port** : 8000 (exposé via docker-compose)
- **Isolation** : Per-club asyncio locks (pas de génération concurrente pour le même club)

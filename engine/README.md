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
make exec             # Entrer dans le conteneur engine

# Dans le conteneur :
pytest tests/                    # Tests complets
pytest tests/test_golden.py      # Tests datasets (5 scénarios)
```

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
5. OR-Tools CP-SAT   Solve(max_time=10s)
6. result_builder.py   Transforme solution → ScheduleOutputSchema
                       + génère diagnostics (unplaced, soft_lock_moved, coach_overload, conflict)
```

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

## Environnement

- **Python** : 3.12
- **Framework** : FastAPI
- **Solver** : Google OR-Tools CP-SAT
- **Port** : 8000 (exposé via docker-compose)
- **Isolation** : Per-club asyncio locks (pas de génération concurrente pour le même club)

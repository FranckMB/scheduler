# ClubScheduler — Backend

> Symfony 7 API + admin workflows. Cœur métier de la plateforme.

## Rôle dans l'architecture

Le **backend** est le point central du système. Il expose l'API REST, gère les données métier (clubs, équipes, entraîneurs, plannings), et orchestre la communication entre le frontend et le moteur de calcul.

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Frontend  │ ───────▶│   Backend   │ ───────▶│   Engine    │
│   (React)   │  /api/…  │  (Symfony)  │ POST /  │  (Python)   │
│             │ ◀─────── │             │ generate│             │
│             │  JSON    │             │ ◀────── │             │
└─────────────┘         └─────────────┘         └─────────────┘
         ▲                      │
         │ Mercure (SSE)        │
         └──────────────────────┘
```

## Communication inter-services

### Backend → Frontend
- **API REST** : Toutes les requêtes passent par `/api/*` via nginx (port 8080)
- **Mercure (SSE)** : Le backend publie des événements en temps réel sur le topic `club:{clubId}:schedule:{scheduleId}` pour notifier de l'avancement de la génération de planning

### Backend → Engine
- Le backend envoie un **POST** à `http://engine:8000/generate` avec le contexte complet du club (équipes, salles, entraîneurs, contraintes)
- L'engine résout le problème d'optimisation CP-SAT et retourne un planning optimisé
- Le backend importe le résultat et met à jour les entités `ScheduleSlotTemplate`

### Frontend → Backend
- Le frontend React appelle l'API via des URLs relatives (`/api/*`) qui sont proxyfiées par le nginx du frontend vers le backend nginx

## API Routes

Toutes les routes sont exposées sous `/api` via **API Platform** (auto-génération CRUD + OpenAPI docs).

### Ressources métier (CRUD standard)

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `Club` | `/api/clubs` | Clubs/organisations |
| `Season` | `/api/seasons` | Saisons sportives |
| `Team` | `/api/teams` | Équipes (catégorie, priorité, créneaux) |
| `Venue` | `/api/venues` | Salles/lieux de pratique |
| `Coach` | `/api/coaches` | Entraîneurs |
| `User` | `/api/users` | Utilisateurs |
| `ClubUser` | `/api/club-users` | Membres du club (rôles) |
| `Sport` | `/api/sports` | Types de sports |
| `SportCategory` | `/api/sport-categories` | Catégories d'âge |
| `PriorityTier` | `/api/priority-tiers` | Niveaux de priorité (S/A/B/C/D) |
| `Plan` | `/api/plans` | Plans d'abonnement |

### Ressources planning (CRUD standard)

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `Schedule` | `/api/schedules` | Générations de planning |
| `ScheduleSlotTemplate` | `/api/schedule-slot-templates` | Créneaux générés |
| `ScheduleDiagnostic` | `/api/schedule-diagnostics` | Erreurs/avertissements |

### Ressources contraintes

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `TeamConstraint` | `/api/team-constraints` | Contraintes par équipe |
| `VenueAvailability` | `/api/venue-availabilities` | Disponibilités hebdomadaires salles |
| `VenueClosure` | `/api/venue-closures` | Fermetures salles (plages) |
| `CoachUnavailability` | `/api/coach-unavailabilities` | Indisponibilités entraîneurs |
| `TeamCoach` | `/api/team-coaches` | Assignations entraîneur-équipe |
| `CoachPlayerMembership` | `/api/coach-player-memberships` | Entraîneurs aussi joueurs |

### Opérations custom

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/health` | GET | Health check (nginx → php-fpm) |
| `/api/schedules/{id}/generate` | POST | Lancer la génération de planning (async) |
| `/api/schedules/{id}/export-pdf` | POST | Exporter le planning en PDF (async) |

### Documentation OpenAPI
- `http://localhost:8080/api/docs` — Swagger UI
- `http://localhost:8080/api/docs.json` — OpenAPI JSON

## Commandes principales

```bash
# Toutes les commandes s'exécutent DANS le conteneur php-fpm
# Le Makefile les lance automatiquement dans le conteneur

make install          # composer install
make test             # Lint + tests (PHPStan, CS-Fixer, Rector, PHPUnit)
make lint             # PHPStan + CS-Fixer + Rector
make exec             # Entrer dans le conteneur php-fpm

# Commandes Symfony directes
make exec             # puis dans le conteneur :
php bin/console doctrine:migrations:migrate  # Migrations
php bin/console messenger:consume async      # Worker (ou utiliser messenger-worker)
php bin/console cache:clear                  # Clear cache
```

## Architecture interne

```
backend/
├── src/
│   ├── ApiResource/          # 20 ressources API Platform
│   ├── Entity/               # 20 entités Doctrine
│   ├── Controller/           # Contrôleurs custom
│   │   ├── HealthController.php
│   │   ├── GenerateScheduleController.php   # POST /api/schedules/{id}/generate
│   │   └── ExportPdfController.php         # POST /api/schedules/{id}/export-pdf
│   ├── MessageHandler/
│   │   └── GenerateScheduleHandler.php      # Appel HTTP → Engine
│   │   └── ExportPdfHandler.php
│   ├── Service/
│   │   ├── ScheduleConstraintBuilder.php    # Construction payload Engine
│   │   ├── ScheduleResultImporter.php       # Import résultat Engine
│   │   └── ClubGenerationLock.php           # Verrou Redis
│   ├── State/Provider/       # State providers API Platform
│   ├── State/Processor/      # State processors API Platform
│   └── DataFixtures/         # Jeux de données
├── config/
│   └── packages/mercure.yaml # Config Mercure hub
├── migrations/               # Migrations Doctrine
└── public/                   # Point d'entrée nginx
```

## Flux de génération de planning

```
1. Frontend        POST /api/schedules/{id}/generate
2. Backend         Crée Schedule + envoie GenerateScheduleMessage (bus async)
3. MessengerWorker Execute GenerateScheduleHandler
4. Handler         Build payload via ScheduleConstraintBuilder
5. Handler         POST http://engine:8000/generate
6. Engine          Résout CP-SAT + retourne slots
7. Handler         Importe résultat via ScheduleResultImporter
8. Handler         Publie Mercure: club:{clubId}:schedule:{scheduleId}
9. Frontend        Reçoit SSE → rafraîchit le calendrier
```

## Environnement

- **PHP** : 8.4
- **Framework** : Symfony 7
- **API** : API Platform
- **DB** : PostgreSQL 16 (via `clubscheduler-postgres`)
- **Cache** : Redis (via `clubscheduler-redis`)
- **Message Bus** : Symfony Messenger + Redis
- **Real-time** : Mercure (SSE)
- **Port** : 9000 (php-fpm interne) — exposé via nginx 8080

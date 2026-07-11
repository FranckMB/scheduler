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

> ⚠️ **URIs en `snake_case`** (`/api/team_coaches`, `/api/venue_training_slots`, `/api/sport_categories`, `/api/priority_tiers`, `/api/schedule_slot_templates`…), **pas** en kebab. La **source de vérité** est l'OpenAPI (`/api/docs`) et l'inventaire [`specs/courantes/backend-inventory.md`](../specs/courantes/backend-inventory.md) ; le tableau ci-dessous est indicatif.

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

### Ressources contraintes & liens

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `Constraint` | `/api/constraints` | Contraintes **unifiées** (familles TIME/DAY/FACILITY/COACH_AVAILABILITY · scope CLUB/TEAM/COACH/FACILITY · `config.targetTag` pour cibler un groupe) |
| `VenueTrainingSlot` | `/api/venue_training_slots` | Disponibilités hebdo des salles (jour, heure, durée, capacité 1/2) |
| `TeamCoach` | `/api/team_coaches` | Assignations entraîneur-équipe (MAIN/ASSISTANT) |
| `CoachPlayerMembership` | `/api/coach_player_memberships` | Entraîneurs aussi joueurs |

### Ressources cockpit temporel & matchs

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `CalendarEntry` | `/api/calendar_entries` | Périodes/événements du cockpit (kind PERIOD/EVENT ; overlay planning via `overlayScheduleId`) |
| `Competition` | `/api/competitions` | Compétitions FFBB (championnat/coupe/brassage) — module matchs palier A |
| `Fixture` | `/api/fixtures` | Rencontres (HOME/AWAY, placement domicile, `externalRef` = n° FBI) |

### Opérations custom (au-delà du CRUD)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/register` | POST | Inscription — compte non vérifié, **202 générique** (anti-énumération A3, aucun token) ; envoie un lien de vérification par email (`AuthController`) |
| `/api/register/verify` | POST | Consomme le token du lien email → vérifie le compte, crée/rejoint le club, **émet le JWT** (login effectif) |
| `/api/me` | GET/PATCH | Profil JWT + contexte club (`AuthController`) |
| `/api/constraints/validate` | POST | Gate pré-solveur : valide les contraintes + détecte les conflits (200/422) |
| `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}` | POST | Ajustements manuels de créneau (boucle de travail) |

### Opérations custom

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/health` | GET | Health check (nginx → php-fpm) |
| `/api/schedules/{id}/generate` | POST | Lancer la génération de planning (async) |
| `/api/schedules/{id}/export-pdf` | POST | Exporter le planning en PDF (async) |

### Opérations cockpit / matchs / transition / calendriers (invokables)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/calendar-entries/conflicts` | GET | Conflits d'un overlay période vs planning socle (cockpit) |
| `/api/league-match-windows` | GET | Fenêtres de match héritées de la ligue du club (catalogue global, fallback AURA) |
| `/api/fixtures/conflicts` | GET | Radar conflits coach/joueur des rencontres (module matchs) |
| `/api/teams/{id}/fixtures/import` | POST | Import FBI des rencontres (.xlsx par équipe) |
| `/api/season-transition` | GET/POST | Recap + bascule de saison (P1/P2) |
| `/api/school-holidays`, `/api/public-holidays` | GET | Vacances scolaires / jours fériés (tables globales) |

> Source de vérité exhaustive = OpenAPI (`/api/docs`) + snapshot `specs/courantes/openapi-snapshot.json`. Le tableau reste indicatif (pas de décompte figé).

### Documentation OpenAPI
- `http://localhost:8080/api/docs` — Swagger UI
- `http://localhost:8080/api/docs.json` — OpenAPI JSON

## Commandes principales

```bash
# Toutes les commandes s'exécutent DANS le conteneur php-fpm
# Le Makefile les lance automatiquement dans le conteneur

make install          # composer install
make test             # CS-Fixer + PHPStan(niveau 8) + PHPUnit (--group phase1)
make lint             # CS-Fixer + PHPStan + Rector
make phpstan          # PHPStan seul (niveau 8)
make cs-fix           # CS-Fixer (auto-format)
make db-init-test     # crée + migre la base de TEST (requis avant `make phpunit`)
make phpunit          # PHPUnit --group phase1
make db-reset         # drop + recreate + migre la base de dev
make exec             # Entrer dans le conteneur php-fpm

# Dans le conteneur (make exec) :
php bin/console doctrine:migrations:diff      # génère une migration depuis le diff d'entités
php bin/console doctrine:migrations:migrate   # applique les migrations
```

> ⚠️ Commandes backend = **dans Docker** (le Makefile enveloppe `docker compose exec`). Elles échouent sur l'hôte. La suite de tests a besoin de la base de test → `make db-init-test` d'abord.

## Architecture interne

```
backend/
├── src/
│   ├── ApiResource/          # ressources API Platform (liste : ls src/ApiResource/)
│   ├── Entity/               # entités Doctrine (liste : ls src/Entity/)
│   ├── Controller/           # Contrôleurs custom (liste : ls src/Controller/)
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

## Pour aller plus loin (docs structurantes)

| Doc / script | Contenu |
|--------------|---------|
| [`scripts/generate-schedule.sh`](scripts/generate-schedule.sh) | **Guide pratique** — pilote create → generate → poll une génération via l'API (vraie aide pour tester/déboguer le flux). |
| [`scripts/smoke-solver.sh`](scripts/smoke-solver.sh) | Vérif end-to-end : assure qu'un planning atteint `COMPLETED` (garde-fou solveur, utilisé en validation). |
| [`scripts/onboarding-smoke.sh`](scripts/onboarding-smoke.sh) | Flux club neuf : register → données minimales → generate → `COMPLETED`. |
| [`docs/TENANT.md`](docs/TENANT.md) | **Isolation multi-tenant** (cœur sécurité) — `TenantFilter` + `TenantFilterListener` (priorité 7, après le firewall) + résolution du club depuis le JWT. |
| [`docs/RLS.md`](docs/RLS.md) | PostgreSQL Row-Level Security : rôles DB, policies, activation sur une nouvelle table. |
| [`docs/commands.md`](docs/commands.md) | **Référence complète des commandes** — cibles make, console `app:*`, pièges RLS (`dbal:run-sql`), scripts. |
| [`docs/ffbb-api.md`](docs/ffbb-api.md) | **Intégration FFBB** — les routes des API publiques FFBB utilisées (Meilisearch + api.ffbb.com), confinement SSRF, cache. |
| [`docs/constraint-coverage.md`](docs/constraint-coverage.md) | Couverture des besoins gestionnaire par le système de contraintes (✅/🟡/❌). |
| [`docs/constraints.md`](docs/constraints.md) · [`docs/generation-flow.md`](docs/generation-flow.md) · [`docs/schedule-generation-guide.md`](docs/schedule-generation-guide.md) | Docs pédagogiques (contraintes métier, pipeline de génération, guide pas-à-pas) — ex-`doc/`, fusionné 2026-07-11. |
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent (conventions CS-Fixer/PHPStan/Rector, flux services, gotchas). |

**Contraintes = cœur métier.** Elles sont *persistées/exposées* ici (`Constraint` + `ScheduleConstraintBuilder` qui construit le payload solveur, dont `resolveTagToTeamIds` pour cibler un groupe) et *résolues* par l'engine — voir [`engine/docs/business.md`](../engine/docs/business.md).

## Environnement

- **PHP** : 8.4
- **Framework** : Symfony 7
- **API** : API Platform
- **DB** : PostgreSQL 16 (via `clubscheduler-postgres`)
- **Cache** : Redis (via `clubscheduler-redis`)
- **Message Bus** : Symfony Messenger + Redis
- **Real-time** : Mercure (SSE)
- **Port** : 9000 (php-fpm interne) — exposé via nginx 8080

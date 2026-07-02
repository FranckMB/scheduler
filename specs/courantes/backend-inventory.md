# Backend Inventory

> Backward inventory of the existing backend (Symfony 7 + API Platform). This document
> describes what exists in the codebase at the time of verification — it is not a roadmap.

Last verified @ 6e35a6ce 2026-06-30

---

## 1. Architecture Backend

### Stack

| Composant | Version / Détail |
|-----------|------------------|
| Langage | PHP 8.4 (`declare(strict_types=1)` dans tous les fichiers) |
| Framework | Symfony 7 |
| API | API Platform (auto-génération CRUD + OpenAPI sous `/api/*`) |
| ORM | Doctrine (migrations dans `backend/migrations/`) |
| Auth | LexikJWTAuthenticationBundle (JWT stateless) |
| Real-time | Mercure (SSE) |
| Message bus | Symfony Messenger (transport Redis, worker dédié) |
| DB | PostgreSQL 16 |
| Cache / Lock | Redis 7 (appendonly) |

### Structure des dossiers

```
backend/
├── src/
│   ├── ApiResource/          # 20 ressources API Platform (DTOs + metadata)
│   ├── Entity/               # 20 entités Doctrine (UUID string)
│   ├── Controller/           # 7 contrôleurs custom (Auth, Generate, ExportPdf, Health, Import, ManualEdit, ResetSeason)
│   ├── MessageHandler/       # GenerateScheduleHandler, ExportPdfHandler
│   ├── Service/              # ScheduleConstraintBuilder, ScheduleResultImporter, ClubGenerationLock, ManualEditService, FfbbExcelImporter
│   ├── State/Provider/       # State providers API Platform (par ressource)
│   ├── State/Processor/      # State processors API Platform (par ressource)
│   ├── EventListener/        # TenantFilterListener (X-Club-Id / X-Season-Id)
│   ├── Doctrine/Filter/      # TenantFilter (Doctrine filter SQL)
│   ├── Enum/                 # ScheduleStatus, LockLevel, ...
│   ├── Dto/                  # Input DTOs (ClubInput, ScheduleInput, ...)
│   └── DataFixtures/         # Jeux de données de test
├── config/
│   ├── packages/security.yaml
│   ├── packages/api_platform.yaml
│   ├── packages/mercure.yaml
│   └── routes.yaml
├── migrations/
├── tests/
└── public/index.php
```

### Config API Platform (`config/packages/api_platform.yaml`)

- Titre : `ClubScheduler API`, version `1.0.0`.
- Formats supportés : `jsonld` (`application/ld+json`), `json` (`application/json`), `html` (`text/html`).
- Docs formats : OpenAPI (`application/vnd.openapi+json`), JSON-LD, HTML.
- `defaults.stateless: true` — toutes les opérations sont stateless.
- `cache_headers.vary` inclut `Content-Type`, `Authorization`, `Origin`.

---

## 2. Resources API Platform

Les 20 ressources sont définies dans `backend/src/ApiResource/`. Chaque ressource est un DTO
avec attributs `#[ApiResource]` déclarant les opérations CRUD standard
(`GetCollection`, `Get`, `Post`, `Put`, `Delete`), un `provider` et un `processor` personnalisés,
et la pagination à 30 items/page. Les entités Doctrine correspondantes vivent dans
`backend/src/Entity/` et utilisent des UUID string.

| # | Resource (shortName) | Endpoint | Description | Notes |
|---|----------------------|---------|-------------|-------|
| 1 | Club | `/api/clubs` | Clubs / organisations | Opération custom `POST /clubs/{id}/import-teams` |
| 2 | Season | `/api/seasons` | Saisons sportives | |
| 3 | Team | `/api/teams` | Équipes (catégorie, priorité, créneaux) | |
| 4 | Venue | `/api/venues` | Salles / lieux de pratique | |
| 5 | Coach | `/api/coaches` | Entraîneurs | |
| 6 | User | `/api/users` | Utilisateurs | |
| 7 | ClubUser | `/api/club-users` | Membres du club (rôles) | |
| 8 | Sport | `/api/sports` | Types de sports | |
| 9 | SportCategory | `/api/sport-categories` | Catégories d'âge | |
| 10 | PriorityTier | `/api/priority-tiers` | Niveaux de priorité (S/A/B/C/D) | |
| 11 | Plan | `/api/plans` | Plans d'abonnement | |
| 12 | Schedule | `/api/schedules` | Générations de planning | `mercure: true` ; opérations custom `generate` et `export-pdf` ; filtres `isActive` (booléen) et `seasonId` (exact) |
| 13 | ScheduleSlotTemplate | `/api/schedule-slot-templates` | Créneaux générés | |
| 14 | ScheduleDiagnostic | `/api/schedule-diagnostics` | Erreurs / avertissements | |
| 15 | Constraint | `/api/constraints` | Contraintes permanentes | |
| 16 | TeamCoach | `/api/team-coaches` | Assignations entraîneur-équipe | |
| 17 | CoachPlayerMembership | `/api/coach-player-memberships` | Entraîneurs aussi joueurs | |
| 18 | TeamTag | `/api/team-tags` | Étiquettes d'équipe | |
| 19 | TeamTagAssignment | `/api/team-tag-assignments` | Assignations d'étiquettes | |
| 20 | VenueTrainingSlot | `/api/venue-training-slots` | Créneaux d'entraînement de salle | |

Chaque ressource déclare `paginationEnabled: true` et `paginationItemsPerPage: 30` au niveau
de l'attribut `#[ApiResource]`. Les réponses collections suivent le format JSON-LD
(`hydra:member`, `hydra:totalItems`, `hydra:view`).

---

## 3. Custom Controllers

Les contrôleurs personnalisés vivent dans `backend/src/Controller/`. Certains sont déclarés
comme opérations custom API Platform (sur la ressource), d'autres comme routes Symfony
classiques avec `#[Route]`.

### Authentification (`AuthController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/login` | POST | Connexion — gérée par le firewall `json_login` de Symfony (username `email`, password `password`), succès/échec délégués à LexikJWT. Route déclarée dans `config/routes.yaml`. |
| `/api/register` | POST | Inscription — crée un `User`, un `Club` (avec slug + code FFBB/ARA), un `ClubUser` (rôle `admin`), une `Season` par défaut, un `Sport` (basketball) et 9 `SportCategory` par défaut. Retourne un JWT (201). Validation : email, password ≥ 8, ARA 3-20 alphanumérique majuscule. |
| `/api/me` | GET | Profil courant — retourne `id`, `email`, `firstName`, `lastName`, `club` (id + name), `hasGenerated` (booléen : `generationCountSeason > 0`). |

### Génération de planning

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/generate` | POST | `GenerateScheduleController` | Lance la génération asynchrone. Vérifie l'appartenance du schedule au club courant (`X-Club-Id`), passe le statut à `PENDING`, dispatche `GenerateScheduleMessage` sur le bus async. Retourne 202. |

### Export PDF

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/export-pdf` | POST | `ExportPdfController` | Lance l'export PDF asynchrone. Passe `pdfExportStatus` à `pending`, dispatche `ExportPdfMessage`. Retourne 202. |

### Édition manuelle (`ManualEditController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/schedule-slots/{id}/manual-edit/constraint` | POST | Crée une contrainte permanente sur un créneau. Body : `type` (requis), `reason`, `createdBy`. Retourne 201 avec `constraintId`. |
| `/api/schedule-slots/{id}/manual-edit/lock` | POST | Applique un verrou sur un créneau. Body : `lockLevel` (enum `LockLevel`). Retourne 200. |
| `/api/schedule-slots/{id}/manual-edit/one-time` | POST | Applique une modification ponctuelle sur un créneau (ex. `startTime`). Retourne 200. Conflit → 409. |

### Import équipes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/clubs/{id}/import-teams` | POST | `ImportController` | Importe un fichier `.xlsx` (Excel) pour un club et une saison donnés. Body multipart : `file` (.xlsx), `seasonId`. Délègue à `FfbbExcelImporter`. Retourne 200 avec `created`, `skipped`, `errors`. |

### Reset saison

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/reset-season` | DELETE | `ResetSeasonController` | Supprime toutes les données d'une saison pour un club. Résout `clubId` et `seasonId` depuis `_club_id` / `X-Club-Id` et `_season_id` / `X-Season-Id`. Supprime en cascade : `ScheduleDiagnostic`, `ScheduleSlotTemplate`, `Constraint`, `TeamCoach`, `CoachPlayerMembership`, `Schedule`, `Team`, `Coach`, `Venue`. Retourne 200 avec `deleted`. |

### Identité du club (accent + logo)

Champs `Club` : `accentColor` (hex), `accentPalette` (json ≤3 hex), `logoUrl` — exposés en lecture (ClubResource, `/api/me`).

| Route | Méthode | Contrôleur | Description |
|-------|---------|-----------|-------------|
| `/api/club/appearance` | PATCH | `ClubAppearanceController` | MAJ partielle de l'accent (`accentColor`, `accentPalette`) du club courant (résolu depuis `_club_id`/JWT), validation hex. |
| `/api/club/logo` | POST · DELETE | `ClubLogoController` | Upload (multipart `file`, raster PNG/JPEG/WebP ≤ 500 Ko) / suppression du logo du club courant. Octets stockés via l'abstraction `App\Storage\LogoStorage` (`LocalLogoStorage` en dev ; alias `services.yaml` swappable pour du stockage objet en prod). |
| `/api/clubs/{clubId}/logo` | GET | `ClubLogoController` | Sert le logo (public, stream + mime via finfo). |

### Health check

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/health` | GET | `HealthController` | Retourne `{"status":"ok"}`. Public (pas d'auth requise). |

---

## 4. Security / Auth

### JWT (LexikJWTAuthenticationBundle)

- Firewall `login` (`^/api/login`) : `stateless: true`, `json_login` avec `check_path: /api/login`,
  `username_path: email`, `password_path: password`. Succès/échec gérés par Lexik.
- Firewall `api` (`^/api`) : `stateless: true`, `provider: app_user_provider`, `jwt: ~`.
- Provider : `app_user_provider` (entity `App\Entity\User`, property `email`).
- Password hasher : `auto` (config `security.yaml`).

### Access control

| Path | Rôle |
|------|------|
| `^/api/login` | `PUBLIC_ACCESS` |
| `^/api/register` | `PUBLIC_ACCESS` |
| `^/api/health` | `PUBLIC_ACCESS` |
| `^/api/docs` | `PUBLIC_ACCESS` |
| `^/api` | `IS_AUTHENTICATED_FULLY` |

Seule la première règle correspondante s'applique. Tout le reste de `/api/*` requiert un JWT valide.

### X-Club-Id et X-Season-Id (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, priorité 8) implémente l'isolation
multi-tenant au niveau de chaque requête :

1. **Résolution du clubId** : depuis l'attribut de requête `_club_id`, sinon depuis le header
   `X-Club-Id`.
2. **Résolution du seasonId** : depuis l'attribut `_season_id`, sinon depuis le header
   `X-Season-Id`.
3. **Validation d'appartenance** : si un `clubId` est résolu et un utilisateur est authentifié,
   le listener vérifie qu'un `ClubUser` actif existe pour `(userId, clubId)`. Sinon → 403.
4. **Filtre Doctrine** : active le filtre `tenant_filter` avec le paramètre `club_id` (UUID).
   Toutes les requêtes Doctrine sont automatiquement filtrées par club.
5. **PostgreSQL SET LOCAL** : exécute `SET LOCAL app.club_id = '<clubId>'` sur la connexion,
   permettant l'isolation au niveau SQL (Row Level Security ou triggers).

---

## 5. Mercure SSE

### Configuration (`config/packages/mercure.yaml`)

- Hub `default` : URL depuis `MERCURE_URL`, public URL depuis `MERCURE_PUBLIC_URL`.
- JWT secret depuis `MERCURE_JWT_SECRET`, permission `publish: '*'` (tous les topics).

### Topic et publication

Le topic Mercure suit le format :

```
club:{clubId}:schedule:{scheduleId}
```

La publication est effectuée par les handlers asynchrones :

- **`GenerateScheduleHandler`** : publie sur le topic après import du résultat du solver
  (`sprintf('club:%s:schedule:%s', $schedule->getClubId(), $schedule->getId())`).
- **`ExportPdfHandler`** : publie sur le même topic après génération du PDF.

La ressource `ScheduleResource` déclare `mercure: true` au niveau de l'attribut `#[ApiResource]`,
ce qui active la diffusion Mercure pour les opérations CRUD standard sur les schedules.

Le frontend consomme ces événements via `EventSource` sur
`/.well-known/mercure?topic=club:{clubId}:schedule:{scheduleId}`.

---

## 6. Pagination

Toutes les 20 ressources API Platform déclarent explicitement :

```php
paginationEnabled: true,
paginationItemsPerPage: 30,
```

au niveau de l'attribut `#[ApiResource]`. Les collections sont servies au format JSON-LD :

- `hydra:member` : tableau des items de la page courante.
- `hydra:totalItems` : nombre total d'items.
- `hydra:view` : liens de navigation (`hydra:first`, `hydra:next`, `hydra:last`, `hydra:previous`).
- Paramètres de requête : `page` (numéro de page), `itemsPerPage` (surchargeable via
  `pagination_client_items_per_page` si activé).

Aucune ressource ne désactive la pagination ni ne surcharge le nombre d'items par page.
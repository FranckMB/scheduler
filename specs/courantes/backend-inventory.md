# Backend Inventory

> Backward inventory of the existing backend (Symfony 7 + API Platform). This document
> describes what exists in the codebase at the time of verification — it is not a roadmap.

Last verified @ c7d93c8 2026-07-03

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
│   ├── ApiResource/          # Ressources API Platform (DTOs + metadata) — liste : ls backend/src/ApiResource/
│   ├── Entity/               # Entités Doctrine (UUID string) — liste : ls backend/src/Entity/
│   ├── Controller/           # Contrôleurs custom — liste : ls backend/src/Controller/ (détail §3)
│   ├── MessageHandler/       # GenerateScheduleHandler, ExportPdfHandler
│   ├── Service/              # ScheduleConstraintBuilder, ScheduleResultImporter, ClubGenerationLock, ManualEditService, FfbbExcelImporter, ConstraintValidationService, ... — liste : ls backend/src/Service/
│   ├── State/Provider/       # State providers API Platform (par ressource)
│   ├── State/Processor/      # State processors API Platform (par ressource)
│   ├── EventListener/        # TenantFilterListener (résolution tenant : attribut / header / JWT)
│   ├── Doctrine/Filter/      # TenantFilter (Doctrine filter SQL)
│   ├── Enum/                 # ScheduleStatus, LockLevel, ...
│   ├── Dto/                  # Input DTOs (ClubInput, ScheduleInput, ...)
│   ├── Repository/           # Repositories Doctrine
│   ├── Storage/              # LogoStorage (interface) + LocalLogoStorage
│   └── DataFixtures/         # Jeux de données de test
├── config/
│   ├── packages/security.yaml
│   ├── packages/api_platform.yaml
│   ├── packages/mercure.yaml
│   ├── packages/rate_limiter.yaml
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

Les ressources sont définies dans `backend/src/ApiResource/` (liste exhaustive : `ls backend/src/ApiResource/`). Chaque ressource est un DTO
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
| 12 | Schedule | `/api/schedules` | Générations de planning | `mercure: true` ; opérations custom `generate` et `export-pdf` ; filtres `isActive` (booléen) et `seasonId` (exact). Les routes de cycle de vie (`validate`/`reopen`/`set-baseline`) sont des routes Symfony hors API Platform (§3). |
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
| `/api/register` | POST | Inscription à deux modes selon le code ARA (rate-limité par IP, `auth_register` : 5/15 min). **Club inexistant** : crée `User` + `Club` (slug + code FFBB/ARA) + `ClubUser` actif (rôle `admin`) + `Season` active + `Sport` basketball + 9 `SportCategory` — `membershipStatus: "active"`. **ARA d'un club existant** : crée `User` + `ClubUser` **inactif** (`isActive=false`, en attente d'approbation admin, aucun accès aux données du club) — `membershipStatus: "pending"`. Retourne 201 `{ token, membershipStatus, user }`. Validation : email, password ≥ 8, ARA 3-20 alphanumérique majuscule, `club_name` requis si nouveau club. |
| `/api/me` | GET | Profil courant — retourne `id`, `email`, `firstName`, `lastName`, `membershipStatus` (`none`/`pending`/`active`), `role`, `club` (id, name, `onboardingCompleted`, `logoUrl`, `accentColor`, `accentPalette`), `baselineScheduleId` (planning principal de la saison active), `hasGenerated` (booléen : `generationCountSeason > 0`). |

### Mots de passe (`PasswordController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/password/forgot` | POST | Demande de réinitialisation (SymfonyCasts ResetPassword). Rate-limité par IP (`auth_password_forgot` : 5/15 min). Envoie un email avec lien `/reset-password/{token}` (expiration 1 h). Répond **toujours** 200 `{status:"sent"}` — pas d'énumération d'emails. |
| `/api/password/reset` | POST | Body `{ token, password }` (password ≥ 8). Valide le token, consomme la demande, re-hash le mot de passe. 400 si token invalide/expiré. Entité support : `ResetPasswordRequest`. |

### Génération de planning

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/generate` | POST | `GenerateScheduleController` | Lance la génération asynchrone. Vérifie l'appartenance du schedule au club courant (`_club_id`/`X-Club-Id`), passe le statut à `PENDING`, marque `onboardingCompleted=true` sur le club à la première génération, dispatche `GenerateScheduleMessage` sur le bus async. Retourne 202. |

### Cycle de vie du planning (VALIDATED / reopen / baseline)

`ScheduleStatus` (enum) : `DRAFT`, `PENDING`, `GENERATING`, `COMPLETED`, `FAILED`, **`VALIDATED`** (le gestionnaire marque le planning terminé → lecture seule). `Season.baselineScheduleId` désigne le planning principal de la saison (le premier planning réussi est auto-désigné à la génération).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/validate` | POST | `ValidateScheduleController` | `COMPLETED` → `VALIDATED` (lecture seule). Contrôle club courant (403 sinon). 409 si le statut n'est pas `COMPLETED`. Plusieurs plannings peuvent être validés. |
| `/api/schedules/{id}/reopen` | POST | `ReopenScheduleController` | Inverse : `VALIDATED` → `COMPLETED` (rééditable). 409 si le statut n'est pas `VALIDATED`. |
| `/api/schedules/{id}/set-baseline` | POST | `SetBaselineController` | Désigne un planning fini (`COMPLETED` ou `VALIDATED`, sinon 409) comme planning principal de la saison (`Season.baselineScheduleId`). Distinct de la validation (verrouillage). |

### Réordonnancement des équipes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/teams/reorder` | POST | `ReorderTeamsController` | Bulk atomique : body `{ items: [{ id, priorityTierId, tierOrder }] }` (ou liste nue), applique `(priorityTierId, tierOrder)` sur chaque équipe en une transaction (un seul flush). Remplace les N `PUT /api/teams/{id}` concurrents du mode tri (course sur le lock optimiste). 403 si une équipe n'appartient pas au club courant. Retourne `{ updated }`. |

### Approbation des membres (`MembershipController.php`)

Réservé à un admin **actif** du club (403 sinon) ; cible toujours restreinte au club de l'admin (404 cross-tenant).

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/memberships/pending` | GET | Liste les `ClubUser` inactifs (`isActive=false`) du club de l'admin, avec `id`, `userId`, `email`, `firstName`, `lastName`. |
| `/api/memberships/{id}/approve` | POST | Active la membership (`isActive=true`). |
| `/api/memberships/{id}/reject` | POST | Supprime la membership. Retourne 204. |

### Validation des contraintes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/constraints/validate` | POST | `ValidateConstraintsController` | Valide les contraintes du club/saison courants via `ConstraintValidationService` avant génération (règles contradictoires, incohérences). Retourne erreurs par contrainte + conflits. |

### Calendriers — vacances scolaires & jours fériés

Référentiels globaux display-only (jamais consommés par le solveur). Détail complet (modèle, zones, commandes d'import, règles) : [`vacances-scolaires-jours-feries.md`](vacances-scolaires-jours-feries.md).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/school-holidays` | GET | `SchoolHolidaysController` | Vacances scolaires de la zone du club (`Club.schoolZone`) dans la fenêtre `from`/`to` (défaut : saison active). Zone null → `items: []`. |
| `/api/public-holidays` | GET | `PublicHolidaysController` | Jours fériés `NATIONAL` ∪ extras du territoire du club, même fenêtre. Zone null → NATIONAL quand même. |

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
| `^/api/password` | `PUBLIC_ACCESS` |
| `^/api/health` | `PUBLIC_ACCESS` |
| `^/api/docs` | `PUBLIC_ACCESS` |
| `^/api/clubs/[^/]+/logo$` (GET) | `PUBLIC_ACCESS` |
| `^/api` | `IS_AUTHENTICATED_FULLY` |

Seule la première règle correspondante s'applique. Tout le reste de `/api/*` requiert un JWT valide.
Le firewall `login` applique en plus `login_throttling` (`max_attempts: 5`) ; `/api/register` et
`/api/password/forgot` sont rate-limités par IP (`config/packages/rate_limiter.yaml`, sliding window 5/15 min).

### Résolution du tenant (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, **priorité 7 — APRÈS le firewall
de sécurité (priorité 8)**, pour que l'utilisateur JWT soit déjà authentifié) implémente
l'isolation multi-tenant au niveau de chaque requête :

1. **Résolution du clubId** : attribut de requête `_club_id`, sinon header `X-Club-Id`,
   sinon **la membership `ClubUser` active de l'utilisateur JWT** (le frontend n'envoie
   aucun header tenant — c'est le chemin nominal).
2. **Résolution du seasonId** : attribut `_season_id`, sinon header `X-Season-Id`, sinon
   la saison `active` du club résolu.
3. **Validation d'appartenance** : si un `clubId` est résolu et un utilisateur est authentifié,
   le listener vérifie qu'un `ClubUser` **actif** existe pour `(userId, clubId)`. Sinon → 403
   (bloque un header `X-Club-Id` spoofé ; une membership `pending` n'a accès à rien).
4. **Filtre Doctrine** : active le filtre `tenant_filter` avec le paramètre `club_id` (UUID).
   Toutes les requêtes Doctrine sur les entités à `club_id` sont automatiquement filtrées.
5. **GUC PostgreSQL** : `TenantConnectionContext::setClubId()` pose `app.club_id` via
   `set_config(..., false)` (session-scoped ; l'ancien `SET LOCAL` hors transaction était un
   no-op). **RLS PostgreSQL ACTIF** (migration `Version20260703120000`, SEC-03) : policies
   `tenant_isolation` FORCE sur toutes les tables à `club_id`, runtime = `app_user`. 3 couches :
   filtre Doctrine + RLS + scoping provider/processor pour Club/User (sans `club_id`). Migrations
   et ops via la connexion `admin` (`clubscheduler`, superuser, bypass RLS = porte superadmin).
   Détail : `backend/docs/TENANT.md`, `docs/security/rls.md`.

**Accès API (SEC-01/02/04)** : `Club` GetCollection/Get/Put scopés aux memberships actifs
(Post/Delete retirés) ; `User` self-only (Get/Put ; pas de collection ni Delete) ;
`import-teams` requiert un membership admin sur le club du path. Gardé par
`ClubAccessTest`/`UserSelfOnlyTest`/`ImportAuthorizationTest`/`RlsIsolationTest` (blocking-tests).

---

## 5. Mercure SSE

### Configuration (`config/packages/mercure.yaml`)

- Hub `default` : URL depuis `MERCURE_URL`, public URL depuis `MERCURE_PUBLIC_URL`
  (dérivée du port publié via compose).
- JWT secret depuis `MERCURE_JWT_SECRET` (**dédié, distinct de `JWT_PASSPHRASE`** — SEC-06),
  permission publisher `publish: '*'`. Hub durci (SEC-05) : pas d'abonné `anonymous`,
  `cors_origins` restreint aux frontends dev, pas de `publish_origins *`. Gardé par
  `MercureHardeningTest`. Conso frontend future = JWT subscriber (voir `docs/security/mercure.md`).

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

Toutes les ressources API Platform déclarent explicitement :

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
# Backend Inventory

> Backward inventory of the existing backend (Symfony 7 + API Platform). This document
> describes what exists in the codebase at the time of verification — it is not a roadmap.

Last verified @ 2026-07-25 (Reservation ajoutée · CoachWish/Campaign/Token #10 · register A3 · cap génération A10 · gate CI audit deps A18)

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
│   ├── Command/              # Commandes CLI (imports holidays, seed league windows, purge/rappels saison) — liste : ls backend/src/Command/
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
| 11 | SubscriptionPlan | `/api/subscription_plans` | Plans d'abonnement (facturation ; renommé depuis `Plan`/`/api/plans` — ADR-0002 lot A, le nom « plan » revient au domaine planning) | |
| 11bis | SchedulePlan | `/api/schedule_plans` | Conteneur nommé des versions d'une saison/période (lecture seule ; ADR-0002) — filtres `calendarEntryId`, `type`. Le renommage arrive avec la bascule (le nom vit alors sur le plan, inv. 12) | |
| 12 | Schedule | `/api/schedules` | Générations de planning | `mercure: true` ; opérations custom `generate` et `export-pdf` ; filtres `isActive` (booléen) et `seasonId` (exact). Les routes de cycle de vie (`validate`/`reopen`) sont des routes Symfony hors API Platform (§3). |
| 13 | ScheduleSlotTemplate | `/api/schedule-slot-templates` | Créneaux générés | |
| 14 | ScheduleDiagnostic | `/api/schedule-diagnostics` | Erreurs / avertissements | |
| 15 | Constraint | `/api/constraints` | Contraintes permanentes | |
| 16 | TeamCoach | `/api/team-coaches` | Assignations entraîneur-équipe | |
| 17 | CoachPlayerMembership | `/api/coach-player-memberships` | Entraîneurs aussi joueurs | |
| 18 | TeamTag | `/api/team-tags` | Étiquettes d'équipe | |
| 19 | TeamTagAssignment | `/api/team-tag-assignments` | Assignations d'étiquettes | |
| 20 | VenueTrainingSlot | `/api/venue_training_slots` | Créneaux d'entraînement de salle — saisonniers (`schedulePlanId` null) ou d'un plan de période (copie du modèle de saison faite à la naissance du plan, #8 : jamais d'union entre les deux couches, l'anti-chevauchement est borné à une même couche) | |
| — | Reservation | `/api/reservations` | Réservation d'un créneau de salle pour une équipe (mutualisation : 2 équipes sur un créneau à capacité 2 ; matérialise le verrou pour l'overlay). GetCollection/Get/Post/Delete (pas de PUT — on supprime/recrée) ; ancrable à un plan de période (`schedulePlanId`). | |
| — | VenuePeriodOverride | `/api/venue_period_overrides` (+ actions atomiques `POST /reset-grid` « reprendre la grille du planning principal » et `POST /clear-grid` « vider la grille » pour un gymnase — SEC-07, 422 si visées sur le plan de saison, 404 hors club) | Mode d'un gymnase pour une période (#8) : sparse par (`schedulePlanId`, `venueId`), `DISABLED`\|`BLANK` — pas de ligne = hériter la grille de saison. DÉSACTIVÉ conserve la grille mais sort le gymnase du payload engine ; VIERGE la vide ; DELETE (retour à hériter) la revide puis la recopie | |
| — | TeamPeriodOverride | `/api/team_period_overrides` | Surcharge d'une équipe pour une période (#8) : sparse par (`schedulePlanId`, `teamId`), `isActive` (équipe hors de l'overlay sans toucher son plan de base) + `sessionsPerWeek` nullable (volume réduit ; null = garder le saisonnier). Pas de ligne = hériter la saison. Le build overlay les lit ; le plan de base n'est jamais modifié |
| — | ConstraintPeriodOverride | `/api/constraint_period_overrides` | Activation d'une contrainte pour une période (#8) : sparse par (`schedulePlanId`, `constraintId`), `isActive`. Une ligne est une déviation EXPLICITE (elle l'emporte) ; sans ligne, la contrainte suit son propre `isActive`. Le build overlay OMET du payload les contraintes désactivées (`ScheduleConstraintBuilder`, simple filtre — zéro engine) ; ni le socle ni le `isActive` de la `Constraint` ne sont touchés |
| — | CoachWish | `/api/coach_wishes` | Doléance coach pour une semaine de vacances (#10 C1) : par (équipe × semaine), nb de créneaux souhaités / jours indisponibles / commentaire / coche « traité ». **Souhait, jamais une contrainte** (zéro effet solveur). Ancrée à l'entrée MÈRE (`calendarEntryId`) + `weekStart` (lundi). Writes SEC-07 ; 422 hors période holiday / sur une semaine enfant / semaine non-lundi ou hors fenêtre / doublon (équipe, semaine). `coachId` nullable (suppression du coach → dé-attribution). Cascades : suppression de la mère, purge saison, suppression d'équipe (delete) / de coach (dé-attribution). |
| — | CoachWishCampaign | `/api/coach_wish_campaigns` (+ actions `POST /{id}/send-links` et `POST /{id}/remind` — SEC-07, #10 C3) | Campagne de collecte (#10 C2) : une par période de vacances (`calendarEntryId` UNIQUE), modifiable (semaines / équipes / deadline). Writes SEC-07 ; 422 doublon d'entrée / ancre non-holiday / semaine hors fenêtre. Sortie enrichie : compteurs radar (`totalCoachCount`/`respondedCoachCount`/`openWishCount`) + `lastReminderAt` + `coaches[{token, respondedAt, email, sentAt}]` (périmètre COURANT). Au POST/PUT, **sync des tokens** (un par coach des équipes retenues, jamais supprimé). DELETE emporte les tokens (FK) mais **laisse les `CoachWish`** (la todo-list C1 survit). Cascades : suppression de la mère, purge saison. **Actions C3** : `send-links` (corps `{coachIds?}`) envoie le lien par email aux coachs à email PAS ENCORE servis, ou aux `coachIds` ciblés (ajout tardif) — stampe `token.sentAt`, best-effort, filtre `FILTER_VALIDATE_EMAIL` ; `remind` relance les silencieux à email, **1×/jour Europe/Paris → 422 sinon**. |
| — | CoachWishToken | *(pas de ressource API)* | Lien personnel d'un coach (#10 C2) : `token` VARCHAR(64) **EN CLAIR** (`bin2hex(random_bytes(32))` — décision fondateur : « copier le lien » doit re-fonctionner ; privilège minuscule, borné au périmètre du token). `TenantOwnedInterface` (porte `club_id` pour poser le GUC RLS sur le chemin public sans JWT). RLS **hybride** : SELECT ouvert (lookup pré-GUC), écritures tenant. Consommé par le contrôleur public ci-dessous. |
| — | *(contrôleur public)* | `GET\|POST /api/coach-wishes/public/{token}` | Page publique de collecte SANS login (#10 C2) — `PublicCoachWishController`, route PUBLIC_ACCESS. Rate-limit PAR IP avant tout lookup (429 — l'IP réelle dépend de `trusted_proxies`, repli `private_ranges` ; derrière un proxy non déclaré le compartiment devient global, ce qui borne toujours l'abus) ; forme `^[0-9a-f]{64}$` sinon **404 byte-identique** (inconnu = malformé, anti-énumération) ; **jamais 401** ; GUC `app.club_id` posé depuis le token, **toujours relâché en `finally`** ; deadline passée → **410** (l'extension ranime le lien) ; saison en lecture seule → **410** aussi, via `CoachWishSeasonGuard` — le read-only est **dérivé du calendrier** (`SeasonResolver::isReadonlyAmong`, pivot 15-juillet) et pas du seul statut, qui n'est pas posé au roulement ; foyer UNIQUE partagé avec les actions management (409), après divergence en revue sécurité. GET rend le contexte pré-rempli (prénom, ses équipes ∩ campagne, semaines, doléances existantes) ; POST upsert borné au **périmètre du token** (ce coach, ses équipes, les semaines de la campagne) — une violation → 422 et **rien d'écrit** —, **cardinalité plafonnée** (`MAX_SUBMISSIONS` = 200, anti-abus O(N)) ; réponse = écrase + `done=false` (« à retraiter ») + `respondedAt` sur le token. |
| — | CalendarEntry | `/api/calendar-entries` | Cockpit temporel : périodes/événements (kind PERIOD/EVENT ; `parentEntryId` = semaine ENFANT d'une mère découpée). L'entrée porte le **FAIT** ; la RÉPONSE vit sur le plan — `overlayScheduleId` a été **supprimé** (ADR-0002 lot D-b), « période → version active » se dérive de `SchedulePlanProvisioner::chosenOfPeriodPlan` | Opération custom conflits (§3) |
| — | Competition | `/api/competitions` | Compétitions FFBB (championnat/coupe/brassage) — module matchs palier A | season-scoped |
| — | Fixture | `/api/fixtures` | Rencontres (HOME/AWAY, placement domicile, `externalRef` = n° FBI) | Ops custom conflits + import FBI (§3) |

> La numérotation n'est **pas** un décompte — liste exhaustive et à jour : `ls backend/src/ApiResource/`. Les tables globales de référence (`PublicHoliday`, `SchoolHolidayPeriod`, `LeagueMatchWindow`) sont exposées en **lecture seule via contrôleurs invokables** (§3), pas comme ressources CRUD.

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
| `/api/register` | POST | Inscription **différée, sans auto-login** (anti-énumération A3, #153 — rate-limité par IP, `auth_register` : 5/15 min). Exige `consent:true` (RGPD, 400 sinon — validation payload-only, enumeration-safe) et stocke la preuve (`termsAcceptedAt`+`termsVersion`). Crée un `User` **non vérifié** (`emailVerifiedAt=null`) + un `EmailVerificationToken` portant l'intention club `{ara, clubName}`, envoie un mail de vérification, et renvoie un **202 générique identique** dans tous les cas (email neuf ou déjà inscrit) — **aucun token émis**. Email déjà connu → aucune création, mail « tu as déjà un compte » (compte non vérifié → renvoie un nouveau lien). **Le club n'est PAS créé ici.** Validation : email, mot de passe (`PasswordPolicy` : ≥12 car. + majuscule + spécial), ARA 3-20 alphanumérique majuscule, `club_name` requis si ARA nouveau. Le login rejette un compte non vérifié (`UserChecker`, message identique à un mauvais mot de passe). |
| `/api/register/verify` | POST | Body `{ token }`. Consomme le token de vérification (verrou pessimiste `PESSIMISTIC_WRITE` anti-double-verify), passe `emailVerifiedAt`, **matérialise le club** sous GUC RLS (ARA nouveau → `Club` + `Season` + `Sport` + 12 `SportCategory` (`Service\Basketball\CategoryCatalog`) + `ClubUser` actif `admin`, `membershipStatus:"active"` ; ARA existant → `ClubUser` **inactif** pending), puis **émet le JWT** (login effectif) : `{ token, membershipStatus, user }`. 400 token invalide/expiré ; 409 si le club à rejoindre a disparu. Purge des comptes non vérifiés > 7j : `app:users:purge-unverified` (cron-runner quotidien à 02:00). |
| `/api/me` | GET | Profil courant — retourne `id`, `email`, `firstName`, `lastName`, `membershipStatus` (`none`/`pending`/`active`), `role`, `club` (id, name, `onboardingCompleted`, `logoUrl`, `accentColor`, `accentPalette`), **`seasonPlan`** (`{id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash}` — LE plan de la saison sélectionnée, ADR-0002 : `chosenScheduleId` = la version choisie, `null` = espace de travail ; `hasFinishedVersion` = le plan porte ≥1 version terminée, ce qui débloque le cockpit ; `currentStructureHash` = hash du payload solver actuel pour comparer la version affichée et griser « Régénérer » quand elle est déjà identique), `hasGenerated` (booléen : `generationCountSeason > 0`), `seasons`. |
| `/api/me` | DELETE | **RGPD droit à l'effacement** (self-only, `DeleteAccountController`). Ré-authentification : body `{ password }` (mot de passe courant, 400 sinon — un JWT volé ne suffit pas). Anonymisation IMMÉDIATE (email → `deleted-{id}@anonymized.invalid`, hash aléatoire, memberships désactivés, transactionnel) ; plus aucun membre actif → `Club.erasureScheduledAt = +30 j` (purge du workspace par `app:clubs:purge-erased`, auto-annulée si un membre revient ; l'identité publique FFBB survit). Réponse `{ message, clubPurgeScheduled, gracePeriodDays }`. NR : `AccountErasureTest`. |
| `/api/me/export` | GET | **RGPD portabilité** (self-only, `RgpdExportController`) : compte + adhésions + preuve de consentement + lastLoginAt, JAMAIS le hash. JSON en téléchargement (`Content-Disposition`). Rate-limité `rgpd_export` (10/h par user). NR : `RgpdExportTest`. |
| `/api/club/export` | GET | **RGPD portabilité club** (management SEC-07, tenant du JWT — pas d'id de chemin ; 404 sans membership actif, 403 non-management) : workspace complet en lignes brutes par table (19 tables, `schedule` sans `snapshot_data`), tenant-scoped garanti par RLS. Rate-limité `rgpd_export`. NR : `RgpdExportTest`. |

### Télémétrie de génération (`SolverMetric.php`)

`solver_metrics` conserve une ligne par tentative de génération (`schedule_id`, `club_id`,
statut, durée, taille du problème, conflits, score, version du solveur, horodatage). La
table est sous RLS `FORCE` et le rôle runtime ne voit que le club courant. `Club.lastActivityAt`
est mis à jour au login authentifié et à la mise en file d'une génération. La rétention et
le partitionnement sont différés aux jobs SA3.

### Authentification superadmin (`AdminAuthController.php`)

Identité, provider et firewall stateful séparés de `User`/`ClubUser` et du JWT club. Le
parcours mot de passe + TOTP, la session, le CSRF et l'audit fail-closed sont spécifiés dans
[`superadmin-auth.md`](superadmin-auth.md). Routes : `POST /api/admin/auth/password`,
`POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `POST /api/admin/auth/logout`.

> **RGPD — mécanismes transverses** (rétention comptes inactifs 24 mois, purges cron, journal
> d'audit append-only, consentement) : registre des traitements et pointeurs code dans
> [`docs/security/rgpd.md`](../../docs/security/rgpd.md).

### Mots de passe (`PasswordController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/password/forgot` | POST | Demande de réinitialisation (SymfonyCasts ResetPassword). Rate-limité par IP (`auth_password_forgot` : 5/15 min). Envoie un email avec lien `/reset-password/{token}` (expiration 1 h). Répond **toujours** 200 `{status:"sent"}` — pas d'énumération d'emails. |
| `/api/password/reset` | POST | Body `{ token, password }` (politique `PasswordPolicy` : ≥12 car. + majuscule + spécial). Valide le token, consomme la demande, re-hash le mot de passe. 400 si token invalide/expiré. Entité support : `ResetPasswordRequest`. |

### Génération de planning

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/generate` | POST | `GenerateScheduleController` | Lance la génération asynchrone. Gate management (`assertManager`, SEC-07). Vérifie l'appartenance du schedule au club courant, **borne de complexité A10 pré-dispatch** (`GenerationComplexityGuard` : teams ≤200 · venues ≤50 · slots ≤3000 · contraintes permanentes ≤500 · teams×venues ≤2000 → **422** avant toute mise en queue, statut inchangé, #156), **épinglage orphelin sur un planning de période** (`OrphanPinGuard`, #8) : un verrou HARD ou une réservation qui ne retombe plus sur aucun créneau de la grille de la période (grille refaite : page blanche, recopie, gymnase désactivé) → **422** nommant le gymnase et le jour, passe le statut à `PENDING`, marque `onboardingCompleted=true` à la première génération, dispatche `GenerateScheduleMessage`. Retourne 202. |

### Cycle de vie du planning (pointeur du plan — ADR-0002)

`ScheduleStatus` (enum) : `DRAFT`, `PENDING`, `GENERATING`, `COMPLETED`, `FAILED`. **« Validé » n'est pas un statut** : c'est le **plan** (`schedule_plan`) qui **pointe** la version faisant foi (`chosen_schedule_id`) — une version pointée reste `COMPLETED`, et le champ de lecture `Schedule.isChosen` l'expose. Le plan de type **SEASON** et sa version choisie **sont** le calendrier de la saison. Générer ne pointe **jamais** (inv. 2) : seul le gestionnaire choisit.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/validate` | POST | `ValidateScheduleController` | **Pointe** la version sur son plan **et supprime les versions sœurs** du même périmètre (inv. 1 — plus d'archivage). Gate management (SEC-07) + contrôle club courant (403 sinon). 409 si le statut n'est pas `COMPLETED`, si une sœur est `PENDING`/`GENERATING`, ou (`overlays_exist`) si choisir une **autre** version de saison détruirait des plans secondaires — confirmer par `{"confirmDeleteOverlays": true}` ; portée et destruction : voir `/reopen`. |
| `/api/schedules/{id}/reopen` | POST | `ReopenScheduleController` | Inverse : le plan **dépointe** la version, qui survit et redevient éditable (inv. 2). Gate management (SEC-07). 409 si la version n'est pas celle que pointe son plan ; 409 `overlays_exist` si le socle porte des plans de période **pas encore commencées** (validés ou non, décision fondateur 2026-07-24, ADR-0002 inv. 14) — confirmés, ils sont détruits **de bout en bout** (versions + plan + grille copiée + réglages) ; l'entrée de calendrier survit, « à traiter » de nouveau. |
| `/api/schedule_plans/{id}` | PUT | `SchedulePlanStateProcessor` | Renomme le plan — le **nom appartient au plan** (inv. 12). Gate management (SEC-07). |

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
| `/api/constraints/validate` | POST | `ValidateConstraintsController` | Gate pré-solve. En mode période, le jeu validé vient de **`PeriodConstraintSelector`** (P2-14) — LA source unique partagée avec `buildForPeriodPlan`, qui aligne le récap sur ce que le solveur recevra (parité gardée par `PeriodGatePayloadParityTest`, phase1). Retourne `errors` par contrainte + `conflits` + `warnings` (drops pour gymnase désactivé, tag inerte, **capacité dans les deux sens** — P2-9 PR A : demande = Σ `sessionsPerWeek` du payload, offre = Σ capacités des créneaux, sous-capacité en « au moins X », surplus dès 1 créneau en trop ; nombres lus du payload `buildForClubSeason`/`buildForPeriodPlan`, jamais recalculés) + `blockers` (coach dédoublé, P2-9 PR B). |

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

### Cockpit temporel (overlays période/événement)

Détail : [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md). `CalendarEntry` (kind PERIOD/EVENT) est le **déclencheur daté** ; le planning de période est un `SchedulePlan` ancré à l'entrée, et c'est **le plan** qui pointe sa version (`chosenScheduleId`). Le pointeur inverse `overlayScheduleId` a été supprimé par ADR-0002 lot D-b.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/calendar-entries/{id}/conflicts` | GET | `CalendarEntryConflictsController` | Conflits d'un overlay période vs le planning socle (créneaux impactés). |

### Module matchs (palier A — FFBB)

Détail : [`module-matchs.md`](module-matchs.md). Placement des rencontres domicile + radar de conflits coach/joueur ; catalogue-ligue global `LeagueMatchWindow` (hors tenant).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/league-match-windows` | GET | `LeagueMatchWindowsController` | Fenêtres de match héritées de la ligue du club (`Club.league`, fallback fédé AURA). Catalogue global partagé. |
| `/api/fixtures/conflicts` | GET | `FixtureConflictsController` | Radar : conflits d'empreinte-temps coach/joueur entre rencontres et entraînements. |
| `/api/teams/{id}/fixtures/import` | POST | `ImportFixturesController` | Import FBI (.xlsx **par équipe**, choisie à l'upload). Idempotent par `Fixture.externalRef` (n° FBI). Rapport `created`/`skipped`/`errors`. |

### Transition de saison (P1/P2)

Détail : [`vacances-scolaires-jours-feries.md`] et roadmap. Bascule de saison au pivot 15 juillet (`SeasonResolver`), re-datation des événements, purge et rappels.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/seasons/{id}/transition` | POST | `SeasonTransitionController` | Déclenche la bascule vers une nouvelle saison (recap + re-datation, `SeasonTransitionService`). |

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
**SEC-11** : tout `^/api` **authentifié** est en plus limité **par utilisateur** (limiteur `api`,
sliding window 300/min) via `ApiRateLimitSubscriber` (priorité 6, après firewall + tenant) → 429
au-delà ; les endpoints publics (sans `User`) gardent leur limiteur par IP.

### Résolution du tenant (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, **priorité 7 — APRÈS le firewall
de sécurité (priorité 8)**, pour que l'utilisateur JWT soit déjà authentifié) implémente
l'isolation multi-tenant au niveau de chaque requête :

1. **Résolution du clubId** : attribut de requête `_club_id`, sinon header `X-Club-Id`,
   sinon **la membership `ClubUser` active de l'utilisateur JWT** (le frontend n'envoie
   aucun header tenant — c'est le chemin nominal).
2. **Résolution du seasonId** : attribut `_season_id`, sinon header `X-Season-Id` (validé →
   403 si étranger/inconnu), sinon la **saison courante dérivée du calendrier** via
   `SeasonResolver::currentAmong` (pivot 15 juillet — remplace l'ancien lookup unique
   `status='active'`). Le listener pose aussi `_season_readonly` (saison archivée →
   écriture 409, cf. `SeasonReadonlyTest`) et active le filtre Doctrine **`season_filter`**
   (frontière de correction intra-club, en plus du `TenantFilter` club_id).
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

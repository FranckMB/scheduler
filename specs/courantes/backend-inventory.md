# Backend Inventory

> Backward inventory of the existing backend (Symfony 7.4 + API Platform). This document
> describes what exists in the codebase at the time of verification — it is not a roadmap.

Last verified @ 2026-08-16 (re-vérifié contre `backend/src/Controller/ManualEditController.php` + `backend/src/Service/MoveSlotService.php` : P2-32 PR A — contrat 2.10, `MoveSlotService::move()`/`place()` gagnent un paramètre `dryRun` (essai : même chemin jusqu'au verdict INCLUS, gardes pré-moteur et verrou souverain compris, retour AVANT toute écriture — `ManualEditController` répond alors **200** `{valid, dryRun:true, violations, compromises, evicted?}` **même quand `valid=false`**, jamais 422 : un essai ne peut pas échouer au sens HTTP, il RAPPORTE) et les réponses ÉCRITES d'un candidat accepté portent désormais `compromises` (delta de confort nommé du moteur, P2-32 — voir `engine-inventory.md` §POST /validate-assignments) ; timeout **HTTP** de l'appel engine 2 s → **8 s** (`VALIDATE_HTTP_TIMEOUT_SECONDS`, un candidat accepté déclenchant jusqu'à 3 solves moteur — le budget **solveur** par solve reste 2 s) — §route `move`/`place-slot`) ; précédemment : 2026-08-16 (re-vérifié contre `backend/src/Controller/ManualEditController.php` + `backend/src/Service/MoveSlotService.php` : P2-30 PR A — `/move` gagne l'éviction optionnelle (`evictSlotId`, D3 verrou souverain, bloc `evicted`) et le nouveau path `POST /api/schedules/{id}/place-slot` crée une séance à la dérive sous verdict moteur, durée résolue **côté serveur** depuis la fenêtre de gymnase (`durationMinutes` du client = simple assertion, 422 `duration_mismatch`/`slot_unavailable` sinon) — §route `move`/`place-slot`) ; précédemment : 2026-08-16 (re-vérifié contre `backend/src/Entity/ScheduleSlotTemplate.php` + `backend/src/Controller/ManualEditController.php` : contrat 2.9 — les 3 champs morts `temporaryLock`/`temporaryLockFor`/`temporaryMinSessionsOverride` disparaissent de l'entité (plus aucun writer depuis le passage CRUD read-only, jamais lus par le solveur), et la route placebo `POST /api/schedule-slots/{id}/manual-edit/constraint` (`ManualEditService::applyPermanentConstraint`) est **supprimée** — la contrainte qu'elle créait portait des clés `config` que le solveur ne lit jamais, contournant `ConstraintConfigValidator`. Zéro ligne `source='manual_edit'` en base. `applyLock`/`move` intacts — §route `manual-edit/lock`) ; précédemment : 2026-08-16 (re-vérifié contre `backend/src/ApiResource/ScheduleSlotTemplateResource.php` + `backend/src/Service/MoveSlotService.php` : le CRUD `schedule_slot_templates` devient read-only (POST/PUT/DELETE + processor/DTO d'entrée supprimés, `/move` est le seul rail d'écriture — via `ManualEditService`/`ScheduleResultImporter` pour `lockOrigin`) et le 422 de `/move` porte désormais les ids du verdict moteur — §ligne 13 et §route `/move`) ; précédemment : 2026-08-16 (re-vérifié contre `backend/src/Service/PdfGenerator.php` : passe esthétique de l'export PDF — pause méridienne teintée, bande de jour, bordure des cellules occupées, heure épinglée en haut + équipes centrées, coach retiré de la grille, matrice équipe×jour en case pleine — §route `export-pdf`) ; précédemment : 2026-08-13 (recalé ce jour par P4-87 : le 3e marqueur de péremption et son listener ; précédemment : 2026-08-12 (recalé ce jour : marqueur `constraintsChangedSinceGeneration` et son listener ; précédemment : 2026-08-12 (recalé ce jour par P4-86 : la route `manual-edit/one-time` est SUPPRIMÉE — `/move` est le seul rail de déplacement ; précédemment : 2026-08-12 (recalé ce jour par P4-84 : la matrice XLSX porte une colonne Rang triable ; précédemment : 2026-08-12 (recalé ce jour par P2-2/F2b : la route `/move` et le marqueur de score périmé ; précédemment : 2026-08-12 (recalé ce jour par P2-2/F1 : `ScheduleSlotTemplate` porte l'ORIGINE de son verrou ; précédemment : 2026-08-11 (recalé ce jour par P2-23 : les routes export-xlsx et export-pdf portent chacune leur 2ᵉ vue équipe × jour ; précédemment : 2026-08-11 (recalé ce jour par P2-23 : l'export XLSX gagne sa 2ᵉ feuille « Équipes × jours » — §route export-xlsx ; précédemment : 2026-08-08 (audit DOC-04, 5e réouverture — re-vérifié au code sur `backend/src/{Entity,ApiResource,Controller,Service,EventListener,Enum}` et `backend/config/`: JWT en cookie httpOnly SEC-16, module démo, approbation de club par token public P3-4, liste blanche `config` des contraintes SEC-13, console superadmin/SEC-17/SEC-18, RLS de `team_tag_assignment` BCK-11, `regenerate`/`regenerate-from`/`export-xlsx`, JWT de souscription Mercure, `TeamLink`/`TeamMatchHabit`)))))))))

---

## 1. Architecture Backend

### Stack

| Composant | Version / Détail |
|-----------|------------------|
| Langage | PHP 8.4 (`declare(strict_types=1)` dans tous les fichiers) |
| Framework | Symfony 7.4 (LTS ; `symfony/framework-bundle` verrouillé via `extra.symfony.require`, cf. `CLAUDE.md` §5) |
| API | API Platform ^4.3 (auto-génération CRUD + OpenAPI sous `/api/*`) |
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
│   ├── Command/              # Commandes CLI (imports holidays, seed league windows, purge/rappels saison, module démo) — liste : ls backend/src/Command/
│   ├── Storage/              # LogoStorage (interface) + LocalLogoStorage
│   ├── Security/             # JwtCookieFactory (SEC-16), AdminSessionCsrf/SuperAdminProvider/TotpService (SA0), UserChecker
│   ├── Message/ · MessageHandler/  # GenerateSchedule(Message|Handler), ExportPdf(Message|Handler)
│   ├── Mercure/              # ClubTopicUpdate (payload publié sur le topic club:{clubId}:schedule:{id})
│   ├── AdminJob/             # Catalogue + exécution des jobs planifiés de la console superadmin (SA3)
│   ├── Clock/                # DevClockStore (Redis) + SimulatedClock — horloge dev globale, distincte de Club::$demoToday (§3 Module démo)
│   ├── Seed/                 # BcclSeeder + BcclSeedProfile (club de démo permanent)
│   ├── Export/                # ScheduleExportData(Provider) — table plate consommée par l'export Excel/PDF
│   ├── OpenApi/               # CustomRoutesOpenApiFactory (documente les routes Symfony hors API Platform)
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
- `normalization_context.skip_null_values: false` — une clé à `null` reste **présente** en
  `application/json` (le défaut d'API Platform l'omet ; `jsonld` l'incluait déjà). Le frontend
  compare en strict (`null === x`) : une clé absente arrive `undefined` et casse la lecture
  (`chosenScheduleId` null lu comme « validé », `parentEntryId` null → mère prise pour racine).
  Gardé par `JsonNullKeysTest` (phase1).

---

## 2. Resources API Platform

Les ressources sont définies dans `backend/src/ApiResource/` (liste exhaustive : `ls backend/src/ApiResource/`). Chaque ressource est un DTO
avec attributs `#[ApiResource]` déclarant les opérations CRUD standard
(`GetCollection`, `Get`, `Post`, `Put`, `Delete`), un `provider` et un `processor` personnalisés,
et une pagination explicite (détail et exceptions au défaut 30/page : §6). Les entités
Doctrine correspondantes vivent dans `backend/src/Entity/` et utilisent des UUID string.

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
| 12 | Schedule | `/api/schedules` | Générations de planning | ⚑ **TROIS marqueurs de PÉREMPTION** (le planning n'est pas faux, il décrit un état antérieur) : `manuallyEditedSinceGeneration` (F2b — un créneau déplacé à la main) et **`constraintsChangedSinceGeneration`** (2026-08-12 — une contrainte a changé depuis la génération). Le troisième, **`resourcesChangedSinceGeneration`** (P4-87, 2026-08-13), est posé par `ResourceChangeStaleScheduleListener` — venue/coach/team/tags → club+saison ; créneaux/réservations/overrides → **le plan que dit leur `schedule_plan_id`** (NULL = plan SEASON — la grille d'une période est une COPIE, ADR-0002, donc la grille saison ne périme JAMAIS un plan de période, gardé par test) ; `priority_tier` délibérément NON écouté (référentiel global immuable au runtime). Le second est posé par `ConstraintChangeStaleScheduleListener`, un **listener d'entité** sur `Constraint` (`postPersist`/`postUpdate`/`postRemove` + `postFlush`) : les contraintes s'écrivent depuis l'API, les entrées de calendrier datées et d'éventuelles commandes — **marquer par appelant garantissait d'en oublier un**. Portée : les plannings **COMPLETED** du club+saison de la contrainte, **plans validés INCLUS**. ⚠ **Le cas validé a été MESURÉ, pas supposé** (`ConstraintWriteOnValidatedPlanTest`) : valider → 200, puis écrire une contrainte → **201**, et le plan **reste validé** — rien ne lie l'écriture des contraintes à l'état du plan. Un planning validé périmé est le plus grave : c'est celui qu'on distribue aux coachs. Les deux marqueurs sont remis à `false` par tout import solveur (`ScheduleResultImporter`, foyer unique). NR `ConstraintChangeStaleScheduleTest`, **step de `blocking-tests`**.  `mercure: true` ; opérations custom `generate`, `export-pdf`, `export-xlsx` ; filtres `isActive` (booléen) et `seasonId` (exact). Les routes de cycle de vie (`validate`/`reopen`/`regenerate`/`regenerate-from`) sont des routes Symfony hors API Platform (§3). |
| 13 | ScheduleSlotTemplate | `/api/schedule_slot_templates` | Créneaux générés | `GET`/`GetCollection` **seulement** (2026-08-16) — POST/PUT/DELETE retirés, plus de processor ni de DTO d'entrée : le déplacement passe par `POST /api/schedule-slots/{id}/move` (sous verdict moteur), les verrous/contraintes par `manual-edit/*`. **`lockOrigin` depuis P2-2/F1 (2026-08-12)** — `RESERVATION` \| `MANUAL` \| `UNKNOWN`, **nullable** (`NULL` = pas de verrou : les 3 valeurs ne portent que sur un verrou RÉEL). **Server-authoritative** : aucune route ne le pose directement. Écrit aux 3 points d'origine — import du résultat solveur (`ScheduleResultImporter`), épinglage work-loop (`ManualEditService::applyLock`), pseudo-créneaux de réservation côté front (affichage seul, jamais persisté). ⚠ **`UNKNOWN` dit une IGNORANCE, pas une absence de verrou** — un verrou HARD sans réservation appariée reste indécidable et n'est **jamais deviné** (gardé par `LockOriginProvenanceTest`, **step de `blocking-tests`**). Le backfill de migration respecte **quel plan chaque réservation alimente** (base `NULL` → SEASON, overlay → son plan) pour ne pas fabriquer de faux `RESERVATION`. |
| 14 | ScheduleDiagnostic | `/api/schedule-diagnostics` | Erreurs / avertissements | |
| 15 | Constraint | `/api/constraints` | Contraintes permanentes | |
| 16 | TeamCoach | `/api/team-coaches` | Assignations entraîneur-équipe | |
| 17 | CoachPlayerMembership | `/api/coach-player-memberships` | Entraîneurs aussi joueurs | |
| 18 | TeamTag | `/api/team-tags` | Étiquettes d'équipe | |
| 19 | TeamTagAssignment | `/api/team-tag-assignments` | Assignations d'étiquettes | Sous RLS FORCE depuis `backend/migrations/Version20260807170000.php` (BCK-11) : colonne `club_id` (backfillée depuis `team.club_id`) + policy `tenant_isolation` — c'était la seule table liée à un tenant sans backstop base de données, l'isolation reposait sur le seul filtre Doctrine |
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
| — | TeamLink | `/api/team_links` | Pont déclaré entre deux équipes — pas d'entité joueur, le gestionnaire déclare le lien (`teamAId < teamBId` normalisé par le processor) : `NOT_SIMULTANEOUS` (double projet, jamais en même temps) ou `BACK_TO_BACK` (enchaînées, implique `NOT_SIMULTANEOUS`). Consommé par le module matchs (`MatchPlacementPayloadBuilder`, `MatchConflictDetector`) — le solveur d'entraînement ne le lit pas | |
| — | TeamMatchHabit | `/api/team_match_habits` | Créneau de match habituel d'une équipe (un par jour de semaine, gymnase optionnel) — consommé par le module matchs (`MatchPlacementPayloadBuilder`, `AwayKickoffEstimator`) pour estimer les coups d'envoi à l'extérieur | |

> La numérotation n'est **pas** un décompte — liste exhaustive et à jour : `ls backend/src/ApiResource/`. Les tables globales de référence (`PublicHoliday`, `SchoolHolidayPeriod`, `LeagueMatchWindow`) sont exposées en **lecture seule via contrôleurs invokables** (§3), pas comme ressources CRUD.

Chaque ressource déclare sa pagination au niveau de l'attribut `#[ApiResource]` — le détail et
les exceptions au défaut `paginationEnabled: true, paginationItemsPerPage: 30` sont en §6. Les
réponses collections suivent le format JSON-LD (`hydra:member`, `hydra:totalItems`, `hydra:view`).

---

## 3. Custom Controllers

Les contrôleurs personnalisés vivent dans `backend/src/Controller/`. Certains sont déclarés
comme opérations custom API Platform (sur la ressource), d'autres comme routes Symfony
classiques avec `#[Route]`.

### Authentification (`AuthController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/login` | POST | Connexion — firewall `json_login` de Symfony (username `email`, password `password`), succès/échec délégués à LexikJWT. **SEC-16 : rend un `204` SANS CORPS** — le JWT est posé en cookie httpOnly `BEARER` (`path=/api`, `SameSite=Strict`), jamais rendu au client ([`jwt-cookie.md`](../../docs/security/jwt-cookie.md)). Route déclarée dans `config/routes.yaml`. |
| `/api/logout` | POST | **SEC-16, `PUBLIC_ACCESS`** — efface le cookie d'authentification. Seul le serveur le peut (httpOnly) : sans cette route, « Se déconnecter » laisserait la session vivre jusqu'à expiration. Idempotent, ne révèle rien ; public pour rester utilisable sur une session déjà expirée. |
| `/api/register` | POST | Inscription **différée, sans auto-login** (anti-énumération A3, #153 — rate-limité par IP, `auth_register` : 5/15 min). Exige `consent:true` (RGPD, 400 sinon — validation payload-only, enumeration-safe) et stocke la preuve (`termsAcceptedAt`+`termsVersion`). Crée un `User` **non vérifié** (`emailVerifiedAt=null`) + un `EmailVerificationToken` portant l'intention club `{ara, clubName}`, envoie un mail de vérification, et renvoie un **202 générique identique** dans tous les cas (email neuf ou déjà inscrit) — **aucun token émis**. Email déjà connu → aucune création, mail « tu as déjà un compte » (compte non vérifié → renvoie un nouveau lien). **Le club n'est PAS créé ici.** Validation : email, mot de passe (`PasswordPolicy` : ≥12 car. + majuscule + spécial), ARA 3-20 alphanumérique majuscule, `club_name` requis si ARA nouveau. Le login rejette un compte non vérifié (`UserChecker`, message identique à un mauvais mot de passe). |
| `/api/register/verify` | POST | Body `{ token }`. Consomme le token de vérification (verrou pessimiste `PESSIMISTIC_WRITE` anti-double-verify), passe `emailVerifiedAt`, **matérialise le club** sous GUC RLS (ARA nouveau → `Club` + `Season` + `Sport` + 12 `SportCategory` (`Service\Basketball\CategoryCatalog`) + `ClubUser` actif `admin`, `membershipStatus:"active"` ; ARA existant → `ClubUser` **inactif** pending), puis **émet le JWT** (login effectif) — **SEC-16 : posé en cookie httpOnly via `JwtCookieFactory`, plus dans le corps** ; la réponse est `{ membershipStatus, user }`. 400 token invalide/expiré ; 409 si le club à rejoindre a disparu. Purge des comptes non vérifiés > 7j : `app:users:purge-unverified` (cron-runner quotidien à 02:00). |
| `/api/me` | GET | Profil courant — retourne `id`, `email`, `firstName`, `lastName`, `membershipStatus` (`none`/`pending`/`active`), `role`, `club` (id, name, `onboardingCompleted`, `logoUrl`, `accentColor`, `accentPalette`), **`seasonPlan`** (`{id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash}` — LE plan de la saison sélectionnée, ADR-0002 : `chosenScheduleId` = la version choisie, `null` = espace de travail ; `hasFinishedVersion` = le plan porte ≥1 version terminée, ce qui débloque le cockpit ; `currentStructureHash` = hash du payload solver actuel pour comparer la version affichée et griser « Régénérer » quand elle est déjà identique), `hasGenerated` (booléen : `generationCountSeason > 0`), `seasons`. |
| `/api/me` | DELETE | **RGPD droit à l'effacement** (self-only, `DeleteAccountController`). Ré-authentification : body `{ password }` (mot de passe courant, 400 sinon — un JWT volé ne suffit pas). Anonymisation IMMÉDIATE (email → `deleted-{id}@anonymized.invalid`, hash aléatoire, memberships désactivés, transactionnel) ; plus aucun membre actif → `Club.erasureScheduledAt = +30 j` (purge du workspace par `app:clubs:purge-erased`, auto-annulée si un membre revient ; l'identité publique FFBB survit). Réponse `{ message, clubPurgeScheduled, gracePeriodDays }`. NR : `AccountErasureTest`. |
| `/api/me/export` | GET | **RGPD portabilité** (self-only, `RgpdExportController`) : compte + adhésions + preuve de consentement + lastLoginAt, JAMAIS le hash. JSON en téléchargement (`Content-Disposition`). Rate-limité `rgpd_export` (10/h par user). NR : `RgpdExportTest`. |
| `/api/club/export` | GET | **RGPD portabilité club** (management SEC-07, tenant du JWT — pas d'id de chemin ; 404 sans membership actif, 403 non-management) : workspace complet en lignes brutes, une clé par table (liste dans `RgpdExportService::CLUB_TABLES`, `schedule` traité à part hors colonnes lourdes), tenant-scoped garanti par RLS. Rate-limité `rgpd_export`. NR : `RgpdExportTest`. |

### Télémétrie de génération (`SolverMetric.php`)

`solver_metrics` conserve une ligne par tentative de génération (`schedule_id`, `club_id`,
`status`, `wallTimeMs`, `nbVariables`/`nbConstraints`/`nbConflicts`, `score`, `solverVersion`,
`planType`, `nbTeams`/`nbVenues`, `createdAt` — `src/Entity/SolverMetric.php`). La table est
sous RLS `FORCE` et le rôle runtime ne voit que le club courant. `Club.lastActivityAt` est mis
à jour **à la mise en file d'une génération** seulement (`GenerateScheduleController::__invoke`,
`src/Controller/GenerateScheduleController.php:125`) — pas au login. La rétention et le
partitionnement sont différés aux jobs SA3.

### Authentification superadmin (`AdminAuthController.php`)

Identité, provider et firewall stateful séparés de `User`/`ClubUser` et du JWT club. Le
parcours mot de passe + TOTP, la session, le CSRF et l'audit fail-closed sont spécifiés dans
[`superadmin-auth.md`](superadmin-auth.md). Routes : `POST /api/admin/auth/password`,
`POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `POST /api/admin/auth/logout`.

Le reste de la console (supervision parc/solveur, jobs planifiés, journaux read-only, actions
de support, demandes de création de club) vit derrière le même firewall `/api/admin` dans
`AdminMonitoringController`, `AdminJobController`, `AdminAuditLogController`,
`AdminMessengerFailedController`, `AdminSystemErrorsController`, `AdminClubActionController`
et `AdminClubRequestController` (`backend/src/Controller/Admin*.php`) — catalogue de routes
exhaustif et à jour dans [`superadmin-auth.md`](superadmin-auth.md), pas dupliqué ici.
Deux mécanismes transverses à toute la console : le `TenantFilterListener` **retourne
immédiatement** sur `/api/admin/**` (SEC-17, `src/EventListener/TenantFilterListener.php:70` —
la console n'a pas de tenant, et poser `app.club_id` pour une identité admin violerait le
contrat SA0) ; et `AdminCsrfListener` (SEC-18, `src/EventListener/AdminCsrfListener.php`,
priorité 6) exige le jeton CSRF sur **toute méthode non sûre** sous `/api/admin` — opt-out
par exemption explicite (`auth/password`, `auth/totp`, les deux portes de connexion), plus
opt-in par contrôleur.

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
| `/api/schedules/{id}/generate` | POST | `GenerateScheduleController` | Lance la génération asynchrone. Gate management (`assertManager`, SEC-07). Vérifie l'appartenance du schedule au club courant, **borne de complexité A10 pré-dispatch** (`GenerationComplexityGuard` : teams ≤200 · venues ≤50 · slots ≤3000 · contraintes permanentes ≤500 · teams×venues ≤2000 → **422** avant toute mise en queue, statut inchangé, #156), **épinglage orphelin sur un planning de période** (`OrphanPinGuard`, #8) : un verrou HARD ou une réservation qui ne retombe plus sur aucun créneau de la grille de la période (grille refaite : page blanche, recopie) → **422** nommant le gymnase et le jour. ⚠ **Un gymnase DÉSACTIVÉ en est exclu depuis P3-20 (2026-08-06)** : son épinglage est inerte (le solveur ne le verra jamais) et revient intact à la réactivation — refuser enfermait le gestionnaire sur un épinglage devenu invisible, passe le statut à `PENDING`, marque `onboardingCompleted=true` à la première génération, dispatche `GenerateScheduleMessage`. Retourne 202. |

### Cycle de vie du planning (pointeur du plan — ADR-0002)

`ScheduleStatus` (enum) : `DRAFT`, `PENDING`, `GENERATING`, `COMPLETED`, `FAILED`. **« Validé » n'est pas un statut** : c'est le **plan** (`schedule_plan`) qui **pointe** la version faisant foi (`chosen_schedule_id`) — une version pointée reste `COMPLETED`, et le champ de lecture `Schedule.isChosen` l'expose. Le plan de type **SEASON** et sa version choisie **sont** le calendrier de la saison. Générer ne pointe **jamais** (inv. 2) : seul le gestionnaire choisit.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/validate` | POST | `ValidateScheduleController` | **Pointe** la version sur son plan **et supprime les versions sœurs** du même périmètre (inv. 1 — plus d'archivage). Gate management (SEC-07) + contrôle club courant (403 sinon). 409 si le statut n'est pas `COMPLETED`, si une sœur est `PENDING`/`GENERATING`, ou (`overlays_exist`) si choisir une **autre** version de saison détruirait des plans secondaires — confirmer par `{"confirmDeleteOverlays": true}` ; portée et destruction : voir `/reopen`. |
| `/api/schedules/{id}/reopen` | POST | `ReopenScheduleController` | Inverse : le plan **dépointe** la version, qui survit et redevient éditable (inv. 2). Gate management (SEC-07). 409 si la version n'est pas celle que pointe son plan ; 409 `overlays_exist` si le socle porte des plans de période **pas encore commencées** (validés ou non, décision fondateur 2026-07-24, ADR-0002 inv. 14) — confirmés, ils sont détruits **de bout en bout** (versions + plan + grille copiée + réglages) ; l'entrée de calendrier survit, « à traiter » de nouveau. |
| `/api/schedules/{id}/regenerate` | POST | `RegenerateController` | « Régénérer » : crée une **nouvelle version linéaire** (V2, V3…) du **même plan** avec la structure club COURANTE — jamais une régénération en place. Gate management (SEC-07) + club courant + borne A10 (`GenerationComplexityGuard`) avant dispatch. Plans SAISON uniquement (409 sinon — un overlay se régénère depuis le cockpit) ; source doit être `COMPLETED`/`FAILED` et non la version **choisie** (409 « rouvrez-le avant ») ; 409 si une génération est déjà en cours pour la saison. Défense en profondeur du socle en vigueur SOUS verrou de plan-scope (`SocleGuard::assertSeasonPlanNotChosen`, miroir de `processPost`). Aucune copie de créneaux : le payload de génération répingle déjà les verrous HARD des versions de base. |
| `/api/schedules/{id}/regenerate-from` | POST | `RegenerateFromVersionController` | « Charger cette version » : **restaure** la photo de structure (`ScheduleStructureSnapshot`, `StructureRestorer`) d'une version `COMPLETED` dans la structure vivante du club et la marque comme contexte chargé de la saison (`Season.liveContextScheduleId`) — **sans lancer de solve**. Plans SAISON uniquement, source `COMPLETED`, ni choisie ni en cours de génération ; restauration destructive faite sous le même verrou de plan-scope + `assertSeasonPlanNotChosen` **avant** l'écrasement. 409 si aucune photo n'existe (version antérieure à la fonctionnalité). |
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

### Approbation de club par token public (P3-4, `ClubApprovalController`)

Page publique SANS login, ouverte depuis le mail institutionnel FFBB — même patron que
`PublicCoachWishController` : le token EST l'identité, 404 byte-identique, rate-limit par IP
AVANT toute résolution (`club_approval_public`, 20/15 min, `config/packages/rate_limiter.yaml`).
Support entité `ClubCreationRequest` (**hors RLS, pas de `club_id`** — `src/Entity/ClubCreationRequest.php:19`,
le club n'existe pas encore au moment de la demande) via `ClubCreationRequestRepository::findPendingByToken`.

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/club-approvals/{token}` | GET | Résout le token (forme `^[0-9a-f]{64}$`, sinon 404), 410 si expiré. Rend `clubName`, `ara`, `requesterName`, `expiresAt`. |
| `/api/club-approvals/{token}` | POST | Body `{decision: "approve"\|"refuse"}` (422 sinon). Décision **unique** : la demande passe hors statut PENDING, un second appel revoit 404. `approve` délègue à `ClubApprovalService::approve` — verrou consultatif Postgres `pg_advisory_xact_lock(hashtext('club-approval:'.ara))` (anti-double-club sur deux demandes concurrentes pour le même ARA), club déjà né entre-temps → la demande devient une adhésion `pending` (jamais un second club), sinon `ClubProvisioner::createClub`. |

### Validation des contraintes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/constraints/validate` | POST | `ValidateConstraintsController` | Gate pré-solve. En mode période, le jeu validé vient de **`PeriodConstraintSelector`** (P2-14) — LA source unique partagée avec `buildForPeriodPlan`, qui aligne le récap sur ce que le solveur recevra (parité gardée par `PeriodGatePayloadParityTest`, phase1). Retourne `errors` par contrainte + `conflits` + `warnings` (drops pour gymnase désactivé, tag inerte, **capacité dans les deux sens** — P2-9 PR A : demande = Σ `sessionsPerWeek` du payload, offre = Σ capacités des créneaux, sous-capacité en « au moins X », surplus dès 1 créneau en trop ; nombres lus du payload `buildForClubSeason`/`buildForPeriodPlan`, jamais recalculés ; **coach indisponible × réservation en dur** — PR A 2026-08-06, miroir du parse moteur dans `CoachDoubleBookingDetector::detectUnavailabilityClashes`, avertit AVANT au lieu de l'INFO post-solve) + `blockers` (coach dédoublé, P2-9 PR B ; **saturation des « au moins » par gymnase** — PR A 2026-08-06 : demande = Σ des minimums (un pin n'y compte pas, sa variable n'existe pas), offre = places des triplets NON verrouillés — demande > offre = INFEASIBLE certain ; **surplus de réservations d'une équipe** — P3-20 2026-08-06 : plus de réservations que de `sessionsPerWeek` est une INCOHÉRENCE gestionnaire, pas une préférence (un verrou est pré-placé hors modèle, les trois s'imposeraient) ; la règle vivait côté client en simple avertissement, elle est revenue au serveur). **L'algèbre des lectures de payload vit dans `PayloadCapacityMirror`** (P3-19, 2026-08-07 — source unique offre/saturation/grille, parité épinglée contre le VRAI moteur par `CapacityMirrorParityTest`, groupe `contract`). |

### Écriture des contraintes — liste blanche `config` (SEC-13)

`ConstraintConfigValidator` (`src/Service/ConstraintConfigValidator.php`) est LA liste blanche
noms+types du champ `config` d'une contrainte — branchée dans `ConstraintStateProcessor`
(création ET PUT), seul champ du formulaire qui n'avait auparavant aucune validation. Une clé
mal orthographiée (`maxStartTme`) rendait 201, s'affichait comme une règle active, et le
solveur l'ignorait silencieusement (déclaré ≠ effectif). Violation → **422** nommant la clé.
Quatre familles (`enum ConstraintFamily` : `TIME`, `DAY`, `FACILITY`, `COACH_AVAILABILITY` —
`FACILITY_CAPACITY` a été retirée, plus personne ne pouvait la créer) + `targetTag`, lisible
par toutes. `config.coachId` a été supprimé (doublon exact du `scope`, la cible est déjà le
scope). Chaque clé de la liste cite qui la lit ; pour les clés lues par le moteur, la preuve
qu'elles changent le résultat du solveur est portée par le job CI dédié `engine-semantics`
(groupe `contract`).

### Calendriers — vacances scolaires & jours fériés

Référentiels globaux display-only (jamais consommés par le solveur). Détail complet (modèle, zones, commandes d'import, règles) : [`vacances-scolaires-jours-feries.md`](vacances-scolaires-jours-feries.md).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/school-holidays` | GET | `SchoolHolidaysController` | Vacances scolaires de la zone du club (`Club.schoolZone`) dans la fenêtre `from`/`to` (défaut : saison active). Zone null → `items: []`. |
| `/api/public-holidays` | GET | `PublicHolidaysController` | Jours fériés `NATIONAL` ∪ extras du territoire du club, même fenêtre. Zone null → NATIONAL quand même. |

### Export PDF / Excel

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/export-pdf` | POST | `ExportPdfController` | Lance l'export PDF asynchrone. Passe `pdfExportStatus` à `pending`, dispatche `ExportPdfMessage`. Retourne 202. **Deux sections depuis P2-23 (2026-08-11)** : page 1 = la grille gestionnaire, page 2+ = la matrice **Équipes × jours** — lignes groupées par **rang** (S→A→B→C→D, sous-titres, `break-inside: avoid` pour qu'un groupe ne soit jamais coupé), colonnes = les seuls jours occupés, cellule = `gymnase · HH:MM` **sans coach**. **Le PNG reste la page 1 seule.** Même route, **1 crédit inchangé**. La 2ᵉ section n'existe pas en périmètre mono-gymnase (`PdfGenerator::hasMatrix` — gymnases **distincts parmi les placements**, même règle qu'au XLSX). ⚠ Le worker (`frontend/worker.js`) garde son chemin mono-section **intact** (aucune ligne retirée) ; le multi-section ajoute le drapeau `multiSection: true` au payload et ajuste la grille par CSS `zoom` — **pas** `transform`, qui ne reflue pas et laissait déborder la grille sur la page 2. **Passe esthétique (2026-08-16)** : page 1 n'est plus « inchangée » depuis P2-23 — la pause méridienne 12:00-14:00 est teintée sur les cases vides/la gouttière d'heures avec un trait marqué à ses deux bornes, une bande verticale noire marque la frontière entre jours (en-tête et corps), les cellules occupées portent une bordure 2 px quasi-noire (contre 1 px gris pour le reste de la grille), et chaque cellule occupée affiche l'**heure épinglée en haut** puis les équipes empilées **centrées** (`vertical-align: middle` sur `td.filled`, fix du même jour — le contenu s'empilait au sommet et une séance longue semblait vide en bas) ; le **coach est retiré de la grille** (il ne survit que dans le panneau `SlotDetail` côté écran). Section 2 (matrice) : la cellule pleine devient un **bloc de la couleur du gymnase** occupant toute la case, texte centré, contraste auto — remplace la pastille (`chip`) d'avant cette date ; le contenu textuel (`gymnase · HH:MM`, sans coach) est inchangé. NR : `backend/tests/Unit/Service/PdfTeamDayMatrixTest.php`. |
| `/api/schedules/{id}/export-xlsx` | POST | `ExportXlsxController` | Export Excel **synchrone** (`PhpSpreadsheet`, pas de tête sans écran à attendre) : flux `.xlsx` en téléchargement direct, filtrable par gymnase. Contrôle club courant (403). Nom de fichier = le nom **vivant** du plan (`SchedulePlanProvisioner::displayNameOf`, pas la photo `Schedule.name`) ; `/`/`\` remplacés avant `makeDisposition` (sinon 500 générique — Symfony lève sur un séparateur de chemin dans un nom de fichier).  **Deux feuilles depuis P2-23 (2026-08-11)** : « Planning » (le tableau plat triable, une ligne par créneau ET par fenêtre vide) et **« Équipes × jours »** — matrice lignes = **toutes** les équipes de la saison (une équipe sans séance garde sa ligne vide : le trou est l'information), colonnes = **`Rang`** (P4-84, 2026-08-12) puis les seuls jours occupés, cellule = `gymnase · HH:MM` **sans le coach**, ⚠ la colonne `Rang` vaut `01 · S`, `02 · A`… — **le préfixe numérique zéro-paddé EST le sujet** : un tri Excel sur les libellés bruts « S, A, B, C, D » rendrait A, B, C, D, S, donc PAS l'ordre du PDF. Ainsi préfixée elle trie **exactement** comme le PDF (même `tierRank`), et le padding tient au-delà de 9 paliers (gardé par test) ; équipe sans rang → cellule vide, donc en fin de tri. deux séances le même jour empilées dans la cellule. ⚠ La 2ᵉ feuille n'est **pas créée** quand les placements ne couvrent qu'un seul gymnase (export scopé ou club mono-gymnase) : elle n'aurait rien à désambiguïser. Même route, même fichier, **1 crédit inchangé**. Les deux feuilles projettent leurs colonnes **par nom** (D-18) — gardé par `SpreadsheetColumnsAreProjectedByNameTest` et `SpreadsheetTeamDayMatrixTest`.|

### Édition manuelle (`ManualEditController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/schedule-slots/{id}/move` | POST | **Déplace un créneau SOUS LE VERDICT DU MOTEUR** (P2-2 F2b, 2026-08-12) — `MoveSlotService` : (1) **409** `generation_in_progress` si `ClubGenerationLock::isGenerating()`, (2) baseline construite **sans la source ET sans les créneaux des versions sœurs** (le placement d'origine de la source est aussi porté en `reference` du payload moteur, P2-32 — sert au delta de compromis, jamais au verdict booléen), (3) `POST /validate-assignments` sur l'engine (timeout HTTP **8 s** — P2-32, un candidat accepté déclenche jusqu'à 3 solves moteur), (4) refus → **422** `{valid:false, violations:[{rule, message, teamId, coachId, venueId, dayOfWeek, startTime, conflictingTeamId}]}` (messages déjà humains, ids null-safe pour le surlignage front) et **rien n'est écrit**, (5) accord → écriture + marqueur `manuallyEditedSinceGeneration` + publication Mercure, réponse `{valid:true, compromises, evicted?}` — **`compromises` (P2-32)** est le delta de confort nommé que ce déplacement casse/apporte (`family`/`effect` `broken`\|`gained`/`message` déjà humain sans id brut/ids d'entité pour le surlignage), jamais un poids ni une note (P5-14b) ; liste vide sur refus. Moteur injoignable → **502**, rien écrit. Planning validé → 409 (lecture seule). ⚠ **La re-validation a lieu AU MOMENT D'ÉCRIRE** — le verdict n'est jamais un cache. **Éviction OPTIONNELLE depuis P2-30 PR A (2026-08-16)** : le body gagne `evictSlotId` — retirer l'occupant de la cible visée. Validé **AVANT tout appel moteur** (D3 : un verrou est souverain) : occupant introuvable / d'un autre planning / égal à la source / ne siégeant pas à la cible → **422** `code=evict_target_mismatch` ; occupant verrouillé (`lockLevel` ≠ NONE) → **422** `code=target_locked`, le moteur n'est jamais consulté. Accepté → l'occupant évincé est **retiré de la baseline** envoyée au moteur, puis **supprimé dans la même transaction** que le déplacement ; le 200 porte un bloc `evicted` (état de l'occupant AVANT suppression : `slotId`/`teamId`/`dayOfWeek`/`startTime`/`venueId`/`durationMinutes`) que le front peut proposer de replacer. Pas de swap atomique (décision fondateur) : un échange vécu reste deux `/move` successifs. **`dryRun` (P2-32, body `dryRun:true`)** : un ESSAI — même chemin JUSQU'AU VERDICT INCLUS (toutes les gardes ci-dessus, le verrou souverain refuse l'essai comme le geste réel), puis retour AVANT toute écriture (ni déplacement, ni suppression de l'occupant, ni marqueur, ni Mercure). Réponse toujours **200** `{valid, dryRun:true, violations, compromises, evicted?}` — **même quand `valid=false`** : un essai RAPPORTE, il ne peut pas échouer au sens HTTP, donc jamais 422 sur ce chemin ; `evicted` y décrit l'état qui SERAIT évincé, sans le supprimer. NR `SlotMoveVerdictTest` (couvre éviction + `reference`/`compromises` + `dryRun` accepté/refusé/avec éviction), **step de `blocking-tests`**. |
| `/api/schedules/{id}/place-slot` | POST | **PLACE une séance À LA DÉRIVE — surnuméraire ou rattrapage — SOUS LE VERDICT DU MOTEUR** (P2-30 PR A, 2026-08-16) — même service `MoveSlotService::place()`, mêmes gardes que `/move` (management, tenant, **409** `generation_in_progress` ou version choisie lecture seule, **502** moteur injoignable). Pas de source à retirer : la baseline reste **complète** (moins les créneaux des versions sœurs), et il n'y a pas de `reference` (création à la dérive → le delta de compromis se lit contre la baseline nue). Aucune garde de comptage — le verdict moteur est seul juge (capacité, fenêtre, repos coach…). Refus → **422** `{valid:false, violations:[…]}` (même forme que `/move`) et rien créé ; accord → **200** `{valid:true, slotId, compromises}`, une ligne `ScheduleSlotTemplate` **déverrouillée** (`lockLevel` NONE, `lockOrigin` null, `coachId` null), marqueur `manuallyEditedSinceGeneration` + Mercure — **`compromises` (P2-32)**, même forme et même règle que `/move` (jamais un poids, liste vide sur refus). ⚠ **La durée ne vient JAMAIS du client** : `durationMinutes` du body est **optionnel** et n'est qu'une **assertion** — la durée persistée est TOUJOURS celle de la fenêtre de gymnase visée (`venueId`+`dayOfWeek`+`startTime`), lue dans le **même payload** que celui envoyé au moteur (même source que `slot_durations` côté solveur). Aucune fenêtre à ce triplet → **422** `code=slot_unavailable` ; fenêtre trouvée mais `durationMinutes` fourni la contredit → **422** `code=duration_mismatch` — les deux AVANT tout appel moteur, rien écrit (correctif d'un finding de revue sécurité : une durée client non validée aurait écrit une occupation jamais jugée par le moteur). `teamId` d'une équipe hors club/saison du planning → **422** (équipe inconnue). **`dryRun` (P2-32, body `dryRun:true`)** : même patron que `/move` — verdict jusqu'au bout (résolution de durée/fenêtre comprise), zéro écriture, **200** `{valid, dryRun:true, violations, compromises}` y compris sur un candidat refusé (jamais 422 sur ce chemin). NR `SlotPlacementVerdictTest` (couvre durée menteuse + `compromises` + `dryRun`), **step de `blocking-tests`**. |
| `/api/schedule-slots/{id}/manual-edit/lock` | POST | Applique un verrou sur un créneau. Body : `lockLevel` (enum `LockLevel`). Retourne 200. |

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

### Module démo

Deux mécanismes distincts, à ne pas confondre :

1. **Horloge simulée PAR CLUB** (`Club::$isDemo`/`$demoToday`, `src/Entity/Club.php:102,110`) —
   `DemoAwareClock` (`src/Service/DemoAwareClock.php`) décore l'horloge réelle : si le club
   résolu par le tenant (`_club_id`, posé par `TenantFilterListener` APRÈS le firewall) est
   `isDemo` et porte un `demoToday`, `now()` rend la **date simulée** à l'**heure réelle** dans
   le fuseau réel ; sinon l'horloge est vraie. **Aucune route HTTP n'écrit `demoToday`** — seule
   la commande CLI `app:demo:clock` (`src/Command/DemoClockCommand.php`, options `--club`,
   `--date`, `--clear`) le fait, une action de support (SA4).
2. **`DevClockController`** (`/api/dev/clock`, GET/POST) est un mécanisme **global**, sans
   rapport avec `demoToday` : il pin/relâche l'horloge de TOUTE l'app dans Redis
   (`DevClockStore`), lue par `SimulatedClock` (alias de `ClockInterface` en dev). Gardé par
   `%kernel.debug%` — 404 en environnement non-debug (donc en prod).

Le club de démonstration permanent (BCCL) est créé/réinitialisé par `app:demo:seed-bccl`
(`src/Command/DemoSeedBcclCommand.php`, connexion `admin`, options `--password`/`--email`) via
`BcclSeeder` + `BcclSeedProfile` (`src/Seed/`, 26 identités fictives substituées de façon
positionnelle et déterministe). Un club de démonstration **prospect** (à partir d'un code
FFBB réel) se crée par `app:demo:create` (`src/Command/DemoCreateCommand.php`, options
`--ffbb`, `--name`, `--animator-email`, `--animator-password`) qui pointe le compte animateur
dessus. Deux contrôleurs dev-only relaient ces gestes en environnement e2e/test (mêmes garde
`%kernel.debug%`, 404 en prod) : `POST /api/dev/approve-club-request` (`DevClubApprovalController`,
approuve la demande PENDING de l'appelant) et `POST /api/dev/mark-season-paid`
(`DevSeasonPaymentController`, marque payée la saison SUIVANTE du club courant — respecte
l'horloge simulée).

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
| `/api/venue_match_windows` · `/api/venue_unavailabilities` | CRUD | API Platform (5-fichiers) | **Capacité (P1-4 PR B)** : fenêtres d'accès match (jour+plage, `start<end` même jour) et indisponibilités toutes-circonstances (dates incluses + motif, écriture management-gated). `venueId` étranger invisible → 422. |
| `/api/venue-unavailability-impact` | GET | `VenueUnavailabilityImpactController` | Flux d'alerte cockpit : par indispo, matchs placés touchés + séances d'entraînement des plannings **effectifs** (ADR-0002, `EffectiveScheduleResolver`). Lecture seule, rien persisté. |
| `/api/fixtures/import/analyze` | POST | `ImportFixturesAnalyzeController` | **Dry-run** de l'export FBI global club : table des divisions résolue contre la correspondance persistée (`Competition`), zéro écriture. |
| `/api/fixtures/import` | POST | `ImportFixturesController` | Import FBI **une passe** (fichier global + `mappings` JSON) : persiste les correspondances puis crée/**met à jour** par diff `(team, n° FBI)`. Rapport `created`/`updated`/`unchanged`/`exempted`/`warnings`/`unmappedDivisions`/`errors`. Remplace `/api/teams/{id}/fixtures/import` (P1-4 PR A, 2026-08-02). |
| `/api/fixtures/place` | POST | `PlaceMatchesController` | « Placer automatiquement » (P1-4 PR D, ADR-0003). Rail **SYNCHRONE** — pas de Messenger/Mercure, verrou Redis dédié `MatchPlacementLock` (TTL 90 s, anti-double-clic). Ordre des gardes : SEC-07 (management) → saison inscriptible → `SocleGuard::assertSeasonPlanChosen` (409 si pas de socle en vigueur). Construit le payload (`MatchPlacementPayloadBuilder`, y compris `TeamLink`/`TeamMatchHabit`), appelle `POST /place-matches` sur l'engine (timeout 60 s, `BAD_GATEWAY` si l'appel échoue — rien n'est écrit avant l'application du résultat), applique les placements (`MatchPlacementResultApplier`). Un match non plaçable n'est **jamais une erreur** : il revient nommé dans `unplaced` avec sa raison. |

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
- **SEC-16 — le jeton voyage en cookie httpOnly** : `set_cookies.BEARER` + `token_extractors.cookie`
  (`config/packages/lexik_jwt_authentication.yaml`). L'extracteur `authorization_header` reste
  ACTIF : scripts d'ops, smokes et helpers e2e ne sont pas des navigateurs et continuent en
  `Bearer`. `Secure` piloté par `JWT_COOKIE_SECURE` (défaut `true`, fail-closed).
  Contrat + pièges : [`jwt-cookie.md`](../../docs/security/jwt-cookie.md).
- Firewall `api` (`^/api`) : `stateless: true`, `provider: app_user_provider`, `jwt: ~`.
- Provider : `app_user_provider` (entity `App\Entity\User`, property `email`).
- Password hasher : `auto` (config `security.yaml`).

### Access control

| Path | Méthode | Rôle |
|------|---------|------|
| `^/api/admin/auth/password$` | — | `PUBLIC_ACCESS` (porte de connexion admin) |
| `^/api/admin/auth/totp$` | — | `PUBLIC_ACCESS` (porte de connexion admin) |
| `^/api/admin` | — | `ROLE_SUPER_ADMIN` (firewall stateful `admin` séparé — §3 Authentification superadmin) |
| `^/api/login` | — | `PUBLIC_ACCESS` |
| `^/api/logout$` | — | `PUBLIC_ACCESS` |
| `^/api/register` | — | `PUBLIC_ACCESS` |
| `^/api/password` | — | `PUBLIC_ACCESS` |
| `^/api/health` | — | `PUBLIC_ACCESS` |
| `^/api/docs` | — | `PUBLIC_ACCESS` |
| `^/api/clubs/[^/]+/logo$` | GET | `PUBLIC_ACCESS` (image de marque publique, SEC-10) |
| `^/api/ffbb-logos/` | GET | `PUBLIC_ACCESS` (logos ligue/comité rehébergés, même motif SEC-10) |
| `^/api/coach-wishes/public/` | GET, POST | `PUBLIC_ACCESS` (le token porte l'identité — #10 C2) |
| `^/api/club-approvals/` | GET, POST | `PUBLIC_ACCESS` (le token porte l'identité — P3-4) |
| `^/api` | — | `IS_AUTHENTICATED_FULLY` |

Seule la première règle correspondante s'applique. Tout le reste de `/api/*` requiert un JWT
valide (ou, sous `/api/admin`, une session superadmin séparée — jamais un JWT club, §3).
Le firewall `login` applique en plus `login_throttling` (`max_attempts: 5`) ; `/api/register` et
`/api/password/forgot` sont rate-limités par IP (`config/packages/rate_limiter.yaml`, sliding window 5/15 min).
**SEC-11** : tout `^/api` **authentifié** est en plus limité **par utilisateur** (limiteur `api`,
sliding window 300/min) via `ApiRateLimitSubscriber` (priorité 6, après firewall + tenant) → 429
au-delà ; les endpoints publics (sans `User`) gardent leur limiteur par IP.

### Résolution du tenant (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, **priorité 7 — APRÈS le firewall
de sécurité (priorité 8)**, pour que l'utilisateur JWT soit déjà authentifié) implémente
l'isolation multi-tenant au niveau de chaque requête. Il **retourne immédiatement** sur
`^/api/admin` (SEC-17, `src/EventListener/TenantFilterListener.php:70`) — la console
superadmin n'a pas de tenant, §3 :

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
  `MercureHardeningTest`.

### Souscription frontend (`MercureAuthController`, FRT-04)

`GET /api/mercure/auth` signe un JWT hub subscriber (même secret `MERCURE_JWT_SECRET` que le
publieur) dont l'autorisation `subscribe` est un **URI template borné au club résolu par le
tenant** — `club:{clubId}:schedule:{id}`, où seul `{id}` varie ; `clubId` revalidé en forme
UUID canonique (défense en profondeur : le sélecteur EST la frontière de sécurité). Le jeton
part en **cookie httpOnly** `mercureAuthorization` (`path: /.well-known/mercure`, `SameSite=Strict`,
`secure` piloté par la MÊME variable `JWT_COOKIE_SECURE` que le cookie JWT applicatif — jamais
`$request->isSecure()`, TTL 3600 s), jamais rendu au JS (même raisonnement que SEC-16 : pas de
second jeton lisible en plus du JWT applicatif). Le frontend consomme
(`frontend/src/shared/lib/scheduleStream.ts`) : un seul `EventSource` par session sur
`/.well-known/mercure?topic={topicTemplate}`, reçoit ainsi les mises à jour de TOUTES ses
générations.

### Topic et publication

Le topic Mercure suit le format :

```
club:{clubId}:schedule:{scheduleId}
```

La publication est effectuée par les handlers asynchrones, toujours via l'enveloppe
`App\Mercure\ClubTopicUpdate::private()` (topic privé, publisher `publish: '*'` mais
consommateur borné par le sélecteur JWT ci-dessus) :

- **`GenerateScheduleHandler`** délègue à `ScheduleProgressPublisher` (BCK-04, extraction du
  handler — `src/Service/ScheduleProgressPublisher.php`) : `publish()`/`publishSafely()` (le
  second avale une panne Mercure — best-effort, le front rattrape par polling) publient
  `{scheduleId, status, score, unplaced, warnings}` — à l'entrée en `GENERATING` et à chaque
  état terminal (succès, échec, timeout), pas seulement après import.
- **`ExportPdfHandler`** publie directement sur le hub (`{pdfExportStatus, pdfExportUrl,
  pngExportUrl}`) après génération du PDF, et une fois sur échec (planning devenu invisible
  sous RLS — pour ne pas laisser le front tourner en boucle sur `pdfExportStatus`).

La ressource `ScheduleResource` déclare `mercure: true` au niveau de l'attribut `#[ApiResource]`,
ce qui active la diffusion Mercure pour les opérations CRUD standard sur les schedules. La
souscription frontend (cookie, template, `EventSource`) est décrite ci-dessus.

---

## 6. Pagination

Chaque ressource API Platform déclare explicitement `paginationEnabled` (et
`paginationItemsPerPage` quand activée) au niveau de l'attribut `#[ApiResource]`. La majorité
suit le défaut `paginationEnabled: true, paginationItemsPerPage: 30`, mais ce n'est **plus
universel** — vérifier au besoin `grep paginationItemsPerPage backend/src/ApiResource/*.php` :

- **Désactivée** (`paginationEnabled: false` — listes petites, sparse, ou consommées entières
  par un écran) : `SchedulePlan`, `TeamPeriodOverride`, `ConstraintPeriodOverride`,
  `VenuePeriodOverride`, `CoachWish`, `CoachWishCampaign`.
- **Surchargée à 50 ou 100** (listes qui grossissent plus vite que le défaut 30 ne le tolère) :
  `TeamLink`, `TeamMatchHabit`, `VenueUnavailability`, `Competition` (50) ; `Reservation`,
  `Fixture` (100).

Les collections sont servies au format JSON-LD :

- `hydra:member` : tableau des items de la page courante.
- `hydra:totalItems` : nombre total d'items.
- `hydra:view` : liens de navigation (`hydra:first`, `hydra:next`, `hydra:last`, `hydra:previous`).
- Paramètres de requête : `page` (numéro de page), `itemsPerPage` (surchargeable via
  `pagination_client_items_per_page` si activé).

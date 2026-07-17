Last verified @ feat/adr0002-lot-c1-plan-au-geste 2026-07-17

Snapshot régénéré depuis le backend vivant : `php bin/console api:openapi:export`. **82 paths.**
Changements récents :
- **ADR-0002 lot C1 — LE PLAN NAÎT DU GESTE (2026-07-17)** : aucun path touché (82),
  un seul champ déménage. `teamSelectionInitialized` quitte **`CalendarEntry`** pour
  **`SchedulePlan`** : le garde de seed est une propriété de la RÉPONSE (le plan), pas
  du FAIT (l'événement calendrier) — inv. 5, les réglages de période s'accrochent au
  plan. Corollaire côté serveur : un plan CLOSURE/HOLIDAY naît désormais à la création
  de sa `CalendarEntry` (le geste « ajuster »), plus à la première génération, donc
  `GET /api/schedule_plans?calendarEntryId=…` répond dès qu'une période existe.
- **ADR-0002 — LA BASCULE (2026-07-16, RUPTURE)** : le plan SEASON et sa version pointée
  sont LE calendrier de la saison, et le legacy meurt dans le même commit.
  - `GET /api/me` : `baselineScheduleId` / `socleValidatedAt` / `planningName`
    **supprimés** (ils n'étaient pas déclarés au contrat, seulement dans le payload).
    `seasonPlan { id, name, chosenScheduleId, hasFinishedVersion }` est la seule couture.
  - **`PUT /api/schedule_plans/{id}`** (nouveau, seul changement de path) : renomme le
    plan — le nom vit sur le plan (inv. 12), donc un seul écrivain. Gate management SEC-07.
  - `Schedule.status` perd **VALIDATED** et **ARCHIVED** : « validé » se dérive du pointeur
    et de rien d'autre. Nouveau champ de lecture **`Schedule.isChosen`** — le plan de cette
    version la pointe (vrai pour le calendrier de la saison comme pour l'overlay d'une
    période, dont le pointeur n'est pas visible depuis `/api/me`).
  - `POST /api/schedules/{id}/set-baseline` **supprimé** (inv. 18) — la route n'était pas
    documentée, donc aucun path ne disparaît du snapshot.
  - Créer un planning secondaire sans socle en vigueur : **409** (était 422). Les deux
    conditions legacy fusionnent en une seule, donc un seul code.
- **Santé technique superadmin SA2 (2026-07-16)** : `GET /api/admin/health`
  sonde DB, Redis, engine, heartbeat worker et Mercure, puis expose backlog,
  échecs et retries Messenger sans propager les pannes individuelles.
- **Supervision superadmin SA2 API (2026-07-16)** : `GET /api/admin/overview`
  expose les agrégats parc/solveur et `GET /api/admin/clubs` la liste transverse
  paginée/recherchable avec saison, volumétrie et métriques sur 30 jours.
- **ADR-0002 pattern « Plan » — Lot B1 (2026-07-16, ADDITIF)** : aucun path ni schéma ne
  bouge et **aucun comportement ne change** (le lot maintient le pointeur du plan sans que
  rien ne le lise). *Périmé par la bascule ci-dessus.*
- **SA1 métriques (2026-07-16)** : les métriques de génération sont persistées côté
  backend et `Club.lastActivityAt` est un champ de lecture pour les futurs agrégats.
- **Superadmin SA0 backend (2026-07-16)** : quatre routes custom sous
  `/api/admin/auth/{password,totp,me,logout}` documentent l'authentification séparée
  mot de passe + TOTP, la session admin et le token CSRF exigé au logout.
- **ADR-0002 pattern « Plan » — Lot A (2026-07-12)** : nouvelle ressource **`SchedulePlan`**
  (`/api/schedule_plans`, lecture seule) — le conteneur nommé des versions d'une saison/période
  (`type` SEASON/CLOSURE/HOLIDAY, `name`, `startDate`/`endDate`, `calendarEntryId?`,
  `chosenScheduleId?`). **`Schedule`** expose `schedulePlanId` + `versionNumber` (lecture).
  Le catalogue de facturation **`Plan`** est renommé **`SubscriptionPlan`**
  (`/api/plans` → `/api/subscription_plans`, lecture seule, SEC-14). Additif : aucun champ
  legacy retiré.
- **contraintes désactivables par période (2026-07-12)** : nouvelle ressource
  **`ConstraintPeriodOverride`** (`/api/constraint_period_overrides`) — surcharge sparse
  par (période CLOSURE, contrainte) : `isActive` (false = contrainte permanente
  désactivée pour la période). Le build overlay filtre les permanentes désactivées ;
  le socle (base plan) et le `isActive` propre de la `Constraint` ne sont jamais touchés.
  Défaut = toutes actives (aucun seed). Wizard : panneau « Contraintes » de la période.
- **période : flag d'initialisation (2026-07-12)** : `CalendarEntry` expose
  `teamSelectionInitialized` (read-only) — vrai dès la 1re surcharge d'équipe
  (`TeamPeriodOverride`). Le wizard ne pré-remplit « Fanion seul » que si faux →
  plus de re-seed après un reset « tout actif » ou un reload (survit au F5).
- **structure de période éditable (2026-07-12)** : `VenueTrainingSlot` gagne
  `calendarEntryId` (créneau scopé période, additif ; listing par défaut = saisonnier
  `IS NULL`, `?calendarEntryId=` liste ceux d'une période). Nouvelle ressource
  **`TeamPeriodOverride`** (`/api/team_period_overrides`) — surcharge sparse par
  (période, équipe) : `isActive` + `sessionsPerWeek?`. Le build overlay résout
  saisonnier→période (créneaux additifs, équipe off = 0 séance, séances override).
- **planning-versions étoile = contexte chargé (2026-07-11)** : `Schedule` expose
  `isLiveContext` (read-only, ★) — la version dont la structure est le contexte
  actuellement chargé (posé sur chaque plan de saison COMPLETED, re-pointé par
  « Charger cette version »). `Season.live_context_schedule_id` (migration). «
  Charger cette version » ne génère plus : elle restaure la structure et repointe
  le ★ sur la version source (200, aucune nouvelle version) ; « Régénérer » crée
  la nouvelle version.
- **planning-versions D3 gating (2026-07-11)** : `Schedule` expose `hasStructurePhoto`
  (read-only) — vrai seulement si la version porte une photo de structure (D2)
  restaurable. Le front n'offre « Charger cette version » que dans ce cas (un plan
  pré-D2 a un payload solveur mais pas de photo → l'action 409ait).
- **RGPD PR-5 consentement (2026-07-11)** : `/api/register` exige `consent: true` (400 sinon,
  validation payload-only — enumeration-safe A3) ; preuve stockée (`termsAcceptedAt` +
  `termsVersion`). Page publique `/confidentialite` côté frontend (placeholders juridiques).
- **RGPD PR-2 portabilité (2026-07-11)** : `GET /api/me/export` (self-only — compte + adhésions,
  jamais le hash) et `GET /api/club/export` (management SEC-07, tenant du JWT — workspace complet
  en lignes brutes par table), servis en téléchargement JSON (`Content-Disposition: attachment`).
- **RGPD PR-1 effacement (2026-07-11)** : `/api/me` gagne **DELETE** (`DeleteAccountController`,
  ajouté à `CustomRoutesOpenApiFactory`) — anonymisation immédiate self-only, confirmation =
  **ré-authentification par mot de passe** (revue sécurité : un JWT volé ne suffit pas) ; si
  plus aucun membre actif, purge du workspace club programmée à +30 j (`clubPurgeScheduled`/
  `gracePeriodDays` dans la réponse), auto-annulée si un membre revient. L'identité publique
  FFBB du club survit à la purge (win-back : ré-inscription sur l'ARA = reprise directe).
- **planning-versions D1 (2026-07-10)** : `ScheduleStatus` gagne `ARCHIVED` (posé serveur
  uniquement — jamais accepté d'un payload client) ; `Schedule` expose `generatedTeamCount`
  (read-only, bandeau divergence) ; `Season` gagne `planningName` (nom du planning de saison,
  écrit via PUT season, lu aussi dans `/api/me`).
- **SEC-14 tables globales en lecture seule (2026-07-10)** : `Plan`, `PriorityTier`, `Sport`
  perdent `Post/Put/Delete` (ne gardent que `GetCollection`/`Get`) — ce sont des tables
  globales (sans `club_id`) lues par le solveur/facturation de tous les clubs ; une écriture
  via l'API tenant les falsifiait cross-club. Leurs DTO d'input + processors write supprimés.
- **Inscription vérifiée par email (A3, 2026-07-09)** : `/api/register` passe d'un `201`+JWT à un
  **`202` générique** (anti-énumération : réponse identique pour un email neuf ou déjà inscrit, aucun
  token) ; nouvelle route custom `POST /api/register/verify` (`AuthController`, ajoutée à
  `CustomRoutesOpenApiFactory`) qui consomme le token du lien email et émet le JWT.
- **Export planning (2026-07-08)** : `POST /api/schedules/{id}/export-xlsx` (opération API Platform
  custom sur `ScheduleResource`, patron `export-pdf`) — export Excel synchrone (téléchargement direct).
  `export-pdf` accepte désormais un `venueId` optionnel (périmètre tous gymnases / un gymnase).
- **Module matchs palier A PR-4 (2026-07-07)** : `POST /api/teams/{id}/fixtures/import` (opération API
  Platform custom sur `TeamResource`, patron `clubs/{id}/import-teams`) — import FBI des rencontres,
  multipart. `FixtureResource.externalRef` exposé en lecture. Voir [`module-matchs.md`](module-matchs.md).
- **Module matchs palier A PR-2 (2026-07-07)** : route custom `GET /api/fixtures/conflicts`
  (`FixtureConflictsController`, ajoutée à `CustomRoutesOpenApiFactory`) — radar de conflits coach à la volée.
  Voir [`module-matchs.md`](module-matchs.md).
- **Module matchs palier A PR-1 (2026-07-06)** : ressources `/api/competitions` + `/api/fixtures`
  (API Platform, `CompetitionResource`/`FixtureResource`) et route custom `GET /api/league-match-windows`
  (`LeagueMatchWindowsController`, ajoutée à `CustomRoutesOpenApiFactory`). Voir
  [`module-matchs.md`](module-matchs.md).
- **Transition de saison (PR #68/69/70)** : `POST /api/seasons/{id}/transition` (custom, factory).
- **Calendriers (PR #53/#62/#63, rattrapage 2026-07-06)** : `GET /api/school-holidays` et
  `GET /api/public-holidays` (contrôleurs Symfony custom) ajoutés à
  `App\OpenApi\CustomRoutesOpenApiFactory` puis au snapshot — ils manquaient aux deux.
  ⚠ Le même gap subsiste pour la plupart des autres routes `#[Route]` custom — liste
  exhaustive + suivi dans `specs/evolution/roadmap.md` §9.
- **G4/G5 (ex `backend-gaps`, absorbé dans `specs/evolution/roadmap.md`)** : les routes Symfony custom `/api/register`, `/api/me`
  (AuthController) et `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}`
  (ManualEditController) sont documentées dans l'OpenAPI via
  `App\OpenApi\CustomRoutesOpenApiFactory` (décorateur de `api_platform.openapi.factory`).
  QW-5 ajoute `PATCH /api/me` (édition profil) + `POST /api/me/password`
  (changement de mot de passe connecté).
- `Team.level` (TeamLevel) exposé en lecture (`TeamResource`) et écrit (`TeamStateProcessor`).
- `/api/users` (collection) retiré — ressource User self-only (SEC-02) ; opérations Club/User `Post`/`Delete` retirées (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans `CustomRoutesOpenApiFactory`.

Last verified @ 2026-08-07 (JSON **régénéré** depuis le backend vivant)

Snapshot régénéré depuis le backend vivant le 2026-08-07 : `php bin/console api:openapi:export`.
En phase avec les ressources de `backend/src/ApiResource/` (chacune est représentée, aucun
path orphelin).
Changements récents :
- **SEC-16 audit (2026-08-07)** : le JWT applicatif passe en **cookie httpOnly** —
  `POST /api/login` rend désormais **204 sans corps** (le `200 {token}` était écrit en dur par
  le décorateur OpenAPI de lexik ; `CustomRoutesOpenApiFactory` le RÉÉCRIT, d'où sa priorité
  de décoration négative — sans elle la correction était silencieusement écrasée),
  `POST /api/register/verify` perd `token` de sa réponse, et +`POST /api/logout`
  (106 → 107 paths). Contrat complet : [`jwt-cookie.md`](../../docs/security/jwt-cookie.md).
- **FRT-04 (2026-08-07)** : +`GET /api/mercure/auth` (route contrôleur
  `MercureAuthController`, **déclarée dans `CustomRoutesOpenApiFactory`** — 105 → 106 paths) —
  jeton de souscription Mercure en cookie httpOnly + `topicTemplate` dans le corps. Contrat
  et périmètre de sécurité : `docs/security/mercure.md` §Frontend consumption.
- **P1-4 PR F2 (2026-08-03)** : regen vérifiée, **JSON inchangé** (105 paths) — l'analyze/import
  FBI sont des opérations multipart dont l'export ne détaille pas le corps de réponse ; les
  nouveaux champs (`suggestedTeamId`, `pouleError`, `pouleUnknownOpponents`, `completeness`,
  warning `POULE_MISMATCH`, finding `COMPETITION_INCOMPLETE` du diagnostic) sont documentés dans
  [`module-matchs.md`](module-matchs.md) §Appariement FFBB (même gap connu que P4-47).
- **P1-4 PR F1 (2026-08-03)** : appariement FFBB — +`GET /api/ffbb/engagements` et
  +`POST /api/ffbb/engagements/confirm` (routes contrôleur, **déclarées dans
  `CustomRoutesOpenApiFactory`** — 103 → 105 paths) ; `Competition` expose les réfs FFBB en lecture
  (`ffbbCompetitionId`/`ffbbPouleId`/`ffbbPouleName`/`ffbbCompetitionName`/`expectedMatchdays` —
  écrites par le seul confirm, jamais par le CRUD). Détail :
  [`module-matchs.md`](module-matchs.md) §Appariement FFBB.
- **P1-4 PR E2 (2026-08-03)** : regen vérifiée, **JSON inchangé** (103 paths) — les deux routes
  qui évoluent sont des contrôleurs custom dont l'export ne porte pas le schéma de réponse :
  `GET /api/fixtures/conflicts` gagne `severity`/`coachRole` + 4 types de findings,
  `GET /api/league-match-windows` gagne `resolvedTeamWindows`. Contrat de réponse documenté dans
  [`module-matchs.md`](module-matchs.md) §Diagnostic gradué (même gap connu que P4-47).
- **P1-4 PR E1 (2026-08-03)** : boucle manuelle — `Fixture.FixtureInput` gagne `placementSource`
  (écriture : `SOLVER` = « rendre au solveur », accepté SEULEMENT sur un PUT à placement inchangé
  et statut PLACED, 422 sinon ; refusé au POST ; `MANUAL` = écho no-op). Aucun path nouveau — la
  boucle réutilise le CRUD `Fixture` existant. Détail : [`module-matchs.md`](module-matchs.md)
  §Boucle manuelle.
- **P1-4 PR D (2026-08-03)** : solveur de placement — +`POST /api/fixtures/place` (route
  contrôleur `PlaceMatchesController`, **déclarée dans `CustomRoutesOpenApiFactory`** — le
  déclencheur « route custom ⇒ entrée factory + regen » est appliqué) ; `Fixture` expose
  `placementSource` (lecture — `MANUAL`/`SOLVER`/null). ⚠ La première regen l'avait perdu :
  **cache Symfony périmé dans le conteneur** (gotcha 17 backend/AGENTS.md) — `cache:clear`
  puis re-export. Détail : [`module-matchs.md`](module-matchs.md) §Solveur de placement.
- **P1-4 PR C (2026-08-03)** : couche préférences matchs — +`/api/team_match_habits` et
  +`/api/team_links` (CRUD API Platform, 5-fichiers) ; l'enum du radar gagne `TEAM_LINK_OVERLAP`
  et les vues fixture du radar portent `estimatedKickoff` (heure empruntée à l'habitude).
  Détail : [`module-matchs.md`](module-matchs.md) §Habitudes + passerelles.
- **P1-4 PR B (2026-08-03)** : couche capacité matchs — +`/api/venue_match_windows` et
  +`/api/venue_unavailabilities` (CRUD API Platform, 5-fichiers), +`/api/venue-unavailability-impact`
  (route contrôleur, **déclarée dans `CustomRoutesOpenApiFactory`** — le déclencheur « route custom ⇒
  entrée factory + regen » est appliqué) ; l'enum du radar `/api/fixtures/conflicts` gagne
  `VENUE_UNAVAILABLE`. Détail : [`module-matchs.md`](module-matchs.md) §Couche capacité.
- **P1-4 PR A (2026-08-02)** : l'import FBI passe au **format réel, une passe** —
  `POST /api/teams/{id}/fixtures/import` **disparaît** (l'opération quitte `TeamResource`),
  remplacé par `POST /api/fixtures/import/analyze` (dry-run multipart `file`) et
  `POST /api/fixtures/import` (multipart `file` + `mappings` JSON) sur `FixtureResource`.
  `Fixture` expose `fbiVenueLabel` (libellé Salle FBI, domicile ET extérieur) et
  `Competition` expose `fbiTeamLabel` (désambiguïsation deux-équipes-une-division).
  Détail : [`module-matchs.md`](module-matchs.md) §Import FBI réel.
- **P4-41 (2026-07-31)** : `Schedule.ScheduleInput` — `name` **quitte les champs requis**
  (`required` ne garde que `status`). ADR-0002 inv. 12 : le nom vit sur le PLAN, une version
  n'a pas d'identité produit ; un POST sans nom laisse le serveur nommer la version d'après
  son plan. Une chaîne **vide ou blanche** reste refusée en 422 (`NotBlank(allowNull: true,
  normalizer: 'trim')`) — absent ≠ vide — et `maxLength: 180` borne le champ, aligné sur la
  colonne. ⚠ Le JSON a dû être **régénéré une seconde fois** : la première passe précédait
  l'ajout de `Assert\Length`, donc le contrat publié annonçait un `name` non borné alors que
  le serveur le refusait déjà (revue #339 round 3 — le déclencheur « changement d'API ⇒
  régénérer » vaut pour CHAQUE modification, pas une fois par PR).
- ⚠ **Dérive antérieure ramassée au passage** : la régénération a aussi fait apparaître les
  `format: uuid` + `externalDocs` de `Reservation.ReservationInput` (`teamId`, `venueId`,
  `schedulePlanId`) et de `Schedule.ScheduleInput.schedulePlanId`. Ils viennent des
  `#[Assert\Uuid]` posés par **P4-22a le 2026-07-26**, dont la PR n'avait pas régénéré le
  snapshot. Signal : le déclencheur « changement d'API ⇒ régénérer » n'avait pas été appliqué.
- **Feature #10 doléances coachs (C1/C2/C3, 2026-07-25)** : +`/api/coach_wishes` (CRUD todo-list),
  +`/api/coach_wish_campaigns` (CRUD + actions `POST /{id}/send-links` et `POST /{id}/remind`,
  exportées car déclarées comme opérations de la ressource). ⚠ La page **publique**
  `GET|POST /api/coach-wishes/public/{token}` (controller pur, PUBLIC_ACCESS) reste **hors export**
  (non déclarée dans `CustomRoutesOpenApiFactory`) — même gap que les autres routes controller.
- **Console super-admin onglets + monitoring (2026-07-25)** : `/api/admin/health` étendu
  (append-only : `containers[]`, `externalDependencies[]`). Les endpoints journaux
  (`/api/admin/audit-log`, `/api/admin/messenger/failed`, `/api/admin/system-errors`) sont des
  controllers purs → **hors export** (gap `CustomRoutesOpenApiFactory`, tracké roadmap §9).
- **#8 — la période POSSÈDE sa grille de gymnases (2026-07-24, RUPTURE)** : nouvelle ressource
  **`VenuePeriodOverride`** (`/api/venue_period_overrides` + `/{id}`) — réglage **épars** par
  (plan de période, gymnase) : `mode` `DISABLED`/`BLANK`, **pas de ligne = hériter** (le défaut).
  Plus deux opérations d'action déclarées sur la ressource, donc exportées :
  `POST /api/venue_period_overrides/reset-grid` (« reprendre la grille du planning principal »)
  et `POST /api/venue_period_overrides/clear-grid` (« vider »), chacune atomique et destructive.
  ⚠ C'est ici que le modèle **additif** meurt : les `VenueTrainingSlot` d'une période sont une
  **copie** ancrée `schedulePlanId`, prise à la naissance du plan et **jamais unie** aux créneaux
  de saison (`ScheduleConstraintBuilder::buildForOverlay`). Un épinglage HARD devenu orphelin
  **bloque la génération** (422 nommant le gymnase et le jour, `OrphanPinGuard`).
- **P2-5 E1 — plans de période à la semaine (2026-07-18)** : aucun path touché (82).
  `CalendarEntry` gagne **`parentEntryId`** (lecture + écriture au POST seulement) — une
  semaine ENFANT d'une période mère, qui naît avec son propre plan (rail 1 entrée = 1 plan).
  Gardes serveur : type hérité, un seul niveau, anti-doublon par lundi, exclusivité
  bloc/semaines (422 au POST enfant si le plan mère a des versions ; 409 au POST
  /api/schedules sur le plan d'une mère découpée).
- **ADR-0002 lot C3 — les calques s'ancrent au PLAN (2026-07-17, RUPTURE)** : aucun path
  touché (82). `VenueTrainingSlot` et `Reservation` remplacent **`calendarEntryId` par
  **`schedulePlanId`** (lecture, écriture, filtre `?schedulePlanId=`). L'ancre reste
  **nullable** et sa nullité garde son sens : **NULL = la structure PARTAGÉE** (créneau
  saisonnier, réservation de base — inv. 6), non-NULL = propre à ce plan.
  ⚠️ **`Constraint` ne change PAS** : les contraintes **datées** restent sur la
  `CalendarEntry`. Elles décrivent le FAIT (« Barros fermé »), et le radar de conflits les
  lit par l'entrée pour déclencher le geste « ajuster » — les ancrer au plan les rendrait
  illisibles tant qu'aucun plan n'existe (décision fondateur, l'invariant 5 corrigé).
- **ADR-0002 lot C2 — les deux jumeaux s'ancrent au PLAN (2026-07-17, RUPTURE)** : aucun
  path touché (82). `TeamPeriodOverride` et `ConstraintPeriodOverride` remplacent
  **`calendarEntryId` par `schedulePlanId`** — en lecture, en écriture et en filtre de
  collection (`?schedulePlanId=`). Inv. 5 : les réglages de période s'accrochent au Plan,
  pas au déclencheur calendrier. Sans effet fonctionnel aujourd'hui (un plan par période),
  c'est le découpage hebdomadaire (types-de-planning E1) que cela débloque : 2 semaines ⇒
  2 plans ⇒ 2 jeux de réglages sur le même déclencheur.
- **ADR-0002 lot C1 — LE PLAN NAÎT DU GESTE (2026-07-17)** : **aucun path touché**
  (82 avant, 82 après). `teamSelectionInitialized` quitte **`CalendarEntry`** pour
  **`SchedulePlan`** : le garde de seed est une propriété de la RÉPONSE (le plan), pas
  du FAIT (l'événement calendrier) — inv. 5, les réglages de période s'accrochent au
  plan. Corollaire côté serveur : un plan CLOSURE/HOLIDAY naît désormais à la création
  de sa `CalendarEntry` (le geste « ajuster »), plus à la première génération, donc
  `GET /api/schedule_plans?calendarEntryId=…` répond dès qu'une période existe.
- **Rattrapage au passage** : cette régénération fait aussi entrer
  **`currentStructureHash`** sur `GET /api/me` — champ livré par la **PR #243**
  (« disable regenerate when structure is unchanged »), qui avait modifié le contrat
  sans régénérer le snapshot. Il n'appartient pas au lot C1 ; il est simplement rendu
  au contrat ici. *(Le compte annoncé plus haut était resté à 80 alors que le snapshot
  en portait déjà 82 : corrigé.)*
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
  ⚠ Dépassé sur deux points : le flag a migré sur **`SchedulePlan`** (lot C1, 2026-07-17,
  entrée ci-dessus) et le défaut de seed n'est plus « Fanion seul » mais **conscient du type
  de période** (E3, 2026-07-19) — reprise = Fanion + importantes (2 premiers rangs),
  fermeture = tout le club actif. Le mécanisme de garde, lui, est inchangé.
- **structure de période éditable (2026-07-12)** : `VenueTrainingSlot` gagne
  `calendarEntryId` (créneau scopé période, additif ; listing par défaut = saisonnier
  `IS NULL`, `?calendarEntryId=` liste ceux d'une période). Nouvelle ressource
  **`TeamPeriodOverride`** (`/api/team_period_overrides`) — surcharge sparse par
  (période, équipe) : `isActive` + `sessionsPerWeek?`. Le build overlay résout
  saisonnier→période (créneaux additifs, équipe off = 0 séance, séances override).
  ⚠ **Doublement dépassé** : l'ancre est passée à `schedulePlanId` (lot C2/C3, 2026-07-17)
  **et** le modèle **additif** a été abandonné (#8, 2026-07-24) — la période **possède** sa
  grille, copiée à la naissance du plan, jamais unie au saisonnier. Il n'y a plus de
  résolution « saisonnier→période » au build.
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
  exhaustive + suivi en roadmap sous **P4-47**.
- **G4/G5 (ex `backend-gaps`, livrés — cf. [`etat-des-lieux.md`](etat-des-lieux.md) §Réf historiques)** : les routes Symfony custom `/api/register`, `/api/me`
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

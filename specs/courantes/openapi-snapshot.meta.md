Last verified @ feat/d1-planning-versions 2026-07-10

Snapshot régénéré depuis le backend vivant : `php bin/console api:openapi:export`. **65 paths.**
Changements récents :
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

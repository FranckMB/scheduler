Last verified @ feat/qw5-profile-logo (off main) 2026-07-04

Snapshot régénéré depuis le backend vivant : `php bin/console api:openapi:export`.
Changements récents :
- **G4/G5 (ex `backend-gaps`, absorbé dans `specs/evolution/roadmap.md`)** : les routes Symfony custom `/api/register`, `/api/me`
  (AuthController) et `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}`
  (ManualEditController) sont désormais documentées dans l'OpenAPI via
  `App\OpenApi\CustomRoutesOpenApiFactory` (décorateur de `api_platform.openapi.factory`).
  49 paths. QW-5 ajoute `PATCH /api/me` (édition profil) + `POST /api/me/password`
  (changement de mot de passe connecté).
- `Team.level` (TeamLevel) exposé en lecture (`TeamResource`) et écrit (`TeamStateProcessor`).
- `/api/users` (collection) retiré — ressource User self-only (SEC-02) ; opérations Club/User `Post`/`Delete` retirées (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp.

Last verified @ docs/bck-f-gaps-openapi (off main `01a8f5d`) 2026-07-04

Snapshot régénéré depuis le backend vivant : `php bin/console api:openapi:export`.
Changements récents :
- **G4/G5 (backend-gaps)** : les routes Symfony custom `/api/register`, `/api/me`
  (AuthController) et `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}`
  (ManualEditController) sont désormais documentées dans l'OpenAPI via
  `App\OpenApi\CustomRoutesOpenApiFactory` (décorateur de `api_platform.openapi.factory`).
  48 paths (au lieu de 43). Les endpoints eux-mêmes sont inchangés.
- `Team.level` (TeamLevel) exposé en lecture (`TeamResource`) et écrit (`TeamStateProcessor`).
- `/api/users` (collection) retiré — ressource User self-only (SEC-02) ; opérations Club/User `Post`/`Delete` retirées (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp.

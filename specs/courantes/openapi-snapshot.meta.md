Last verified @ 4c4c575 2026-07-03

Snapshot régénéré depuis le backend vivant : `curl http://localhost:8080/api/docs.json`.
Changements récents :
- `Team.level` (TeamLevel) désormais exposé en lecture (`TeamResource`) et écrit (`TeamStateProcessor`) — niveau de jeu FFBB saisi au wizard.
- `/api/users` (collection) retiré — ressource User self-only (SEC-02) ; opérations Club/User `Post`/`Delete` retirées (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API (resource, controller custom, DTO exposé) et bumper ce stamp.

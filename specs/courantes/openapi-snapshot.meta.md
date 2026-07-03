Last verified @ c7d93c8 2026-07-03

Snapshot régénéré depuis le backend vivant : `curl http://localhost:8080/api/docs.json` (43 paths).
Changement vs édition précédente : `/api/users` (collection) retiré — la ressource User est self-only (SEC-02). Les opérations Club `Post`/`Delete` et User `Post`/`Delete` sont retirées au niveau opération (SEC-01/02).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API (resource, controller custom, DTO exposé) et bumper ce stamp.

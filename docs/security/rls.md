# PostgreSQL RLS — architecture effective

> Status: **ACTIVE** since migration `Version20260703120000` (audit SEC-03, série sécurité PR-C).
> Design détaillé : `backend/docs/RLS.md` · couche applicative : `backend/docs/TENANT.md`.

## Ce qui tourne

- **Connexion runtime = `app_user`** (`DATABASE_URL`) : NOSUPERUSER, DML only. **Toute table portant une colonne `club_id`** porte `ENABLE` + `FORCE ROW LEVEL SECURITY` et une policy `tenant_isolation FOR ALL TO app_user` (pas de compte figé ici — chaque nouvelle table tenant hérite du motif via la migration ; un décompte périmerait) :
  `USING/WITH CHECK (club_id = NULLIF(current_setting('app.club_id', true), '')::uuid)` — GUC absent → **0 ligne, pas d'erreur** (fail-closed).
- **GUC `app.club_id`** posé par `App\Service\TenantConnectionContext` via `SELECT set_config('app.club_id', ?, false)` (session-scoped, paramétré). Le `SET LOCAL` historique hors transaction était un no-op — ne pas y revenir.
- **Qui pose le GUC** :
  | Contexte | Où |
  |---|---|
  | Requête HTTP | `TenantFilterListener` (clear en début de requête, set après résolution du club) |
  | Register (anonyme) | `AuthController` dans les closures `wrapInTransaction`, dès que le club est connu ; `clear()` en `finally` |
  | Worker messenger | `GenerateScheduleHandler` / `ExportPdfHandler` : `setClubId($message->getClubId())` en 1re instruction, `clear()` en `finally` (le message porte le `clubId`) |
  | Fixtures | `BasketballInit` (tournent en admin de toute façon) |
- **Exception `club_user`** : `SELECT` ouvert (policy `club_user_read USING (true)`) — le tenant est **bootstrappé** depuis les memberships (listener, register, `/api/me`) avant qu'aucun club ne soit connu. Écritures tenant-scopées. Le code applicatif filtre toujours par `user_id`.

## Porte superadmin (supervision développeur)

`clubscheduler` (owner/superuser de la DB) **bypasse toutes les policies**. C'est voulu : supervision totale via
- `psql -U clubscheduler`,
- `php bin/console doctrine:query:sql --connection admin "…"`,
- le futur dashboard super-admin (P2) devra utiliser cette connexion.

`DATABASE_ADMIN_URL` alimente la connexion Doctrine `admin` — utilisée par les **migrations** (`doctrine_migrations.connection: admin`), `db-init-test`/`db-reset*` et `make fixtures` (le purge DELETE serait silencieusement partiel sous RLS). **Ne jamais pointer `DATABASE_URL` runtime dessus** — `RlsIsolationTest::testConnectionUserIsNotSuperuser` le garde.

## Caveats

- **pgbouncer transaction-pooling incompatible** avec le GUC session-scoped (fuite cross-tenant). À reconcevoir avant d'introduire un pooler (GUC transactionnel + transaction par requête).
- `doctrine:query:sql` sans `--connection admin` = app_user sans GUC → 0 ligne sur les tables tenant. C'est le comportement attendu, pas un bug.
- Tables **sans `club_id`** = hors RLS : `club`/`app_user` (protégés au niveau API, SEC-01/02) ; `team_tag_assignment` (jointure season-scoped, ses deux côtés `team`/`team_tag` sont RLS — résiduel assumé) ; les **tables de référence GLOBALES** enrichies par l'usage, sans donnée club (`public_holiday`, `school_holiday_period`, `league_match_window`) ; les **journaux d'idempotence** keyés sur un uuid globalement unique (`period_reminder_log`, `transition_reminder_log` — **SEC-09 : résiduel assumé**, aucune API de lecture, pas de `club_id`, écrits par le cron ; un `calendar_entry_id` non devinable ne fuit rien sans endpoint) ; l'infra Doctrine/Symfony (`sport`, `priority_tier`, `reset_password_request`, `messenger_*`, `doctrine_migration_versions`). Règle : une table est hors RLS **ssi** elle ne porte pas de `club_id` — cf. `RlsIsolationTest` (énumération dynamique) et `TenantOwnedInterfaceCompletenessTest`.
- Prod : remplacer les mots de passe `app_user_password` / dev par des secrets réels (env), et rejouer la migration sur la base cible (idempotente côté rôle/grants).

## Tests de non-régression (phase1)

`tests/Security/RlsIsolationTest.php` — SQL brut sur la connexion runtime : isolation SELECT/UPDATE/DELETE, WITH CHECK rejette un `club_id` ≠ GUC, fail-closed sans GUC, bootstrap `club_user`, garde anti-superuser.
`tests/MessageHandler/ExportPdfHandlerRlsTest.php` — un handler worker pose son propre GUC (GenerateScheduleHandler : même pattern, couvert e2e par `smoke-solver.sh`).
Les suites Tenant* (HTTP, JWT réel) et `AuthFlowTest` (register) tournent intégralement sous RLS.

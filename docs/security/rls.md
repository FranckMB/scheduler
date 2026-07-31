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
  | **Page publique doléances (anonyme, #10)** | `PublicCoachWishController` : `setClubId()` depuis le `club_id` porté par le `CoachWishToken`, dès qu'il est résolu ; `clear()` en `finally`. Route `PUBLIC_ACCESS` — **aucun JWT**, le token EST le porteur du tenant |

## Exceptions au modèle — les trois tables qui ne suivent pas le motif

Le motif normal est une policy unique `tenant_isolation FOR ALL`. Trois tables s'en écartent, **chacune pour une raison structurelle**. Toute nouvelle exception doit être justifiée ICI.

- **`club_user` — `SELECT` ouvert** (`club_user_read USING (true)`). Le tenant est **bootstrappé** depuis les memberships (listener, register, `/api/me`) avant qu'aucun club ne soit connu. Écritures tenant-scopées. Le code applicatif filtre toujours par `user_id`.
- **`coach_wish_token` — RLS HYBRIDE** (#10, `Version20260726100000`) : `SELECT` ouvert (`coach_wish_token_read USING (true)`), écritures tenant (`tenant_isolation_{insert,update,delete}`). Raison : la page publique `/api/coach-wishes/public/{token}` n'a **pas de JWT** — il faut lire le token pour découvrir le `club_id` qui posera le GUC. On ne peut pas scoper sur un club qu'on ne connaît pas encore. Ce qui tient l'isolation à la place : (1) le **filtre Doctrine `TenantFilter`**, qui s'applique à toute entité portant une colonne `club_id` — donc à celle-ci — sur tout chemin authentifié ; (2) aucun SQL brut ne touche cette table ; (3) la campagne qui expose les tokens est **management-only en lecture comme en écriture** (SEC-07) et sous RLS tenant complète ; (4) le token est un secret de 32 octets, sans endpoint de listing, avec un **404 byte-identique** pour l'inconnu comme le malformé.
- **`audit_log` — policies scindées** : `SELECT` tenant, `INSERT WITH CHECK (club_id IS NULL OR <tenant>)` (les actions hors club sont journalisables), et **aucune policy `UPDATE` ni `DELETE`**. L'immuabilité du journal est tenue par la **base**, pas par le code. La purge à 12 mois passe par la connexion `admin`.

> **La PORTÉE des policies est gardée** (SEC-12, `RlsIsolationTest::testEveryPolicyOnClubIdTablesIsTenantScoped`, phase1). En plus de `rls_enabled`/`rls_forced`/`policies > 0`, chaque policy **permissive** des tables portant `club_id` est comparée par **égalité stricte** au prédicat canonique lu à l'exécution sur `team_tag.tenant_isolation` (pas de chaîne en dur — PostgreSQL reformate les prédicats). Un `USING (true)` posé par erreur (le chemin probable : copier-coller de la migration `coach_wish_token`) **fait rougir le gate bloquant** en nommant `table.policy (cmd)`. Les seuls écarts tolérés sont **allowlistés et justifiés** (`club_user`/`coach_wish_token` SELECT ouvert ; `audit_log` INSERT `club_id IS NULL OR …`), et l'allowlist est **bidirectionnelle** : une dérogation devenue inutile fait elle aussi rougir (« périmée — retirer l'entrée »).
>
> ⚠️ **Ce qui est livré, c'est le GARDIENNAGE, pas un durcissement.** Les policies n'ont pas changé : sur ces trois tables l'isolation repose toujours sur **une** couche (le filtre Doctrine) au lieu de deux — une requête native ou un `createQuery` filtre désactivé y fuiterait encore. La dette « scoper le SELECT quand le GUC est posé » reste entière (roadmap, SEC-12). Ce que le test n'assure pas : la justesse *sémantique* du canon (portée par les tests comportementaux voisins) ni un prédicat équivalent écrit autrement (échoue volontairement — fail-noisy).

## Porte superadmin (supervision développeur)

`clubscheduler` (owner/superuser de la DB) **bypasse toutes les policies**. C'est voulu : supervision totale via
- `psql -U clubscheduler`,
- `php bin/console dbal:run-sql --connection admin "…"`,
- le futur dashboard super-admin (P2) devra utiliser cette connexion.

`DATABASE_ADMIN_URL` alimente la connexion Doctrine `admin` — utilisée par les **migrations** (`doctrine_migrations.connection: admin`, donc aussi `make migration-migrate` et `make bootstrap`), `db-init`/`db-init-test`/`db-reset*` et `make fixtures` (le purge DELETE serait silencieusement partiel sous RLS). **Ne jamais pointer `DATABASE_URL` runtime dessus** — `RlsIsolationTest::testConnectionUserIsNotSuperuser` le garde.

## Caveats

- **pgbouncer transaction-pooling incompatible** avec le GUC session-scoped (fuite cross-tenant). À reconcevoir avant d'introduire un pooler (GUC transactionnel + transaction par requête).
- `dbal:run-sql` sans `--connection admin` = app_user sans GUC → 0 ligne sur les tables tenant. C'est le comportement attendu, pas un bug.
- Tables **sans `club_id`** = hors RLS : `club`/`app_user` (protégés au niveau API, SEC-01/02) ; `team_tag_assignment` (jointure season-scoped, ses deux côtés `team`/`team_tag` sont RLS — résiduel assumé) ; les **tables de référence GLOBALES** enrichies par l'usage, sans donnée club (`public_holiday`, `school_holiday_period`, `league_match_window`) ; les **journaux d'idempotence** keyés sur un uuid globalement unique (`period_reminder_log`, `transition_reminder_log` — **SEC-09 : résiduel assumé**, aucune API de lecture, pas de `club_id`, écrits par le cron ; un `calendar_entry_id` non devinable ne fuit rien sans endpoint) ; le **catalogue de facturation** (`subscription_plan`, global) ; les tables **SA0/SA3** hors tenant (`super_admin`, `admin_audit_log`, `admin_job_run`, `admin_alert_state` — identité et exploitation globales, jamais rattachées à un club) ; `email_verification_token` (seul le sha256 du token est stocké, lié au `User`) ; `constraint_conflict` (porte un `schedule_id`, donc de la donnée tenant, **sans `club_id`** — résiduel assumé au même titre que `team_tag_assignment` : son parent `schedule` est sous RLS et il part par cascade, cf. `SeasonDataPurger`/`OverlayManager`) ; l'infra Doctrine/Symfony (`sport`, `priority_tier`, `reset_password_request`, `messenger_*`, `doctrine_migration_versions`). Règle : une table est hors RLS **ssi** elle ne porte pas de `club_id` — cf. `RlsIsolationTest` (énumération dynamique) et `TenantOwnedInterfaceCompletenessTest`.
- Prod : remplacer les mots de passe `app_user_password` / dev par des secrets réels (env), et rejouer la migration sur la base cible (idempotente côté rôle/grants).

## Tests de non-régression (phase1)

`tests/Security/RlsIsolationTest.php` — SQL brut sur la connexion runtime : isolation SELECT/UPDATE/DELETE, WITH CHECK rejette un `club_id` ≠ GUC, fail-closed sans GUC, bootstrap `club_user`, garde anti-superuser.
`tests/MessageHandler/ExportPdfHandlerRlsTest.php` — un handler worker pose son propre GUC (GenerateScheduleHandler : même pattern, couvert e2e par `smoke-solver.sh`).
Les suites Tenant* (HTTP, JWT réel) et `AuthFlowTest` (register) tournent intégralement sous RLS.

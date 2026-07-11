# ClubScheduler — PostgreSQL Row-Level Security (RLS)

> ✅ **STATUS: ACTIVE** since migration `Version20260703120000` (SEC-03 fixed). The migration — not the initdb scripts — is the source of truth for policies and grants: **every table carrying a `club_id` column** is under `FORCE ROW LEVEL SECURITY` with a `tenant_isolation` policy `TO app_user` (no hard count here — new tenant tables inherit the pattern via the migration helper; the count would rot). `club_user` carries the special open-SELECT bootstrap policy. Runtime connects as `app_user`; the GUC is set via `TenantConnectionContext` (`set_config`, session-scoped). **This file = operator how-to (env, roles, troubleshooting); the effective architecture (who sets the GUC, the superadmin door) is the canonical `docs/security/rls.md` — keep the two in sync, don't duplicate.** The `01/02/03-*.sql` initdb scripts remain for fresh volumes only.

## Overview

ClubScheduler is designed to use **PostgreSQL Row-Level Security (RLS)** to enforce **tenant isolation** at the database layer. Every business table that belongs to a club contains a `club_id` column. RLS policies ensure that the application user (`app_user`) can only see and manipulate rows whose `club_id` matches the tenant context set for the current database session.

## Database Users

| User | Purpose | DDL Rights | RLS Bypass |
|------|---------|------------|------------|
| `app_user` | Symfony runtime (API requests) | **None** | **No** — policies apply |
| `migration_user` | legacy (created by init SQL, **not used by any configured connection**) | `GRANT ALL` on schema/tables/sequences (DML + `CREATE` on schema) — **no `ALTER`/`DROP` on existing tables** (not grantable in PostgreSQL; requires ownership, held by `clubscheduler`) | **No** — `NOSUPERUSER`, no `BYPASSRLS`, no policy targets it → default-deny on tenant tables under `FORCE` |
| `clubscheduler` | **migrations / ops / superadmin door** (Doctrine `admin` connection, `DATABASE_ADMIN_URL`) | all (owner/superuser) | **Yes** — superuser bypasses every policy (see CLAUDE.md §6) |

> **Security rule:** `app_user` is **not** a `SUPERUSER` and does **not** hold `CREATEDB` or `CREATEROLE`.

## How Tenant Isolation Works

1. **Set context** — Before executing tenant queries, the application (`TenantConnectionContext`) executes directly:
   ```sql
   SELECT set_config('app.club_id', '550e8400-e29b-41d4-a716-446655440000', false);
   ```
   This stores the current club ID in the session variable `app.club_id`. Note: the SQL helper function `app_security.set_club_id(...)` exists in the initdb scripts but is called by **no application code** — the app always issues `set_config` itself (the function remains a convenience for manual `psql` sessions).

2. **Policy enforcement** — Every RLS-protected table has a policy:
   ```sql
   CREATE POLICY tenant_isolation ON public.event
       FOR ALL
       USING (club_id = current_setting('app.club_id')::UUID)
       WITH CHECK (club_id = current_setting('app.club_id')::UUID);
   ```
   - `USING` filters rows on `SELECT`, `UPDATE`, `DELETE`.
   - `WITH CHECK` validates rows on `INSERT`, `UPDATE`.

3. **Force RLS** — `ALTER TABLE ... FORCE ROW LEVEL SECURITY` ensures the policy applies even to the table owner, preventing accidental data leakage if the connection string is misused.

## Enabling RLS on a New Table

There is **no manual post-deploy step**: the Doctrine migration that creates a new `club_id` table also creates its RLS policy — the migration is the source of truth. A migration adding a tenant table must include:

```sql
-- 1. Enable RLS
ALTER TABLE public.<table_name> ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.<table_name> FORCE ROW LEVEL SECURITY;

-- 2. Create tenant isolation policy
CREATE POLICY tenant_isolation ON public.<table_name>
    FOR ALL
    USING (club_id = current_setting('app.club_id')::UUID)
    WITH CHECK (club_id = current_setting('app.club_id')::UUID);
```

`RlsIsolationTest` (blocking, `--group phase1`) guards that every `club_id` table is covered.

> **Do NOT enable RLS on tables without `club_id`** (e.g. `doctrine_migration_versions`, `messenger_messages`, `sessions`).

## Batch-Enable RLS on All Existing Tables

A helper function is provided in `01-rls.sql`:

```sql
SELECT app_security.enable_rls_for_existing_clubscheduler_tables();
```

This loops over every table in the `public` schema that has a `club_id` column and enables RLS. It **does not** create policies — you must add those separately (see `03-rls-template.sql`).

## Symfony Integration

### 1. Connection Configuration

Use `app_user` for the runtime `DATABASE_URL`:

```env
# .env.local (runtime)
DATABASE_URL="postgresql://app_user:app_user_password@postgres:5432/clubscheduler?serverVersion=16&charset=utf8"
```

Migrations and ops run on the **`admin` Doctrine connection** (`clubscheduler`, superuser — the only RLS bypass):

```env
# .env (migrations/ops — doctrine.yaml `admin` connection)
DATABASE_ADMIN_URL="postgresql://clubscheduler:...@postgres:5432/clubscheduler?serverVersion=16&charset=utf8"
```

⚠ Do **not** use `migration_user` for migrations: it has no RLS bypass (default-deny under `FORCE`) and is not wired to any connection — it is a legacy artifact of the init SQL.

### 2. Setting the Tenant Context

This mechanism **exists and is active**: on every request, `TenantFilterListener` (kernel listener, priority 7 — after the firewall) resolves the club from the authenticated user (or validated header) and hands it to `TenantConnectionContext`, which sets the GUC on the runtime connection:

```php
// src/Service/TenantConnectionContext.php (actual code)
$connection->executeStatement(
    "SELECT set_config('app.club_id', ?, false)",
    [$clubId]
);
```

Messenger workers do the same from the message's `clubId` before touching tenant data. No `app_security.set_club_id(...)` call is involved anywhere in the application.

### 3. Testing RLS

You can verify isolation directly in `psql`:

```sql
-- Connect as app_user
\c clubscheduler app_user

-- Without context: should return 0 rows on an RLS-protected table
SELECT * FROM public.event;

-- Set context
SELECT app_security.set_club_id('550e8400-e29b-41d4-a716-446655440000'::uuid);

-- Now only rows for this club are visible
SELECT * FROM public.event;
```

## Files Reference

| File | Purpose |
|------|---------|
| `docker/postgres/init/01-rls.sql` | Helper function to batch-enable RLS on existing tables |
| `docker/postgres/init/02-users.sql` | Creates `app_user` and `migration_user` with correct grants |
| `docker/postgres/init/03-rls-template.sql` | Copy-paste templates for `ALTER TABLE ... ENABLE RLS` and `CREATE POLICY` |

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `0 rows returned` for a table that has data | `app.club_id` not set | In `psql`: run `SELECT set_config('app.club_id', '<uuid>', false)` (or the `app_security.set_club_id(...)` helper). In the app: the context is set automatically by `TenantConnectionContext` |
| `permission denied for table` | `app_user` lacks `GRANT` | Re-run `02-users.sql` or check `GRANT` statements |
| Policy not enforced for table owner | `FORCE ROW LEVEL SECURITY` missing | Run `ALTER TABLE ... FORCE ROW LEVEL SECURITY` |
| Migration fails with RLS error | Migration runs as `app_user` (or `migration_user` — no bypass either) | Run migrations on the `admin` connection (`clubscheduler`, superuser) |

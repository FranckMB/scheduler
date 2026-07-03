# ClubScheduler — PostgreSQL Row-Level Security (RLS)

> ✅ **STATUS: ACTIVE** since migration `Version20260703120000` (SEC-03 fixed). The migration — not the initdb scripts — is the source of truth for policies and grants: 14 `club_id` tables under `FORCE ROW LEVEL SECURITY` with `tenant_isolation` policies `TO app_user`, `club_user` with an open-SELECT bootstrap policy. Runtime connects as `app_user`; the GUC is set via `TenantConnectionContext` (`set_config`, session-scoped). Effective architecture: `docs/security/rls.md` (repo root). The `01/02/03-*.sql` initdb scripts remain for fresh volumes only.

## Overview

ClubScheduler is designed to use **PostgreSQL Row-Level Security (RLS)** to enforce **tenant isolation** at the database layer. Every business table that belongs to a club contains a `club_id` column. RLS policies ensure that the application user (`app_user`) can only see and manipulate rows whose `club_id` matches the tenant context set for the current database session.

## Database Users

| User | Purpose | DDL Rights | RLS Bypass |
|------|---------|------------|------------|
| `app_user` | Symfony runtime (API requests) | **None** | **No** — policies apply |
| `migration_user` | Doctrine migrations / deploy | `CREATE`, `ALTER`, `DROP` | **Yes** — `BYPASSRLS` is **not** granted, but migrations run before RLS is enabled on new tables |

> **Security rule:** `app_user` is **not** a `SUPERUSER` and does **not** hold `CREATEDB` or `CREATEROLE`.

## How Tenant Isolation Works

1. **Set context** — Before executing a query, the application calls:
   ```sql
   SELECT app_security.set_club_id('550e8400-e29b-41d4-a716-446655440000'::uuid);
   ```
   This stores the current club ID in the session variable `app.club_id`.

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

After a Doctrine migration creates a new table that contains `club_id`, run the following **manually** (or via a post-deploy script):

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

Use `migration_user` only during migrations:

```env
# .env.migration (deploy only)
DATABASE_URL="postgresql://migration_user:migration_user_password@postgres:5432/clubscheduler?serverVersion=16&charset=utf8"
```

### 2. Setting the Tenant Context

Create a Doctrine middleware or event subscriber that executes `app_security.set_club_id(...)` at the start of every request, using the club resolved from the authenticated user or JWT token:

```php
// src/Doctrine/TenantContextMiddleware.php (example)
$connection->executeStatement(
    "SELECT app_security.set_club_id(?::uuid)",
    [$currentClubId]
);
```

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
| `0 rows returned` for a table that has data | `app.club_id` not set | Call `app_security.set_club_id(...)` before querying |
| `permission denied for table` | `app_user` lacks `GRANT` | Re-run `02-users.sql` or check `GRANT` statements |
| Policy not enforced for table owner | `FORCE ROW LEVEL SECURITY` missing | Run `ALTER TABLE ... FORCE ROW LEVEL SECURITY` |
| Migration fails with RLS error | Migration runs as `app_user` | Use `migration_user` for Doctrine migrations |

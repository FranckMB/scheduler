#!/bin/bash
# ClubScheduler database users — runs after 01-rls.sql (app_security exists).
#
# Shell wrapper (was 02-users.sql) so the passwords come from the environment.
# FAIL-CLOSED: no fallback defaults here — a cluster initialised without these
# vars would permanently carry the publicly-committed dev passwords (initdb.d
# never re-runs). The DEV compose passes the historical dev values explicitly;
# prod compose passes them from .env.prod. Any other path (bare `docker run`,
# a compose refactor that drops the mappings) aborts the init instead.
set -euo pipefail

: "${APP_USER_PASSWORD:?02-users.sh: APP_USER_PASSWORD must be set (dev compose provides the dev default; prod .env.prod provides the real one)}"
: "${MIGRATION_USER_PASSWORD:?02-users.sh: MIGRATION_USER_PASSWORD must be set}"

psql -v ON_ERROR_STOP=1 \
     -v app_user_password="$APP_USER_PASSWORD" \
     -v migration_user_password="$MIGRATION_USER_PASSWORD" \
     --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'EOSQL'
-- =============================================================================
-- 1. APPLICATION USER (app_user)
-- =============================================================================
-- Used by the Symfony backend at runtime.
-- NO DDL privileges, NO SUPERUSER.
-- Can only read/write data in the public schema.

CREATE USER app_user WITH PASSWORD :'app_user_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- Grant usage on the public schema
GRANT USAGE ON SCHEMA public TO app_user;

-- Grant DML on all existing tables in public
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_user;

-- Grant DML on all future tables in public (via default privileges)
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_user;

-- Grant usage on all sequences (needed for auto-increment IDs)
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO app_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE ON SEQUENCES TO app_user;

-- =============================================================================
-- 2. MIGRATION USER (migration_user)
-- =============================================================================
-- Used by Doctrine Migrations / Symfony console only during deploy/migrate.
-- Needs DDL privileges to create/alter/drop tables, but NOT SUPERUSER.

CREATE USER migration_user WITH PASSWORD :'migration_user_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;

-- Full access to public schema for DDL operations
GRANT ALL PRIVILEGES ON SCHEMA public TO migration_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO migration_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO migration_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO migration_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO migration_user;

-- Allow the migration user to execute the RLS helper function
GRANT EXECUTE ON FUNCTION app_security.enable_rls_for_existing_clubscheduler_tables() TO migration_user;

-- =============================================================================
-- 3. RLS CONTEXT SETTER HELPER (executed by app_user or migration_user)
-- =============================================================================
-- This function safely sets the tenant context variable used by RLS policies.
-- It is owned by the bootstrap superuser but executable by app_user.

CREATE OR REPLACE FUNCTION app_security.set_club_id(club_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    PERFORM set_config('app.club_id', club_id::text, true);
END;
$$;

COMMENT ON FUNCTION app_security.set_club_id(uuid) IS
    'Sets the RLS tenant context variable. Must be called before each transaction that relies on tenant_isolation policies.';

GRANT EXECUTE ON FUNCTION app_security.set_club_id(uuid) TO app_user;
GRANT EXECUTE ON FUNCTION app_security.set_club_id(uuid) TO migration_user;
EOSQL

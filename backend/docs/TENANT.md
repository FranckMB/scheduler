# ClubScheduler — Tenant Isolation Architecture

## Overview

ClubScheduler is a **multi-tenant** application where every business entity belongs to exactly one club. Tenant isolation is enforced at **two layers**:

1. **Application layer** — A Doctrine SQL filter (`TenantFilter`) transparently appends `club_id = ?` to every DQL/SQL query.
2. **Database layer** — PostgreSQL Row-Level Security (RLS) policies ensure that the `app_user` connection can never see rows belonging to another club, even if the application filter is bypassed.

This defence-in-depth strategy guarantees that a bug or misconfiguration in one layer cannot lead to data leakage across clubs.

## Components

### 1. `TenantFilter` (Doctrine SQL Filter)

**File:** `backend/src/Doctrine/Filter/TenantFilter.php`

- Extends `Doctrine\ORM\Query\Filter\SQLFilter`.
- Dynamically detects whether an entity has a `club_id` column by inspecting `ClassMetadata`.
- When the filter is enabled, every query on entities that own `club_id` receives an extra `AND club_id = '<uuid>'` predicate.
- The filter is **disabled by default** and activated per-request by `TenantFilterListener`.

### 2. `TenantFilterListener` (HTTP Event Subscriber)

**File:** `backend/src/EventListener/TenantFilterListener.php`

- Subscribes to `kernel.request` at **priority 7 — AFTER the security firewall** (priority 8), so the JWT user is authenticated by the time the tenant is resolved.
- On each **main HTTP request**:
  1. Resolves the current `club_id`: `_club_id` route attribute → `X-Club-Id` header → **the authenticated JWT user's single active `ClubUser` membership** (the frontend sends no header — the club is derived from the token). The active season is resolved the same way (`_season_id` → `X-Season-Id` → the club's active `Season`).
  2. If a club came from a header/attribute and a user is present, validates the membership (403 if the user is not an active member — blocks a spoofed `X-Club-Id`).
  3. Enables the `tenant_filter` SQL filter and sets its `club_id` parameter.
  4. Executes `SET LOCAL app.club_id = '<uuid>'` on the PostgreSQL connection so that RLS policies are satisfied.
- **Safety rule:** if no `club_id` can be resolved, the filter is **not** enabled and `SET LOCAL` is **not** executed. This prevents accidental cross-tenant queries.

> **Ordering is load-bearing (fixed in tranche 3).** When this listener ran *before* the firewall (priority 8, same as the firewall — order undefined), a header-less request had no authenticated user yet → no club → the SQL filter stayed disabled and no RLS scope was set → **collection reads leaked every club's data**. It only surfaced without an `X-Club-Id` header, i.e. exactly the real frontend flow (`TenantFromJwtTest` used `loginUser`, which pre-injects the token and hid the ordering). Guarded now by `TenantJwtIsolationTest` (a real Bearer JWT) and `OnboardingFlowTest`.

### 3. CLI Context

Console commands do **not** trigger `kernel.request`. Therefore:

- The `tenant_filter` is **not** enabled automatically.
- `SET LOCAL app.club_id` is **never** executed without an HTTP context.
- CLI scripts that need tenant isolation must implement their own mechanism (e.g., explicit `--club-id` option) or run as `migration_user` (which bypasses RLS for maintenance tasks).

## Registration

### Doctrine Filter

`backend/config/packages/doctrine.yaml`:

```yaml
doctrine:
    orm:
        filters:
            tenant_filter:
                class: App\Doctrine\Filter\TenantFilter
                enabled: false
```

### Event Subscriber

`backend/config/services.yaml`:

```yaml
services:
    App\EventListener\TenantFilterListener:
        tags:
            - { name: kernel.event_subscriber }
```

> With `autoconfigure: true` the tag is redundant, but explicit registration documents the intent and survives future configuration changes.

## Behaviour Matrix

| Context | Filter Enabled | SET LOCAL | RLS Active | Notes |
|---------|---------------|-----------|------------|-------|
| HTTP (authenticated) | Yes | Yes | Yes | Normal API request |
| HTTP (no club_id) | No | No | Yes | Returns 0 rows on RLS tables |
| CLI | No | No | Yes | Use `--club-id` or `migration_user` |
| Tests (HTTP kernel) | Yes* | Yes* | Yes* | Same as HTTP if `X-Club-Id` header is set |

\* Phase 1 stub: set the `X-Club-Id` request header to inject a tenant context in integration tests.

## Phase 1 Stub → Phase 2 Migration

In Phase 1 there is no real JWT authentication. The listener resolves `club_id` from:

1. Request attribute `_club_id` (where the JWT authenticator will store it later).
2. Fallback: `X-Club-Id` HTTP header for manual testing.

**Phase 2 upgrade:** replace `resolveClubId()` with extraction from the Lexik JWT token or Symfony security token.

## Security Considerations

- **No bypass by design:** the filter is only enabled when a valid `club_id` is present. If resolution fails, the filter stays disabled and RLS still blocks access (returning zero rows).
- **Force RLS:** every table that contains `club_id` must have `ALTER TABLE ... FORCE ROW LEVEL SECURITY`. See `backend/docs/RLS.md`.
- **Connection separation:** runtime API uses `app_user` (RLS-enforced). Migrations and DDL use `migration_user`.

## See Also

- `backend/docs/RLS.md` — PostgreSQL RLS setup and troubleshooting
- `docker/postgres/init/02-users.sql` — `app_user` / `migration_user` creation

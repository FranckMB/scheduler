# ClubScheduler — Tenant Isolation Architecture

## Overview

ClubScheduler is a **multi-tenant** application where every business entity belongs to exactly one club. Tenant isolation design has **two layers** — but only one is active today:

1. **Application layer (ACTIVE)** — A Doctrine SQL filter (`TenantFilter`) transparently appends `club_id = ?` to every DQL/SQL query on entities that own a `club_id` column. **This is the effective tenant barrier.**
2. **Database layer (PREPARED, NOT ACTIVE — audit 2026-07-03, SEC-03)** — PostgreSQL Row-Level Security. The init scripts only ship a helper (`docker/postgres/init/01-rls.sql`, never invoked) and a commented template (`03-rls-template.sql`); **no `CREATE POLICY` exists in any migration**, and the runtime connects as the `clubscheduler` user, not the restricted `app_user`. Additionally the listener's `SET LOCAL app.club_id` runs outside a transaction, which PostgreSQL treats as a no-op. Until policies are created and the connection user is switched, **do not count on RLS as a safety net.**

⚠ Entities **without** a `club_id` column (`Club`, `User`) are NOT covered by the Doctrine filter — the tenant barrier does not apply to them. Their access control is enforced explicitly in their API Platform state provider/processor (SEC-01/SEC-02, fixed):
- **Club** (`ClubStateProvider` / `ClubStateProcessor`): the collection is bounded to the caller's active `ClubUser` memberships (resolved via `ClubUserRepository::findActiveClubIds`, a raw query so the tenant filter does not narrow a multi-club member to one club); item read requires an active membership (else 404); `Put` requires an active **management role** — `owner` or `admin` (404 if no membership, 403 if member but `editor`/`viewer`). No bare `Post`/`Delete` (a club is created via `/api/register`; deletion needs a dedicated cascade flow, not yet exposed).
- **User** (`UserStateProvider` / `UserStateProcessor`): self-only — `Get`/`Put` restricted to the caller's own id (else 404); no `GetCollection` (email enumeration), no bare `Post`, and **no `Delete`** (would orphan `ClubUser` rows — no FK cascade — and could lock a club out; account erasure is a future GDPR flow).
- **Import** (`ImportController`, `POST /clubs/{id}/import-teams`): requires an active management-role membership in the club named in the path (SEC-04) — the listener validates the header/JWT club, not the path `{id}`. No active membership → **404** (same no-existence-oracle semantics as the Club `Put` path); member but not a management role → 403.

The shared membership lookups (`findActiveMembership`, `findActiveClubIds`, `isManagementRole`) live in `ClubUserRepository` — one source of truth for the Club provider, processor, and import controller.

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

| Context | Filter Enabled | SET LOCAL issued | RLS enforced | Notes |
|---------|---------------|-----------|------------|-------|
| HTTP (authenticated) | Yes | Yes (but no-op outside a transaction) | **No — no policies exist** | Doctrine filter is the barrier |
| HTTP (no club_id resolvable) | No | No | No | Filter stays off; unsafe reads are prevented by resolution failing, not by RLS |
| CLI / messenger worker | No | No | No | Handlers must filter by `clubId` explicitly (e.g. `GenerateScheduleHandler`) |
| Tests (HTTP kernel) | Yes | Yes (inside dama transaction — appears to work) | No | dama wraps tests in a transaction, masking the prod no-op |

## Security Considerations

- **The Doctrine filter is currently the single effective barrier** for `club_id` entities. Treat any change to `TenantFilter`/`TenantFilterListener` as security-critical and keep `TenantIsolationTest`/`TenantJwtIsolationTest` green.
- **To actually activate RLS** (target state, see `backend/docs/RLS.md`): create policies + `FORCE ROW LEVEL SECURITY` per table (migration), switch the runtime `DATABASE_URL` to `app_user`, and issue the tenant GUC transactionally (`SET LOCAL` inside a transaction, or `set_config(..., true)`).
- **Connection separation (target, not wired):** `docker/postgres/init/02-users.sql` creates `app_user` / `migration_user`, but the runtime currently connects as `clubscheduler`.

## See Also

- `backend/docs/RLS.md` — PostgreSQL RLS setup and troubleshooting
- `docker/postgres/init/02-users.sql` — `app_user` / `migration_user` creation

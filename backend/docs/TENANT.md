# ClubScheduler — Tenant Isolation Architecture

## Overview

ClubScheduler is a **multi-tenant** application where every business entity belongs to exactly one club. Tenant isolation has **two layers, both active today**:

1. **Application layer (ACTIVE)** — A Doctrine SQL filter (`TenantFilter`) transparently appends `club_id = ?` to every DQL/SQL query on entities that own a `club_id` column. **This is the effective tenant barrier.**
2. **Database layer (ACTIVE since `Version20260703120000` — SEC-03 fixed)** — PostgreSQL Row-Level Security. Every `club_id` table carries `FORCE ROW LEVEL SECURITY` + a `tenant_isolation` policy keyed on the `app.club_id` GUC, the runtime connects as the restricted `app_user`, and the GUC is set via `TenantConnectionContext` (`set_config`, session-scoped — the old out-of-transaction `SET LOCAL` was a no-op). Workers set their own GUC from the message's `clubId`. See `docs/security/rls.md` for the full architecture, the `club_user` bootstrap exception and the `clubscheduler` superadmin door.

Tenant entities also carry the explicit `App\Entity\TenantOwnedInterface` marker (BCK-03): the generic State providers/processors gate item reads and `Put`/`Delete` by `instanceof TenantOwnedInterface` (replacing `method_exists('getClubId')`), a type-safe app-layer check layered on the column-based filter + RLS. `TenantOwnedInterfaceCompletenessTest` (phase1) keeps the marker set identical to the `club_id`-column set, so no tenant entity can slip past the app-layer guards.

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
  1. Resolves the current `club_id`: `_club_id` route attribute → `X-Club-Id` header → **the authenticated JWT user's single active `ClubUser` membership** (the frontend sends no header — the club is derived from the token).
  2. If a club came from a header/attribute and a user is present, validates the membership (403 if the user is not an active member — blocks a spoofed `X-Club-Id`).
  3. Enables the `tenant_filter` SQL filter and sets its `club_id` parameter.
  4. Executes `SET LOCAL app.club_id = '<uuid>'` on the PostgreSQL connection so that RLS policies are satisfied.
  5. Resolves the **season** (after the GUC — the season table is RLS-protected): explicit `_season_id` attribute / `X-Season-Id` header, **validated against the club** (unknown, malformed or foreign-club id → 403, never a silent fallback) → else the **calendar-derived current season** (`SeasonResolver`, July-15 pivot on `startDate` — `Season.status` is display metadata, never read for resolution). Sets `_season_id` + `_season_readonly` attributes and enables the **`season_filter`** SQL filter (clone of the tenant filter keyed on `season_id`) so every season-scoped read stays inside the selected season.
- **Safety rule:** if no `club_id` can be resolved, the filters are **not** enabled and `SET LOCAL` is **not** executed. This prevents accidental cross-tenant queries. Same for the season: no resolvable season → `season_filter` stays off (mono-season behaviour unchanged).

### Season scoping (multi-season, transition P1)

- **Boundary type:** season isolation is an intra-club **correctness** boundary (a rolled-over club must never mix N-1/N/N+1 rows) — the **security** boundary stays the club (`tenant_filter` + RLS, which are club-only).
- `SeasonFilter` (`backend/src/Doctrine/Filter/SeasonFilter.php`) is column-based/fail-secure like the tenant filter: any entity with a `season_id` column is scoped (bonus: covers `TeamTagAssignment`, which has no `club_id`); entities without it (Season, Club, ClubUser, TeamTag, SportCategory…) are untouched, so seasons stay listable for the selector.
- `SeasonResolver` (`backend/src/Service/SeasonResolver.php`): `seasonYear(d) = Y if d ≥ Y-07-15 else Y-1`; current = greatest `startDate` among non-future season-years; single-season club → current unconditionally; `isReadonly` = season-year strictly before the current one.
- **Read-only enforcement** (archived seasons, transition PR-3): the listener stamps `_season_readonly` (true for N-1 and older). `SeasonAccessGuard` turns it into a **409** at two choke points — `AbstractStateProcessor` for every API Platform mutation on a season-scoped entity, and `SeasonReadonlyGuardListener` (kernel.controller) for the custom write controllers marked `SeasonScopedWriteInterface`. Reads stay open; current + draft seasons stay writable.
- **Retention** (`app:seasons:purge`, manual): keeps current + N-1 + futures, deletes N-2 and older (Season row included) via `SeasonDataPurger` (the canonical delete-order list, shared with `ResetSeasonController`).
- Guarded by `tests/Security/SeasonIsolationTest.php` + `tests/Security/SeasonReadonlyTest.php` (blocking, phase1), `tests/Unit/Service/SeasonResolverTest.php`, `tests/Integration/Command/PurgeSeasonsCommandTest.php`.

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

| Context | Filter Enabled | GUC set | RLS enforced | Notes |
|---------|---------------|-----------|------------|-------|
| HTTP (authenticated) | Yes | Yes (`TenantConnectionContext`, session-scoped) | **Yes** | Doctrine filter + RLS, defence in depth |
| HTTP (no club_id resolvable) | No | Cleared at request start | Yes | Fail-closed: tenant tables return 0 rows |
| Register (anonymous) | No | Yes, inside the transaction (AuthController) | Yes | WITH CHECK would reject the seeding otherwise |
| Messenger worker | No | Yes — handler sets it from the message `clubId` | Yes | `GenerateScheduleHandler` / `ExportPdfHandler`, cleared in `finally` |
| CLI (`doctrine:query:sql` default) | No | No | Yes | 0 rows on tenant tables — use `--connection admin` for ops |
| Tests | Yes | Yes | Yes | dama rollback also reverts the GUC (`set_config(..., false)` is transactional) |

## Security Considerations

- Defence in depth is real now: Doctrine filter (layer 2) **and** RLS (layer 3). Keep `TenantIsolationTest`, `TenantJwtIsolationTest` and `RlsIsolationTest` green — they are the blocking guards.
- **Connection separation (wired):** runtime = `app_user` (`DATABASE_URL`), migrations/ops/fixtures = `clubscheduler` via the Doctrine `admin` connection (`DATABASE_ADMIN_URL`). `clubscheduler` bypasses RLS — that is the deliberate superadmin supervision door (see `docs/security/rls.md`).
- ⚠ pgbouncer transaction-pooling is incompatible with the session-scoped GUC — redesign before introducing a pooler.

## See Also

- `backend/docs/RLS.md` — PostgreSQL RLS setup and troubleshooting
- `docker/postgres/init/02-users.sql` — `app_user` / `migration_user` creation

# State Processors — Agent Context

> 14+ API Platform state processors. Inheritance pattern with `AbstractStateProcessor`.

## Structure

| File | Role |
|------|------|
| `AbstractStateProcessor.php` | Base class with POST/PUT/DELETE logic |
| `VenueStateProcessor.php` | Venue CRUD |
| `TeamStateProcessor.php` | Team CRUD |
| `CoachStateProcessor.php` | Coach CRUD |
| `ScheduleStateProcessor.php` | Schedule + generation trigger |
| `...` | 10+ other entity processors |

## Pattern

All processors extend `AbstractStateProcessor` which handles:
- **Tenant injection** : auto-sets `clubId` from `X-Club-Id` header
- **Season resolution** : auto-resolves `seasonId` from active club season if header missing
- **Access control** : checks `clubId` matches on PUT/DELETE
- **Method dispatch** : `POST` → `processPost`, `PUT/PATCH` → `processPut`, `DELETE` → `processDelete`

## Critical Gotchas

1. **Season auto-resolution** — `resolveSeasonId()` finds the active season for the club. If no active season exists, the entity will fail with a DB NOT NULL constraint.
2. **No `seasonId` header** — Frontend sends `X-Club-Id` but not `X-Season-Id`. The backend resolves it automatically.
3. **Method_exists checks** — `setClubId`/`setSeasonId` are checked via `method_exists()` because not all entities have these fields.
4. **UUID strings** — All IDs are string UUIDs, never integers.
5. **DTO mapping** — `createEntityFromInput()` maps DTO → Entity. `updateEntityFromInput()` maps DTO → existing Entity. `mapEntityToOutput()` maps Entity → ApiResource.

## Anti-Patterns

- **Never** hardcode `seasonId` in a processor — always use `resolveSeasonId()`
- **Never** skip `method_exists()` checks — some entities lack `setClubId`/`setSeasonId`
- **Never** call `$entityManager->flush()` outside `AbstractStateProcessor` — the base class handles it

## Quick Reference

| Task | Location |
|------|----------|
| Add new entity processor | Extend `AbstractStateProcessor` + implement 4 abstract methods |
| Fix 500 on POST | Check `season_id` NOT NULL + `resolveSeasonId()` working |
| Add tenant field to entity | Add `setClubId`/`getClubId` + `setSeasonId`/`getSeasonId` |
| Test auto-save | Check `X-Club-Id` header present in request |

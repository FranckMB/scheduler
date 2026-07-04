# ClubScheduler — Backend Agent Context

> Symfony 7.4 + API Platform 4.3 + PHP 8.4. Core API and async scheduling orchestration.
> **Pointer file** — commands, CI, boundaries, flow: see root [`CLAUDE.md`](../CLAUDE.md) and [`docs/project-map.md`](../docs/project-map.md). Do not duplicate them here.

## Where things live (no counts — they rot)

- `src/ApiResource/` resources · `src/Entity/` Doctrine entities · `src/Controller/` custom controllers · `src/State/{Provider,Processor}/` API Platform state layer · `src/Service/` domain services · `src/MessageHandler/` async handlers · `src/Dto/` inputs.
- Deep-dives: [`docs/TENANT.md`](docs/TENANT.md) (tenant isolation), root [`docs/project-map.md`](../docs/project-map.md) §2 (flow + services), [`docs/testing/testing-strategy.md`](../docs/testing/testing-strategy.md).
- Drive a real generation: `scripts/generate-schedule.sh` · full smoke: `scripts/smoke-solver.sh` (see CLAUDE.md §7).

## Zone gotchas (facts not in the root docs)

1. **All commands run in the php-fpm container** — `backend/Makefile` wraps `docker compose exec`. `composer`/`php bin/console` on the host fail.
2. **CS-Fixer rule set** is `@Symfony` + `@Symfony:risky` + `@PHP84Migration` + `@PHP80Migration:risky` (see `.php-cs-fixer.dist.php` — the canonical source).
3. **Entities use string UUIDs** (no auto-increment) and scalar FK columns (`clubId`, `seasonId`… as strings, no object associations).
4. **`declare(strict_types=1)`** in every file; single-action controllers use `#[AsController]` + `__invoke()`.
5. **Redis lock** `ClubGenerationLock` (SETEX NX + release token) serialises generation per club.
6. **Cache pool `cache.schedule`** — dedicated Redis pool for engine payloads, TTL 4h, invalidated by `CacheInvalidationListener`.
7. **`SOFT_LOCK_PENALTY = 10_000`** in `ScheduleConstraintBuilder` — builder-side weight sent to the engine for soft-locked slots (distinct from the engine's own tier weights).
8. **Test DB required** before PHPUnit: `make db-init-test` once, then `make phpunit` (`--group phase1`).
9. **Stuck-schedule watchdog** `app:schedules:reconcile-stuck` (BCK-01) must run on a **cron in prod** (e.g. every 10 min): it fails `GENERATING` schedules abandoned by a crashed/OOM worker. Terminal-status nets are detailed in root `docs/project-map.md` §2.4.
10. **Dated constraints excluded from generation** — a `Constraint` with `calendarEntryId` set belongs to a `CalendarEntry` period (cockpit, palier A) and MUST NOT feed base-plan generation. Generation/validation/report load constraints via `ConstraintRepository::findPermanentByClubSeason` (`calendarEntryId IS NULL`), never `findByClubSeason`. See `specs/evolution/accueil-cockpit-temporel.md` §9ter.
11. **Sticky cockpit unlock** — `Season.socleValidatedAt` is stamped once when the season's baseline schedule is first `VALIDATED` (or a `VALIDATED` schedule is set as baseline); **never reset** (reopen keeps it). Exposed on `/api/me`. Gates the frontend cockpit vs work-loop.

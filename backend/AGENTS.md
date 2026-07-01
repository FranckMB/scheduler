# ClubScheduler — Backend Agent Context

> Symfony 7 + API Platform + PHP 8.4. Core API and async scheduling orchestration.

---

## Architecture

```
backend/
├── src/
│   ├── ApiResource/          # 20 API Platform resources (auto-generated CRUD)
│   ├── Entity/               # 20 Doctrine entities
│   ├── Controller/           # 3 custom controllers
│   │   ├── HealthController.php            # GET /api/health
│   │   ├── GenerateScheduleController.php  # POST /api/schedules/{id}/generate
│   │   └── ExportPdfController.php         # POST /api/schedules/{id}/export-pdf
│   ├── MessageHandler/
│   │   ├── GenerateScheduleHandler.php   # Async HTTP call to engine:8000/generate
│   │   └── ExportPdfHandler.php            # Stub (MVP)
│   ├── Service/
│   │   ├── ScheduleConstraintBuilder.php   # Builds payload for engine + Redis caching
│   │   ├── ScheduleResultImporter.php      # Imports solver results into ScheduleSlotTemplate
│   │   └── ClubGenerationLock.php          # Redis-based per-club concurrency lock
│   ├── State/Provider/       # API Platform state providers
│   ├── State/Processor/      # API Platform state processors
│   ├── Dto/                  # Data transfer objects
│   └── DataFixtures/         # Test/fixture data
├── config/                   # Symfony config (packages/, routes/)
├── migrations/               # Doctrine migrations
├── tests/                    # PHPUnit tests (group phase1 for blocking tests)
├── public/                   # Web entry point (index.php)
└── bin/                      # Console scripts
```

---

## Key Conventions

- **PHP 8.4** with `declare(strict_types=1)` in every file.
- **API Platform** auto-generates all CRUD under `/api/*`. OpenAPI docs at `/api/docs`.
- **Custom controllers** use `#[AsController]` and `__invoke()` for single-action controllers.
- **Messenger** async handlers use `#[AsMessageHandler]` and run in `messenger-worker` container.
- **State providers/processors** override default API Platform behavior for complex resources.
- **Entities** use string UUIDs, not auto-increment integers.
- **DTOs** are used for complex input/output shapes not covered by entities.

---

## Toolchain

- **PHPStan** level 8 (includes Doctrine + Symfony extensions).
- **PHP-CS-Fixer** `@PSR12` + `@Symfony` + `strict_comparison` + `yoda_style` (equal/identical only).
- **Rector** targets PHP 8.4 (`withPhpVersion(80400)`), aligned with the composer `>=8.4` requirement.
- **PHPUnit** 11 via the direct `phpunit/phpunit` dep — binary at `vendor/bin/phpunit` (same in CI, `Makefile`, `composer test`).
- **Doctrine** migrations in `migrations/`. Run `make migration-migrate` or `php bin/console doctrine:migrations:migrate`.

---

## Commands

All commands run **inside the php-fpm container** via `backend/Makefile`:

```bash
cd backend
make install              # composer install
make test                 # lint + phpunit --group phase1
make lint                 # phpstan + cs + rector (dry-run)
make phpunit              # phpunit --group phase1
make phpstan              # composer phpstan (level 8)
make cs-fix               # composer cs-fix (PHP-CS-Fixer)
make rector               # composer rector -- --dry-run
make schema-validate      # doctrine:schema:validate
make migration-diff       # doctrine:migrations:diff
make migration-migrate    # doctrine:migrations:migrate --no-interaction
make exec                 # shell in php-fpm container
```

Inside the container:
```bash
php bin/console messenger:consume async      # Run worker manually
php bin/console cache:clear                  # Clear cache
```

---

## Service Flow

### Schedule Generation
1. `POST /api/schedules/{id}/generate` → `GenerateScheduleController`
2. Dispatches `GenerateScheduleMessage` to async bus (Redis).
3. `GenerateScheduleHandler` in `messenger-worker` container:
   - Acquires Redis lock via `ClubGenerationLock`.
   - Builds payload via `ScheduleConstraintBuilder` (cached in Redis for 4h).
   - POSTs to `http://engine:8000/generate`.
   - Imports result via `ScheduleResultImporter`.
   - Publishes Mercure SSE on topic `club:{clubId}:schedule:{scheduleId}`.
   - Releases Redis lock.

### Export PDF
1. `POST /api/schedules/{id}/export-pdf` → `ExportPdfController`
2. Sets status to `pending`, dispatches `ExportPdfMessage`.
3. `ExportPdfHandler` is currently a **stub** — actual PDF generation is future work.

---

## Gotchas

1. **PHPUnit binary** is `vendor/bin/phpunit` (PHPUnit 11), identical in CI, `Makefile` and `composer test`. The suite needs the test DB — run `make db-init-test` first.
2. **All commands run in container** — `backend/Makefile` wraps everything with `docker compose exec`. Running `composer` or `php bin/console` directly on host will fail.
3. **Blocking tests** use `--group phase1` and must pass before the rest of the suite in CI.
4. **Rector targets PHP 8.4** (`withPhpVersion(80400)`), aligned with the project requirement.
5. **Redis lock** in `ClubGenerationLock` prevents concurrent generation for the same club. Uses `nx` + `ex` atomically.
6. **Cache pool** `cache.schedule` is a dedicated Redis cache pool for schedule input payloads (TTL 4h).
7. **Soft lock penalty** is 10,000 points in the solver objective (defined in `ScheduleConstraintBuilder`).
8. **ExportPdfHandler is a stub** — it does nothing yet. Do not implement PDF generation here without a plan.

---

## Quick Reference

| Task | Command |
|------|---------|
| Install deps | `cd backend && make install` |
| Run tests | `cd backend && make test` |
| Run lint | `cd backend && make lint` |
| Run phpunit | `cd backend && make phpunit` |
| Run phpstan | `cd backend && make phpstan` |
| Fix CS | `cd backend && make cs-fix` |
| Run rector | `cd backend && make rector` |
| Schema validate | `cd backend && make schema-validate` |
| Enter container | `cd backend && make exec` |

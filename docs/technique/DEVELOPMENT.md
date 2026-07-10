# ClubScheduler — Development Guide

## Quick Start

```bash
# 1. Start all services — on a fresh clone this also installs the dependencies,
#    generates the JWT keypair and creates+migrates the dev database.
make start

# 2. Check health
curl http://localhost:8080/api/health

# 3. Run tests
make test
```

The database starts empty; demo data is opt-in (`make -C backend fixtures`). After a `git pull`
that brings new migrations, run `make bootstrap` — `make start` never migrates on its own.

## Architecture

ClubScheduler is a monorepo with three main stacks:

- **backend/** — Symfony 7 + API Platform 4 (PHP 8.4)
- **engine/** — Python 3.12 + FastAPI + OR-Tools CP-SAT
- **frontend/** — React 19 + Vite + Tailwind 4

## Services

| Service | Port | Description |
|---------|------|-------------|
| nginx | 8080 | Reverse proxy |
| php-fpm | — | Symfony API |
| postgres | 5432 | PostgreSQL 16 |
| redis | 6379 | Cache + Messenger transport |
| engine | 8000 | Python solver microservice |
| mercure | 3000 | SSE hub for real-time updates |
| mailpit | 8025 | Email catcher |

## Commands

Root orchestration only. Zone commands (`phpstan`, `cs-fix`, `rector`, `phpunit`, migrations…)
live in `backend/Makefile` and `engine/Makefile` — run `make -C backend help` or
`make -C engine help` rather than trusting a copy of the list here.

```bash
make help        # Show all root commands
make start       # Start Docker services (bootstraps a fresh clone)
make bootstrap   # JWT keypair + create/migrate the dev DB (idempotent)
make stop        # Stop Docker services
make test        # Run all tests
make lint        # Run all linters
```

## Multi-Tenant Architecture

Every business entity has `club_id` and `season_id`. Tenant isolation is enforced at two layers:

1. **Application layer**: Doctrine `TenantFilter` appends `WHERE club_id = ?` to every query.
2. **Database layer**: PostgreSQL RLS policies ensure `app_user` can only see its own club's rows.

The `TenantFilterListener` activates the filter on each HTTP request and executes `SET LOCAL app.club_id`.

## Phase Plan

| Phase | Focus | Key Deliverable |
|-------|-------|-----------------|
| 1 | Foundation | Docker, Symfony, RLS, TenantFilter, Cache |
| 2 | Entities | 20 Doctrine tables + API Platform resources |
| 3 | Solver | OR-Tools CP-SAT model + constraints + objective |
| 4 | Product | Wizard React, FullCalendar, PDF export |

## Tests

- **4 blocking tests** (CI gates):
  - `TenantIsolationTest`
  - `TenantCacheIsolationTest`
  - `ConcurrentGenerationTest`
  - `ContractSchemaTest`

Run them with: `make phpunit`

## Contributing

1. Create a feature branch
2. Run `make test` before committing
3. Open a PR — CI will run all checks

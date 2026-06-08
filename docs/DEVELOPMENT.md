# ClubScheduler — Development Guide

## Quick Start

```bash
# 1. Start all services
make start

# 2. Check health
curl http://localhost:8080/api/health

# 3. Run tests
make test
```

## Architecture

ClubScheduler is a monorepo with three main stacks:

- **backend/** — Symfony 7 + API Platform 4 (PHP 8.3)
- **engine/** — Python 3.12 + FastAPI + OR-Tools CP-SAT
- **frontend/** — React 18 + Vite + Tailwind v4

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

```bash
make help        # Show all commands
make start       # Start Docker services
make stop        # Stop Docker services
make test        # Run all tests
make phpunit     # Run PHPUnit tests
make engine-test # Run Python tests
make phpstan     # Run PHPStan (level 8)
make cs-fix      # Run PHP-CS-Fixer
make rector      # Run Rector
make schema-validate  # Validate Doctrine schema
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

# ClubScheduler

ClubScheduler is a greenfield monorepo for a club scheduling platform.

## Architecture

- `backend/` — Symfony PHP application for the core API and admin workflows
- `engine/` — Python services for automation, optimization, and background jobs
- `frontend/` — React application for the user interface
- `docker/` — container and local environment assets
- `contracts/` — shared API, schema, and integration contracts
- `tests/` — cross-cutting test assets and end-to-end checks

## Repository goals

- Keep backend, engine, and frontend work isolated but aligned
- Share contracts explicitly between services
- Use Docker for local development and consistent environments

## Make commands

- `make start` — start the local development stack
- `make test` — run the full test suite
- `make lint` — run code quality checks
- `make stop` — stop local services

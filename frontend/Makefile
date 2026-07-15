.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
ROOT_ENV := ../.env

$(ROOT_ENV):
	$(MAKE) -C .. .env

exec: $(ROOT_ENV) ## Enter the Dockerized Node.js tooling environment
	$(FIG) --profile tools run --rm --build --no-deps frontend-tooling sh

start: ## Start the full Docker stack
	$(FIG) up -d

logs: ## Follow frontend service logs
	$(FIG) --profile dev logs -f frontend frontend-dev

stop: ## Stop the frontend service
	$(FIG) --profile dev stop frontend frontend-dev

shell: ## Open a shell in the frontend service container
	$(FIG) exec frontend sh

status: ## Show frontend service status
	$(FIG) --profile dev ps frontend frontend-dev

install: $(ROOT_ENV) ## Build the Dockerized Node.js tooling environment
	$(FIG) --profile tools build frontend-tooling

build: $(ROOT_ENV) ## Build the production frontend image
	$(FIG) build frontend

dev: $(ROOT_ENV) ## Start Dockerized Vite with hot reload
	$(FIG) --profile dev up --build frontend-dev

lint: install ## Run ESLint + TypeScript checks in Docker
	$(FIG) --profile tools run --rm --no-deps frontend-tooling sh -lc 'npm run lint && npx tsc --noEmit'

test: ## Run frontend checks and tests in Docker
	$(MAKE) lint
	$(FIG) --profile tools run --rm --no-deps frontend-tooling npm run test -- --maxWorkers=2

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

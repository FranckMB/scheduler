.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)

# Note: frontend service exists in docker-compose; exec still runs a one-off node container for ad hoc work
exec: ## Enter a Node.js container for frontend work (one-off)
	@echo "Starting one-off node container for frontend dev..."
	docker run --rm -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) -v /home/franck/Projets/scheduler:/app:cached -w /app/frontend node:20-alpine sh -c "
		if ! command -v npm >/dev/null 2>&1; then apk add --no-cache npm; fi;
		sh
	"

start: ## Start the full Docker stack
	$(FIG) up -d

logs: ## Follow frontend service logs
	$(FIG) logs -f frontend

stop: ## Stop the frontend service
	$(FIG) stop frontend

shell: ## Open a shell in the frontend service container
	$(FIG) exec frontend sh

status: ## Show frontend service status
	$(FIG) ps frontend

install: ## Install Node dependencies
	npm install

build: ## Build frontend
	npm run build

dev: ## Start dev server
	npm run dev

lint: ## Run ESLint + TypeScript checks
	npm run lint && npx tsc --noEmit

test: ## Run frontend tests
	$(MAKE) lint
	npm run test || true

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

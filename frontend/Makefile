.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)

# Note: no frontend service in docker-compose yet; exec runs a one-off node container
exec: ## Enter a Node.js container for frontend work (one-off)
	@echo "Starting one-off node container for frontend dev..."
	$(FIG) run --rm -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) -w /app/frontend node:20-alpine sh -c "
		if ! command -v npm >/dev/null 2>&1; then apk add --no-cache npm; fi;
		sh
	"

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

.DEFAULT_GOAL := help

DOCKER_COMPOSE := docker compose --env-file .env
INFRA_SERVICES := postgres redis mailpit mercure

.env:
	cp .env.dist .env

.installed: .env
	$(DOCKER_COMPOSE) build
	$(DOCKER_COMPOSE) up -d $(INFRA_SERVICES)
	$(MAKE) install
	touch .installed

start: .env .installed ## Start all Docker services, install dependencies on first run
	$(DOCKER_COMPOSE) up -d --wait

stop: ## Stop all Docker services
	$(DOCKER_COMPOSE) down

restart: stop start ## Restart all services

install: .env ## Install all dependencies
	$(MAKE) -C backend install
	$(MAKE) -C engine install
	$(MAKE) -C frontend install

reinstall: .env ## Force reinstall dependencies
	rm -f .installed
	$(MAKE) .installed

build: .env ## Build all Docker images
	$(DOCKER_COMPOSE) build

rebuild: .env ## Rebuild all Docker images without cache
	$(DOCKER_COMPOSE) build --no-cache

status: .env ## Show Docker services status
	$(DOCKER_COMPOSE) ps

exec: .env ## Open shell in container (usage: make exec SERVICE=php-fpm)
	@if [ -z "$(SERVICE)" ]; then echo "Usage: make exec SERVICE=<service>"; exit 1; fi
	$(DOCKER_COMPOSE) exec $(SERVICE) sh

logs: .env ## Show Docker logs
	$(DOCKER_COMPOSE) logs -f

logs-service: .env ## Show Docker logs for one service (usage: make logs-service SERVICE=php-fpm)
	@if [ -z "$(SERVICE)" ]; then echo "Usage: make logs-service SERVICE=<service>"; exit 1; fi
	$(DOCKER_COMPOSE) logs -f $(SERVICE)

test: .env ## Run all tests
	$(MAKE) -C backend test
	$(MAKE) -C engine test
	$(MAKE) -C frontend test

lint: .env ## Run all linters
	$(MAKE) -C backend lint
	$(MAKE) -C engine lint
	$(MAKE) -C frontend lint

health: ## Check API health
	@curl -s http://localhost:8080/api/health | python3 -m json.tool || true

clean: .env ## Stop services and remove containers/networks
	$(DOCKER_COMPOSE) down --remove-orphans

clean-volumes: .env ## Stop services and remove containers/networks/volumes
	$(DOCKER_COMPOSE) down --remove-orphans --volumes
	rm -f .installed

reset-install: ## Force next make start to reinstall dependencies
	rm -f .installed

services: .env ## List Docker Compose services
	$(DOCKER_COMPOSE) config --services

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_.-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
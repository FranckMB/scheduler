.DEFAULT_GOAL := help

start: ## Start all Docker services
	docker compose --env-file .env up -d --wait

stop: ## Stop all Docker services
	docker compose down

restart: stop start ## Restart all services

exec: ## Open shell in container (usage: make exec SERVICE=php-fpm)
	@if [ -z "$(SERVICE)" ]; then echo "Usage: make exec SERVICE=<service>"; exit 1; fi
	docker compose exec $(SERVICE) sh

install: ## Install all dependencies
	$(MAKE) -C backend install
	$(MAKE) -C engine install
	$(MAKE) -C frontend install

test: ## Run all tests
	$(MAKE) -C backend test
	$(MAKE) -C engine test
	$(MAKE) -C frontend test

lint: ## Run all linters
	$(MAKE) -C backend lint
	$(MAKE) -C engine lint
	$(MAKE) -C frontend lint

health: ## Check API health
	@curl -s http://localhost:8080/api/health | python3 -m json.tool || true

logs: ## Show Docker logs
	docker compose logs -f

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

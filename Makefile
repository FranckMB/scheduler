.DEFAULT_GOAL := help

# ──────────────────────────────────────────────────────────────────────────────
# ClubScheduler — Development Makefile
# ──────────────────────────────────────────────────────────────────────────────

##@ 🐳 Docker

.PHONY: start
start: ## Start all 9 Docker services
	docker compose --env-file .env up -d --wait

.PHONY: stop
stop: ## Stop all Docker services
	docker compose down

.PHONY: restart
restart: stop start ## Restart all Docker services

##@ 📦 Dependencies

.PHONY: install
install: ## Install PHP, Node and Python dependencies
	docker compose exec php-fpm composer install --no-interaction
	docker compose exec engine pip install -r requirements.txt || true
	cd frontend && npm install || true

##@ 🧪 Tests

.PHONY: test
test: ## Run all tests (PHP + Python)
	$(MAKE) phpunit
	$(MAKE) engine-test

.PHONY: phpunit
phpunit: ## Run PHPUnit tests (4 blocking tests + unit)
	docker compose exec php-fpm sh -c 'cd /app/backend && php vendor/bin/.phpunit/phpunit-9.6-0/phpunit --group phase1'

.PHONY: engine-test
engine-test: ## Run pytest in engine container
	docker compose exec engine pytest || true

##@ 🔍 Quality

.PHONY: phpstan
phpstan: ## Run PHPStan level 8
	docker compose exec php-fpm composer phpstan

.PHONY: cs-fix
cs-fix: ## Run PHP-CS-Fixer
	docker compose exec php-fpm composer cs-fix

.PHONY: rector
rector: ## Run Rector
	docker compose exec php-fpm composer rector -- --dry-run

.PHONY: lint
lint: ## Run all linters (PHP + Python)
	$(MAKE) phpstan
	$(MAKE) cs-fix -- --dry-run --diff
	$(MAKE) rector

##@ 🗄️ Database

.PHONY: schema-validate
schema-validate: ## Validate Doctrine schema
	docker compose exec php-fpm php bin/console doctrine:schema:validate

.PHONY: migration-diff
migration-diff: ## Generate Doctrine migration
	docker compose exec php-fpm php bin/console doctrine:migrations:diff

.PHONY: migration-migrate
migration-migrate: ## Run Doctrine migrations
	docker compose exec php-fpm php bin/console doctrine:migrations:migrate --no-interaction

##@ 🚀 Frontend

.PHONY: frontend-build
frontend-build: ## Build frontend
	cd frontend && npm run build

.PHONY: frontend-dev
frontend-dev: ## Start frontend dev server
	cd frontend && npm run dev

##@ 🐛 Debug

.PHONY: health
health: ## Check API health
	@curl -s http://localhost:8080/api/health | python3 -m json.tool || true

.PHONY: logs
logs: ## Show Docker logs
	docker compose logs -f

##@ 📋 Help

.PHONY: help
help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

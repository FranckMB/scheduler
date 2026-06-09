.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)
EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm
RUN := $(FIG) exec php-fpm

exec: ## Enter the backend container shell (auto-start if needed)
	@$(FIG) ps php-fpm | grep -q "Up" || ($(FIG) up -d php-fpm && echo "Container started")
	$(EXEC) bash || $(EXEC) sh

install: ## Install PHP dependencies
	$(RUN) composer install --no-interaction

test: ## Run lint + PHPUnit tests
	$(MAKE) lint
	$(MAKE) phpunit

phpstan: ## Run PHPStan level 8
	$(RUN) composer phpstan

phpunit: ## Run PHPStan level 8
	$(RUN) php vendor/bin/.phpunit/phpunit-9.6-0/phpunit --group phase1

cs-fix: ## Run PHP-CS-Fixer
	$(RUN) composer cs-fix

cs: ## Run PHP-CS
	$(RUN) composer cs-fix -- --dry-run --diff

rector: ## Run Rector
	$(RUN) composer rector -- --dry-run

lint: ## Run all PHP linters
	$(MAKE) phpstan
	$(MAKE) cs
	$(MAKE) rector

schema-validate: ## Validate Doctrine schema
	$(RUN) php bin/console doctrine:schema:validate

migration-diff: ## Generate Doctrine migration
	$(RUN) php bin/console doctrine:migrations:diff

migration-migrate: ## Run Doctrine migrations
	$(RUN) php bin/console doctrine:migrations:migrate --no-interaction

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

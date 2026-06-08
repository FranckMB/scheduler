.DEFAULT_GOAL := help

install: ## Install PHP dependencies
	composer install --no-interaction

test: ## Run PHPUnit tests
	php vendor/bin/.phpunit/phpunit-9.6-0/phpunit --group phase1

phpstan: ## Run PHPStan level 8
	composer phpstan

cs-fix: ## Run PHP-CS-Fixer
	composer cs-fix

rector: ## Run Rector
	composer rector -- --dry-run

lint: ## Run all PHP linters
	$(MAKE) phpstan
	$(MAKE) cs-fix -- --dry-run --diff
	$(MAKE) rector

schema-validate: ## Validate Doctrine schema
	php bin/console doctrine:schema:validate

migration-diff: ## Generate Doctrine migration
	php bin/console doctrine:migrations:diff

migration-migrate: ## Run Doctrine migrations
	php bin/console doctrine:migrations:migrate --no-interaction

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

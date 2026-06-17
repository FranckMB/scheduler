.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
USERID := $(shell id -u)
GROUPID := $(shell id -g)
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev

ifdef APP_ENV
	ifeq ($(shell uname),Darwin)
		EXECROOT := $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
		EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
	else
		EXECROOT := $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
		EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm
	endif
else
	EXECROOT :=
	EXEC :=
endif

.PHONY: help ## Display this help
help:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_.@%-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.PHONY: install ## Install PHP dependencies
install:
	$(EXEC) composer install --no-interaction

.PHONY: test ## Run PHPStan, CS, and unit tests
test: APP_ENV = test
test:
	$(MAKE) APP_ENV=$(APP_ENV) phpstan
	$(MAKE) APP_ENV=$(APP_ENV) cs
	$(EXEC) php vendor/bin/phpunit --testsuite Unit

.PHONY: tests-complete ## Run unit and integration test suites
tests-complete: APP_ENV = test
tests-complete: db-init-test
	$(MAKE) APP_ENV=$(APP_ENV) test
	$(EXEC) php vendor/bin/phpunit --testsuite Integration

.PHONY: phpunit ## Run blocking PHPUnit group
phpunit: APP_ENV = test
phpunit:
	$(EXEC) php vendor/bin/phpunit --group phase1

.PHONY: db-init-test ## Create and migrate the test database
db-init-test: APP_ENV = test
db-init-test:
	$(EXEC) php bin/console doctrine:database:create --if-not-exists -e test
	$(EXEC) php bin/console doctrine:migrations:migrate --no-interaction -e test

.PHONY: db-reset ## Drop, recreate, and migrate the test database
db-reset: APP_ENV = dev
db-reset:
	$(EXECROOT) php bin/console doctrine:database:drop --force --if-exists
	$(EXECROOT) php bin/console doctrine:database:create
	$(EXECROOT) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: db-reset-test ## Drop, recreate, and migrate the test database
db-reset-test: APP_ENV = test
db-reset-test:
	$(EXECROOT) php bin/console doctrine:database:drop --force --if-exists -e test
	$(EXECROOT) php bin/console doctrine:database:create -e test
	$(EXECROOT) php bin/console doctrine:migrations:migrate --no-interaction -e test

.PHONY: fixtures ## Load backend fixtures (also clears var/generate)
fixtures: APP_ENV = dev
fixtures:
	$(EXEC) php bin/console doctrine:fixtures:load --no-interaction
	$(EXECROOT) rm -rf var/generate

.PHONY: phpstan ## Run PHPStan
phpstan:
	$(EXEC) composer phpstan

.PHONY: cs ## Run PHP-CS-Fixer in dry-run mode
cs:
	$(EXEC) composer cs-fix -- --dry-run --diff

.PHONY: cs-fix ## Fix code style with PHP-CS-Fixer
cs-fix:
	$(EXEC) composer cs-fix

.PHONY: rector ## Run Rector in dry-run mode
rector:
	$(EXEC) composer rector -- --dry-run

.PHONY: lint ## Run PHPStan, CS, and Rector
lint:
	$(MAKE) phpstan
	$(MAKE) cs
	$(MAKE) rector

.PHONY: exec ## Enter the php-fpm container
exec:
	@$(FIG) ps php-fpm | grep -q "Up" || $(FIG) up -d php-fpm >/dev/null
	$(EXEC) bash || $(EXEC) sh

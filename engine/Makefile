.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)
EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) engine
RUN := $(FIG) exec engine

exec: ## Enter the engine container shell (auto-start if needed)
	@$(FIG) ps engine | grep -q "Up" || ($(FIG) up -d engine && echo "Container started")
	$(EXEC) bash || $(EXEC) sh

install: ## Install Python dependencies
	$(RUN) pip install -r requirements.txt

test: ## Run pytest
	$(MAKE) lint
	$(RUN) pytest || true

lint: ## Run ruff + mypy
	$(RUN) ruff check . || true
	$(RUN) mypy app/ || true

format: ## Format code with ruff
	$(RUN) ruff format . || true

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

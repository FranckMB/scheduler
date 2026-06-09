.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev
USERID := $(shell id -u)
GROUPID := $(shell id -g)
EXEC := $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) engine
RUN := $(FIG) exec -T engine

exec: ## Enter the engine container shell (auto-start if needed)
	@$(FIG) ps engine | grep -q "Up" || ($(FIG) up -d engine && echo "Container started")
	$(EXEC) bash || $(EXEC) sh

install: ## Install Python dependencies
	$(RUN) python -m pip install -e ".[dev]"

test: ## Run pytest
	$(RUN) pytest

lint: ## Run ruff + mypy
	$(RUN) ruff check .
	$(RUN) mypy

format: ## Format code with ruff
	$(RUN) ruff format .

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

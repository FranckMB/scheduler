.DEFAULT_GOAL := help

install: ## Install Python dependencies
	pip install -r requirements.txt

test: ## Run pytest
	pytest

lint: ## Run ruff + mypy
	ruff check .
	mypy app/

format: ## Format code with ruff
	ruff format .

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

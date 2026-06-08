.DEFAULT_GOAL := help

install: ## Install Node dependencies
	npm install

build: ## Build frontend
	npm run build

dev: ## Start dev server
	npm run dev

lint: ## Run ESLint + TypeScript checks
	npm run lint && npx tsc --noEmit

test: ## Run frontend tests
	npm run test

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

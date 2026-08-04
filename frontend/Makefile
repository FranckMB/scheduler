.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
ROOT_ENV := ../.env

$(ROOT_ENV):
	$(MAKE) -C .. .env

exec: $(ROOT_ENV) ## Enter the Dockerized Node.js tooling environment
	$(FIG) --profile tools run --rm --build --no-deps frontend-tooling sh

start: ## Start the full Docker stack
	$(FIG) up -d

logs: ## Follow frontend service logs
	$(FIG) --profile dev logs -f frontend frontend-dev

stop: ## Stop the frontend service
	$(FIG) --profile dev stop frontend frontend-dev

shell: ## Open a shell in the frontend service container
	$(FIG) exec frontend sh

status: ## Show frontend service status
	$(FIG) --profile dev ps frontend frontend-dev

install: $(ROOT_ENV) ## Build the Dockerized Node.js tooling environment
	$(FIG) --profile tools build frontend-tooling

build: $(ROOT_ENV) ## Build the production frontend image
	$(FIG) build frontend

dev: $(ROOT_ENV) ## Start Dockerized Vite with hot reload
	$(FIG) --profile dev up --build frontend-dev

# `tsc -b`, PAS `tsc --noEmit` : le tsconfig racine est un fichier SOLUTION
# ("files": [] + references), et --noEmit n'y voit alors AUCUN fichier — il sortait 0
# sans rien vérifier, pendant que la CI (qui fait bien `tsc -b`) tombait sur les erreurs.
# --force : sans lui, le tsbuildinfo peut court-circuiter la vérification.
lint: install ## Run ESLint + TypeScript checks in Docker
	$(FIG) --profile tools run --rm --no-deps frontend-tooling sh -lc 'npm run lint && npx tsc -b --force'

test: ## Run frontend checks and tests in Docker
	$(MAKE) lint
	$(FIG) --profile tools run --rm --no-deps frontend-tooling npm run test -- --maxWorkers=2

# P4-33 — e2e Playwright dockerisé : plus AUCUN Node requis sur l'hôte. Exige la
# stack complète (`make start` à la racine) + le Vite dev (`make dev` ici) :
# les specs s'exécutent contre http://frontend-dev:5173 (code vivant), Mailpit
# est joint en interne (voir le service `e2e` du compose). PUPPETEER_SKIP_DOWNLOAD :
# le postinstall de puppeteer (dép. transitive) cassait sur un ~/.cache pollué —
# ici il n'a rien à télécharger, Chromium vient de l'image.
# ⚠ Le self-heal du globalSetup (compose up -d --wait) est SAUTÉ dès que
# E2E_BASE_URL est posé — or le run dockerisé le pose toujours. Il vit donc ICI,
# côté hôte (le conteneur e2e n'a pas de socket docker) : un messenger-worker
# mort (time-limit horaire) laissait sinon toute génération en PENDING.
e2e: $(ROOT_ENV) ## Run Playwright e2e tests fully inside Docker (stack + `make dev` required)
	$(FIG) up -d --wait
	# Un volume nommé neuf naît possédé par root : la pré-étape le rend à l'UID
	# hôte, sans quoi npm ci (exécuté en $${USER_ID:-1000}) refuse d'écrire.
	$(FIG) --profile tools run --rm --no-deps --user root e2e sh -lc 'chown -R $${USER_ID:-1000}:$${GROUP_ID:-1000} /app/frontend/node_modules'
	$(FIG) --profile tools run --rm --no-deps e2e sh -lc 'PUPPETEER_SKIP_DOWNLOAD=1 npm ci --legacy-peer-deps && npx playwright test'

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

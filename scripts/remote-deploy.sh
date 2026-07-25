#!/usr/bin/env bash
# Runs ON the production VM (piped over SSH by .github/workflows/deploy.yml —
# never executed locally). Rolls the stack to $VERSION:
#
#   1. pin VERSION in .env.prod (persists across manual `up -d` later)
#   2. pre-migration safety dump (rule: backup --force BEFORE any migration)
#   3. pull the tagged images, recreate what changed
#   4. run doctrine migrations (admin connection, pinned in the bundle config)
#   5. final health probe through the edge
#
# Expects on the VM (first-install runbook, docs/ops/deploy.md):
#   $DEPLOY_PATH/docker-compose.prod.yml + .env.prod + jwt/ + `docker login ghcr.io`
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH not set}"
: "${VERSION:?VERSION not set}"

cd "$DEPLOY_PATH"
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.prod)

echo "==> Deploying $VERSION to $DEPLOY_PATH"

# 1. Pin the version in .env.prod (idempotent).
if grep -q '^VERSION=' .env.prod; then
  sed -i "s|^VERSION=.*|VERSION=${VERSION}|" .env.prod
else
  printf 'VERSION=%s\n' "$VERSION" >> .env.prod
fi

# 2. Pre-migration dump — skipped (with a loud warning) when php-fpm is not
#    running yet, i.e. the very first deploy on an empty VM.
if "${COMPOSE[@]}" ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running; then
  echo "==> Pre-migration backup (app:db:backup --force)"
  "${COMPOSE[@]}" exec -T php-fpm php bin/console app:db:backup --force
else
  echo "WARN: php-fpm not running — first deploy? Skipping the pre-migration dump."
fi

# 3. Pull + roll.
echo "==> Pulling images ($VERSION)"
"${COMPOSE[@]}" pull --quiet
echo "==> Recreating services"
"${COMPOSE[@]}" up -d --wait

# 4. Migrations (doctrine_migrations.yaml pins the admin connection).
echo "==> Running migrations"
"${COMPOSE[@]}" exec -T php-fpm php bin/console doctrine:migrations:migrate --no-interaction

# 5. Health through the edge (FRONTEND_PORT from .env.prod, default 8081).
FRONTEND_PORT=$(grep -oP '^FRONTEND_PORT=\K.*' .env.prod || echo 8081)
echo "==> Health probe"
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/health" >/dev/null
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/api/health" >/dev/null

echo "==> Deploy $VERSION OK"

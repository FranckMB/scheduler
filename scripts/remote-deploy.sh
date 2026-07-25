#!/usr/bin/env bash
# Runs ON the production VM (piped over SSH by .github/workflows/deploy.yml —
# never executed locally). Rolls the stack to $VERSION, fail-closed:
#
#   1. first-install detection (no postgres container yet = nothing to dump)
#   2. pre-migration dump — MANDATORY otherwise: runs even if php-fpm is
#      crashed (compose run --rm on the CURRENT images); failure ABORTS
#   3. pull the new images ($VERSION as process env — compose gives process
#      env precedence over --env-file, so .env.prod is not touched yet)
#   4. migrations FIRST, via the NEW image (compose run --rm), while the old
#      stack still serves — a failed migration leaves prod untouched.
#      Convention: migrations are backward-compatible one release back
#      (docs/ops/deploy.md), so old-code-on-new-schema is safe for the
#      seconds the roll takes.
#   5. recreate services, wait healthy, probe /health + /api/health
#   6. only THEN pin VERSION into .env.prod (a failed deploy never leaves
#      .env.prod pointing at images the VM doesn't have)
#
# Expects on the VM (first-install runbook, docs/ops/deploy.md):
#   $DEPLOY_PATH/docker-compose.prod.yml + .env.prod + jwt/ + ghcr login
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH not set}"
: "${VERSION:?VERSION not set}"
# Defense in depth: the workflow already regex-validates VERSION, but this
# script also runs it through sed/compose — refuse anything exotic.
[[ "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "FATAL: invalid VERSION '$VERSION'" >&2; exit 1; }

cd "$DEPLOY_PATH"
export VERSION  # process env beats --env-file in compose interpolation
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.prod)

echo "==> Deploying $VERSION to $DEPLOY_PATH"

# 1+2. Pre-migration dump, fail-closed. `run --rm` works even when php-fpm is
# crash-looping (a state where `exec` is impossible and skipping the dump
# before a destructive migration would be unforgivable). Only a genuine first
# install (no postgres container at all) may skip.
if "${COMPOSE[@]}" ps -a postgres --format '{{.Names}}' 2>/dev/null | grep -q .; then
  echo "==> Pre-migration backup (mandatory — failure aborts the deploy)"
  # env -u VERSION: the dump must run on the CURRENT images (.env.prod's
  # pinned version), not the one being deployed — the new images aren't
  # pulled yet.
  env -u VERSION docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm --no-TTY php-fpm php bin/console app:db:backup --force
else
  echo "==> First install detected (no postgres container) — nothing to dump."
fi

# 3. Pull the new images (VERSION from the process env).
echo "==> Pulling images ($VERSION)"
"${COMPOSE[@]}" pull --quiet

# 4. Migrations from the NEW image while the OLD stack still serves.
echo "==> Running migrations (new image, old stack untouched on failure)"
"${COMPOSE[@]}" run --rm --no-TTY php-fpm php bin/console doctrine:migrations:migrate --no-interaction

# 5. Roll + probe.
echo "==> Recreating services"
"${COMPOSE[@]}" up -d --wait

FRONTEND_PORT=$(grep -oP '^FRONTEND_PORT=\K[0-9]+' .env.prod | head -1 || true)
FRONTEND_PORT=${FRONTEND_PORT:-8081}
echo "==> Health probe (:${FRONTEND_PORT})"
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/health" >/dev/null
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/api/health" >/dev/null

# 6. Persist the version only now — a later manual `up -d` stays on it.
if grep -q '^VERSION=' .env.prod; then
  sed -i "s|^VERSION=.*|VERSION=${VERSION}|" .env.prod
else
  printf 'VERSION=%s\n' "$VERSION" >> .env.prod
fi

echo "==> Deploy $VERSION OK"

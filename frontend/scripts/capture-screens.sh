#!/usr/bin/env bash
# Visual capture of the frontend auth screens — a browserless-host-friendly
# guardrail. Rebuilds the prod frontend image, serves it, and screenshots via
# the pdf-worker container's chromium (no host browser / no root needed).
# Reusable in CI for visual regression. Output: frontend/.screenshots/
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$ROOT"

docker compose build frontend
docker compose up -d frontend pdf-worker

docker compose cp frontend/scripts/capture-screens.cjs pdf-worker:/tmp/capture-screens.cjs
docker compose exec -T pdf-worker sh -c 'NODE_PATH=/home/pptruser/node_modules node /tmp/capture-screens.cjs'

OUT="$ROOT/frontend/.screenshots"
rm -rf "$OUT"
docker compose cp pdf-worker:/tmp/out "$OUT"
echo "Screenshots written to $OUT"

# Mercure hub — access control

> Status: hardened 2026-07-03 (audit SEC-05/SEC-06).

The Mercure hub pushes schedule-generation status to clients on the topic
`club:{clubId}:schedule:{scheduleId}`. It is reachable only on `127.0.0.1:${MERCURE_PORT}`
(never published on a public interface in dev).

## What changed

Before, the hub ran with `anonymous` (any client could subscribe to any topic
without a JWT), `cors_origins *` and `publish_origins *`, and signed its JWTs
with the **same** secret as the lexik auth passphrase (`${JWT_PASSPHRASE}`).

Now (`docker-compose.yml`, service `mercure`):

- **No `anonymous`** — a subscriber must present a valid subscriber JWT signed
  with `MERCURE_JWT_SECRET`. No token → 401.
- **`cors_origins`** limited to the dev frontends (`http://localhost:5173`,
  `http://localhost:8081`).
- **No `publish_origins *`** — browser-side publishing is not allowed; the
  backend publishes server-side (no `Origin` header) with a publisher JWT, which
  is unaffected by `publish_origins`.
- **Dedicated secret** `MERCURE_JWT_SECRET`, distinct from the lexik
  `JWT_PASSPHRASE` (SEC-06 — the two were the same value and `JWT_PASSPHRASE`
  was even defined twice in `backend/.env`).

## Secrets

| Var | Where | Role |
|-----|-------|------|
| `JWT_PASSPHRASE` | root `.env` (feeds php-fpm via `env_file`) + `backend/.env` | passphrase of the lexik RSA private key — **auth only** |
| `MERCURE_JWT_SECRET` | root `.env` (hub, via compose) + `backend/.env` (publisher) | HS256 secret signing Mercure publisher/subscriber JWTs |

Both hold dev placeholders. **Prod: replace `MERCURE_JWT_SECRET` with a random
32+ byte secret** and keep it in sync between the hub (compose) and the backend
publisher (`backend/config/packages/mercure.yaml` reads `%env(MERCURE_JWT_SECRET)%`).
Where the secret is generated and stored on the VM: `docs/ops/deploy.md` (§`.env.prod`).

## Prod (`docker-compose.prod.yml`)

The prod stack tightens the same four axes rather than restating them:

- **No published port at all.** The dev hub is bound to `127.0.0.1:${MERCURE_PORT}`;
  in prod the service declares no `ports:` — browsers reach it only through the
  frontend edge (`location /.well-known/mercure` in `docker/frontend/nginx.prod.conf`).
- **Image pinned** to `dunglas/mercure:v0.19` where dev rides `:latest` — a routine
  `docker compose pull` must never swap the hub version under a running production.
- **`cors_origins ${PUBLIC_BASE_URL}` — that single origin**, not the dev
  `localhost:5173 / localhost:8081` allow-list.
- **Secrets declared `${MERCURE_JWT_SECRET:?}`**: the stack refuses to start if the
  variable is missing, so a dev placeholder cannot silently ride into prod through
  an incomplete `.env.prod`.

## Public URL

`MERCURE_PUBLIC_URL` (the browser-facing hub URL) is set by compose on the
`php-fpm` service to `http://localhost:${MERCURE_PORT}/.well-known/mercure`, so
it always matches the port the hub is actually published on. The static value in
`backend/.env` is only a fallback for non-Docker runs.

## Frontend consumption (future)

The frontend currently **polls** the API for generation status and does not
subscribe to Mercure (see `frontend/src/features/planning/queries.ts`). When a
real SSE subscription is wired, the client must obtain a **subscriber JWT** —
signed with `MERCURE_JWT_SECRET`, scoped to that club's topics only
(`club:{clubId}:schedule:*`) — delivered as the `mercureAuthorization` cookie or
an `Authorization` header. Do not re-enable `anonymous`.

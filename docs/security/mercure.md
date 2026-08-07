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

## Frontend consumption (delivered — FRT-04, 2026-08-07)

The frontend subscribes to the hub for generation progress; polling survives
only as a **fallback** (the publisher is best-effort — a missed event self-heals
on the next poll).

- **Subscriber JWT**: minted by `GET /api/mercure/auth`
  (`backend/src/Controller/MercureAuthController.php`) — HS256, **same
  `MERCURE_JWT_SECRET` as the publisher**, `subscribe` claim = the single URI
  template `club:{clubId}:schedule:{id}` where `clubId` is the **authenticated
  member's resolved tenant** (`_club_id` request attribute — never a client
  parameter). No wildcard, no other club. TTL 1 h.
- **Delivery**: `mercureAuthorization` **cookie, httpOnly, SameSite strict,
  path `/.well-known/mercure`** — the JS never sees the hub token (the
  app JWT in localStorage is already the weak point; no second exposed token),
  and the browser only sends it to the hub, same-origin via the vite/nginx
  proxies. Guarded by `backend/tests/Api/MercureAuthTest.php` (phase1 — the
  claim's club scope is a tenant boundary).
- **Client**: `frontend/src/shared/lib/scheduleStream.ts` — ONE ref-counted
  `EventSource` per session, subscribed to the **template itself as topic**
  (the response's `topicTemplate`; the hub matches every exact
  `club:X:schedule:<uuid>` topic against it, so all the club's generations
  arrive on one connection without knowing their ids). Events **invalidate**
  the react-query caches (the server stays the source of truth); the poll
  degrades from 2.5 s to a 15 s fallback while the stream is connected. On
  stream error the client closes and **re-authenticates on retry** — never the
  native EventSource reconnection, which would replay an expired cookie forever.

`anonymous` stays off; nothing here relaxes the hub configuration above.

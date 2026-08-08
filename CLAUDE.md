# ClubScheduler — Operational Index

> Canonical agent cheat-sheet for this monorepo. Short on purpose (< 200 lines).
> Detail lives in `docs/`. If a fact is obvious from filenames, it is not here.
> Agent read order: **this file → `docs/project-map.md` (detail) → `specs/courantes/` (execution specs)**.
> Scope note: `frontend/` has been **rebuilt from scratch** (React 19 · Vite · Tailwind 4) and is **active** — it is indexed by the code-review-graph and Serena. Delivered (`src/features/*`): auth, cockpit, the **planning work-loop**, the **data-entry wizard**, matchs, doléances coachs.

## 1. What this is

ClubScheduler generates per-club, per-season training schedules for basketball clubs (FFBB).
A constraint solver (OR-Tools CP-SAT) places teams into venue time-slots under hard rules + a soft scoring objective. **Backend** orchestrates/persists/exposes the API, **engine** solves, **frontend** renders (wizard to enter data → generate → work-loop to adjust/regenerate).

## 2. Stack & zones

| Zone | Lang / Runtime | Entry point | Role |
|------|----------------|-------------|------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 | `public/index.php` | API, persistence, async orchestration |
| `engine/` | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` | Schedule solver (`POST /generate`) |
| `frontend/` | TS · React 19 · Vite · Tailwind 4 | `src/main.tsx` | UI — auth · planning work-loop · data-entry wizard |
| `specs/` | Markdown | `specs/README.md` | Living specs (initiales/courantes/evolution) |

**Boundaries (critical — never cross these):**
- `frontend → backend` via `/api/*` · `backend → engine` via `POST http://engine:8000/generate` · `backend → frontend` via Mercure SSE topic `club:{clubId}:schedule:{scheduleId}`.
- **Engine is reactive: it NEVER calls the backend.** **Frontend NEVER calls the engine directly** — aucun proxy `/engine` côté Vite (FRT-17) ; le nginx de DEV en garde un (`docker/frontend/nginx.conf`, absent de `nginx.prod.conf`) : outil de debug, pas un chemin applicatif.

## 3. Key commands

Backend, engine and frontend tooling run **inside Docker** (their Makefiles wrap Docker Compose).

```bash
make start | stop | install | test | lint        # root orchestration (docker compose, reads .env)
make bootstrap             # JWT keypair + create/migrate the dev DB — idempotent; `.installed` runs it on first install, so re-run it by hand after a pull adds migrations
cd backend && make test    # PHPStan(lvl8) + CS-Fixer + PHPUnit `--testsuite Unit` ONLY (⚠ pas toute la suite : §10.2)
cd backend && make phpstan | cs-fix | rector | tests-complete | migration-diff | migration-migrate | jwt-keys | db-init
cd engine  && make test    # ruff + mypy + bandit + pytest (+ coverage)   |  make format
make -C frontend dev        # Dockerized Vite :5173 (proxies /api, /exports, /.well-known/mercure — never /engine)
make -C frontend e2e        # Playwright e2e ENTIÈREMENT dockerisé (P4-33) — exige stack + `dev` lancés ; l'hôte n'a plus besoin de Node
```

## 4. CI order (`.github/workflows/ci.yml`)

`{lint, phpstan} → blocking-tests → {unit-tests, e2e (Playwright, full stack + Vite)}` · **`smoke-tests`** (5 smokes sémantiques : onboarding · solveur saison · placement matchs · overlay de période · doléances coachs) est un job **DÉDIÉ SANS `needs`** — il répond « la fonctionnalité marche-t-elle ? », question indépendante des suites unitaires, et n'a besoin ni de npm ni de Playwright : il démarre à t=0 et répond ~2× plus tôt que s'il était accroché à l'e2e · the `phpstan` job runs **PHPStan *and* CS-Fixer** (`--dry-run`, since 2026-07-17 — it has the PHP container the `lint` job lacks; `lint` = docker-compose + Makefile only) · **`rector`** (P4-24, depuis 2026-07-26) est un job DÉDIÉ, sans `needs` : il ne gate aucun autre job, mais **il BLOQUE le merge** — le contexte « Rector (style gate) » fait partie des required status checks de `main` depuis le 2026-07-27 (10 contextes requis) — même forme que `dependency-audit` et pour la même raison : `rector.php` tire ses règles des versions installées, donc un bump dependabot peut le rougir sur un commit qui n'a rien changé ; dans `phpstan` il aurait fait sauter `blocking-tests` (isolation tenant/RLS) et `build-docker` pour de la dérive de style · **`engine-semantics`** (SEC-13, dédié SANS `needs`, **bloquant**) porte tout le groupe `contract` — les tests qui interrogent le VRAI moteur : chaque clé de la liste blanche `config` doit CHANGER son résultat, le miroir de capacité doit rendre le même verdict que lui, le payload doit rester recevable. Ils vivaient noyés dans `unit-tests` (1200 tests), donc invisibles en tombant ; `unit-tests` les exclut désormais (`--exclude-group contract`) · `engine-tests`, `frontend` (eslint + `tsc -b` + `vite build` + `vitest`) **and** `dependency-audit` (SEC A18 — `composer audit` / `npm audit --audit-level=high` / `pip-audit`, **blocking, no needs**, ne gate PAS build-docker) run in parallel from the start (no needs) · **`secrets-scan`** (SEC A19, Gitleaks historique complet, **blocking, no needs** — exceptions analysées une à une dans `.gitleaks.toml`) et **`semgrep`** (GATE depuis SEC-14 — exclusions `.semgrepignore` + `nosemgrep` inline motivés) idem · **Trivy** scanne les images prod dans `build-docker` (gate CRITICAL fixables, `.trivyignore`) + balayage hebdo des images ghcr (`security-weekly.yml`) — le tout : `docs/security/scanners.md` · `build-docker` needs **[blocking-tests, engine-tests] only** — unit-tests, e2e, frontend and dependency-audit do NOT gate it · `engine-perf` gate runs on main only.

⚠ **`--group phase1` n'est PAS « le gate »** — le groupe compte **143 fichiers**, le gate en lance **24** : ce qui bloque, c'est d'être un **step nommé du job `blocking-tests`**, pas de porter l'annotation. Un fichier `phase1` non listé ci-dessous tourne quand même, mais dans `unit-tests` (`phpunit tests/`) — donc **après** `blocking-tests` et **sans gater `build-docker`** ; le job documente lui-même ce piège (`ci.yml`, commentaire P2-9ter). Conséquence pour un agent : **la liste ci-dessous se lit comme la liste des steps du job**, et toute affirmation « X est bloquant » se vérifie dans `.github/workflows/ci.yml`, jamais à l'annotation. Cas ouvert : `Security/TeamTagScopeTest` (P4-42, portée des tranches d'âge) porte `phase1` **sans être un step**. ⚠ Il **bloque quand même le merge** — `Unit Tests` fait partie des contextes requis de `main` — mais **pas `build-docker`** (qui ne dépend que de `blocking-tests` + `engine-tests`), et son verdict tombe **après** le gate. L'écart est donc de rigueur et de délai, pas un trou : décision à prendre (l'ajouter au job, ou assumer qu'il n'y est pas), cf. roadmap DOC-3.

**blocking-tests** (must pass first — 22 steps, chacun lancé avec `--group phase1`): `Unit/Entity/UserInterfaceContractTest` (axe auth : `eraseCredentials` présente sur `User` ET `SuperAdmin` — sans quoi le conteneur ne boote plus), `Security/TenantIsolationTest`, `Security/SeasonIsolationTest` (multi-season scoping + X-Season-Id validation), `Security/SeasonReadonlyTest` (archived-season writes → 409), `Security/MatchTenantIsolationTest` (match entities tenant+season scoped), `Security/TenantCacheIsolationTest`, `Queue/ConcurrentGenerationTest`, `CrossStack/ContractSchemaTest`, `Security/RlsIsolationTest` (RLS enforced at the DB), `Security/{ClubAccessTest,UserSelfOnlyTest,ImportAuthorizationTest}` (SEC-01/02/04 tenant-API lockdown), `Security/MercureHardeningTest` (SEC-05/06), `Security/ManagementRoleTest` (SEC-07 management-role gate on cockpit writes), `Security/ApiRateLimitTest` (SEC-11 per-user API throttle), `Security/SuperAdminAccessTest` (SA0 MFA/session/CSRF/admin-DB boundary), `Security/EngagedTeamGuardTest` (périmètre engagé : une équipe qui joue ne peut être ni supprimée ni changer de niveau), `Security/PeriodPlanBirthTest` (ADR-0002 amendé 2026-07-24 : le plan naît du geste **d'Adapter** — `POST /schedule_plans` ou semaine cochée au picker ; matérialiser une période ne crée rien ; la découpe supprime le plan-bloc ; `cutoff`/`mutualisation` n'en portent jamais ; l'identité d'une période à plan OU à semaines est gelée), `Security/SeasonVersionUniquenessTest` (P2-7 2026-07-30 : le socle en vigueur est unique — `POST /api/schedules` refusé en 409 tant que le plan SEASON pointe une version), `Security/SeasonPlanInForceTest` (P2-9bis 2026-07-31 : défense en profondeur de `SocleGuard::assertSeasonPlanNotChosen` sur `generate`/`regenerate`/`regenerate-from`, épinglant la propriété émergente qui tient déjà l'invariant), `Security/PeriodGatePayloadParityTest` (P2-14 2026-07-31 : le gate pré-solve valide EXACTEMENT le jeu de contraintes que le payload sérialise — parité tenue par la source unique `PeriodConstraintSelector`, plus par deux copies manuelles), `Security/RecapCapacityWarningTest` (P2-9 PR A 2026-07-31 : le volet capacité du récap dans les deux sens — nombres lus du payload du solveur, jamais recalculés à la main), `Security/CoachDoubleBookingTest` (P2-9 PR B, axe constraint semantics : un verrou HARD est pré-placé HORS du solveur — sa variable n'existe pas, donc le moteur ne PEUT pas refuser deux verrous qui dédoublent un coach ; le récap bloque la génération, chevauchement sur intervalles réels et gymnases différents seulement), `Integration/ScheduleConstraintBuilderOverlayTest` (P2-9ter : « le build n'écrit pas », « le write-path écrit vraiment », ordre des tags déterministe, un seul chemin de calcul, cache scopé par saison — ajouté au job après avoir constaté qu'il portait `phase1` sans qu'aucun step ne le lance). Detail: `docs/testing/testing-strategy.md`.

## 5. Conventions (essentials)

- **Backend:** PHPStan level 8 (Doctrine+Symfony ext) · CS-Fixer `@Symfony` + `@PHP84Migration` + risky + Yoda + strict comparisons + `fully_qualified_strict_types` avec `import_symbols` · Rector targets PHP **8.4** (aligned with composer `>=8.4`) et son style FAIT convention sur `src/` ET `tests/` — notamment `!$x instanceof Foo` plutôt que `null === $x` pour un `?Foo` (P4-24). Aucune règle n'est `withSkip` — Rector charge ses sets d'après les versions **installées**, et le stack étant homogène en 7.4, aucun set Symfony 8.0 ne s'active · **Stack Symfony sur la LTS 7.4** (bugs nov. 2028, sécurité nov. 2029) via `extra.symfony.require` — Flex filtre TOUS les splits Symfony, transitifs compris (`PackageFilter::removeLegacyPackages()` ne regarde jamais si le paquet est en require directe). ⚠ **Sa seule échappatoire est le LOCK** : un paquet déjà verrouillé en 8.0.x est exempté du filtre et n'en sort plus par mise à jour partielle — c'est ainsi que 19 paquets ont vécu en 8.0.x sous des bundles 7.4 (P4-31). **Correctif d'une dérive : `composer update <les paquets>`** — surtout PAS un pin dans `composer.json`, qui traiterait le symptôme en laissant croire que les transitifs ne sont pas couverts. Audit : `composer show "symfony/*"` (hors `*-contracts`, `flex`, `mercure`, `polyfill-*`, à versionnage propre) ; gardé par `SymfonyStackAlignmentTest` (lit l'INSTALLÉ, pas le lock) · PHPUnit runs directly via `vendor/bin/phpunit` (PHPUnit 11, the `phpunit/phpunit` dev-dep) — same binary in CI, `Makefile`, and `composer test`.
- **Engine:** ruff (line 120, py312, double quotes, LF) · mypy `strict` + `pydantic.mypy` plugin (`ortools.*` ignored) · pytest (`-ra`) + golden fixtures + invariants + hypothesis.

## 6. Critical mechanisms

- **Multi-tenant isolation** (backend): 3 layers. (1) Doctrine `TenantFilter` + `TenantFilterListener` (**priority 7, AFTER the firewall**) resolves club from `_club_id` / `X-Club-Id` / else the **authenticated JWT user's active `ClubUser` membership** (the frontend sends no header); spoofed header → 403. The listener also resolves the **season** (`X-Season-Id` validated → 403 if foreign/unknown; else calendar-derived current via `SeasonResolver`, July-15 pivot) and enables the `season_filter` (intra-club correctness boundary — see `backend/docs/TENANT.md`). (2) **PostgreSQL RLS ACTIVE**: runtime = `app_user`, `FORCE` policies on all `club_id` tables keyed on the `app.club_id` GUC (`TenantConnectionContext`; workers set it from the message `clubId`) — deux tables sont **hybrides** (`club_user`, `coach_wish_token`) : SELECT ouvert **seulement hors contexte tenant** (il faut lire la ligne AVANT de connaître le club ; GUC posé → étanche, SEC-12 2026-08-04 — lectures cross-tenant légitimes via `TenantConnectionContext::runWithoutTenant()`), écritures tenant-scopées ; migrations/ops = `clubscheduler` via the Doctrine `admin` connection (**superadmin door, bypasses RLS**). (3) Club/User (no `club_id`) scoped in their providers/processors. See `backend/docs/TENANT.md` + `docs/security/rls.md`. ⚠️ Listener before auth = historical cross-club leak (fixed — never move it back). Le listener **retourne immédiatement sur `/api/admin/**`** (SEC-17) : la console n'a pas de tenant, et son anti-spoof ne s'arme que sous identité `User`. Guarded by `TenantIsolationTest`, `TenantJwtIsolationTest`, `RlsIsolationTest`, `OnboardingFlowTest`.
- **JWT applicatif en cookie httpOnly** (SEC-16 audit, 2026-08-07) : `set_cookies` lexik + extracteur cookie — `BEARER`, `path=/api`, `SameSite=Strict`, `Secure` piloté par `JWT_COOKIE_SECURE` — jamais `$request->isSecure()` (nginx prod écoute en 80 derrière la TLS et y répond faux). ⚠ **Où la variable est posée compte** : `backend/.env` entre dans l'image de prod, donc le `false` de dev vit dans `.env.dev`/`.env.test` (exclus de l'image) et `.env.prod` + `services.yaml` disent `true` — gardé par `JwtCookieSecureDefaultTest`. `POST /api/login` rend **204 sans corps**, `register/verify` pose le même cookie via `JwtCookieFactory`, `POST /api/logout` (PUBLIC_ACCESS) l'efface — le JS ne peut pas effacer ce qu'il ne voit pas. Le Bearer reste accepté pour scripts/smokes/e2e. Le front ne garde qu'un booléen de session. Gardé par `JwtCookieContractTest` (groupe `phase1`, joué par `unit-tests` — **pas** un step du gate, cf. §4). ⚠ Piège de test : browser-kit rejoue le cookie d'une requête à l'autre → `App\Tests\StartsFreshBrowserSession` là où l'identité change. Détail : `docs/security/jwt-cookie.md`.
- **Superadmin SA0:** separate global identity (never `User`/`ClubUser`), stateful firewall `/api/admin/**`, password + mandatory TOTP, per-IP throttle, **CSRF central** (`AdminCsrfListener` : toute méthode non sûre sous `/api/admin`, exemptions = les 2 portes de connexion — plus d'opt-in par contrôleur, SEC-18), and fail-closed access audit over the Doctrine `admin` connection. A club JWT never crosses this firewall and the admin session never sets `app.club_id`. Current contract: `specs/courantes/superadmin-auth.md`; UI/metrics remain in `specs/evolution/console-superadmin.md`.
- **Public token pages** (2 routes `/api` qui lisent ET écrivent sans JWT — le token EST l'identité, 404 byte-identique, rate-limit par IP) : `PublicCoachWishController` (`GET|POST /api/coach-wishes/public/{token}`, #10 2026-07-26) et `ClubApprovalController` (`GET|POST /api/club-approvals/{token}`, P3-4 2026-08-05 — l'approbation du club par son mail institutionnel FFBB CRÉE le club via `ClubProvisioner` ; hors RLS, la table `club_creation_request` n'a pas de `club_id`). Pour coach-wish : `PublicCoachWishController` est (les autres `PUBLIC_ACCESS` : login/register/password/health/docs + logos en GET) — le `CoachWishToken` (secret EN CLAIR) porte coach + club, donc le contrôleur pose lui-même `app.club_id` (relâché en `finally`) et 404 byte-identique pour token inconnu/malformé ; saison gardée par `CoachWishSeasonGuard`, relances par `app:coach-wishes:digest`.
- **Concurrency**: backend `ClubGenerationLock` (Redis `SETEX NX` + release token); engine per-club `asyncio.Lock`. Guarded by `ConcurrentGenerationTest`.
- **Async generation**: `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler` (frozen snapshot → POST engine → import results → Mercure publish). Symfony Messenger over Redis, `messenger-worker` container.
- **Period-owned venue grid** (backend, #8, 2026-07-24): a period's `VenueTrainingSlot` rows (ancrées par `schedulePlanId`) are a **copy** of the season model taken at plan birth — the overlay build never unions with the season's own slots (`ScheduleConstraintBuilder::buildForOverlay`). Per-venue mode (`VenuePeriodOverride` : `VenuePeriodMode` `DISABLED`/`BLANK`, sparse, default = inherit) acts on that copy — même patron sparse pour `TeamPeriodOverride` et `ConstraintPeriodOverride` ; an orphaned HARD pin blocks generation with a 422 naming the venue/day (`OrphanPinGuard`). Reworking the season's chosen version now destroys the **whole plan** (not just versions) of every period-plan that has not started yet, validated or not — a period already under way survives (ADR-0002 inv. 5/14, amended).
- **Backend↔engine contract**: engine Pydantic schemas ⇄ backend payload; version in `engine/CONTRACT_VERSION` (**2.2** — UN seul contrat pour les DEUX endpoints engine). **No codegen — synced manually.** Guarded by `ContractSchemaTest` + `MatchPlacementContractSchemaTest`.
- **Placement des matchs** (P1-4 PR D, ADR-0003) : second problème engine `POST /place-matches`, rail **SYNCHRONE** (`POST /api/fixtures/place` — pas de Messenger/Mercure, verrou Redis dédié `MatchPlacementLock`) ; best-effort à poids dominant — **aucune HARD violée, le non-plaçable sort NOMMÉ** ; ancres `Fixture.placementSource` (MANUAL/déposé = FIXED, jamais bougé) ; le backend projette (ADR-0002, estimation extérieure, enveloppe ligue), l'engine reste plat. Smoke dédié : `backend/scripts/smoke-place-matches.sh`.
- **FFBB API integration** (lot C, outbound): at club creation `AuthController::verifyEmail` dispatches `PopulateClubFromFfbbMessage` (async) → `FfbbClubPopulator` fills the club + shared `FfbbLeague`/`FfbbCommittee` reference rows (no `club_id`, outside RLS, cache-first) from the public FFBB Meilisearch API, and rehosts logos. **Confined & SSRF-safe**: the two hosts (`api.ffbb.com`, `meilisearch-prod.ffbb.app`) are hard-coded in `FfbbApiClient`/`FfbbLogoFetcher` (never derived from input), the club code is format-validated, redirects disabled; best-effort (failure never breaks register). Same service backs the management-gated `POST /api/club/ffbb-import`. Routes catalogued in `backend/docs/ffbb-api.md`. The frontend never calls FFBB.
- **Solver**: CP-SAT, **no relaxation fallback** (all HARD constraints in every attempt; the objective is optimised in two lexicographic phases — placement then chaining, phase 2 capped at 10 s). Phase-1 budget = **adaptive tiers 60/180/600 s** by problem size (`n_teams×n_venues` ≤50/≤200/else), with the payload `solver_timeout_seconds` (default 650) as a **ceiling only** — never the actual budget. **`num_search_workers` is also adaptive** (`_adaptive_workers`): ≤200 complexity → 1 (deterministic, golden fixtures depend on it), else → 8 (the single worker FINDS the optimum in ~2 s on dense soft-preference problems but can't PROVE it — stalled 612 s on BCCL; the 8-worker portfolio closes the proof in ~2 s, same objective, at the cost of a non-deterministic *assignment* — the *value* stays stable). Seed from `solver_seed`. INFEASIBLE → `status="failed"` + diagnostics (see `docs/architecture/adr-0001-single-pass-solve.md`, amended 2026-07-07). Un verrou HARD reste souverain mais n'est plus silencieux (P2-9, SOLDÉ 2026-07-31) : `diagnose_locked_slot_violations` émet un diagnostic INFO `constraint_not_honored` par contrainte que l'épinglage rend inatteignable, le récap bloque un coach dédoublé par verrou (PR B/C) et annonce la capacité dans les deux sens (PR A).

## 7. Workflow rules (orchestrator)

All custom agents/skills are **manual / user-triggered**. No hidden automation, with one pre-existing exception documented in `docs/project-map.md` (the `code-review-graph` PostToolUse hook).

**Git discipline (non-negotiable).** **NEVER commit directly on `main`** — always branch first (feature/fix/**docs & specs included**), commit on the branch, open a PR. **NEVER merge a PR without the user's explicit go** — the user keeps the hand on everything that lands on `main`. Push the branch freely (no CI gating), but stop at "PR ready, waiting for your go" and never run `gh pr merge` before it. Applies to **every** change, doc-only ones too.

**Two lanes.** Pick the lane BEFORE starting and say which one applies:
- **Full lane** (default for any feature, behaviour change, API/schema change, or anything touching a structuring axis §7.1).
- **Light lane** — only if ALL true: ≤2 files, no behaviour/API/schema change, no structuring axis touched (typo, label, doc, tiny fix). Cycle: implement → relevant tests green locally → `documentation-update` → PR → user go.

**Full lane cycle:**
0. **Lire le CODE avant d'analyser (non négociable, précède tout le reste).** Tout constat sur l'existant — « ça n'existe pas », « c'est déjà fait », « ce champ vaut X », « ce fichier dit Y » — se **vérifie dans le code** (grep/read/test), jamais de mémoire ni par déduction depuis un doc. La doc et les mémoires d'agent **retardent toujours** sur le code. Coût observé du raccourci : un besoin cadré sur une prémisse fausse part à contresens et se découvre 3 rounds de revue plus tard. Corollaire : ne jamais écrire « vérifié » sur un balayage partiel, et citer `fichier:ligne` quand on affirme un fait sur l'existant.
1. **Need validation (mandatory, before any plan):** reformulate the need in 3–6 lines + open ambiguities + what I will NOT do — **each factual claim backed by the code read at step 0**. **User validates or corrects — no `/plan` before that.**
2. `/plan` injecting boundaries §2, conventions §5, scope checklist §9 (the built-in `Plan`/`Explore` subagents do **not** read `CLAUDE.md`). Optional `contrarian-review` on the plan. User validates the plan.
3. Implement **strictly in scope** (no opportunistic refactor).
4. **Non-regression (mandatory if a structuring axis §7.1 is touched):** add/extend a test guarding the axis in the same PR (`--group phase1`, engine invariant/golden, or e2e). ⚠ **Annoter `#[Group('phase1')]` ne suffit PAS à en faire un gate** (§4) : le groupe compte 143 fichiers, seuls les **steps nommés** du job `blocking-tests` bloquent — un NR annoté mais non ajouté au job tourne dans `unit-tests`, donc après le gate et sans bloquer `build-docker`. Si le NR doit gater, **ajouter son step à `ci.yml` ET sa ligne à la liste §4** dans la même PR ; sinon le dire explicitement. Le piège a déjà mordu deux fois (`ScheduleConstraintBuilderOverlayTest` P2-9ter, `TeamTagScopeTest` audit DOC-26).
5. **Tests green locally before proposing merge** — run `/validation-runner` (selects the changed zone's targeted suite + cross-zone contract test + the mandatory smoke-solver when engine/backend is touched, and justifies any suite it could not run); it must be green on blocking tests + the new NR tests + zone suite. CI is a double-check and does NOT block the merge.
6. Change summary + **`documentation-update` (mandatory, EXÉCUTÉ — avant chaque PR, les deux lanes).** La doc est **vivante** : une PR qui corrige, ajoute ou retire quelque chose a de la doc à mettre à jour **quelque part**. « Rien d'impacté » est une conclusion qu'on atteint en regardant (et en disant quels fichiers ont été regardés), jamais une hypothèse de départ. Le skill porte la règle des deux fichiers : `specs/evolution/roadmap.md` = **l'ouvert seulement**, `specs/courantes/etat-des-lieux.md` = **le livré + les décisions fermées**. Un item livré **quitte** la roadmap ; il n'y passe jamais en ✅.
7. **Revues IA : `/code-review` est SORTI du cycle systématique** (décision fondateur 2026-08-05 — coût en tokens, et les rounds introduisaient des régressions corrigées en boucle, cf. §7.2) : seul le FONDATEUR le déclenche, quand il le juge utile ; ne jamais le lancer ni le proposer comme étape. **`/security-review` RESTE systématique** dès que la PR touche auth/données/intégrations externes. Le filet automatique par PR = scanners CI zéro-token (`secrets-scan`/Gitleaks · Trivy · Semgrep · `dependency-audit` — `docs/security/scanners.md`) + tests NR (étape 4) + validation locale (étape 5). **PR doc-only** → check de complétude écrit dans la description : ce qui a bougé, où c'est atterri, la preuve que rien n'est perdu. Quand une revue A lieu : **cadrer sur le PÉRIMÈTRE DE LA PR** — un défaut réel hors diff devient une ligne de dette en roadmap, il ne se corrige pas dans la PR.
8. PR → **user's explicit go** → merge.

### 7.1 Structuring axes (closed list — NR test required when touched)

tenant isolation (filter/listener/voters) · generation pipeline (controller→messenger→engine→import→Mercure) · **constraint semantics** (a constraint entered in the UI must be honored by the solver — semantic smoke, not just COMPLETED) · planning lifecycle (le plan SEASON pointé = le calendrier de la saison ; valider/rouvrir + verrous d'édition — ADR-0002) · **périmètre engagé** (une équipe qui joue en compétition : ni suppression ni changement de niveau — ses matchs sont déposés à la fédération) · backend↔engine contract (schemas/CONTRACT_VERSION) · auth & memberships (register/login/approval/roles). Extending this list = user decision.

**Engine/backend changes — mandatory final verification:** the solver smoke-test `backend/scripts/smoke-solver.sh` drives create→generate→poll and asserts a schedule reaches `COMPLETED` (diagnostics/warnings acceptable — the point is the CP-SAT solver responded and produced a plan). It runs inside `validation-runner`. `generate-schedule-test.sh` is a *mock* (fake `curl`) and does **not** count.

### 7.2 Répondre à une revue (établi 2026-07-31 sur #339→#342, 4 PR mesurées — et la raison pour laquelle la revue systématique est sortie du cycle le 2026-08-05)

Constat fondateur : « à chaque round 1 on introduit des erreurs qu'on corrige au round 2 ». Cause mesurée : le finding est traité comme **un cas** et non comme **l'exemple d'une règle**, et le correctif change ce qu'un écran dit sans qu'on regarde qui d'autre en dépend. Sur #342, la moitié des 10 défauts du round 2 étaient nés des correctifs du round 1.

1. **Corriger la règle, pas le cas.** Avant d'éditer, écrire la règle que le finding instancie, puis chercher TOUS ses sites (grep). Un correctif qui ne vaut qu'à la ligne citée en fabrique un autre ailleurs.
2. **Suivre les consommateurs.** Changer ce qu'un écran montre oblige à revérifier ce qui en dépend : le **verdict/gate** (`useStepValidation` & co — un écran qui compte autrement que sa porte, ce sont deux vérités), l'**export** (rendu serveur : il ne connaît aucun filtre client), l'**état vide**, les **libellés**. Les pires défauts d'une revue ne sont pas dans le diff : ils sont dans ce que le diff rend faux ailleurs.
3. **Masquer n'est légitime que pour un CHOIX.** Un sélecteur n'offre que l'actif ; un **libellé** (et la valeur courante d'un formulaire d'édition) se lit toujours sur la liste complète ; un **geste correctif** reste atteignable — mais fermé au geste fautif. Et jamais masquer ce qu'un export contient : on l'annonce.
4. **Charger ≠ échouer.** `readState` a trois états (`shared/lib/readState.ts`). Replier `loading` sur `failed` fait crier « n'a pas pu être lu » en régime normal — et un bandeau d'alerte qui se déclenche à chaque ouverture n'alerte plus de rien.
5. **Chaque règle neutralisée doit faire rougir son test.** Commiter AVANT la falsification (`git checkout --` efface le non-commité en silence). Un test d'écran qui mocke le hook porteur ne garde que le câblage : extraire la règle en fonction pure, ou monter le hook sur un vrai `QueryClient` en ne mockant que la couche API (module VOISIN — le mock ESM n'intercepte pas les appels intra-module).
6. **Cadence** : round 1 automatique ; **tout round suivant exige le GO du fondateur** ; plafond 4 rounds/PR. Un défaut réel hors périmètre ne se corrige pas ici — il devient une ligne de dette en roadmap.

## 8. Documentation rules

`CLAUDE.md` = short index; `docs/` = detail; **one canonical home, no duplication**. Root `AGENTS.md` is a pointer to this file; nested `backend/AGENTS.md` & `engine/AGENTS.md` hold package-level detail. Update via the `documentation-update` skill, **exécuté avant chaque PR** (§7 étape 6). Structural decisions → ADR in `docs/architecture/adr-index.md`. Update `specs/courantes/` per the triggers in `specs/README.md`.

**Les deux fichiers de suivi (refonte 2026-07-31) — ne jamais les confondre :** `specs/evolution/roadmap.md` ne tient que **ce qui reste à faire** (bugs, évolutions, dette, parking, vision) ; `specs/courantes/etat-des-lieux.md` tient **ce qui est livré**, les **décisions fermées** (abandons tranchés — sans elles le sujet se re-pose tous les trois mois) et les traces datées. Un item livré **quitte** la roadmap et atterrit dans l'état des lieux, jamais les deux, jamais aucun. « Est-ce que X est fait ? » se répond dans l'état des lieux, pas dans la roadmap.

## 9. Scope checklist — inject verbatim into every `/plan`; the produced plan must fill these literally

- besoin reformulé et ambiguïtés identifiées avant de planifier ;
- **constats sur l'existant vérifiés DANS LE CODE, chacun cité en `fichier:ligne`** (jamais de mémoire, jamais depuis un doc — §7 étape 0) ;
- zone ou sous-projet concerné (engine / backend / frontend, etc.) ;
- dossiers autorisés et dossiers interdits pour cette feature ;
- fichiers probablement modifiés et fichiers de tests probablement modifiés ;
- documentation à mettre à jour si le plan est exécuté ;
- conditions qui exigeraient de revenir demander une validation (changement de zone, dépendance inter-zone non prévue) ;
- confirmation explicite qu'aucun refactoring hors scope n'est prévu ;
- **axes structurants (§7.1) touchés → test de non-régression prévu dans la même PR** (lequel, dans quel groupe) ;
- si la zone touche **engine ou backend**, la section vérification inclut le **smoke-test solveur** (`backend/scripts/smoke-solver.sh`, planning attendu en `COMPLETED`).

## 10. Gotchas (top)

1. Backend, engine and frontend tooling run in Docker; the host only needs Docker, Docker Compose and Make.
2. PHPUnit = `vendor/bin/phpunit` (PHPUnit 11) everywhere (CI, `Makefile`, `composer test`). ⚠️ **`make phpunit` ne lance QUE `--group phase1`** — soit **143 fichiers**, ce qui **couvre largement le gate CI** (24 fichiers en steps nommés, §4) mais **n'est pas la même chose** : le local est plus large, la CI plus sélective, et aucun des deux ne voit tout — et **`make test` que la testsuite `Unit`** — or le job CI `unit-tests` lance **`phpunit tests/`, le dossier entier**. Les testsuites déclarées (`Unit` · `Integration` = Integration+Security+Queue · `Contract` = CrossStack) ne couvrent ni `Api`, `Command`, `Double`, `EventListener`, `MessageHandler`, `OpenApi` ni `Validator` : **valider en local avec `make phpunit` seul laisse ces dossiers hors de vue** (lot C2 : des échecs y ont dormi jusqu'à la CI). **Avant de pousser, `make -C backend tests-complete`** — miroir exact de la CI, et il enchaîne lui-même `db-init-test` (les autres cibles exigent la DB de test au préalable).
3. `contracts/` and the top-level `tests/` dir are empty placeholders (cross-stack tests live in `backend/tests/`).
4. Frontend is rebuilt + **active** — indexed by the graph (only its build artifacts `dist`/`node_modules`/`storybook-static` are ignored). Tenant is resolved server-side from the JWT: the frontend sends **no** `X-Club-Id` header.

**Pointers:** `docs/project-map.md` · `docs/glossary.md` (termes & clés de payload) · `docs/testing/testing-strategy.md` · `specs/evolution/roadmap.md` (**l'ouvert** : backlog priorisé + dette + parking + vision) · `specs/courantes/etat-des-lieux.md` (**le livré** : carte des capacités + décisions fermées + traces datées) · `docs/architecture/adr-index.md` · `specs/README.md` · commandes backend : `backend/docs/commands.md` · routes FFBB : `backend/docs/ffbb-api.md` · **ops (backups `pg_dump`/restore-check + activation Sentry 3 zones)** : `docs/ops/backup-restore.md` · **stack prod (`docker-compose.prod.yml`, images immuables, INF-03)** : `docs/ops/prod-stack.md` · **déployer (tag `v*` → ghcr.io → SSH, runbook fondateur)** : `docs/ops/deploy.md` · **sécurité** : `docs/security/` (`rls.md` · `mercure.md` · `jwt-cookie.md` · `rgpd.md`) · **clés `config` d'une contrainte** : `backend/docs/constraint-config-keys.md` · docs sorties du périmètre actif : `docs/archive/`

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->
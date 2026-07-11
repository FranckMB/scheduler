# Commandes backend — référence complète

> **Tout se lance dans le container** (`docker compose exec php-fpm …`) — les cibles `make`
> le font pour toi. PHPUnit exige `APP_ENV=test` (sinon `test.service_container` introuvable).
> La base : `make help` affiche cette liste côté Makefile.

## Cibles Make (`backend/Makefile`)

| Cible | Effet |
|-------|-------|
| `make install` | `composer install` dans le container |
| `make test` | PHPStan + CS-Fixer (dry-run) + PHPUnit `--group phase1` |
| `make tests-complete` | Suites unit **et** integration complètes |
| `make phpunit` | PHPUnit `--group phase1` seul (`APP_ENV=test` injecté) |
| `make db-init-test` | Crée + migre la base de test (**pré-requis de toute suite**) |
| `make db-reset` / `make db-reset-test` | Drop + recreate + migrate (dev / test) |
| `make fixtures` | Fixtures dev + seed jours fériés/vacances — **injecte l'URL admin** (RLS : ne JAMAIS lancer `doctrine:fixtures:load` à la main, le purge silencieux casse) |
| `make phpstan` / `make cs` / `make cs-fix` / `make rector` | Analyses (cs/rector en dry-run, `cs-fix` applique) |
| `make lint` | PHPStan + CS + Rector (tout en dry-run) |
| `make migration-diff` / `make migration-migrate` | Diff / applique les migrations (connexion **admin**) |
| `make fix-perms` | Répare les droits de `var/generate` (rapports lisibles côté host) |
| `make exec` | Shell dans le container php-fpm |

## Commandes console custom (`php bin/console app:…`)

Toutes manuelles sauf mention. Détail : `ls backend/src/Command/`.

| Commande | Effet |
|----------|-------|
| `app:schedules:reconcile-stuck` | Passe en FAILED les plannings bloqués PENDING/GENERATING (crash worker / message perdu) |
| `app:constraint:export-implicit` | Exporte la config des contraintes implicites en JSON (versionnée avec le contrat) |
| `app:overlays:purge` | Supprime les versions overlay des périodes échues — manuel, jamais auto |
| `app:seasons:purge` | Supprime les saisons < N-1 (rétention : courante + précédente + futures) — manuel |
| `app:purge-orphans` | Nettoie les orphelins logiques pré-cascade (réservations orphelines, liens pendants) — manuel |
| `app:users:purge-unverified` | Supprime les comptes non vérifiés > 7 j — **auto, horaire (cron-runner)** |
| `app:periods:remind` | Emails J-14/J-7/J-3 aux gestionnaires : période sans plan overlay — n'agit jamais seul |
| `app:seasons:remind-transition` | Emails J-61/J-30/J-14 avant le pivot du 15 juillet : saison N+1 non préparée |
| `app:public-holidays:seed` / `app:public-holidays:import` | Jours fériés : seed offline (JSON embarqué) / import API etalab — idempotents |
| `app:school-holidays:seed` / `app:school-holidays:import` | Vacances scolaires : seed offline / import API Éducation nationale — idempotents |
| `app:league-windows:seed` | Catalogue des fenêtres de matchs par ligue (JSON AURA) — idempotent |
| `app:clubs:backfill-school-zone` | Déduit `Club.schoolZone` du code FFBB (dry-run sans `--apply`) |

## Commandes Doctrine utiles (rappels RLS)

| Commande | Piège |
|----------|-------|
| `dbal:run-sql "…"` | Connexion `default` = `app_user` **sous RLS sans GUC → 0 ligne sur les tables tenant**. Ops/debug : `--connection admin`. *(doctrine-bundle 3 a supprimé l'ancien alias `doctrine:query:sql`.)* |
| `doctrine:migrations:migrate` | Toujours via la connexion **admin** (les cibles make le font) |
| `doctrine:fixtures:load` | **Interdit à la main** — garde applicatif (BasketballInit) : passe par `make fixtures` |

## Scripts (`backend/scripts/`)

| Script | Effet |
|--------|-------|
| `smoke-solver.sh` | **Garde-fou solveur** : create → generate → poll, exige `COMPLETED`. Obligatoire quand engine/backend est touché (§7 CLAUDE.md) |
| `generate-schedule.sh` | Guide pratique : pilote une génération via l'API (debug du flux) |
| `onboarding-smoke.sh` | Flux club neuf : register → données minimales → generate → `COMPLETED` |

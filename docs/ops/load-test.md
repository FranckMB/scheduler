# Mesure de charge multi-club — procédure

> Livré 2026-08-13. Le harness OBSERVE, il ne tune rien : `max_concurrent_solves`, tiers de
> workers CP-SAT (1/8, contractuels pour les golden fixtures) et budgets solveur sont
> intouchables par ce rail. ⚠ **Un run LOCAL est indicatif** (courbe de forme, murs mémoire) —
> c'est le re-run sur le VPS de prod qui dimensionne (« une génération < 30 s en dev ne
> dimensionne rien », étude d'hébergement).

## Lancer

```bash
bash backend/scripts/load-test/run-load-test.sh --clubs 5          # défaut : limites mémoire de PROD
bash backend/scripts/load-test/run-load-test.sh --clubs 5 --no-limits
bash backend/scripts/load-test/run-load-test.sh --clubs 3 --rounds 2
```

Prérequis : stack dev up (`make start`), `DATABASE_ADMIN_URL` disponible (`.env`). Le script :
applique l'overlay `docker-compose.load.yml` (les 7 `mem_limit` de `docker-compose.prod.yml`),
seed N clubs jetables (`app:load-test:seed-clubs`, commande DEV-ONLY — inexistante hors env dev,
garde runtime en plus), tire N générations en rafale via `generate-schedule.sh`, échantillonne
`docker stats` + la file Redis toutes les 5 s, et écrit rapport + CSV dans **`var/load-test/`**
(non versionné).

## Lire le rapport

- **Wait = bout-en-bout − wall solveur** : c'est la file, produite par les DEUX sérialiseurs
  volontaires de la stack — un seul `messenger-worker` ET `max_concurrent_solves=1` global côté
  engine. Une attente qui croît linéairement avec la position dans la file est le comportement
  NOMINAL, pas une anomalie.
- **Peak RAM vs limite + OOMKilled** : la moitié « murs mémoire » — n'a de sens que si le host
  supporte cgroup memory (le rapport le dit ; caveat WSL2 possible).
- Statut ≠ COMPLETED ou OOMKilled=true → investiguer avant toute conclusion de capacité.

## Teardown

```bash
docker compose -f docker-compose.yml up -d     # retire l'overlay de limites
make -C backend fixtures                        # purge/re-seed si on veut effacer les clubs jetables
```

## Résultats

Synthèse datée des runs : `specs/evolution/infrastructure-hebergement.md` §Mesures. Bruts :
`var/load-test/<horodatage>/` (local).

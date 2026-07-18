# Runbook — sauvegardes, restauration & Sentry

> Prod-readiness (2026-07-18). Deux couches de protection des données + la capture d'erreurs.
> La console superadmin AFFICHE la santé (`/admin`, board « Fraîcheur ») ; ce runbook est ce
> qu'on FAIT quand ça tourne mal — à relire à froid, pas pendant l'incident.

## 1. Le modèle à deux couches

| Couche | Couvre | Où |
|---|---|---|
| **Snapshots hébergeur** (VM/disque entier) | Disque mort, VM cassée | Console de l'hébergeur (§4) |
| **Dumps `pg_dump`** pilotés par l'activité | Restauration FINE : migration ratée, mauvais club purgé, corruption logique | `app:db:backup` (job `db-backup`, catalogue SA3) |

Exclus par décision (2026-07-18) : WAL/PITR, réplication, HA — RPO = la journée d'activité en
cours, suffisant pré-commercialisation.

## 2. Les dumps

- **Cadence** : tick nocturne (01:00) qui **skippe sans activité** — zéro club = zéro dump,
  saison pleine = quotidien de fait. Signal : `club.last_activity_at` + `solver_metrics` +
  `audit_log`.
- **Emplacement** : `backend/var/backups/clubscheduler-YYYYmmdd-His-u.dump` (format custom
  `pg_dump -Fc`), **rétention 14 dumps**.
- **Off-site (optionnel mais recommandé dès la prod)** : poser `BACKUP_SYNC_COMMAND` dans
  l'env (ex. `rclone copy /app/backend/var/backups b2:clubscheduler-backups`) — exécutée après
  chaque dump, échec = warning jamais bloquant.
- **Surveillance** : ligne « Sauvegarde base de données » du board fraîcheur + alerte email
  automatique (`freshness:db-backup`) si de l'activité reste non couverte > 26 h.

Commandes utiles :

```bash
php bin/console app:db:backup            # dump si activité (le job nocturne fait pareil)
php bin/console app:db:backup --force    # dump inconditionnel (avant une migration risquée !)
php bin/console app:db:restore-check     # PREUVE que le dernier dump est restaurable
```

**Règle d'or : `app:db:backup --force` AVANT toute migration/manipulation risquée en prod.**

## 3. Restaurer

### 3a. Restauration FINE (le cas fréquent : une table/un club abîmé)

1. `php bin/console app:db:restore-check` — restaure le dernier dump dans une base jetable
   `clubscheduler_restore_<rand>` et la DÉTRUIT. Pour INSPECTER au lieu de détruire :
   restaurer à la main dans une base temporaire :

   ```bash
   psql -h postgres -U clubscheduler -d postgres -c 'CREATE DATABASE inspect_restore'
   pg_restore --no-owner --no-privileges -h postgres -U clubscheduler -d inspect_restore \
       backend/var/backups/clubscheduler-<le-plus-recent>.dump
   ```

2. Comparer/extraire ce qu'il faut (`pg_dump -t <table> inspect_restore | psql ...`, ou des
   `INSERT ... SELECT` ciblés vers la base nominale).
3. `DROP DATABASE inspect_restore` à la fin.

### 3b. Restauration TOTALE (base perdue/corrompue)

1. **Stopper l'app** (worker + php-fpm) — plus aucune écriture.
2. Recréer la base vide puis restaurer :

   ```bash
   psql -h postgres -U clubscheduler -d postgres -c 'DROP DATABASE clubscheduler'
   psql -h postgres -U clubscheduler -d postgres -c 'CREATE DATABASE clubscheduler'
   pg_restore --no-owner --no-privileges -h postgres -U clubscheduler -d clubscheduler \
       backend/var/backups/clubscheduler-<choisi>.dump
   ```

3. `php bin/console doctrine:migrations:status` — vérifier l'alignement schéma/migrations.
4. Relancer l'app, vérifier `/admin` (santé + board).
5. Si la VM entière est morte : restaurer d'abord le **snapshot hébergeur** (§4), puis
   appliquer le dump le plus récent par-dessus si plus frais que le snapshot.

## 4. Snapshots hébergeur — checklist d'activation (une fois, à la mise en prod)

- **Hetzner Cloud** : console → serveur → *Backups* → activer (7 slots glissants, ~20 % du prix
  du serveur). Optionnel : snapshot manuel avant chaque grosse opération.
- **OVH VPS** : options → *Automated Backup* (quotidien) ; **Scaleway** : *Snapshots* +
  éventuelle politique programmée.
- Tester UNE restauration de snapshot vers un serveur temporaire après l'activation — même
  règle que les dumps : non testé = inexistant.

⚠️ Les snapshots restent chez le MÊME fournisseur (compte compromis/suspendu = tout perdu) :
`BACKUP_SYNC_COMMAND` vers un bucket B2/S3 indépendant est la 3e patte du « 3-2-1 ».

## 5. Sentry — activation (le code est déjà câblé, DSN vide = inactif)

1. Créer le compte sur sentry.io (free tier) + **3 projets** : `backend` (PHP), `engine`
   (Python), `frontend` (JS).
2. Poser les DSN :
   - `.env` (backend) : `SENTRY_DSN=<dsn-php>` ;
   - `.env` (racine, engine) : `ENGINE_SENTRY_DSN=<dsn-python>` ;
   - front : `VITE_SENTRY_DSN=<dsn-js>` **au build** (`frontend/.env`), puis rebuild.
3. Vérifier : lever une erreur volontaire par zone (ex. route inexistante côté API ne suffit
   pas — un `throw` de test) → l'event apparaît dans Sentry.
4. Périmètre : **erreurs uniquement** (traces_sample_rate: 0 partout) — la perf solveur vit
   dans `solver_metrics`, pas dans un APM.

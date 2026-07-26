# Déployer ClubScheduler en production — runbook fondateur

> Écrit pour être suivi seul, étape par étape, sans connaissance Docker/GitHub
> Actions préalable. Partie 1 = une seule fois (première mise en prod).
> Partie 2 = le quotidien (déployer, vérifier, revenir en arrière).
> La stack elle-même est décrite dans [`prod-stack.md`](prod-stack.md) ;
> les backups dans [`backup-restore.md`](backup-restore.md).

## Comment ça marche (2 minutes de lecture)

- Chaque release construit **6 images Docker complètes** (code inclus) et les
  pousse sur **ghcr.io** (le registre d'images de GitHub, lié au repo, gratuit).
- La VM ne contient QUE : `docker-compose.prod.yml`, `.env.prod` (les secrets),
  le dossier `jwt/`, et les volumes de données. **Jamais le code source.**
- Déployer = la VM télécharge les images taguées `vX.Y.Z` et redémarre dessus.
  Le deploy **ré-envoie aussi `docker-compose.prod.yml` + le script** sur la VM
  à chaque passage — ne les édite jamais directement sur la VM (écrasés au
  prochain deploy) ; seul `.env.prod` appartient à la VM.
- Revenir en arrière = redéployer le tag précédent : le workflow détecte que
  ses images existent déjà sur ghcr et les **réutilise telles quelles** (jamais
  de rebuild qui écraserait l'artefact d'origine).
- Le workflow (`.github/workflows/deploy.yml`) a deux moitiés : *build-push*
  (toujours active) et *deploy SSH* (dormante tant que la variable repo
  `DEPLOY_ENABLED` n'est pas à `true` — donc rien ne casse tant que la VM
  n'existe pas).

---

## Partie 1 — Première mise en prod (une seule fois)

> Prérequis : un compte Scaleway (ou autre hébergeur de VM), le domaine choisi,
> et les accès GitHub au repo. Compter ~1 h. Chaque ⬜ est une action à toi ;
> on peut dérouler cette partie ensemble en session.

### 1.1 Créer la VM

⬜ Console Scaleway → *Instances* → créer :
- type **PLAY2-NANO/DEV1-M ou plus** (≥ 4 Go RAM — la stack est bornée à ~3,7 Go pire cas) ;
- image **Ubuntu 24.04** ;
- une IP publique (IPv4).

⬜ SSH sur la VM puis installer Docker (paquet officiel) :

```bash
curl -fsSL https://get.docker.com | sh
docker compose version   # doit afficher v2.24 ou plus (interpolation .env)
```

### 1.2 Poser les fichiers de la stack

⬜ Sur la VM :

```bash
sudo mkdir -p /srv/clubscheduler && sudo chown $USER /srv/clubscheduler
cd /srv/clubscheduler
```

⬜ Copier depuis le repo (scp ou copier-coller) :
- `docker-compose.prod.yml` (racine du repo) ;
- `.env.prod.dist` → renommé **`.env.prod`**, puis `chmod 600 .env.prod`.

⬜ Remplir **chaque CHANGEME** de `.env.prod` (le fichier se commente lui-même).
Générateurs : `openssl rand -hex 32` (secrets), `openssl rand -hex 24` (mots de
passe DB). ⚠ Répéter à la main les mots de passe dans `DATABASE_URL` /
`DATABASE_ADMIN_URL` (pas de `${}` dans ce fichier).

### 1.3 Clés JWT

⬜ Toujours dans `/srv/clubscheduler`, avec le `JWT_PASSPHRASE` posé en 1.2 :

```bash
mkdir -p jwt
openssl genpkey -algorithm RSA -aes256 -pass pass:<JWT_PASSPHRASE> -pkeyopt rsa_keygen_bits:4096 -out jwt/private.pem
openssl pkey -in jwt/private.pem -passin pass:<JWT_PASSPHRASE> -pubout -out jwt/public.pem
chown -R 1000:1000 jwt && chmod 600 jwt/private.pem && chmod 644 jwt/public.pem
```

⚠ Le `chown 1000:1000` n'est PAS optionnel : sans lui la stack démarre verte
mais **tous les logins renvoient 500**.

### 1.4 Accès ghcr.io depuis la VM

⬜ GitHub → *Settings → Developer settings → Personal access tokens →
Tokens (classic)* → générer un token **`read:packages` uniquement**, expiration 1 an.

⬜ Sur la VM (`--password-stdin` : le token ne doit jamais apparaître dans
l'historique shell ni dans la liste des process) :

```bash
echo '<le-token>' | docker login ghcr.io -u <ton-user-github> --password-stdin
history -d $(history 1 | awk '{print $1}')   # efface la ligne du token de l'historique
```

### 1.5 TLS + domaine (Caddy)

⬜ DNS : enregistrement A du domaine → IP de la VM.

⬜ Sur la VM :

```bash
sudo apt install -y caddy
sudo tee /etc/caddy/Caddyfile > /dev/null <<'EOF'
TON-DOMAINE.example.com {
    reverse_proxy 127.0.0.1:8081
}
EOF
sudo systemctl reload caddy
```

C'est tout : Caddy obtient et renouvelle le certificat Let's Encrypt seul.
(8081 = `FRONTEND_PORT` de `.env.prod`, seul port publié par la stack, sur
localhost uniquement.)

### 1.6 Armer le workflow de déploiement

⬜ GitHub → repo → *Settings → Secrets and variables → Actions* :

| Type | Nom | Valeur |
|---|---|---|
| Secret | `DEPLOY_HOST` | IP (ou domaine) de la VM |
| Secret | `DEPLOY_USER` | l'utilisateur SSH (ex. `root` ou ton user) |
| Secret | `DEPLOY_SSH_KEY` | une clé privée SSH dédiée au deploy (générer : `ssh-keygen -t ed25519 -f deploy_key`, mettre `deploy_key.pub` dans `~/.ssh/authorized_keys` de la VM, coller `deploy_key` ici) |
| Variable | `DEPLOY_ENABLED` | `true` |
| Variable | `DEPLOY_PATH` | `/srv/clubscheduler` (optionnelle, c'est le défaut) |

### 1.7 Premier déploiement

⬜ Depuis ta machine :

```bash
git tag v1.0.0 && git push origin v1.0.0
```

Suivre dans GitHub → *Actions → Deploy*. Le script distant saute le backup
pré-migration (première fois, rien à sauver), pull, démarre, migre, sonde
`/health`. À la fin :

⬜ Ouvrir `https://TON-DOMAINE` → créer TON compte (register + vérif email —
le SMTP doit donc être bon dans `MAILER_DSN`).

⬜ Vérifications finales :
- `https://TON-DOMAINE/api/health` → `{"status":"ok"}` ;
- générer un planning de test bout-en-bout ;
- backups : suivre [`backup-restore.md`](backup-restore.md) §4bis (bucket +
  `BACKUP_SYNC_COMMAND`), puis `app:db:backup --force` et vérifier le fichier
  dans le bucket ;
- Sentry : poser les 3 DSN (backup-restore.md §5) ;
- superadmin : `docker compose ... exec php-fpm php bin/console app:superadmin:create <email>`.

---

## Partie 2 — Au quotidien

### Déployer une release

```bash
git tag v1.2.0 && git push origin v1.2.0
```

Rien d'autre. Le workflow build → push → déploie → migre → sonde. Vert dans
*Actions* = en prod.

### Hotfix / déployer sans tag

```bash
make deploy                    # commit courant de main, version = sha
make deploy VERSION=v1.2.0     # re-déployer un tag existant
```

(= `gh workflow run deploy.yml`, puis suit le run en direct.)

### Revenir en arrière (rollback)

```bash
make deploy VERSION=v1.1.0     # la version d'avant — les images sont toujours sur ghcr
```

Les images v1.1.0 existent déjà sur ghcr → le workflow **saute le build** et
redéploie **exactement les artefacts qui tournaient** (pas un rebuild aux
couches de base dérivées). Marche aussi pour un hotfix sha :
`make deploy VERSION=sha-abc1234`.

⚠ Le rollback rejoue le code d'avant mais **ne dé-migre pas la base**. Si la
release fautive contenait une migration destructive : restaurer le dump pris
automatiquement AVANT la migration (`backup-restore.md` §3 — le script refuse
de migrer sans ce dump, il est fail-closed).

### Règle d'écriture des migrations (convention, à respecter dans les PRs)

Le deploy migre **avant** de basculer les conteneurs : pendant quelques
secondes l'ancien code tourne sur le nouveau schéma. Toute migration doit donc
être **rétro-compatible une release en arrière** — ajouter une colonne
nullable/DEFAULT : oui ; supprimer/renommer une colonne encore lue par la
release précédente : non (faire en deux releases : arrêter de lire, puis
supprimer).

### Vérifier l'état

- GitHub → *Actions → Deploy* : historique des déploiements (un run = un deploy).
- `https://TON-DOMAINE/health` (edge) + `/api/health` (backend).
- Board fraîcheur superadmin (backups, heartbeats).
- Sentry : erreurs runtime des 3 zones.
- Sur la VM : `docker compose -f docker-compose.prod.yml --env-file .env.prod ps`
  → tout doit être `healthy`.

### Changer un secret / une variable d'env

1. Éditer `.env.prod` sur la VM ;
2. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d`
   (sans nom de service — recrée tous les conteneurs dont l'env a changé) ;
3. cas particuliers : rotation du `JWT_PASSPHRASE` = régénérer aussi le keypair
   (§1.3) ; rotation DB = `ALTER USER` côté postgres d'abord.

### Restaurer un backup

→ [`backup-restore.md`](backup-restore.md) §3 (restore-check puis restauration réelle).

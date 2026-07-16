# Démo à distance — tunnel Cloudflare (Quick Tunnel)

> Objectif : rendre la stack locale accessible en HTTPS depuis n'importe quel navigateur,
> le temps d'une démo, sans déployer, sans ouvrir de port, sans compte Cloudflare.
> Poste de référence : Docker Desktop (WSL2) + `cloudflared` **sous PowerShell** (Windows).

## 1. Ce que ça fait

`cloudflared tunnel --url http://localhost:8081` ouvre une **connexion sortante** de ta machine
vers le réseau Cloudflare. Cloudflare te renvoie une URL publique jetable
`https://<mots-aléatoires>.trycloudflare.com` et relaie chaque requête reçue sur cette URL vers
le port local indiqué. Le trafic passe dans le tunnel déjà ouvert : **aucun port entrant n'est
ouvert** sur la box, le pare-feu ou la machine, et les ports Docker restent publiés sur
`127.0.0.1` (cf. `docker-compose.yml`) — c'est `cloudflared`, tournant en local, qui les atteint.

C'est un **Quick Tunnel** : sans compte, sans domaine, URL aléatoire à chaque lancement, durée de
vie = celle du process. `CTRL+C` → l'URL est morte dans la seconde.

## 2. Pourquoi c'est intéressant

- **Démo à distance sans déploiement** : un président de club ouvre le lien depuis son téléphone,
  chez lui, sur sa 4G. Pas de VPN, pas de « connecte-toi à mon Wi-Fi », pas de mise en prod.
- **HTTPS réel** : certificat valide côté Cloudflare → pas d'écran d'avertissement navigateur,
  et les API navigateur qui exigent un contexte sécurisé fonctionnent.
- **La stack de dev telle quelle** : les données du moment, la branche du moment. Zéro build de démo.
- **Fermeture immédiate et totale** : `CTRL+C`, plus rien n'est joignable. Pas de serveur oublié
  qui traîne en ligne.
- **Ça évite de trimballer le portable *dans la salle*** — voir la limite ci-dessous.

### La limite à connaître (correction de la note initiale)

Le tunnel **ne supprime pas la machine** : elle doit rester **allumée, réveillée, connectée**,
Docker démarré et `cloudflared` en cours d'exécution. Si le PC se met en veille, la démo tombe.

Ce que le tunnel supprime réellement : le besoin que le public soit **sur le même réseau**, et le
besoin de **brancher le portable sur l'écran de la salle**. Une démo pendant laquelle ta machine
est éteinte demande un vrai hébergement (VPS + tunnel nommé, ou déploiement) — pas un Quick Tunnel.

## 3. Le port, c'est 8081 — pas 8080 (correction de la note initiale)

| Port | Service | Ce que verrait l'invité via le tunnel |
|------|---------|----------------------------------------|
| **8081** | `frontend` (nginx) | ✅ **le SPA**, qui proxifie `/api/`, `/exports/`, `/.well-known/mercure`, `/engine/` sur la même origine |
| 8080 | `nginx` (API Symfony) | ❌ du JSON — aucune interface |

Une seule origine publique suffit donc : `8081` sert l'appli **et** relaie l'API (cf.
`docker/frontend/nginx.conf`). Rien d'autre n'est exposé — Mailpit, Postgres, Redis, le hub Mercure
restent hors tunnel.

## 4. Procédure

Prérequis (une fois) : `winget install --id Cloudflare.cloudflared` sous PowerShell.

```powershell
# 1. Stack démarrée (WSL / Docker Desktop)
docker compose up -d          # ou: make start

# 2. Vérifier depuis Windows AVANT d'ouvrir le tunnel
curl.exe http://localhost:8081/health        # -> healthy
curl.exe http://localhost:8081/api/health    # -> 200

# 3. Ouvrir le tunnel
cloudflared tunnel --url http://localhost:8081
```

`cloudflared` affiche l'URL `https://<...>.trycloudflare.com` dans un encadré : c'est elle qu'on
envoie. Fin de démo : **CTRL+C** dans la fenêtre PowerShell → l'URL ne répond plus. La stack Docker,
elle, continue de tourner en local (`docker compose down` si on veut aussi l'arrêter).

Données de démo : la base est vide par défaut, `make -C backend fixtures` seede un club complet.

## 5. Sécurité — à lire avant de partager l'URL

L'URL est aléatoire mais **il n'y a aucune authentification devant elle** : quiconque a le lien
atteint l'appli. C'est acceptable pour une démo courte et surveillée, pas pour laisser tourner.

- **Ne jamais laisser le tunnel ouvert** au-delà de la démo. Pas de tunnel « au cas où ».
- La stack de démo est la stack **de dev** : `APP_ENV=dev`, secrets par défaut du `.env`, comptes
  de fixtures à mots de passe connus (`mara.mb@bccl.fr` / `maraboubccl`). Ne jamais pointer un
  tunnel vers une base contenant de vraies données personnelles de club.
- `/engine/` est proxifié par le nginx frontend : **le solveur devient joignable publiquement et
  sans auth** pendant le tunnel. Un lien fuité = quelqu'un peut lancer des solves (CPU). Raison de
  plus pour ne pas laisser l'URL vivre.
- Le lien de vérification d'email part dans **Mailpit** (`localhost:8025`, hors tunnel) : un invité
  ne peut pas s'inscrire tout seul. Donne-lui un compte de fixtures, ou récupère le lien dans
  Mailpit et transmets-le à la main.

## 6. Limites du Quick Tunnel & alternative

- **URL différente à chaque lancement** (impossible à communiquer à l'avance ; à envoyer au moment
  de la démo).
- **Aucune garantie de dispo** : c'est un service gratuit best-effort, Cloudflare peut throttler.
- **Pas de reprise** : si le process meurt, la nouvelle URL est différente.
- Si un jour on veut une **URL stable** (`demo.exemple.fr`) : tunnel *nommé* (`cloudflared tunnel
  create` + `cloudflared tunnel route dns`), qui exige un compte Cloudflare et un domaine sur
  Cloudflare — et alors il faut mettre une auth devant (Cloudflare Access) et une stack non-dev.

## 7. Point d'attention si le code évolue

Le front **poll** aujourd'hui le statut de génération — il n'ouvre pas de `EventSource` vers Mercure
(cf. `frontend/src/features/planning/queries.ts`). Le jour où un abonné SSE navigateur arrive, la
directive `cors_origins` du service `mercure` (`docker-compose.yml`, limitée à `localhost:5173` /
`localhost:8081`) ne couvrira pas l'origine `*.trycloudflare.com` : la démo cassera sur le temps réel.

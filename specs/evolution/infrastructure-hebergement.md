# Étude d'hébergement & infrastructure — ClubScheduler

> **Statut** : **étude / aide à la décision** (2026-07-10, revue 2026-07-17). Pas un plan d'implémentation. But : comparer les options d'hébergement, chiffrer, et **recommander** une architecture pour la commercialisation (mi-2027).
> **Périmètre** : **technique** uniquement. L'angle économique (coût par club, arbitrage managé vs temps fondateur, RGPD comme argument de vente) vit dans `business/hebergement-couts.md` — *dossier local, non versionné* (`.gitignore`).
> **Nature** : SaaS multi-tenant (clubs de basket FFBB, marché **français**) → contraintes **RGPD / localisation UE**, données critiques (plannings, contacts), charge **CPU-bursty** (solveur CP-SAT).
> **Prérequis liés** (prod-readiness) : **backups PostgreSQL** et **observabilité** (Sentry/health/alerting) **livrés côté code le 2026-07-18** (`docs/ops/backup-restore.md`) — reste l'activation OPS (snapshots hébergeur, DSN Sentry, `BACKUP_SYNC_COMMAND` off-site) à la mise en prod, cf. §6. **Config prod distincte reste ⬜** (P0-2, `specs/evolution/roadmap.md` §Backlog).

---

## 1. Ce qu'il faut héberger (empreinte réelle)

| Composant | Profil | Contrainte infra |
|---|---|---|
| **PostgreSQL 16** | Stateful, **donnée critique** | Backups + PITR, RGPD UE, dispo. **Le point le plus important.** |
| **Redis 7** | Semi-durable (file Messenger, locks, cache) | Persistance souhaitable (une perte de file = générations perdues, tolérable) |
| **engine** (FastAPI + OR-Tools CP-SAT) | **CPU-bound, bursty** (1→8 workers, jusqu'à 600 s/solve) | Cœurs CPU réels pendant la génération ; scale horizontal utile |
| **php-fpm + nginx** | Web API stateless | Scale horizontal facile |
| **messenger-worker / pdf-worker** | Async, stateless | Scale selon la charge de génération/export |
| **cron-runner** | Tâches planifiées | 1 instance (pas de parallélisme) |
| **mercure** | Hub SSE, **connexions longues** | Sticky/keep-alive ; 1 hub suffit longtemps |
| **frontend** | Build statique (`dist`) | Servi par nginx / CDN |
| ~~mailpit~~ | Dev only | En prod : **SMTP tiers** (Brevo/Mailgun/SES) |

**Caractéristiques structurantes** : (1) une **base critique** qui exige des backups sérieux ; (2) un **solveur CPU-bursty** (pics courts et intenses, sinon quasi-idle) — mauvais candidat au « toujours-allumé surdimensionné », bon candidat au **scale-to-load** ; (3) le reste = stateless classique.

### 1.1 Profil de charge réel — trois faits qui dimensionnent (relevé 2026-07-17)

- **La charge est saisonnière, pas moyenne.** Les clubs génèrent **tous dans la même fenêtre** (août/septembre, avant la reprise), et le work-loop est itératif (ajuster → régénérer), pas one-shot. Dimensionner sur une moyenne annuelle ou sur « 2-3 utilisateurs simultanés » est un contresens : **le pic de septembre est la seule unité pertinente**. Le profil exact (générations par club, fenêtre, tolérance à l'attente) est une **inconnue à collecter auprès des clubs**, pas à benchmarker.
- **Le débit global de génération est aujourd'hui de 1 à la fois** — non pas à cause du `ClubGenerationLock` (qui, lui, est *par club*), mais parce qu'il n'existe **qu'un seul conteneur `messenger-worker`** exécutant un unique `messenger:consume`. Au pic, la file sérialise tous les clubs : *N* générations en attente × jusqu'à 600 s. **Le premier levier de débit n'est donc pas un serveur plus gros : c'est un nombre de conteneurs worker** — à condition d'avoir les cœurs réels pour les servir (8 workers CP-SAT par solve sur le tier haut).
- **Le worst-case, pas le p50.** Le budget adaptatif du tier des gros clubs (complexité `n_teams × n_venues` > 200 — p. ex. 40 équipes × 9 gymnases = 360) est de **600 s à 8 workers**. Une génération « en moins de 30 s » observée en dev ne dimensionne rien.

> **Ne pas re-benchmarker `num_search_workers`** : le choix est déjà le résultat d'une mesure (ADR-0001, amendé 2026-07-07 — 1 worker stalle 612 s sur BCCL là où le portefeuille 8 workers prouve l'optimum en ~2 s). Les tiers actuels (`_adaptive_workers` : ≤200 → 1, sinon 8) sont **contractuels pour les golden fixtures**, qui dépendent du déterminisme à 1 worker.

## 2. Les options d'architecture

### Option A — VPS unique, `docker compose` (lift-and-shift)
Le `docker-compose.yml` actuel sur **un seul serveur** (Hetzner/OVH/Scaleway).
- **Forces** : migration **quasi nulle** (déjà en compose), coût **plancher**, contrôle total, un seul lieu.
- **Faiblesses** : **SPOF** (un serveur = tout tombe), backups/patchs **à ta charge**, pas de scale du solveur, Postgres **auto-géré** (risque #1 : sauvegardes mal faites = perte de données). Pas de HA.
- **Coût** : **~15–40 €/mois** (1 VPS 4–8 vCPU / 8–16 Go).

### Option B — VPS applicatif + **PostgreSQL managé** (hybride, recommandé au lancement)
App en compose sur un VPS ; **base déléguée** à un Postgres managé UE (backups/PITR/patchs inclus).
- **Forces** : **résout le point #1** (backups/PITR gérés) sans usine à gaz ; coût maîtrisé ; migration faible ; le VPS reste jetable/reconstruisible.
- **Faiblesses** : SPOF applicatif subsiste (mais l'app est stateless → redéployable vite) ; deux fournisseurs à gérer.
- **Coût** : **~40–90 €/mois** (VPS 4–8 vCPU + Postgres managé 2 vCPU/4–8 Go + backups).
- 🔴 **Pré-requis bloquant à vérifier avant de s'engager** (relevé 2026-07-17) : la connexion `admin` (`clubscheduler`) bypasse RLS **parce qu'elle est superuser** — sous `FORCE ROW LEVEL SECURITY`, le propriétaire de la table ne bypasse pas (cf. `docs/security/rls.md`, `backend/docs/RLS.md`). Or **un Postgres managé n'accorde jamais le superuser**. Il faut donc confirmer auprès du provider qu'on peut obtenir un rôle portant l'attribut **`BYPASSRLS`** (lui-même non grantable sans superuser). Sans ça : **migrations, `make fixtures` et la console superadmin cassent** sur un managé. Option A n'a pas ce problème (Postgres auto-géré = superuser disponible). **À trancher avant tout engagement contractuel** — c'est le point qui peut invalider la recommandation §5.

### Option C — PaaS conteneurs (Clever Cloud 🇫🇷, Scaleway Containers, Render/Railway)
Chaque service déployé comme app managée, scale automatique, Postgres/Redis add-ons managés.
- **Forces** : **zéro gestion d'OS**, backups/scaling/TLS/observabilité **inclus**, **scale-to-load du solveur** (idéal pour le CPU-bursty), déploiement Git. **Clever Cloud = 🇫🇷, RGPD natif.**
- **Faiblesses** : **coût/unité plus élevé**, moins de contrôle bas-niveau, le modèle multi-conteneurs (engine + workers + hub SSE) peut multiplier les « apps » facturées ; le SSE longue-durée (Mercure) demande une offre compatible.
- **Coût** : **~120–300 €/mois** au lancement (plusieurs apps + add-ons), **croît proprement** avec la charge.

### Option D — Kubernetes managé (Scaleway Kapsule, OVH, GKE/EKS UE)
Orchestration complète, HA, autoscaling.
- **Forces** : HA réelle, autoscaling fin (solveur ↔ charge), standard industrie, portable.
- **Faiblesses** : **surdimensionné avant plusieurs centaines de clubs** ; complexité d'exploitation (compétence K8s requise), coût de base non négligeable, temps d'ingénierie détourné du produit.
- **Coût** : **~150–400 €/mois** plancher (control-plane + 2–3 nodes + managed DB), avant même la charge.

### Option E — Serverless / hybride (out of scope au lancement)
Front sur CDN + API en conteneurs scale-to-zero + solveur en jobs à la demande. Séduisant pour le bursty, mais **cold starts** (OR-Tools lourd), refonte non triviale. À reconsidérer à grande échelle.

## 3. Focus données (le vrai sujet)

Le risque produit #1 n'est pas le CPU, c'est **la perte de données** (aujourd'hui **aucun backup** en place). Quelle que soit l'option, **un PostgreSQL managé UE avec PITR** (point-in-time recovery) est **non négociable** avant la commercialisation. C'est ce qui fait pencher vers **B ou C** plutôt que A (auto-géré). Ordre de grandeur : un managé 🇫🇷/UE = **~20–50 €/mois** pour la taille du lancement, backups inclus — l'assurance la moins chère du projet.

## 4. Coûts comparés (ordres de grandeur, UE, hors trafic)

| Palier | A (VPS seul) | B (VPS + PG managé) | C (PaaS) | D (K8s) |
|---|---|---|---|---|
| **Lancement** (≤ ~30 clubs) | **140–170 €** | **150–180 €** | 120–300 € | 150–400 € |
| **Croissance** (~100–300 clubs) | risqué (SPOF/scale) | 200–350 € | 250–600 € | 300–700 € |
| **Échelle** (500+ clubs) | inadapté | migrer | scale propre | scale propre |

> ⚠️ **Chiffres A et B révisés le 2026-07-17** (les précédents — 15-40 € / 40-90 € — supposaient du **vCPU partagé** et une grille Hetzner antérieure). Deux causes : (1) le solveur exige du **vCPU dédié** (§6), dont le prix est sans commune mesure avec l'entrée de gamme mutualisée ; (2) **Hetzner a augmenté les gammes CCX de ×2,1 à ×2,7 le 15 juin 2026**. Conséquence : **l'écart de prix entre A et B se réduit à ~11 €/mois** (un Postgres managé d'entrée de gamme suffit — la base n'est pas le goulot d'étranglement, le solveur l'est), ce qui **renforce** la recommandation B du §5. Détail chiffré et sources : `business/hebergement-couts.md` (local, non versionné).

> Providers UE/🇫🇷 pertinents (RGPD) : **Scaleway** & **OVHcloud** & **Clever Cloud** (🇫🇷), **Hetzner** (DE), Postgres managé chez Scaleway/OVH/Clever/Aiven-UE. Éviter les régions US par défaut (RGPD/transfert). **L'avantage tarifaire historique de Hetzner ne vaut plus sur le vCPU dédié** depuis le 15/06/2026 (~20 €/mois d'écart avec Scaleway) : le surcoût d'un hébergement 🇫🇷 est devenu marginal.

## 5. Recommandation (phasée)

- **Lancement (mi-2027) → Option B.** VPS UE (Scaleway/OVH/Hetzner, 4–8 vCPU **dédiés** — cf. §6) faisant tourner le `docker compose` **moins la base**, + **PostgreSQL managé UE avec PITR** (gamme d'entrée suffisante). Migration minime, backups **résolus**, coût **~150–180 €/mois** (révisé le 2026-07-17, cf. §4). Le solveur bursty tient sur les cœurs du VPS au lancement (peu de générations concurrentes). Ajouter : **SMTP tiers**, **Sentry**, un **health/uptime** externe, secrets hors repo, CI/CD de déploiement.
- **Croissance → sortir le solveur du VPS** en premier (c'est lui qui sature en pic) : soit un 2ᵉ VPS worker, soit basculer **engine + workers vers un PaaS scale-to-load** (Option C partielle) pendant que web+DB restent stables. Hybride assumé.
- **Échelle (500+ clubs) → PaaS complet (C) ou K8s (D)** si la charge et l'équipe le justifient. **Ne pas** commencer par là : c'est du temps d'ingénierie volé au produit avant d'en avoir le besoin.

**Principe directeur** : *managed pour l'état (DB), jetable pour le calcul (app/solveur), simple d'abord.* On paie pour ne PAS gérer les backups ; on reste simple partout ailleurs tant que la charge ne l'exige pas.

## 6. Points de vigilance (indépendants de l'option)

- **RGPD / localisation** : héberger en **UE** (idéalement 🇫🇷), DPA signé avec le provider, pas de région US par défaut. Croise les données personnelles (contacts club/coach, lot B/C — dont des **licenciés mineurs**). **Cloudflare est un sous-traitant américain** (le trafic transite par ses PoP) : à inscrire au registre et à évaluer, indépendamment du provider retenu. Arbitrage 🇫🇷-vs-UE (Hetzner = 🇩🇪) : décision **commerciale** (discours de vente vs prix), pas technique — donc hors de cette étude.
- **vCPU dédié, pas partagé** : CP-SAT est CPU-bound et sature jusqu'à 8 cœurs pendant un solve. Sur du vCPU mutualisé (gammes d'entrée type Hetzner CX/CPX, Scaleway DEV), le *CPU steal* fait varier les temps de solve sur un budget déjà fixé à 600 s. Les prix « plancher » des comparatifs §4 sont ceux du **partagé** ; le dédié équivalent coûte ~2×. Comparer à gamme égale.
- **Le `pdf-worker` est un Chrome** (Puppeteer, ~0,5–1 Go RAM par instance) colocalisé avec le solveur : à compter dans la RAM, surtout au pic de septembre où exports et générations se disputent la machine.
- **Exports PDF sur disque local** (`backend/public/exports`) : ne survivent pas à un redéploiement et croissent sans borne → **stockage objet** à prévoir avec le reste.
- **Backups testés** : `app:db:restore-check` livré (2026-07-18) — restaure le dernier dump dans une base jetable et le prouve, cf. `docs/ops/backup-restore.md`. Reste à **mesurer le RTO** en conditions réelles sur l'infra retenue (temps de reconstruction depuis un backup nu) : la saisonnalité rend la HA prématurée mais le RTO critique — une panne d'un jour en février ne se voit pas, la même en septembre tue la saison.
- **Secrets** : clés JWT/Mercure/DB hors repo (déjà tracké par l'audit A15) → gestionnaire de secrets du provider ou variables d'env chiffrées.
- **Observabilité** : Sentry + health-checks + alerting **livrés côté code** (2026-07-18, cf. `docs/ops/backup-restore.md` §5) — reste à poser les DSN et activer à la mise en prod. La console superadmin les *affiche*, ne les remplace pas — cf. `console-superadmin.md`.
- **CI/CD de déploiement** : build image → push registry → deploy (aujourd'hui `build-docker` en CI ; il manque le *deploy*).
- **Coûts trafic/SSE** : Mercure = connexions longues ; vérifier que l'offre ne facture pas au vilain (concurrence de connexions).

## 7. Ce que ce fichier n'engage pas

Aucun choix de provider définitif ni migration. But : cadrer la décision, chiffrer les ordres de grandeur, et **recommander B au lancement** (managed DB + VPS app), avec une trajectoire claire vers le PaaS/K8s **quand** la charge le justifie — pas avant.

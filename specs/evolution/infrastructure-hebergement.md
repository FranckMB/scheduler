# Étude d'hébergement & infrastructure — ClubScheduler

> **Statut** : **étude / aide à la décision** (2026-07-10). Pas un plan d'implémentation. But : comparer les options d'hébergement, chiffrer, et **recommander** une architecture pour la commercialisation (mi-2027).
> **Nature** : SaaS multi-tenant (clubs de basket FFBB, marché **français**) → contraintes **RGPD / localisation UE**, données critiques (plannings, contacts), charge **CPU-bursty** (solveur CP-SAT).
> **Prérequis liés** (prod-readiness, aujourd'hui absents) : **backups PostgreSQL**, observabilité (Sentry/health/alerting), config prod distincte. Le choix d'infra doit **résoudre le backup en priorité** (cf. `docs/technical-debt.md`).

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
| **Lancement** (≤ ~30 clubs) | 15–40 € | **40–90 €** | 120–300 € | 150–400 € |
| **Croissance** (~100–300 clubs) | risqué (SPOF/scale) | 90–200 € | 250–600 € | 300–700 € |
| **Échelle** (500+ clubs) | inadapté | migrer | scale propre | scale propre |

> Providers UE/🇫🇷 pertinents (RGPD) : **Hetzner** (moins cher, DE), **Scaleway** & **OVHcloud** & **Clever Cloud** (🇫🇷), Postgres managé chez Scaleway/OVH/Clever/Aiven-UE. Éviter les régions US par défaut (RGPD/transfert).

## 5. Recommandation (phasée)

- **Lancement (mi-2027) → Option B.** VPS UE (Hetzner/Scaleway/OVH, 4–8 vCPU) faisant tourner le `docker compose` **moins la base**, + **PostgreSQL managé UE avec PITR**. Migration minime, backups **résolus**, coût **~50–80 €/mois**. Le solveur bursty tient sur les cœurs du VPS au lancement (peu de générations concurrentes). Ajouter : **SMTP tiers**, **Sentry**, un **health/uptime** externe, secrets hors repo, CI/CD de déploiement.
- **Croissance → sortir le solveur du VPS** en premier (c'est lui qui sature en pic) : soit un 2ᵉ VPS worker, soit basculer **engine + workers vers un PaaS scale-to-load** (Option C partielle) pendant que web+DB restent stables. Hybride assumé.
- **Échelle (500+ clubs) → PaaS complet (C) ou K8s (D)** si la charge et l'équipe le justifient. **Ne pas** commencer par là : c'est du temps d'ingénierie volé au produit avant d'en avoir le besoin.

**Principe directeur** : *managed pour l'état (DB), jetable pour le calcul (app/solveur), simple d'abord.* On paie pour ne PAS gérer les backups ; on reste simple partout ailleurs tant que la charge ne l'exige pas.

## 6. Points de vigilance (indépendants de l'option)

- **RGPD / localisation** : héberger en **UE** (idéalement 🇫🇷), DPA signé avec le provider, pas de région US par défaut. Croise les données personnelles (contacts club/coach, lot B/C).
- **Backups testés** : un backup non restauré n'existe pas → répéter une **restauration** régulièrement.
- **Secrets** : clés JWT/Mercure/DB hors repo (déjà tracké par l'audit A15) → gestionnaire de secrets du provider ou variables d'env chiffrées.
- **Observabilité** : Sentry + health-checks + alerting **avant** la vente (la console superadmin les *affiche*, ne les remplace pas — cf. `console-superadmin.md`).
- **CI/CD de déploiement** : build image → push registry → deploy (aujourd'hui `build-docker` en CI ; il manque le *deploy*).
- **Coûts trafic/SSE** : Mercure = connexions longues ; vérifier que l'offre ne facture pas au vilain (concurrence de connexions).

## 7. Ce que ce fichier n'engage pas

Aucun choix de provider définitif ni migration. But : cadrer la décision, chiffrer les ordres de grandeur, et **recommander B au lancement** (managed DB + VPS app), avec une trajectoire claire vers le PaaS/K8s **quand** la charge le justifie — pas avant.

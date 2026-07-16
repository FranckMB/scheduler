# Console super-admin (monitoring, exploitation & data ops) — spécification

> **Statut** : **SA0, SA1, console read-only SA2 et socle jobs SA3-A livrés** (2026-07-16) — vérité courante dans [`../courantes/superadmin-auth.md`](../courantes/superadmin-auth.md). Suite : solde SA3 → SA5.
> **Nature** : l'écran d'exploitation transverse (cross-tenant **par conception**) pour piloter le SaaS — santé, usage, conversion, support, **mise à jour automatique des données de référence**. Surface la **plus sensible** du produit (elle voit tous les clubs) → **sécurité d'abord**.
> **Rattachement roadmap** : `roadmap.md` §9 (transverse/observabilité) — s'appuie sur `solver_metrics` (§9, à persister) + audit trail (§9) + rétention/purge (§3).
> **Réutilise l'existant** : connexion Doctrine **`admin`** (`clubscheduler`, bypass RLS — déjà la porte superadmin) · conteneur **`cron-runner`** (déjà là) · commandes CLI déjà écrites (`app:seasons:purge`, `PurgeUnverifiedUsersCommand`, `ReconcileStuckSchedulesCommand`, `Import{School,Public}HolidaysCommand`, `PeriodReminderCommand`, `TransitionReminderCommand`) · métriques calculées par `SolverMetricsMapper` puis persistées par SA1 dans `solver_metrics` · route lot C `POST /api/club/ffbb-import` (refresh FFBB) · champs freemium `Club.planId`/`billingCycle`/`generationCountSeason`.

---

## 1. Le besoin (reformulé, validé)

« Je veux une **interface super-admin** pour administrer l'application et les clubs, avec des **métriques** et la **mise à jour automatique des données** (vacances scolaires, clubs FFBB…). »

Trois usages :
- **Santé** : l'app tourne-t-elle bien ? (engine, worker, DB, Redis, files Messenger, erreurs)
- **Usage & business** : combien de clubs, quelle activité, quelle **conversion** Découverte→payant, quelle charge solveur ?
- **Support & data ops** : diagnostiquer/aider un club, et **tenir à jour les données de référence** (vacances, ligues/comités/clubs FFBB) automatiquement, avec supervision.

## 2. Décisions socle (verrouillées)

| # | Décision |
|---|---|
| 1 | **Qui = compte superadmin SÉPARÉ + MFA.** Un compte distinct des `User`/`ClubUser`, **hors multi-tenant**, MFA obligatoire. Jamais un simple flag sur un compte gestionnaire (pas de mélange god-mode / usage normal). |
| 2 | **SA0, SA1, SA2 et le socle SA3-A sont livrés ; la suite solde SA3** (planification, API/UI et relances). |
| 3 | **Read-only d'abord.** La 1re version **monitore** et **supervise les jobs** ; **aucune action mutante** sur les clubs au départ. Les actions support (SA4) et l'impersonation (SA5) viennent après durcissement. |
| 4 | **Sécurité = priorité absolue** (surface cross-tenant assumée, bypass RLS via `admin`). Firewall dédié `/admin/**`, **chaque accès et action audité**, périmètre minimal, jamais exposée au réseau public sans garde. Croise A15/A16/A17. |

## 3. Découpage en lots (ordre imposé : SA0 → SA5)

### SA0 — Socle sécurité & auth superadmin *(backend ✅ · frontend ✅)*
Livré : identité distincte hors tenant, session séparée mot de passe + TOTP, firewall `/api/admin/**`, rate limit, CSRF, audit fail-closed et NR `phase1`, plus feature React lazy sous `/admin` (login, TOTP, hydratation de session, logout et layout dédié). Le frontend n'utilise pas le JWT/store club. Voir [`../courantes/superadmin-auth.md`](../courantes/superadmin-auth.md).

### SA1 — Capture des métriques *(plomberie ✅)*
Livré : persistance `solver_metrics` à chaque tentative de génération (statut, durée, taille, conflits, score, version), RLS tenant-scoped, et `Club.lastActivityAt` alimenté par activité authentifiée + génération mise en file. Le partitionnement mensuel et la purge six mois restent à rattacher aux jobs d'exploitation ; l'audit des accès admin est déjà livré en SA0.

### SA2 — Console read-only (monitoring) *(🟢 lecture seule)*
- **Livré côté API** : parc global, activité 7/30 jours, nouveaux/semaine, charge solveur
  sur 30 jours et liste paginée/recherchable des clubs avec saison, volumétrie et métriques.
- **Livré côté API santé** : engine, heartbeat worker, Redis, DB, Mercure ; **file Messenger** (backlog, échecs, retries du jour). Sentry reste hors périmètre tant qu'il n'est pas branché.
- **Livré côté React** : vue `/admin` des agrégats parc/solveur, sondes et files,
  recherche/pagination des clubs, rafraîchissement périodique et états partiels erreur/vide.
  L'écran ne contient aucune mutation.

### SA3 — Jobs de données auto + supervision *(🟡 — répond au besoin « mise à jour auto »)*
- **Livré SA3-A** : historique global restreint `admin_job_run`, catalogue fermé,
  wrapper `app:jobs:run`, verrou anti-chevauchement et instrumentation des huit jobs
  horaires existants. Aucun output ou message d'erreur métier n'est persisté.
- **Planifier** les commandes existantes sur `cron-runner` : **vacances scolaires/publiques** (annuel), **purges** (saisons N-2, users non vérifiés, orphelins), **rappels** (périodes, transition), **reconcile** stuck schedules.
- **Vue superadmin des jobs** : dernier run, statut (OK/échec), **prochain run**, **re-trigger manuel** (non destructif).
- **Refresh FFBB club** (route lot C déjà prête) : à la demande sur un club, ou **batch** (rafraîchir les ligues/comités périmés).

### SA4 — Actions support *(🟠 durci, chaque action confirmée + auditée)*
Reset quota Découverte · purge saison (`app:seasons:purge`) · reset club (`ResetSeasonController` élargi) · **suspendre/désactiver** un club · **approuver** un gestionnaire en fallback (cf. [`enregistrement-ffbb.md`](enregistrement-ffbb.md)).

### SA5 — Impersonation support *(🔴 le plus sensible, en dernier)*
Se mettre à la place d'un club — **lecture d'abord**, bornée dans le temps, **bannière visible**, **tout audité**. Écriture éventuelle = décision ultérieure séparée.

## 4. Fonctionnalités intéressantes (au-delà de l'évident)

- **Clubs « chauds » pour la vente** : quota Découverte épuisé **+** activité récente → liste de prospects (conversion Découverte→payant). *Business.*
- **Rétention par cohorte** : % de clubs encore actifs N semaines après inscription.
- **Alerting** : backlog Messenger, taux d'INFEASIBLE qui grimpe, engine down → notification (email/Slack).
- **Data-freshness board** : date de dernière MAJ des vacances / ligues / comités / clubs FFBB → **signale le périmé** (rend le besoin « mise à jour auto » visible et redevable).
- **Kill switch génération** (mode maintenance) : suspendre globalement les générations pendant un incident.
- **Coûts d'infra projetés à N clubs** : extrapolation charge solveur / ressources.
- **Audit viewer** : qui a fait quoi — en particulier les actions **superadmin** elles-mêmes.

## 5. Prérequis amont (hors console, mais bloquants pour sa valeur)

- **Observabilité** (Sentry, health-checks, backups PostgreSQL) : aujourd'hui **absents** (prod-readiness). La console les *affiche*, ne les remplace pas.
- **Persistance `solver_metrics` + audit trail** : = SA1 (sans quoi SA2 est vide).

## 6. Questions ouvertes (à trancher au `/plan` de chaque lot)

1. **Granularité/rétention de l'audit viewer SA2** : SA0 capture déjà acteur, route, méthode, statut et date dans `admin_audit_log`; durée de conservation et filtres UI restent à décider.

Décisions désormais prises : console React intégrée sous `/admin` ; API sous `/api/admin` ; MFA TOTP ; authentification admin par session séparée du JWT club.

## 7. Ce que ce fichier engage / n'engage pas

**Engage** : le périmètre (santé / usage / support / data ops), les 4 décisions socle (§2), l'ordre des lots (§3), React intégré, TOTP et la plomberie métriques SA1. **N'engage pas** : l'architecture détaillée de SA2 à SA5, tranchée au `/plan` de chaque lot.

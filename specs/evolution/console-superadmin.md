# Console super-admin (monitoring, exploitation & data ops) — spécification

> **Statut** : **SA0 à SA3 livrés** (2026-07-16) + **SA2-stats** et **SA4 v1 (catalogue d'actions)** livrés (2026-07-18) — vérité courante dans [`../courantes/superadmin-auth.md`](../courantes/superadmin-auth.md). Suite : SA4 v2 (suspension/approbation, au premier cas réel) → SA5.
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
| 2 | **SA0, SA1, SA2 et SA3-A/B/C/D sont livrés ; la suite commence par SA4.** |
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

### SA3 — Jobs de données auto + supervision *(✅ — répond au besoin « mise à jour auto »)*
- **Livré SA3-A** : historique global restreint `admin_job_run`, catalogue fermé,
  wrapper `app:jobs:run`, verrou anti-chevauchement et instrumentation des jobs
  horaires existants. Aucun output ou message d'erreur métier n'est persisté.
- **Livré SA3-B** : `GET /api/admin/jobs` et panneau React read-only du catalogue avec
  cadence déclarée, dernier run, statut, origine, durée et code de sortie. Le prochain run
  était différé tant que la boucle `sleep 3600` ne fournissait pas d'échéance fiable.
- **Livré SA3-C** : tick minute, horaires fermés `Europe/Paris`, rattrapage après arrêt,
  unicité par créneau, prochain passage API/React et deux imports trimestriels. Les purges
  d'orphelins restent manuelles conformément au périmètre validé.
- **Livré SA3-D** : relance synchrone, confirmée, CSRF et auditée des seuls imports
  idempotents vacances scolaires et jours fériés. Le catalogue reste fermé ; purges,
  rappels et réconciliation sont explicitement exclus de l'action React.
- **Refresh FFBB club** (route lot C déjà prête) : à la demande sur un club, ou **batch** (rafraîchir les ligues/comités périmés).

### SA4 — Actions support *(🟠 durci, chaque action confirmée + auditée)*
Reset quota Découverte · purge saison (`app:seasons:purge`) · reset club (`ResetSeasonController` élargi) · **suspendre/désactiver** un club · **approuver** un gestionnaire en fallback (cf. [`enregistrement-ffbb.md`](enregistrement-ffbb.md)).

### SA5 — Impersonation support *(🔴 le plus sensible, en dernier)*
Se mettre à la place d'un club — **lecture d'abord**, bornée dans le temps, **bannière visible**, **tout audité**. Écriture éventuelle = décision ultérieure séparée.

### SA2-stats — stats d'usage *(✅ livré 2026-07-18)*

Répond au besoin fondateur « l'app est-elle utilisée, à quel volume ? » :
- **Télémétrie append-only (décision fondateur 2026-07-18)** : `solver_metrics` n'est plus purgée
  ni avec les versions supprimées (validation, inv. 1) ni au reset de saison — l'historique des
  TENTATIVES est la stat d'usage. Seule porte de sortie : l'effacement RGPD du club
  (`ErasedClubPurger`). Dimensions dénormalisées à la capture : `plan_type`, `nb_teams`,
  `nb_venues` (lisibles après la mort de la version/du plan).
- **`schedule_plan.first_chosen_at`** : posé UNE fois par `choose()` (1re validation, jamais
  effacé) → stat « temps de clôture » (création du plan → 1re validation).
- **Overview `/api/admin/overview` + section React « Usage produit »** : plans par type (dont
  validés), temps de clôture p50/p95 (saison vs périodes), charge solveur 30 j par type de plan,
  distribution des tailles de clubs (tranches d'équipes actives + gymnases médians).

### SA4 v1 — catalogue d'actions support *(✅ livré 2026-07-18 : socle + 3 actions)*

Le levier « agir sans développer la fonctionnalité » : catalogue FERMÉ (`AdminActionCatalog`)
d'actions sur UN club, paramétrées par le seul `clubId` (jamais d'argument libre), réutilisant
toute la plomberie SA3 (verrou, historique `admin_job_run` — désormais avec `arguments` —,
CSRF, audit SA0). Manuel uniquement, jamais schedulé (`AdminJobSchedule::manual()` lève si
atteint par le scheduler). Console : bouton « Actions » par ligne club ; action *dangereuse* →
**confirmation nominative** (taper le nom du club). NR `phase1` : `AdminClubActionTest`
(firewall, CSRF, catalogue/club bornés, trace arguments, commandes, chemin destructif réel,
contrat catalogue↔commandes).
**⚠️ Exception SA0 assumée (revue SA4)** : l'invariant « la session admin ne pose jamais
`app.club_id` » connaît UNE exception délibérée — `app:clubs:reset-season` s'exécute
in-process dans la requête admin et scope la connexion RUNTIME au club ciblé (RLS active,
jamais en bypass) le temps de la purge ; le GUC est relâché dans le `finally` de la commande
ET par une ceinture dans le controller (`AdminClubActionController`), la requête admin ne
continue jamais tenant-scopée. L'audit trace le club visé (`_admin_audit_context` → details),
y compris sur les tentatives refusées.
- Livré : `reset-generation-quota` (`app:clubs:reset-quota`), `reset-current-season`
  (`app:clubs:reset-season` — miroir CLI de `ResetSeasonController`, `--dry-run`),
  `purge-old-seasons` (`app:seasons:purge --club`, existant).
- **Différés AU PREMIER CAS RÉEL (décision fondateur 2026-07-18)** : **suspension /
  désactivation d'un club** (aucun impayé/abus à ce jour ; l'effet exact — login ? génération ? —
  reste à trancher) et **approbation d'un gestionnaire en fallback** (le circuit normal
  `MembershipController` suffit tant qu'aucun club n'a de gestionnaire injoignable).

### Reste à implémenter
- **SA5 — impersonation support** : lecture bornée dans le temps, bannière visible, audit complet, aucune écriture tant qu'elle n'est pas décidée séparément.
- **Hygiène de la console** : ~~partitionnement mensuel + purge 6 mois de `solver_metrics`~~ **abandonnés (2026-07-18)** — télémétrie append-only, rétention **≥ 13 mois** (une saison + marge, décision, sans mécanique de purge au volume cible ; le partitionnement n'est pas nécessaire, l'index `(club_id, created_at)` suffit). Reste : arbitrage de la rétention / des filtres de l'audit viewer.
- **Data ops FFBB** : le refresh club existe, mais le mode batch de rattrapage des ligues / comités périmés reste à cadrer si on le garde dans le lot.

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

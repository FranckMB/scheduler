# Console super-admin (monitoring & exploitation) — besoin à spécifier

> **Statut** : **besoin à spécifier** (discovery **ouverte** — périmètre & métriques posés, décisions à trancher). **Pas un plan.**
> **Nature** : l'écran d'exploitation transverse (cross-tenant **par conception**) pour piloter le SaaS — santé, usage, conversion, support. Surface la **plus sensible** du produit (elle voit tous les clubs) → sécurité d'abord.
> **Rattachement roadmap** : `roadmap.md` §9 (transverse/observabilité) — s'appuie sur `solver_metrics` (§9, à persister) + audit trail (§9) + rétention/purge (§3).
> **Réutilise l'existant** : connexion Doctrine **`admin`** (`clubscheduler`, bypass RLS — déjà la porte superadmin) · CLI `app:seasons:purge` · `ResetSeasonController` · métriques déjà calculées par `SolverMetricsMapper` (pas encore persistées).

---

## 1. Le besoin (reformulé)

« **Quid de l'écran super-admin ? Que doit-il monitorer, que mettre dedans, quelles métriques par club et pour l'ensemble de l'app ?** »

Il faut une **console d'exploitation** répondant à trois usages :
- **Santé** : l'app tourne-t-elle bien ? (engine, worker, DB, Redis, files, erreurs)
- **Usage & business** : combien de clubs, quelle activité, quelle **conversion** freemium→payant, quelle charge solveur ?
- **Support** : diagnostiquer/aider un club précis (voir son état, réinitialiser un quota, purger, dépanner).

## 2. Métriques **par club**

- Volumétrie : nb équipes / gymnases / coachs / contraintes.
- Générations : **compteur total** (quota Découverte, cf. [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md)), date + statut de la dernière, **taux d'INFEASIBLE**, temps de solve p50/p95.
- Cycle de vie : plan (Découverte / payant), date de création, **dernière activité** (connexion / génération), saison courante, mode onboarding.
- Ressources : stockage logo, taille des données.

## 3. Métriques **globales (application)**

- Parc : nb clubs total, **actifs 7 j / 30 j**, nouveaux/semaine.
- Conversion : Découverte→payant (taux, délai médian), clubs à quota épuisé (chauds pour la vente).
- Charge solveur : générations/jour, file Messenger (backlog, échecs, retries), **temps de solve p50/p95**, taux d'INFEASIBLE global.
- Santé technique : engine up, worker up, Redis, DB, Mercure ; erreurs (Sentry, cf. observabilité §prod-readiness) ; **coûts d'infra projetés à N clubs**.

## 4. Actions d'exploitation (au-delà du read-only)

À arbitrer (chacune = surface de risque) : **reset du quota** de générations (Découverte), **purge saison N-2** (`app:seasons:purge`), **reset club** (`ResetSeasonController` élargi), **désactiver/suspendre** un club, **approuver** une demande de gestionnaire en fallback (cf. [`enregistrement-ffbb.md`](enregistrement-ffbb.md)), **impersonation support** (se mettre à la place d'un club — très sensible).

## 5. Questions ouvertes (à trancher)

1. **Qui est superadmin ?** Un rôle applicatif distinct (pas un `ClubUser`), hors multi-tenant. Comment on l'authentifie (compte séparé, **MFA** ?).
2. **Read-only d'abord, ou actions dès le départ ?** Le read-only (monitoring) est peu risqué ; les actions (reset/impersonation) exigent un durcissement fort.
3. **D'où viennent les métriques ?** Il faut **persister `solver_metrics`** (§9, aujourd'hui calculées mais jetées) et brancher l'**audit trail** (§9). Sans ça, la console n'a pas de données historiques.
4. **Sécurité de la console** = priorité : c'est la seule surface **cross-tenant assumée** (bypass RLS via la connexion `admin`). Auth séparée, **toute action auditée**, périmètre minimal, jamais exposée au réseau public sans garde. Croise les findings cyber A15/A16/A17.
5. **Où vit-elle ?** Route protégée dans l'app, sous-domaine séparé, ou outil interne distinct ?
6. **Observabilité amont** : Sentry/health-checks/backups sont **prérequis** (aujourd'hui absents — cf. prod-readiness) ; la console les *affiche*, elle ne les remplace pas.

## 6. Ce que ce fichier n'engage pas

Aucune décision d'archi. But : cadrer le périmètre (santé / usage / support), lister les métriques candidates, et forcer la question **sécurité de la surface cross-tenant** avant tout `/plan`.

# Transition de saison — besoin spécifié

> **Statut** : **besoin spécifié** (discovery close, décisions tranchées §6) — **pas un plan**.
> **Nature** : fixe le vrai besoin métier de la bascule saison N → N+1, après confrontation au terrain (gestionnaire d'un club réel).
> **Rattachement roadmap** : `roadmap.md` §3 (onboarding & saisons). Concrétise `SeasonTransitionService` (FF#3) + la capitalisation des contraintes + la rétention.
> **Réutilise l'existant** : self-FK `parentTeamId`/`parentVenueId`/`parentCoachId` **déjà en base** · `Season.transitionData` (jsonb) · la **gate cockpit** (`socleValidatedAt === null` → work-loop, read-only tant que baseline non posé) · `CalendarEntry` (événements club). **Zéro changement engine.**

---

## 1. Le problème

Chaque année le club reconduit sa structure à 90 % (mêmes gymnases, souvent mêmes coachs, contraintes récurrentes) — **l'app ne modélise pas les joueurs**, donc « les joueurs montent d'une catégorie » est **invisible** : les équipes (catégorie + tier + genre + niveau) sont des structures **stables**. Ressaisir 49 équipes + gyms + coachs + contraintes chaque saison est absurde. La transition **pré-remplit N+1 depuis les entrées de N** (copie éditable, `parent_*_id` trace la filiation) et le gestionnaire **ajuste le churn de marge** (équipe créée/dissoute, coach parti/arrivé, priorités re-jugées).

**Anticipation** : dès mi-mai le gestionnaire peut préparer N+1 pendant que N tourne, pour avoir le plan prêt en juin (répondre aux parents, etc.) — **ne pas subir** la rentrée.

## 2. Le workflow

1. **Préparer N+1** (dès ~mi-mai) — le gestionnaire **cible** la saison N+1 (elle coexiste avec N encore courante). L'app crée N+1 en **copiant les entrées de N** (gyms, équipes, coachs, contraintes, créneaux salle) via `parent_*_id`.
2. **Revue guidée** — par type de donnée, le gestionnaire confirme/édite le jeu copié :
   - Gymnases : garder / retirer (≈0 changement).
   - Coachs : garder / parti / nouveau + réaffecter équipe.
   - Équipes : garder / dissoudre / créer + re-classer priorité (S/A/B/C/D).
   - Contraintes : reconduites, éditables.
   - **Événements club** (`CalendarEntry` kind=event) : reconduits mais les dates glissent → **« garder ? + nouvelle date »**.
   - Récap → génère le plan N+1 (fraîche génération, **comme le premier planning**).
3. **Bascule mi-juillet** (non destructive, cf. §3) — N+1 devient la saison **courante** :
   - **Anticipé** → plan N+1 prêt, le gestionnaire bosse.
   - **Pas anticipé** → à la connexion, la **gate cockpit existante** (`socleValidatedAt === null`) redirige sur le work-loop « planning principal à remplir », read-only tant que le baseline n'est pas posé. **On force la main avec du code déjà écrit.** N reste intact en readonly.

## 3. Le modèle

- **Multi-saison simultané** (le vrai chantier) : l'app tient **N-1 (readonly) + N (courante) + N+1 (brouillon)** et le gestionnaire **choisit laquelle il édite** (sélecteur/cible de saison). Aujourd'hui la résolution du tenant est **mono-saison** (`status='active'` unique, `findActiveByClubId` partout) → c'est **ça** qui est structurant, pas la copie.
- **« Courante » dérivée du calendrier** (reco) : `courante = saison dont la fenêtre contient aujourd'hui`, avec un **seuil de bascule** (~mi-juillet) qui pointe sur N+1 dès qu'on le franchit. **Pas** un job cron qui flippe `status` en fond → non destructif par construction, cohérent « jamais d'auto-action », pas de failure mode.
- **Copie** : entrées de N clonées vers N+1, `parent_*_id` = filiation. **Le plan généré N ne se copie PAS** — seulement le setup, puis génération fraîche.
- **Rétention** : fenêtre glissante **2 saisons** (N courante + N-1 readonly), **purge N-2** (aucune valeur au-delà, sert le jalon RGPD). Transitoire mai→juillet : N-1 + N + N+1 brouillon (le brouillon ne compte pas comme historique ; quand N+1 devient courante, N-1 purge).
- **Read-only** : N-1 toujours ; N passe read-only quand N+1 devient courante (verrou serveur, comme le VALIDATED).

## 4. Nouveau vs existant

| Élément | État |
|---|---|
| Self-FK `parent{Team,Venue,Coach}Id`, `Season.transitionData` | ✅ en base (infra anticipée) |
| Gate cockpit `socleValidatedAt` → force le baseline | ✅ existant (réutilisé pour la bascule) |
| `CalendarEntry` événements club | ✅ existant (copiés + re-datés) |
| **Résolution multi-saison** (N-1/N/N+1 + sélecteur + courante dérivée du calendrier) | ⬜ **nouveau — structurant** (touche le tenant partout) |
| `SeasonTransitionService` (copie entrées N→N+1) | ⬜ **nouveau** |
| Wizard de revue guidée (keep/modify/remove + re-date événements) | ⬜ **nouveau** (frontend) |
| Rétention : purge N-2 + read-only N à la bascule | ⬜ **nouveau** |
| `parentConstraintId` (capitalisation contraintes) | ⬜ à ajouter (Team/Venue/Coach l'ont déjà) |
| Moteur (engine) | ✅ inchangé |

## 5. Phasage

| Phase | Contenu | Livre |
|---|---|---|
| **P1 — Transition fonctionnelle** ✅ **LIVRÉ (PR #68/69/70, 2026-07-06)** | Résolution multi-saison (N-1/N/N+1 + sélecteur + courante dérivée du calendrier + read-only N) · `SeasonTransitionService` (copie entrées) · rétention/purge (`app:seasons:purge`). Le gestionnaire copie N→N+1 et **édite librement avec le wizard existant**, génère, valide. | La transition **marche de bout en bout** (plus de ressaisie). Cœur utile seul. |
| **P2 — Revue guidée** ⬜ **à venir** | Wizard de revue par type (keep/modify/dissolve, réaffectation coach, re-classement priorité) + **re-datation des événements club** (`CalendarEntry kind=event` — non copiés en P1) + alertes d'anticipation (mi-mai → mi-juillet). Éventuel report des tags custom (dépend de la dette `TeamTagService`). | Le **confort** qui rend la transition guidée et sans oubli. Sur P1. |

## 6. Décisions tranchées

1. **Copie des entrées** (pas l'output du plan N), éditable — point de départ, pas migration muette (churn de marge attendu).
2. **Bascule non destructive** : « courante » dérivée du calendrier + seuil mi-juillet ; N préservée readonly ; N+1 vide → gate cockpit force le baseline. Pas de job qui flippe `status`.
3. **Multi-saison simultané** = cœur du chantier (le tenant devient multi-saison). La copie est facile.
4. **Rétention 2 saisons** (N + N-1 readonly), purge N-2.
5. **Pas de modèle joueur** → copie de structure transparente (les joueurs qui montent sont hors app).
6. **Événements club** reconduits en « garder ? + nouvelle date ».
7. **Nommage des équipes = label libre du gestionnaire** — copié tel quel, éditable, **jamais renommé automatiquement**. La catégorie d'âge réelle vit dans `sportCategoryId` (structuré, séparé du nom) → **aucune logique de vieillissement/renommage** dans la transition.

## 7. Hors scope

- Modèle joueur / roster (l'app ne le connaît pas).
- Copie du **plan généré** N (seulement les entrées).
- Rétention au-delà de N-1.
- Renommage automatique des équipes (voir question ouverte).

## 8. Questions ouvertes

**Aucune.** Le nommage des équipes (seul point resté ouvert) est tranché : label libre du gestionnaire, jamais renommé auto, catégorie d'âge portée par `sportCategoryId` (décision §6.7). Le besoin est complet — prêt pour `/plan` (P1).

## 9. Axes structurants (§7.1) & vérification

- **⚠️ tenant isolation / résolution de saison** : passer de mono-saison (`status='active'`) à **multi-saison ciblable** touche `TenantFilter` / `findActiveByClubId` **partout** → NR tenant **lourd** (une saison ne doit jamais fuir dans une autre ; N readonly non éditable ; N-1 purgée invisible). C'est le risque n°1.
- **planning lifecycle** : read-only de la saison N à la bascule (verrou serveur, comme VALIDATED).
- **constraint semantics** : le plan N+1 généré depuis les entrées copiées doit honorer les contraintes reconduites → smoke-solveur (`smoke-solver.sh`, `COMPLETED`).
- **Vérification finale** : smoke-solveur sur une saison issue d'une transition (copie → génération → COMPLETED).

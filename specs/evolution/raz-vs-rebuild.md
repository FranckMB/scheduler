# RAZ vs Rebuild — Débat Ouvert

Last verified @ 2026-06-30

---

## Le Débat

> **OPEN QUESTION for Claude Code at execution time.**
>
> Ce document présente objectivement trois options pour la refonte du frontend.
> Aucune option n'est choisie ici. La décision est explicitement différée au
> moment de l'exécution par Claude Code, qui devra confirmer son choix en lisant
> `specs/evolution/handoff-to-claude-code.md` avant de démarrer.
>
> L'utilisateur a exprimé une préférence pour "RAZ PUR" lors des interviews
> (tours 2-3 du plan `frontend-raz-cleanup.md`). Cette préférence est notée
> comme contexte, **pas** comme une décision finale. Le plan mandate que le
> débat reste OPEN dans ce document.

### Contexte

Le frontend actuel (`frontend/src/`) contient 74 fichiers répartis en deux
strates :

- **`shared/`** (5 fichiers) : infrastructure transverse — client HTTP `ky`,
  `queryClient` TanStack, `ErrorBoundary`, `LoadingSpinner`, `lazyLoader`.
- **`features/`** (58 fichiers) : 8 modules fonctionnels — `auth/`, `dashboard/`,
  `entities/`, `priorities/`, `profile/`, `schedule/`, `ui/`, `wizard/`.

Tests existants : 5 fichiers de tests unitaires (`authStore.test.ts`,
`DashboardPage.test.tsx`, `ExportPdfButton.test.tsx`,
`DiagnosticsPanel.test.tsx`, `ManualEditDialog.test.tsx`) + 4 specs E2E
Playwright (`smoke.spec.ts`, `wizard-flow.spec.ts`,
`post-generation-workflow.spec.ts`, `season-reset.spec.ts`).

Le contrat API backend est stable (OpenAPI snapshot gelé dans
`specs/courantes/openapi-snapshot.json`, 168 KB, 20 resources API Platform,
7 custom controllers). La stack cible est fixée dans
`specs/courantes/frontend-strategy.md` (React 19.2, Vite 8, TS ~6, Tailwind 4,
TanStack Query 5, Zustand 5, ky 2, FullCalendar 6, @dnd-kit 0.5).

---

## Option A: RAZ Pur

### Description

Suppression totale de `frontend/src/` et réécriture complète depuis un projet
Vite neuf. Aucun fichier source existant n'est migré. Le nouveau projet est
généré via `npm create vite@latest` avec la stack cible, puis les fonctionnalités
sont réimplémentées selon `specs/courantes/frontend-spec.md` et
`specs/courantes/frontend-wizard.md`.

### Périmètre

- **Razé** : `frontend/src/` dans son intégralité (74 fichiers).
- **Préservé** : `docker/frontend/` (Dockerfile + nginx.conf), `frontend/index.html`,
  `frontend/package.json` (mis à jour selon stack cible), `frontend/vite.config.ts`
  (config proxy réutilisée ou adaptée), `frontend/Makefile`.

### Pros

- Terrain vierge : aucune dette technique, aucun pattern hérité à défaire.
- Architecture propre dès le départ : feature folders, conventions de nommage,
  structure de tests alignée avec `frontend-strategy.md`.
- Pas de compromis avec l'existant : Tailwind 4 CSS-first (pas de
  `tailwind.config.js`), Zustand 5 sans `migrate()`, TanStack Query 5 sans
  `onSuccess`.
- TDD appliqué from scratch : tests écrits avant implémentation, pas de tests
  legacy à adapter.

### Cons

- Coût de réécriture élevé : intégration Mercure (EventSource + SSE),
  wizard 4-steps avec auto-save et import Excel, FullCalendar avec
  drag-and-drop @dnd-kit, intégration JSON-LD API Platform — tout à recréer.
- 5 tests unitaires + 4 specs E2E perdus (à réécrire).
- Risque de régression sur les intégrations critiques (auth, Mercure, proxy
  Nginx) qui fonctionnent actuellement.
- Délai avant première page fonctionnelle plus long.

### Coût estimé

**Élevé.** Réécriture complète de 74 fichiers + 9 tests. Estimation basée sur
le volume de fonctionnalités (10 routes, 5 pages, wizard 4-steps, 20 resources
API à consommer) : l'effort est proportionnel à un projet neuf.

---

## Option B: Rebuild Sélectif

### Description

Conservation de `frontend/src/shared/` (5 fichiers d'infrastructure transverse)
et raz de `frontend/src/features/` (58 fichiers). Les modules fonctionnels sont
réécrits, mais la couche HTTP (ky client), le queryClient, l'ErrorBoundary et
les utilitaires de chargement sont réutilisés.

### Périmètre

- **Razé** : `frontend/src/features/` (58 fichiers — auth, dashboard, entities,
  priorities, profile, schedule, ui, wizard).
- **Préservé** : `frontend/src/shared/` (5 fichiers), `docker/frontend/`,
  `frontend/index.html`, `frontend/package.json`, `frontend/vite.config.ts`,
  `frontend/Makefile`.

### Pros

- Coût réduit d'environ 5x par rapport à Option A : l'intégration HTTP
  (Bearer token, 401 → logout), le queryClient (cache, invalidation), et
  l'ErrorBoundary sont déjà testés et fonctionnels.
- Intégration Mercure préservée si elle vit dans `shared/` (à vérifier —
  actuellement dans `features/schedule/`).
- Tests `authStore.test.ts` potentiellement conservables (vit dans
  `features/auth/` — serait razé, mais la logique pourrait être migrée).
- Délai avant première page plus court : la plomberie HTTP est en place.

### Cons

- Pas de "terrain vierge" : `shared/` peut porter des patterns hérités
  (Zustand 4 vs 5, ky 1.x vs 2, TanStack Query 4 vs 5) nécessitant une
  migration avant réutilisation.
- Dette architecturale potentielle : `shared/` a été écrit pour l'ancien
  modèle, pas pour la nouvelle architecture cible.
- Risque de contamination : les nouveaux modules `features/` pourraient
  hériter de conventions implicites de `shared/` qui ne correspondent pas
  à `frontend-strategy.md`.
- L'authStore (testé) vit dans `features/auth/`, pas dans `shared/` — il
  serait razé malgré sa valeur.

### Coût estimé

**Modéré.** Réécriture de 58 fichiers + adaptation de 5 fichiers `shared/`
pour compatibilité stack cible. Tests à réécrire partiellement (4 sur 5
unitaires + 4 E2E).

---

## Option C: Hybride

### Description

Raz de `frontend/src/features/` (comme Option B) mais avec migration
explicite des fichiers à haute valeur d'intégration vers le nouveau projet.
Trois fichiers sont identifiés comme porteurs d'intégration critique :

1. `features/auth/authStore.ts` — store d'authentification testé
   (Zustand, token management, club context).
2. `shared/api/client.ts` — client HTTP ky avec injection Bearer token
   et redirect 401 → logout.
3. `shared/lib/queryClient.ts` — configuration TanStack Query (cache,
   retry, staleTime).

Ces fichiers sont migrés comme base du nouveau projet, puis adaptés à la
stack cible (Zustand 5, ky 2, TanStack Query 5). Le reste est réécrit from
scratch.

### Périmètre

- **Razé** : `frontend/src/features/` sauf `authStore.ts` (55 fichiers).
- **Migré** : `authStore.ts`, `client.ts`, `queryClient.ts` (3 fichiers).
- **Préservé** : `docker/frontend/`, `frontend/index.html`,
  `frontend/package.json`, `frontend/vite.config.ts`, `frontend/Makefile`.
- **Non migré** : `ErrorBoundary.tsx`, `LoadingSpinner.tsx`, `lazyLoader.tsx`
  (réécrits — simples composants, faible coût).

### Pros

- Coût ~30% de Option A : garde les 3 fichiers les plus précieux
  (intégration auth testée, client HTTP, queryClient) sans trainer le
  reste de `shared/`.
- Intégration critique préservée : auth + HTTP + cache sont les points
  de friction les plus coûteux à réécrire.
- `authStore.test.ts` peut être migré avec `authStore.ts` — test
  d'authentification préservé.
- Plus proche du "terrain vierge" que Option B : seulement 3 fichiers
  hérités, pas toute la couche `shared/`.

### Cons

- Migration requiert adaptation : `authStore.ts` est en Zustand 4/5,
  `client.ts` en ky 1.x/2, `queryClient.ts` en TanStack 4/5 — versions
  cible différentes, breaking changes possibles.
- Risque de "mi-chemin" : ni fully clean (A) ni fully pragmatic (B),
  potentiellement le pire des deux mondes si la migration des 3 fichiers
  introduit des bugs subtils.
- Décision de périmètre arbitraire : pourquoi 3 fichiers et pas 5 ?
  La frontière est floue et peut générer du débat à l'exécution.
- `ErrorBoundary` et `LoadingSpinner` sont triviaux à réécrire — les
  exclure de la migration est logique mais ajoute de la complexité de
  décision.

### Coût estimé

**Modéré-faible.** Réécriture de 55 fichiers + migration/adaptation de
3 fichiers + réécriture de 2 composants triviaux. 1 test unitaire
migrable (`authStore.test.ts`), 4 à réécrire + 4 E2E.

---

## Distinction Infra-vs-Source

> **Finding Metis** : le débat "RAZ vs rebuild" contient une fausse dichotomie
> si on ne distingue pas l'infrastructure du code source.

Le frontend comporte deux strates qui ne sont **pas** soumises au même régime :

| Strate | Localisation | Régime | Détail |
|--------|-------------|--------|--------|
| **Infrastructure** | `docker/frontend/` | **Préservée dans toutes les options** | `Dockerfile` (build multi-stage Node 20 → Nginx 1.27) + `nginx.conf` (proxy `/api` → backend, `/.well-known/mercure` → hub, `/engine` → engine, SPA fallback, headers sécurité, cache assets). Validée et fonctionnelle. |
| **Source** | `frontend/src/` | **Soumise au débat** | 74 fichiers (5 shared + 58 features + autres). C'est le seul périmètre sur lequel portent les Options A/B/C. |

### Règle absolue

> `docker/frontend/Dockerfile` et `docker/frontend/nginx.conf` ne sont **jamais**
> supprimés, ni en Option A, ni en Option B, ni en Option C. Ces fichiers
> représentent l'infrastructure de déploiement validée et ne sont pas du code
> source applicatif. Toute opération de raz doit explicitement les exclure.

Cette distinction est également documentée dans
`specs/courantes/frontend-strategy.md` §4 "Infrastructure Reuse" qui liste
explicitement `docker/frontend/Dockerfile` et `docker/frontend/nginx.conf`
comme "Préserver tel quel".

### Fichiers hors-débat

Les fichiers suivants ne sont pas du code source applicatif et ne sont pas
concernés par le débat RAZ vs rebuild :

- `docker/frontend/Dockerfile` — image Docker (préservé)
- `docker/frontend/nginx.conf` — config Nginx (préservé)
- `frontend/index.html` — point d'entrée HTML (préservé ou adapté)
- `frontend/package.json` — manifeste de dépendances (mis à jour selon stack cible)
- `frontend/package-lock.json` — lockfile (régénéré)
- `frontend/vite.config.ts` — config Vite + proxy (réutilisé ou adapté)
- `frontend/Makefile` — helpers Docker (préservé)
- `frontend/tsconfig*.json` — config TypeScript (adapté si besoin)

---

## Critères de Décision

Claude Code devra évaluer les critères suivants au moment de l'exécution.
Aucun critère ne détermine seul le choix — c'est la pondération de
l'ensemble qui guide la décision.

| Critère | Penche vers A | Penche vers B | Penche vers C |
|---------|---------------|---------------|---------------|
| Frustration résiduelle sur `shared/` | Oui → A | Non → B ou C | Partielle → C |
| Contrat API encore instable | Oui → A (static first) | Non → B ou C | Non → C |
| Besoin de démo rapide | Non → A | Oui → B | Oui → C |
| Tests actuels à conserver | Non → A | Oui → B | Partiel (authStore) → C |
| Niveau de dette technique perçu | Élevé → A | Faible → B | Moyen → C |
| Budget temps alloué | Large → A | Restreint → B | Intermédiaire → C |
| Confiance dans l'intégration Mercure existante | Faible → A | Forte → B | Moyenne → C |

### Questions ouvertes pour Claude Code

1. **L'authStore actuel est-il compatible Zustand 5 ?** Si `migrate()` est
   nécessaire, le coût de migration change l'équation C.
2. **Le client ky est-il en ky 1.x ou 2 ?** La signature des hooks a changé
   en ky 2 — si migration nécessaire, le coût de B/C augmente.
3. **Où vit l'intégration Mercure ?** Actuellement dans `features/schedule/`,
   pas dans `shared/` — cela affecte la valeur de préserver `shared/` en B.
4. **Les 4 specs E2E Playwright sont-elles réutilisables ?** Elles dépendent
   de la structure DOM qui sera différente en A/B/C.

---

## Où est Préservée cette Décision

La décision finale entre Option A, B, et C est différée à Claude Code au
moment de l'exécution du plan de rebuild frontend.

### Document de référence

**`specs/evolution/handoff-to-claude-code.md`** (Task 20 du plan
`frontend-raz-cleanup.md`) est le document que Claude Code lira en premier
avant de démarrer le rebuild. Il contient :

- La stack et les versions cibles.
- La décision wizard ("Draft hybride" — fixée, non rouverte).
- La définition du périmètre du raz.
- Les contraintes (TDD mandatory, infra preservation).
- Le "start here" pointer.
- La directive TDD.
- La note de préservation `docker/frontend/`.
- **La référence à ce document** (`raz-vs-rebuild.md`) pour que Claude Code
  confirme son choix d'option avant exécution.

### Règle de non-résolution

Ce document ne résout **pas** le débat. Il documente objectivement les trois
options, leurs coûts, leurs trade-offs, et les critères de décision. La
résolution est explicitement différée à Claude Code, qui devra :

1. Lire `handoff-to-claude-code.md`.
2. Lire ce document (`raz-vs-rebuild.md`).
3. Évaluer les critères de décision contre l'état réel du codebase au moment
   de l'exécution.
4. Confirmer son choix d'option avant de démarrer le raz.

### Références croisées

- `specs/courantes/frontend-strategy.md` §4 — Infrastructure Reuse (docker/frontend/ preservation)
- `specs/courantes/frontend-spec.md` — spec forward du frontend cible
- `specs/courantes/frontend-wizard.md` — spec du wizard 4-steps
- `specs/courantes/openapi-snapshot.json` — contrat API gelé
- `specs/evolution/handoff-to-claude-code.md` — handoff packet pour Claude Code
- `.omo/plans/frontend-raz-cleanup.md` — plan source (interviews tours 2-3, Metis review)

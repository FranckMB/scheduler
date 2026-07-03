# Frontend Strategy — TDD, Stack Fixée & Anti-patterns

Last verified @ 09e67f3 2026-07-03

> Document stratégique pour le rebuild du frontend ClubScheduler. Fixe le mandat TDD, les
> versions exactes de la stack, les anti-patterns bannis et les règles de préservation
> d'infrastructure. Le détail fonctionnel (routes, composants, wizard) est dans
> `frontend-spec.md` et `frontend-wizard.md` (T12) — ce document ne les duplique pas.

---

## 1. Testing Strategy — TDD Mandatory

**TDD is MANDATORY for the frontend rebuild plan. Write tests FIRST, watch them fail (RED), implement minimal to pass (GREEN), then refactor (REFACTOR).**

Aucune exception. Chaque composant, hook, store, route et intégration API doit suivre le
cycle RED → GREEN → REFACTOR avant d'être considéré livrable.

### Règles d'application

| Étape | Action | Critère de sortie |
|------|--------|-------------------|
| **RED** | Écrire le test unitaire / d'intégration AVANT toute implémentation. Lancer le test — il doit échouer pour la bonne raison (assertion manquante, import absent, type error). | Sortie console montre l'échec attendu, pas une erreur de compilation non liée. |
| **GREEN** | Implémenter le code minimal pour faire passer le test. Pas de code défensif non testé. Pas de feature non demandée. | Tous les tests du cycle passent (exit 0). |
| **REFACTOR** | Améliorer la structure (extraction, renommage, typage) sans changer le comportement. Re-lancer les tests après chaque refactor. | Tests toujours verts après refactor. |

### Périmètre de test obligatoire

- **Composants UI** : tests de rendu (React Testing Library) — props, états, accessibilité ARIA.
- **Hooks personnalisés** : `renderHook` + scénarios de cycle de vie.
- **Stores Zustand** : tests d'état, d'actions, de `persist`/`migrate`.
- **Queries TanStack Query** : tests avec `QueryClient` de test, mock `ky`, vérification
  `useQuery`/`useMutation` + gestion d'erreur.
- **Routes** : tests de navigation (React Router memory router), guards d'auth, redirections.
- **Intégration API** : mock `ky` via MSW ou interceptor, vérification des payloads et headers.

### Outils de test (versions fixées)

| Outil | Version (`frontend/package.json`) | Rôle |
|------|---------|------|
| Vitest | ^3.2 | Runner de test |
| @testing-library/react | ^16.3 | Rendu et queries DOM |
| @testing-library/user-event | ^14.6 | Simulation d'interaction |
| jsdom | ^27.0 | Environnement DOM |
| msw | ^2.8 | Mock réseau HTTP |
| @playwright/test | ^1.60 | E2E (`frontend/tests/e2e/`) |

> Les versions ci-dessus sont figées au même titre que la stack applicative (§2). Aucune
> mise à jour sans validation explicite.

---

## 2. Stack Versions Fixed

Les versions suivantes sont **figées** pour toute la durée du rebuild. Aucune mise à jour
de version majeure ou mineure sans décision explicite et re-vérification de compatibilité.

| Package | Version (`frontend/package.json`) | Rôle | Notes |
|---------|--------------|------|-------|
| react / react-dom | ^19.2 | Framework UI / rendu DOM | React 19 — pas de ReactDOM.render, createRoot obligatoire (voir §3) |
| vite | ^8.0 | Bundler / dev server | Plugin `@tailwindcss/vite` |
| typescript | ~6.0 | Typage | `~6.0` = patch libre, minor figée |
| tailwindcss | ^4.3 | CSS utility-first | Configuration via CSS `@theme`, pas `tailwind.config.js` (voir §3) |
| @tanstack/react-query | ^5.101 | Server state | v5 — pas de `onSuccess` (voir §3) |
| zustand | ^5.0 | Client state | v5 — `migrate()` requiert null check (voir §3) |
| ky | ^2.0 | Client HTTP | v2 — API fetch moderne |
| @dnd-kit/core + sortable + utilities | ^6.3 / ^10.0 / ^3.2 | Drag & drop | Accessible ; utilisé pour le tri des équipes (wizard) |
| react-router-dom | ^7.1 | Routing | Nested layouts |
| lucide-react | ^0.470 | Icônes | SVG tree-shakeable |

> **FullCalendar n'est PAS installé** : la grille planning est un composant custom
> (`src/features/planning/WeekGrid.tsx`). La liste exhaustive des dépendances vit dans
> `frontend/package.json` — source de vérité.

### Règles de verrouillage

1. `package.json` utilise des plages `^`/`~` ; `package-lock.json` est la source de
   vérité effective des versions installées — tout changement de version doit être
   reflété dans le lockfile.
2. Une mise à jour de version majeure = un commit dédié + re-run complet des tests
   (Vitest) + vérification `tsc --noEmit` + `npm run build`.

---

## 3. Anti-patterns Banned

Les patterns suivants sont **interdits** dans le code du rebuild. Tout PR les introduisant
est rejeté automatiquement.

| # | Anti-pattern | Pourquoi banni | Correct à la place |
|---|-------------|----------------|-------------------|
| 1 | `ReactDOM.render(...)` | Supprimé en React 19 — lance un avertissement puis casse en production. | `createRoot(container).render(...)` |
| 2 | `onSuccess` dans `useQuery` / `useMutation` (TanStack Query v5) | Supprimé en v5 — causait des effets de bord implicites et des fuites de state. | `useEffect` sur `data`/`isSuccess`, ou `select` pour transformer les données. |
| 3 | `migrate()` sans null check dans Zustand 5 | `persist` v5 passe `persistedState` potentiellement `null` — un `migrate` qui assume un objet non-null lance une `TypeError`. | `migrate: (persistedState: unknown, version: number) => { if (persistedState === null) return initialState; ... }` |
| 4 | `@apply` dans des composants Tailwind v4 | Tailwind v4 déprécie `@apply` dans les composants — casse l'extraction utility-first et le tree-shaking CSS. | Composer avec des classes utility directement, ou extraire un composant React réutilisable. |
| 5 | `eslint-config-prettier` pas en dernière position dans `extends` | Si une config ESLint est chargée APRÈS `prettier`, elle ré-active des règles de formatage que prettier désactive — conflits silencieux. | `extends: [..., 'prettier']` — toujours en dernier. |
| 6 | `tailwind.config.js` (fichier JS de config) | Tailwind v4 remplace la config JS par la directive CSS `@theme` dans le fichier CSS principal. Le fichier JS est ignoré ou cause des conflits. | Définir les tokens (couleurs, fonts, breakpoints) via `@theme { ... }` dans `src/index.css`. |

### Détection automatique

Ces anti-patterns doivent être détectés par :

- **ESLint** : règles custom ou plugins (`eslint-plugin-react`, `@tanstack/eslint-plugin-query`).
- **TypeScript** : `tsc --noEmit` échoue sur `ReactDOM.render` (type supprimé en React 19).
- **Code review** : checklist obligatoire dans le template de PR.

---

## 4. Infrastructure Reuse

Le rebuild est un **raz ciblé sur le code source** — l'infrastructure Docker existante doit
être **préservée**.

### Fichiers à préserver (NE PAS SUPPRIMER)

| Fichier | Rôle | Action |
|---------|------|--------|
| `docker/frontend/Dockerfile` | Image Docker du frontend (build multi-stage + Nginx) | **Préserver tel quel** — adapter uniquement si la structure de build change. |
| `docker/frontend/nginx.conf` | Config Nginx (proxy `/api` → backend, `/.well-known/mercure` → hub, `/engine` → engine, SPA fallback) | **Préserver tel quel** — la config proxy est validée et fonctionnelle. |

### Périmètre du raz

- **Raz s'applique à** : `frontend/src/` uniquement (composants, hooks, stores, routes,
  styles, types, utils).
- **Raz ne s'applique PAS à** : `docker/frontend/`, `frontend/public/` (assets statiques
  si présents), `frontend/index.html` (point d'entrée HTML), `frontend/package.json` et
  `frontend/package-lock.json` (mis à jour selon §2, pas razé).

### Règle de préservation

> Toute opération de raz ou de reset du frontend doit explicitement exclure
> `docker/frontend/Dockerfile` et `docker/frontend/nginx.conf`. Ces fichiers représentent
> l'infrastructure de déploiement validée et ne sont pas du code source applicatif.

---

## 5. Références croisées

| Document | Relation |
|----------|----------|
| `frontend-spec.md` | Spécification forward complète (routes, composants, stack) — ce document en est le complément stratégique. |
| `frontend-wizard.md` (T12) | Spécification du wizard d'onboarding — non dupliquée ici. |
| `backend-inventory.md` | Inventaire backward du backend — référencé par `frontend-spec.md`. |
| `openapi-snapshot.json` | Snapshot OpenAPI du backend — source de vérité pour les contrats API. |
| `AGENTS.md` | Contexte agent (commandes dev, architecture, gotchas). |

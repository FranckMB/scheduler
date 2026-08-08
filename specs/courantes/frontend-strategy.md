# Frontend Strategy — TDD, Stack Fixée & Anti-patterns

Last verified @ 2026-08-02 (versions re-lues dans `frontend/package.json` le 2026-07-29 ; contenu recalé depuis par P4-65 — activer Sentry ne peut plus échouer en silence, garde de build `sentryCspGuard`)

> **Statut : le rebuild est LIVRÉ.** Les formulations « pour le rebuild » ci-dessous sont
> historiques ; le document reste la référence vivante des **versions de la stack**, des
> **anti-patterns bannis** et des **règles de préservation d'infrastructure**.
>
> Fixe le mandat de test, les versions de la stack, les anti-patterns et les règles de
> préservation d'infrastructure. Le détail fonctionnel (routes, composants, wizard) est dans
> `frontend-spec.md` et `frontend-wizard.md` — ce document ne les duplique pas.
>
> ⚠️ **`frontend/package.json` fait foi.** Ce fichier a laissé dériver ses tableaux de
> versions pendant des semaines tout en se présentant comme un verrou : il « figeait » des
> versions que personne n'utilisait. En cas de doute, lire le `package.json`, pas ce tableau.

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
| Vitest | ^4.1 | Runner de test (config `vitest.config.ts` : jsdom, `globals`, setup `src/test/setup.ts`, exclut `tests/e2e/`) |
| @vitest/coverage-v8 · @vitest/ui | ^4.1 | Couverture · UI de debug |
| @testing-library/react | ^16.3 | Rendu et queries DOM |
| @testing-library/user-event | ^14.6 | Simulation d'interaction |
| @testing-library/jest-dom | ^7.0 | Matchers DOM |
| jsdom | ^29.1 | Environnement DOM |
| msw | ^2.15 | Mock réseau HTTP |
| @playwright/test | ^1.62 | E2E (`frontend/tests/e2e/`) |
| **vitest-axe** · **@axe-core/playwright** | ^0.1 · ^4.12 | **Assertions a11y** — suite unitaire (`src/test/a11y.test.tsx`) + spec de contraste e2e (`tests/e2e/a11y-contrast.spec.ts`) |
| storybook · @storybook/react-vite | ^10.5 | Atelier de composants (`npm run storybook`) |

> Toute mise à jour de version doit être **reflétée ici en même temps que dans le lockfile**.
> Ces tableaux ne sont pas un verrou technique (rien ne les vérifie automatiquement) : ils ne
> valent que par leur exactitude.

### L'accessibilité est bloquante, pas indicative

`frontend/eslint.config.js` re-sévérise **tout le set `jsx-a11y` recommandé** en `error` via un
unique interrupteur `A11Y_LEVEL` (garde-fou WCAG 2.2 AA). Le remappage préserve les options
réglées de chaque règle et **laisse désactivées** celles que `recommended` désactive
délibérément (ex. `label-has-for`, qui double-signalerait des `label`/`id` correctement
associés). `label-has-associated-control` connaît les composants maison
(`Input`, `Select`, `TeamSelect`) pour ne pas crier au faux positif sur un
`<label>…<Input/></label>`. Repasser à `warn` ne se fait que pour débloquer temporairement un
gros refactor.

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
| react-router | ^8.3 | Routing | Data router (`createBrowserRouter`), **`lazy` par route** (P4-6), nested layouts. ⚠ paquet **`react-router`**, PAS `react-router-dom` |
| lucide-react | ^1.27 | Icônes | SVG tree-shakeable |
| @sentry/react | ^10.68 | Reporting d'erreurs | **Erreurs seules** — pas d'APM, pas de replay, `tracesSampleRate: 0` (quota free tier préservé). DSN absent → init sautée, SDK inerte. ⚠ **L'activer demande DEUX gestes** (P4-65) : poser `VITE_SENTRY_DSN` au build **ET** autoriser l'hôte d'ingestion du DSN dans `connect-src` (`docker/frontend/csp.conf`, qui n'autorise aucun tiers). Le DSN seul initialise le SDK et la CSP jette chaque envoi **en silence** ; un garde de build (`frontend/tooling/sentryCspGuard.ts`) refuse désormais cette combinaison. INF-01 |
| @radix-ui/react-label + react-slot | ^2.1 / ^1.1 | Primitives UI | Base des composants shadcn-style de `shared/components/ui/` |

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
| 5 | `tailwind.config.js` (fichier JS de config) | Tailwind v4 remplace la config JS par la directive CSS `@theme` dans le fichier CSS principal. Le fichier JS est ignoré ou cause des conflits. | Définir les tokens (couleurs, fonts, breakpoints) via `@theme { ... }` dans `src/index.css`. |
| 6 | Lire `error.response` dans un `catch` d'appel ky | ky 2.x **consomme lui-même** le corps de la réponse d'erreur et l'expose en `error.data` avant tout consommateur — re-lire la réponse lance `body stream already read`. C'est aussi pourquoi le client n'a **pas** de hook `beforeError`. | Lire **`error.data`** (`shared/api/errors.ts`, `errorMessage()`). |
| 7 | `data ?? []` sur une query en premier chargement | Fabrique un **vide crédible** (« aucun créneau », « aucun réglage ») qui pousse le gestionnaire à re-saisir (doublons) ou à valider une période qu'il croit vide. Symétriquement, traiter `isError` comme fatal détruit un écran qui fonctionne alors que seul un refetch d'arrière-plan a échoué. | `readState()` / `readFailed()` (`shared/lib/readState.ts`) — trois états sur le seul critère « a-t-on une donnée ? ». |

> L'anti-pattern historique « `eslint-config-prettier` pas en dernier » a été retiré : le
> projet n'utilise ni prettier ni `eslint-config-prettier` (aucun script `format`).

### Détection automatique

- **ESLint** (`frontend/eslint.config.js`) : `@eslint/js`, `typescript-eslint`,
  `eslint-plugin-react-hooks`, `eslint-plugin-react-refresh`, `@tanstack/eslint-plugin-query`,
  `eslint-plugin-jsx-a11y`. L'anti-pattern n°1 est **réellement bloqué** par une règle
  `no-restricted-syntax` qui interdit `ReactDOM.render`.
- **TypeScript** : `npx tsc -b --force`.
  ⚠️ **Jamais `tsc --noEmit`** : le `tsconfig.json` racine est un fichier **solution**
  (`"files": []` + `references`), donc `--noEmit` n'y voit **aucun fichier** — il sortait 0
  sans rien vérifier pendant que la CI (qui fait bien `tsc -b`) tombait sur les erreurs. Le
  `--force` est nécessaire aussi : un `tsbuildinfo` périmé court-circuite la vérification.
- **Code review** : checklist obligatoire dans le template de PR.

---

## 4. Infrastructure Reuse

Le rebuild est un **raz ciblé sur le code source** — l'infrastructure Docker existante doit
être **préservée**.

### Fichiers à préserver (NE PAS SUPPRIMER)

| Fichier | Rôle | Action |
|---------|------|--------|
| `docker/frontend/Dockerfile` | Image Docker du frontend (build multi-stage + Nginx) | **Préserver tel quel** — adapter uniquement si la structure de build change. |
| `docker/frontend/nginx.conf` | Config Nginx (proxy `/api` → backend, `/exports` → backend, `/bundles/` → backend, `/.well-known/mercure` → hub, `/engine/` → engine, en-têtes de sécurité + CSP, SPA fallback) | **Préserver tel quel** — la config proxy est validée et fonctionnelle. |

> ⚠️ **Écart connu, non tranché** : le bloc `location /engine/` → `http://engine:8000/` existe
> toujours dans `docker/frontend/nginx.conf`, alors que le proxy `/engine` a été **supprimé**
> côté Vite (FRT-17) au nom de la frontière « le frontend ne contacte jamais l'engine »
> (`CLAUDE.md` §2). Aucun code de `frontend/src/` ne l'appelle, mais il ouvre une route que
> cette frontière interdit. Décision fondateur en attente.

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

# ClubScheduler — Frontend

> Interface web **React 19 · Vite · Tailwind 4**. Trois modes : authentification, **assistant de saisie** (wizard) et **boucle de travail** sur le planning.

## Rôle & périmètre

Le frontend est l'UI de la plateforme. Un gestionnaire de club y **saisit ses données** (équipes, gymnases, coachs, contraintes), **génère** un planning, puis l'**ajuste et régénère**. Servi en statique par Nginx en prod ; en dev, le serveur Vite tourne **sur l'hôte** (pas dans Docker).

**Frontières (à ne jamais franchir) :**
- Parle **uniquement** au backend via `/api/*`. **Ne contacte jamais l'engine directement** (la génération passe par `POST /api/schedules/{id}/generate`, le backend appelle l'engine).
- **N'envoie aucun header `X-Club-Id`** : le tenant est résolu côté serveur depuis le JWT (voir [`backend/docs/TENANT.md`](../backend/docs/TENANT.md)).
- URIs API en **`snake_case`** (`/api/team_coaches`, `/api/venue_training_slots`, `/api/priority_tiers`…).

## Commandes principales

```bash
# Dev frontend = sur l'HÔTE (pas dans Docker), Vite proxifie vers le backend
npm install
npm run dev            # Vite, http://localhost:5173 (proxy /api → :8080, /.well-known/mercure → :3000)
npm run build          # tsc + vite build (prod → dist/)
npm run lint           # ESLint
npm run test           # Vitest (unit + intégration RTL avec vi.mock)

# e2e (Playwright)
npx playwright test    # parcours bout-en-bout (frontend/tests/e2e, config playwright.config.ts)
```

## Recap projet

- **Entry point** : `src/main.tsx` → `src/app/` (`router.tsx`, `AppLayout.tsx`, `AuthGuard.tsx`).
- **Une feature = un dossier** `src/features/<x>/` avec `{api,queries,store}.ts` + ses composants. Features livrées :
  - **`auth`** — login / register (club ARA, statut pending/active) / `/me`. Token dans un store Zustand ; `AuthGuard` redirige (pas de token → `/login`, onboarding non terminé → `/wizard`).
  - **`planning`** — **boucle de travail** : `WeekGrid` (grille semaine par gymnase / coach / équipe), `PlanningToolbar` (sélecteur de planning + régénérer + vues), `ResourceFilter`, `SlotDetail` (lock/déplacer un créneau), `DiagnosticsPanel`.
  - **`wizard`** — **saisie en 6 étapes** (`lib/steps.ts`) : Équipes → Gymnases → Coachs → Contraintes → Récapitulatif → **Génération**. Sauvegarde **au fil de l'eau, par entité** (POST/PUT/DELETE immédiats, mutations TanStack) — **pas** de draft-blob.
  - **`club`** — écran **Gestion du club** (`/club`) : **identité visuelle** (couleur d'accent + logo, cropper cercle zoom/cadrage, extraction 3 couleurs, `--accent` global AA via `shared/hooks/useApplyClubTheme`) **+ section « Demandes »** (approbation des adhésions `pending`, admin).
  - **`cockpit`** — **accueil temporel** (`/`) : bandeau planning socle (ouvrir/modifier/tous les plannings), calendrier mensuel des exceptions, radar d'overlays période/événement. Débloqué (sticky) dès `me.socleValidatedAt`.
  - **`matches`** — **module matchs** (`/matchs`) : grille week-end de placement des rencontres domicile, radar de conflits coach/joueur, import FBI (`ImportFbiDialog`, .xlsx par équipe).
  - **`season-transition`** — sélecteur de saison + bandeau d'anticipation de bascule (pivot 15 juillet), `transitionUiStore`.
- **Partagé** : `src/shared/api/client.ts` (client **ky**, injecte le Bearer, **aucun** header tenant, 401 → logout), `shared/api/collection.ts` (unwrap JSON-LD `{member:[…]}` + pagination), `shared/components/ui/` (primitives).
- **Stack serveur-état/état-client** : TanStack Query 5 (serveur) + Zustand 5 (client). Statut de génération : **poll** du schedule (+ Mercure SSE côté backend).

## Points structurants (à comprendre avant de coder)

- **Deux modes, même app** : le **wizard** alimente le solveur (et, à l'étape Génération, affiche le planning inline une fois `COMPLETED`) ; la **boucle de travail** (`planning`) ajuste/verrouille/régénère un planning existant. Détail canonique : [`specs/courantes/frontend-wizard.md`](../specs/courantes/frontend-wizard.md).
- **Onboarding guidé vs libre** : selon `me.club.onboardingCompleted` (nav verrouillée vers l'avant pour un club neuf ; reprise sur la 1re étape incomplète).
- **Contraintes ciblées par groupe** : l'écran Contraintes pose une contrainte CLUB + `config.targetTag` (ex. `JEUNE`) que le backend éclate en N contraintes d'équipe.

## Pour aller plus loin (docs structurantes)

| Doc | Contenu |
|-----|---------|
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent : conventions (ky sans header tenant, Zustand, React Query, URLs relatives), proxy dev vs prod, Mercure SSE, gotchas. |
| [`specs/courantes/frontend-wizard.md`](../specs/courantes/frontend-wizard.md) | Flux réel du wizard (6 étapes) + principes (save par entité, modes, reprise). |
| [`specs/courantes/frontend-spec.md`](../specs/courantes/frontend-spec.md) · [`frontend-strategy.md`](../specs/courantes/frontend-strategy.md) | Architecture (routes, state), stack figée, anti-patterns, mandat TDD. |
| [`specs/courantes/frontend-components.md`](../specs/courantes/frontend-components.md) | Contrat pages/composants. |

## Stack

- **React** 19 · **TypeScript** ~6 · **Vite** · **Tailwind CSS** 4
- **Data** : TanStack Query 5 · **State** : Zustand 5 · **HTTP** : ky 2
- **Tests** : Vitest + Testing Library (unit/intégration) · Playwright (e2e)
- **Temps réel** : Mercure (SSE) via le backend
- **Ports** : 5173 (dev Vite) · 8081 (Nginx prod, sert `dist/`)

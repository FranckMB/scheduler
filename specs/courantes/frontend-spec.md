# Frontend Spec — Forward

> Specification FORWARD pour le rebuild du frontend ClubScheduler, réconciliée avec le code
> livré (`frontend/src/`). L'inventaire backward du backend est dans
> `backend-inventory.md` — ce document le référence sans le dupliquer.

Last verified @ 2026-07-10 (register vérifié par email A3 : 202 + /verify-email)

---

## 1. Stack Decided

Versions figées pour le rebuild. Aucune librairie ne sera ajoutée sans justification explicite.

| Catégorie | Choix | Version | Rôle |
|-----------|------|---------|------|
| Framework UI | React | 19.2 | Base composants, concurrent features, `use()` hook |
| Build tool | Vite | 8 | Dev server, HMR, build production |
| Langage | TypeScript | ~6.0 | Typage statique, `strict: true` |
| Styling | Tailwind CSS | 4 | Utility-first, engine Oxide, `@tailwindcss/vite` plugin |
| Server state | TanStack Query | 5 | Cache, invalidation, optimistic updates, pagination |
| Client state | Zustand | 5 | Stores globaux légers (auth, thème, UI wizard/planning) |
| HTTP client | ky | 2 | Fetch wrapper, interceptors, retry, hooks |
| Grille planning | Composant custom `WeekGrid` | — | Grille hebdomadaire maison (`src/features/planning/WeekGrid.tsx`) — **pas de FullCalendar** |
| Drag & drop | @dnd-kit (core 6 + sortable) | 6.x | Tri des équipes (inter-tier), accessible DnD |
| Primitives UI | Radix UI (label, slot) + cva + tailwind-merge | — | Composants shadcn-style dans `src/shared/components/ui/` |
| Routing | React Router | 7 | Routes déclaratives, nested layouts |
| Icons | lucide-react | latest | Icônes SVG tree-shakeable |
| Types API | — (manuels) | — | Types API écrits à la main par feature (`features/*/api.ts`) ; le codegen `openapi-typescript`/`types.gen.ts` a été **supprimé** (FRT-15 : 8365 l., 0 import, source de vérité fantôme) |

### Principes de la stack

- **Pas de Redux.** Zustand + TanStack Query couvrent tous les cas d'usage.
- **Pas d'Axios.** ky remplace — plus léger, hooks natifs, basé sur fetch.
- **Pas de CSS-in-JS.** Tailwind 4 uniquement. Les styles dynamiques via `clsx` + conditional classes.
- **Pas de i18n framework.** MVP = français uniquement. Les strings sont en dur dans les composants.
- **Pas de FullCalendar, pas de date-fns, pas de React Hook Form/Zod** dans le code livré :
  grille custom `WeekGrid`, formulaires contrôlés simples avec validation manuelle.
- **TypeScript strict.** `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true`.

---

## 2. Routes / Objectives

Chaque route a un objectif produit précis. Le routing utilise React Router 7 avec nested
layouts (`src/app/router.tsx`).

| Route | Objectif | Auth | Layout |
|-------|----------|------|--------|
| `/login` | Connexion gestionnaire (email + password) | Public | `AuthLayout` |
| `/register` | Inscription (A3) : soumet le formulaire → écran « vérifie tes emails » (aucune session ; le club et le JWT sont créés à la vérification) | Public | `AuthLayout` |
| `/verify-email/:token` | Consomme le lien email → crée/rejoint le club, connecte, redirige (`/waiting` si pending, sinon `/`) | Public | `AuthLayout` |
| `/forgot-password` | Demande de réinitialisation de mot de passe (`POST /api/password/forgot`) | Public | `AuthLayout` |
| `/reset-password/:token` | Saisie du nouveau mot de passe (`POST /api/password/reset`) | Public | `AuthLayout` |
| `/waiting` | Attente d'approbation (`WaitingApprovalPage`) — poll `/api/me` toutes les 5 s, redirige vers `/` dès `membershipStatus === "active"` | Token requis | `AuthLayout` |
| `/` | **Cockpit temporel** (`CockpitPage`) : bandeau planning principal (Ouvrir/**Modifier** = reopen · Tous les plannings) · calendrier mensuel des exceptions · radar (à traiter). **Débloqué (sticky) dès `me.socleValidatedAt` non null** ; sinon redirige vers `/planning`. **Palier B** : CTAs radar « Adapter » actifs (→ wizard mode période) ; « Voir le plan » (overlay généré → consultation) ; « Modifier » le socle avec overlays → **confirmation proportionnée** (409 `overlays_exist` → dialog « supprimera N secondaires »). Overlays exclus du sélecteur de plannings (badge « Période ») | Required | `AppLayout` |
| `/planning` | **Boucle de travail planning** (`PlanningPage`, ex-`/`) : grille `WeekGrid`, toolbar (**sélecteur de versions** « V3 — 10 juil. 14:32 » — planning-versions D1 : versions non renommables, suppression d'une version de travail avec confirmation, ARCHIVED masquées ; régénérer, valider — archive les sœurs et fixe la baseline —, rouvrir, planning principal ★), **nom du planning éditable au header** (`Season.planningName`), bandeau divergence structure, diagnostics, détail créneau | Required | `AppLayout` |
| `/matchs` | **Module matchs** (`MatchesPage`) : placement des rencontres domicile (grille week-end), radar de conflits coach/joueur, import FBI (`ImportFbiDialog`) | Required | `AppLayout` |
| `/wizard` | Assistant de saisie 6 étapes : Équipes → Gymnases → Coachs → Contraintes → Récapitulatif → Génération (`AuthGuard` y redirige tant que `onboardingCompleted === false`) | Required | `AppLayout` |
| `/club` | Identité du club : logo (upload + recadrage `LogoCropper` + suppression), couleur d'accent (+ palette) **et section « Demandes »** (approbation des adhésions `pending`, admin — l'ancienne route `/pending-members` a été repliée ici) | Required | `AppLayout` |
| `/profile` | Profil utilisateur | Required | `AppLayout` |

> Toute URL authentifiée inconnue (dont l'ancienne `/pending-members`) redirige vers `/` (catch-all `router.tsx`).

### Guards et redirects (`src/app/AuthGuard.tsx`)

- Pas de JWT dans `authStore` → redirect `/login` ; 401 API (hors `/api/login`) → clear + redirect `/login` (hook ky `afterResponse`).
- `membershipStatus === "pending"` → `/waiting`.
- `club.onboardingCompleted === false` → redirect `/wizard` (sauf si déjà sur `/wizard`).
- **Gate cockpit (sticky)** : `CockpitPage` redirige vers `/planning` tant que `me.socleValidatedAt === null` (le socle n'a jamais été validé). Le jalon est posé côté backend à la 1re validation du baseline et **jamais retiré** — voir `planning-lifecycle-validated.md` et `specs/courantes/accueil-cockpit-temporel.md` §2ter.

### Routes non livrées

Il n'existe **pas** de routes `/dashboard`, `/teams`, `/priorities`, `/schedules/:id` ni
`/schedules/:id/diagnostics` : le planning et ses diagnostics vivent sur `/`, le CRUD
équipes/salles/coachs et le tri par priorité vivent dans le wizard (`/wizard`, rééditable).

---

## 3. State Management Strategy

Deux couches distinctes, responsabilités non chevauchantes.

| Couche | Outil | Responsabilité | Règle |
|--------|-------|----------------|-------|
| Server state | TanStack Query 5 | Données issues de l'API (resources, collections, mutations) | **Toujours** via Query. Jamais de state local pour des données serveur. |
| Client state | Zustand 5 | État UI pur, token JWT, thème, préférences | **Jamais** de données serveur en Zustand. Sync via Query callbacks. |

### Frontière stricte

```typescript
// Illustration — frontière Zustand / TanStack Query

// ✅ Zustand : état UI pur, pas de données serveur (authStore réel : token seul)
type AuthStore = {
  token: string | null;
  setToken: (token: string | null) => void;
  clear: () => void;
};

// ✅ TanStack Query : données serveur, cache, invalidation
const schedulesQuery = useQuery({
  queryKey: ['schedules', { clubId, seasonId }],
  queryFn: () => api.get('schedules', { clubId, seasonId }),
});

// ❌ Interdit : stocker le résultat de useQuery dans Zustand
// ❌ Interdit : faire un fetch manuel dans un composant sans passer par Query
```

### Quand utiliser Zustand vs TanStack Query

| Situation | Choix | Raison |
|-----------|-------|--------|
| JWT token après login | Zustand (`authStore`, persist `cs-auth`) | Persistant côté client, pas une resource API |
| Contexte tenant (club/saison) | **Aucun état client** | Résolu côté serveur depuis le JWT (`TenantFilterListener`) — le frontend n'envoie aucun header tenant |
| Thème clair/sombre | Zustand (`themeStore`) | UI pure ; l'accent club vient de `/api/me` via `useApplyClubTheme` |
| État UI wizard / planning | Zustand (stores de feature `store.ts`) | UI pure, pas de persistence serveur |
| Liste des équipes | TanStack Query | Donnée serveur, cacheable, invalidable |
| Statut d'une génération | TanStack Query + polling (`refetchInterval`) | Donnée serveur quasi temps réel |
| Formulaires wizard | État local contrôlé | Formulaires simples, soumis puis invalidés via Query |

---

## 4. HTTP Client Strategy

ky 2 comme unique client HTTP. Configuration centralisée, jamais instancié ad-hoc dans les composants.

### Instance configurée (`src/shared/api/client.ts`)

```typescript
// Extrait fidèle au code livré
export const api = ky.create({
  prefix: "/api", // proxy Vite dev, Nginx prod — jamais de host en dur
  hooks: {
    beforeRequest: [
      (state) => {
        const token = useAuthStore.getState().token;
        if (token) state.request.headers.set("Authorization", `Bearer ${token}`);
      },
    ],
    afterResponse: [
      (state) => {
        // 401 sur /api/login = mauvais identifiants (géré par l'appelant).
        const isLogin = state.request.url.includes("/api/login");
        if (state.response.status === 401 && !isLogin) {
          useAuthStore.getState().clear();
          window.location.assign("/login");
        }
      },
    ],
  },
});
```

### Règles

- **Toutes les requêtes passent par l'instance `api` ky.** Pas de `fetch()` direct dans les composants.
- **Aucun header tenant.** Le club et la saison actifs sont résolus **côté serveur** depuis la
  membership du JWT (`backend-inventory.md` §4). Le frontend n'envoie ni `X-Club-Id` ni `X-Season-Id`.
- **401 → logout automatique** (sauf sur `/api/login`). Le hook `afterResponse` vide le store et redirige vers `/login`.
- **Pas de hardcodage d'URL.** `prefix: '/api'` utilise le proxy Vite en dev et Nginx en prod.
- **Content-Type.** API Platform sert du JSON-LD (`application/ld+json`). Le déballage hydra vit dans `src/shared/api/collection.ts`.

### Proxy Vite (dev)

```typescript
// vite.config.ts — réel (extrait)
export default defineConfig({
  server: {
    proxy: {
      '/api': { target: 'http://127.0.0.1:8080', changeOrigin: true },
      '/.well-known/mercure': { target: 'http://127.0.0.1:3000', changeOrigin: true },
      '/engine': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
});
```

En production, le Nginx frontend proxy `/api` → backend Nginx et `/.well-known/mercure` → Mercure hub.

---

## 5. Suivi temps réel de la génération — Polling (Mercure non consommé)

**État livré : le frontend ne consomme PAS Mercure.** Aucun `EventSource` dans `frontend/src/`.
Le suivi de génération se fait par **polling TanStack Query** (`src/features/planning/queries.ts`) :
la query des schedules a un `refetchInterval` de **2 500 ms tant qu'un planning est en vol**
(statut `PENDING`/`GENERATING`), désactivé sinon. `WaitingApprovalPage` poll `/api/me` toutes les 5 s.

Côté infra, le backend publie bien sur Mercure (topic `club:{clubId}:schedule:{scheduleId}`,
voir `backend-inventory.md` §5) et les proxies existent (Vite dev et Nginx prod exposent
`/.well-known/mercure`) — la bascule polling → SSE reste donc possible sans changement d'infra.

### Règles (si la consommation SSE est introduite un jour)

- **EventSource sur `/.well-known/mercure`.** Jamais d'URL hardcodée vers le hub Mercure directement.
- **Invalidation Query sur événement**, pas de mutation directe du cache sauf pour le statut.
- Tant que ce n'est pas fait, le polling à 2,5 s pendant la génération est la référence.

---

## 6. Besoins identifiés par l'expérience (forward)

Cette section capture les besoins frontend qui émergent de l'expérience produit, pas du
code existant. Ils guident le rebuild.

### 6.1 Onboarding guidé non-négociable

Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le frontend doit
le guider étape par étape sans le perdre. Le wizard livré compte **6 étapes** (Équipes →
Gymnases → Coachs → Contraintes → Récapitulatif → Génération — détail : `frontend-wizard.md`).
Le frontend doit :

- Sauvegarder à chaque étape (mutations API immédiates)
- Permettre la navigation arrière sans perte
- Valider chaque étape (`useStepValidation`, erreurs bloquantes + avertissements non bloquants)

### 6.2 Visualisation planning = `WeekGrid` (custom)

Le planning est une semaine type (lun-sam), rendu par le composant maison `WeekGrid`
(`src/features/planning/WeekGrid.tsx` + `lib/grid.ts`) — pas de FullCalendar :

- Créneaux colorés, filtre par ressource (`ResourceFilter` : équipe / coach / salle)
- Click sur créneau → détail (`SlotDetail` : équipe, coach, salle, verrou)
- Lecture seule quand le planning est `VALIDATED` (verrou d'édition)
- Pas de vue mensuelle — le planning est hebdomadaire type

### 6.3 Tri des équipes drag & drop (mode « Trier » du wizard)

La priorisation des équipes (S/A/B/C/D) vit dans l'étape Équipes du wizard
(`TeamsStep`, bouton « Trier » / « Terminer le tri ») :

- @dnd-kit (`useSortable` + zones droppables par tier) — **drag & drop inter-tier** :
  une équipe peut être déposée dans un autre tier, flèches haut/bas en fallback clavier/a11y
- Couleurs et libellés de tiers cohérents avec le planning
- Sauvegarde **en bulk atomique** à la fin du tri : `POST /api/teams/reorder` avec
  `{ items: [{ id, priorityTierId, tierOrder }] }` (une transaction — remplace les N
  `PUT /api/teams/{id}` concurrents qui perdaient des mises à jour sur le lock optimiste)

### 6.4 Diagnostics en langage gestionnaire

Le rapport post-génération affiche les `schedule_diagnostics` avec :

- Regroupement par severity (error > warning > info)
- Messages tels que rédigés côté backend (langage gestionnaire, pas technique)
- Liens directs vers l'entité à corriger (équipe, coach, salle)
- Pas d'auto-correction MVP — l'utilisateur clique → navigue vers l'entité

### 6.5 Export PDF asynchrone avec feedback — NON LIVRÉ

Le backend expose `POST /api/schedules/{id}/export-pdf` (asynchrone, Messenger), mais
**aucune UI d'export PDF n'existe dans le frontend livré** (aucun appel à `export-pdf`
dans `frontend/src/`). Besoin forward conservé : statut « Génération PDF en cours »,
activation du bouton de téléchargement à réception, timeout UX 60 s.

### 6.6 Multi-tenant transparent

Le gestionnaire ne voit jamais le concept de `club_id` ou `season_id`. Le frontend :

- N'envoie **aucun** header tenant : le backend dérive club et saison actifs de la
  membership du JWT (`TenantFilterListener`)
- N'affiche jamais de sélecteur de club (un user = un club en MVP)
- Le sélecteur de saison est implicite (saison active par défaut)

### 6.6 bis Cycle de vie du planning (VALIDATED)

- Un planning `COMPLETED` peut être **validé** (bouton « Valider » de la toolbar) → modale de
  confirmation (`ValidateDialog`, avertit si des alertes subsistent) → `POST /api/schedules/{id}/validate`
  → statut `VALIDATED`, planning en **lecture seule** (grille non éditable, renommage et
  régénération masqués).
- « Rouvrir » (`POST /api/schedules/{id}/reopen`) ramène en `COMPLETED` (rééditable).
- « Définir principal » (`POST /api/schedules/{id}/set-baseline`) marque le planning ★ de la
  saison (affiché dans le sélecteur ; `baselineScheduleId` vient de `/api/me`).

### 6.7 Optimistic updates pour édition manuelle

Quand le gestionnaire déplace un créneau dans la grille (`WeekGrid`) :

1. UI se met à jour immédiatement (optimistic)
2. Mutation `POST /api/schedule-slots/{id}/manual-edit/one-time` envoyée
3. Si 409 (conflit) → rollback + message "Ce créneau est en conflit"
4. Si succès → dialogue post-modification (contrainte permanente / lock / ponctuel)

### 6.8 Loading states et error boundaries

Chaque route a :

- Un skeleton loader pendant le chargement initial (pas de spinner vide)
- Un error boundary React qui affiche un message + bouton "Réessayer"
- Pas de page blanche en cas d'erreur API

---

## 7. TanStack Query Strategy

### Conventions de query keys

```typescript
// Illustration — hiérarchie de query keys (clé réelle du profil : ["me"])
type QueryKey =
  | ['me']                                       // GET /api/me
  | ['schedules', { seasonId: string }]          // GET /api/schedules?seasonId=X
  | ['schedules', scheduleId]                    // GET /api/schedules/{id}
  | ['schedule-slots', scheduleId]               // GET /api/schedule-slot-templates?scheduleId=X
  | ['schedule-diagnostics', scheduleId]         // GET /api/schedule-diagnostics?scheduleId=X
  | ['teams', { seasonId: string }]              // GET /api/teams
  | ['priority-tiers']                           // GET /api/priority-tiers (cache longue durée)
  | ['sport-categories']                         // GET /api/sport-categories
  | ['venues', { seasonId: string }]             // GET /api/venues
  | ['coaches', { seasonId: string }]            // GET /api/coaches
  ;
```

### Stale time par type de donnée

| Type de donnée | `staleTime` | Raison |
|----------------|-------------|--------|
| Auth (`/api/me`) | 5 min | Change rarement, mais doit détecter logout côté serveur |
| Référentiels (tiers, categories, sports) | 30 min | Données quasi-statiques |
| Collections métier (teams, venues, coaches) | 1 min | Changent pendant la saisie |
| Schedule + slots | 0 (toujours stale) | Temps réel via Mercure, re-fetch systématique |
| Diagnostics | 0 | Re-fetch après génération |

### Mutations et invalidation

```typescript
// Illustration — pattern mutation + invalidation
const useGenerateSchedule = () => {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      api.post(`schedules/${scheduleId}/generate`),
    onSuccess: (_, scheduleId) => {
      // Le statut arrive via SSE, on invalide pour le re-fetch
      queryClient.invalidateQueries({
        queryKey: ['schedules', scheduleId],
      });
    },
  });
};
```

### Pagination JSON-LD

API Platform sert des collections au format JSON-LD (`hydra:member`, `hydra:totalItems`, `hydra:view`).

```typescript
// Illustration — parsing collection JSON-LD
type HydraCollection<T> = {
  'hydra:member': T[];
  'hydra:totalItems': number;
  'hydra:view'?: {
    'hydra:next'?: string;
    'hydra:previous'?: string;
  };
};

// Livré : collection()/collectionAll() (src/shared/api/collection.ts) déballent
// hydra:member et suivent hydra:next pour agréger toutes les pages — pas
// d'useInfiniteQuery dans le code actuel.
```

### Règles

- **Pas de `useQuery` sans `queryKey` structuré.** Les keys sont typées et hiérarchiques.
- **Toutes les mutations invalident explicitement.** Pas d'invalidation globale (`invalidateQueries()` sans key).
- **Pas de `queryClient.setQueryData` sauf pour statut temps réel SSE.** Préférer invalidation + re-fetch.
- **`enabled` conditionnel pour les queries dépendantes.** Ex: slots query `enabled: !!scheduleId`.

---

## 8. Zustand Strategy

### Stores (livrés)

| Store | Fichier | Contenu | Persistence |
|-------|---------|---------|-------------|
| `authStore` | `src/shared/stores/authStore.ts` | `token` uniquement (`setToken`, `clear`) | `localStorage` (`persist`, clé `cs-auth`, `migrate` avec null-check) |
| `themeStore` | `src/shared/stores/themeStore.ts` | mode clair/sombre | persisté |
| wizard `store` | `src/features/wizard/store.ts` | étape courante + état UI du wizard | Non persisté |
| planning `store` | `src/features/planning/store.ts` | planning sélectionné + état UI (vue, filtres) | Non persisté |

### authStore

```typescript
// Fidèle au code livré — le token est le SEUL état d'auth client.
type AuthState = {
  token: string | null;
  setToken: (token: string | null) => void;
  clear: () => void;
};

// Tout le reste (user, club, membershipStatus, baselineScheduleId, accent)
// vient de GET /api/me via TanStack Query (queryKey ["me"]).
```

### Règles

- **Un store par domaine.** Pas de store global "app" qui mélange tout.
- **Pas de données serveur en Zustand.** Si ça vient de l'API, c'est en TanStack Query.
- **Actions dans le store, pas dans les composants.** `login()`, `logout()`, `setContext()` vivent dans le store.
- **Pas de middleware complexe.** `persist` pour le token, c'est tout. Pas de `devtools` en prod.
- **Sélecteurs fins.** `useAuthStore((s) => s.token)` pour éviter les re-renders inutiles.

---

## 9. Contrat API Frontend ↔ Backend

> Ce section référence le contrat API. L'inventaire complet des ressources, contrôleurs, et
> sécurité est dans `backend-inventory.md`. Le snapshot OpenAPI complet est dans
> `openapi-snapshot.json`. Ce section ne duplique pas — il spécifie comment le frontend
> consomme le contrat.

### Références contrat

| Document | Rôle | Localisation |
|----------|------|--------------|
| `backend-inventory.md` | Inventaire backward : resources API Platform, contrôleurs custom, sécurité JWT, Mercure, pagination | `specs/courantes/backend-inventory.md` |
| `openapi-snapshot.json` | Snapshot OpenAPI 3.1 des ressources API Platform (contrat/doc ; plus de codegen front — types API manuels depuis FRT-15) | `specs/courantes/openapi-snapshot.json` |

### Endpoints consommés par le frontend (par route)

| Route frontend | Endpoints backend consommés |
|----------------|---------------------------|
| `/login` | `POST /api/login` |
| `/register` | `POST /api/register` (202, écran « vérifie tes emails ») |
| `/verify-email/:token` | `POST /api/register/verify` (émet le JWT → app) |
| `/forgot-password`, `/reset-password/:token` | `POST /api/password/forgot`, `POST /api/password/reset` |
| `/waiting` | `GET /api/me` (poll 5 s jusqu'à `membershipStatus === "active"`) |
| `/` (planning) | `GET /api/me`, `GET /api/schedules` (poll 2,5 s si génération en vol), `GET /api/schedule_slot_templates?scheduleId={id}`, `GET /api/schedule_diagnostics?scheduleId={id}`, `POST /api/schedules/{id}/generate`, `POST /api/schedules/{id}/validate`, `POST /api/schedules/{id}/reopen`, `POST /api/schedules/{id}/set-baseline`, `PUT /api/schedules/{id}` (renommage), `POST /api/schedule-slots/{id}/manual-edit/lock`, `POST /api/schedule-slots/{id}/manual-edit/one-time`, collections référentiels (`teams`, `venues`, `coaches`, `sport_categories`, `team_coaches`, `coach_player_memberships`) |
| `/wizard` | CRUD `teams`/`venues`/`coaches`/`constraints`/`venue_training_slots`…, `GET /api/priority_tiers`, `GET /api/sport_categories`, `POST /api/teams/reorder` (mode tri), `POST /api/constraints/validate`, `POST /api/schedules` + `generate` (étape Génération) |
| `/club` | `PATCH /api/club/appearance`, `POST/DELETE /api/club/logo`, `GET /api/clubs/{clubId}/logo` (public, cache-buster sur l'URL après upload) |
| `/pending-members` | `GET /api/memberships/pending`, `POST /api/memberships/{id}/approve`, `POST /api/memberships/{id}/reject` |
| `/profile` | `GET /api/me` |

### Headers obligatoires

| Header | Source | Injection |
|--------|--------|-----------|
| `Authorization: Bearer {jwt}` | `authStore.token` | ky `beforeRequest` hook |

**Aucun header tenant** : `X-Club-Id`/`X-Season-Id` restent supportés côté backend comme
override (tests), mais le frontend ne les envoie pas — le tenant est dérivé du JWT
(`backend-inventory.md` §4).

### Authentification

| Endpoint | Méthode | Body | Réponse | Action frontend |
|----------|---------|------|---------|-----------------|
| `/api/login` | POST | `{ email, password }` | `{ token }` (JWT) | Stocker token en Zustand, redirect `/` |
| `/api/register` | POST | `{ email, password, firstName, lastName, ara, club_name? }` | **202** `{ status:"verification_pending" }` (aucun token — A3) | Afficher l'écran « vérifie tes emails » ; **pas de redirect** (le JWT vient de la vérification) |
| `/api/register/verify` | POST | `{ token }` (du lien email) | `{ token, membershipStatus, user }` | Stocker token ; `pending` → `/waiting`, sinon `/` |
| `/api/me` | GET | — | `{ id, email, firstName, lastName, membershipStatus, role, club: { id, name, onboardingCompleted, logoUrl, accentColor, accentPalette }, baselineScheduleId, hasGenerated }` | Query `["me"]` — source des guards, du thème (accent) et du planning principal |

Référence : `backend-inventory.md` §3 (AuthController, PasswordController, MembershipController).

### Génération asynchrone

| Étape | Endpoint | Statut HTTP | Frontend |
|-------|----------|-------------|----------|
| Lancer | `POST /api/schedules/{id}/generate` | 202 | Mutation TanStack Query, écran `GenerationWaiting` |
| Suivi | `GET /api/schedules` (polling) | 200 | `refetchInterval` 2 500 ms tant que `PENDING`/`GENERATING` (§5) |
| Résultat | `GET /api/schedule_slot_templates?scheduleId={id}` | 200 | Re-fetch slots à la fin du polling |
| Diagnostics | `GET /api/schedule_diagnostics?scheduleId={id}` | 200 | Afficher rapport (`DiagnosticsPanel`) |

Référence : `backend-inventory.md` §3 (GenerateScheduleController) + §5 (Mercure, publié mais non consommé côté frontend).

### Édition manuelle

| Endpoint | Méthode | Body | Réponse | Dialogue frontend |
|----------|---------|------|---------|-------------------|
| `/api/schedule-slots/{id}/manual-edit/constraint` | POST | `{ type, reason, createdBy }` | 201 `{ constraintId }` | "Créer contrainte permanente" |
| `/api/schedule-slots/{id}/manual-edit/lock` | POST | `{ lockLevel }` | 200 | "Verrouiller SOFT/HARD" |
| `/api/schedule-slots/{id}/manual-edit/one-time` | POST | `{ startTime? }` | 200 / 409 conflit | "Juste ponctuel" + rollback si 409 |

Référence : `backend-inventory.md` §3 (ManualEditController).

### Pagination

Toutes les collections API Platform sont paginées à 30 items/page (JSON-LD).

- `hydra:member` : items de la page
- `hydra:totalItems` : total
- `hydra:view` : navigation (`hydra:next`, `hydra:previous`)
- Query param `page` pour la pagination

Le frontend utilise `useInfiniteQuery` pour les listes longues (teams, diagnostics) et `useQuery` pour les collections courtes (tiers, categories).

Référence : `backend-inventory.md` §6.

### Formats

- **Requêtes** : `application/json` (ky default)
- **Réponses collections** : `application/ld+json` (JSON-LD)
- **Réponses item** : `application/ld+json` ou `application/json`
- **Import Excel** : `multipart/form-data` (file + seasonId)

Référence : `backend-inventory.md` §1 (config API Platform).

---

## 10. Conventions de code frontend

### Structure des dossiers (livrée)

```
frontend/src/
├── main.tsx                    # Entry point
├── index.css                   # Tailwind 4 (@theme) + variables d'accent
├── app/                        # AppLayout, AuthGuard, providers, router
├── features/                   # Logique métier par domaine (liste : ls src/features/)
│   ├── auth/                   # Login/Register/ForgotPassword/ResetPassword/WaitingApproval + api/queries
│   ├── club/                   # ClubPage (logo + accent + section Demandes/approbation), LogoCropper
│   ├── cockpit/                # CockpitPage : bandeau planning socle, calendrier mensuel, radar overlays
│   ├── matches/                # MatchesPage : grille week-end, radar conflits, ImportFbiDialog
│   ├── planning/               # PlanningPage, PlanningToolbar, WeekGrid, SlotDetail, DiagnosticsPanel, ResourceFilter, GenerationWaiting, store, lib/grid
│   ├── profile/                # ProfilePage
│   ├── season-transition/      # SeasonSelector, SeasonTransitionBanner, transitionUiStore
│   └── wizard/                 # WizardLayout, steps/ (Teams, Venues, Coaches, Constraints, Recap, Generate), lib/, store
├── shared/
│   ├── api/                    # client ky, collection (hydra), errors
│   ├── components/ui/          # Composants UI réutilisables (shadcn-style)
│   ├── hooks/                  # useApplyTheme, useApplyClubTheme
│   ├── lib/                    # color, palette, queryClient, utils
│   └── stores/                 # authStore, themeStore
└── test/                       # setup vitest
```

### Alias

- `@/` → `src/` (configuré dans `vite.config.ts` et `tsconfig.json`)

### Naming

- Composants : `PascalCase` (`ScheduleCalendar.tsx`)
- Hooks : `camelCase` préfixé `use` (`useApplyClubTheme.ts`)
- Stores : `camelCase` + `Store` (`authStore.ts`)
- Types : `PascalCase` (`ScheduleSlot`, `HydraCollection`)
- Query keys : `kebab-case` strings (`['schedule-slots', id]`)

### Tests

- Vitest + React Testing Library + MSW (Mock Service Worker) pour les tests composants (`*.test.tsx` co-localisés)
- Harnais E2E Playwright présent dans `frontend/tests/e2e/` (`@playwright/test` en devDependency)
- Couverture : composants critiques (auth, planning, toolbar, grille, wizard)

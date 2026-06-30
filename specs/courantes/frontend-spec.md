# Frontend Spec — Forward

> Specification FORWARD pour le rebuild du frontend ClubScheduler. Ce document décrit ce qui
> doit être construit, pas ce qui existe. L'inventaire backward du backend est dans
> `backend-inventory.md` — ce document le référence sans le dupliquer.

Last verified @ 6e35a6ce 2026-06-30

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
| Client state | Zustand | 5 | Stores globaux légers (auth, UI, tenant context) |
| HTTP client | ky | 2 | Fetch wrapper, interceptors, retry, hooks |
| Calendrier | FullCalendar | 6 | Grille planning hebdomadaire, drag-and-drop créneaux |
| Drag & drop | @dnd-kit | v0.5 | Tier list, réordonnancement, accessible DnD |
| Routing | React Router | 7 | Routes déclaratives, loaders, nested layouts |
| Date handling | date-fns + date-fns-tz | latest | Manipulation dates, timezone club |
| Forms | React Hook Form + Zod | latest | Validation schemas, formulaires wizard |
| Icons | lucide-react | latest | Icônes SVG tree-shakeable |

### Principes de la stack

- **Pas de Redux.** Zustand + TanStack Query couvrent tous les cas d'usage.
- **Pas d'Axios.** ky remplace — plus léger, hooks natifs, basé sur fetch.
- **Pas de CSS-in-JS.** Tailwind 4 uniquement. Les styles dynamiques via `clsx` + conditional classes.
- **Pas de i18n framework.** MVP = français uniquement. Les strings sont en dur dans les composants.
- **TypeScript strict.** `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true`.

---

## 2. Routes / Objectives

Chaque route a un objectif produit précis. Le routing utilise React Router 7 avec nested layouts.

| Route | Objectif | Auth | Layout |
|-------|----------|------|--------|
| `/login` | Connexion gestionnaire (email + password) | Public | `AuthLayout` |
| `/register` | Inscription club (crée User + Club + Season + Sport + Categories) | Public | `AuthLayout` |
| `/` | Redirect post-login : si `onboarding_completed=false` → `/wizard`, sinon → `/dashboard` | Required | `AppLayout` |
| `/wizard` | Onboarding initial 4 étapes "Draft hybride" (réservé T12 — non détaillé ici) | Required | `WizardLayout` |
| `/schedules/:id` | Visualisation planning hebdomadaire FullCalendar + actions génération/export | Required | `AppLayout` |
| `/schedules/:id/diagnostics` | Rapport post-génération : erreurs, avertissements, suggestions | Required | `AppLayout` |
| `/profile` | Profil utilisateur + club settings (timezone, school_zone, plan) | Required | `AppLayout` |
| `/teams` | CRUD équipes : catégorie, priorité, coaches, contraintes, sessions/semaine | Required | `AppLayout` |
| `/priorities` | Tier list drag & drop S/A/B/C/D + min_sessions_override par tier | Required | `AppLayout` |
| `/dashboard` | Vue d'ensemble : statut saison, dernière génération, alertes diagnostics | Required | `AppLayout` |

### Guards et redirects

```typescript
// Illustration — guard logic, pas un fichier .ts
function requireAuth(): RouteGuard {
  // Si pas de JWT en Zustand auth store → redirect /login
  // Si JWT expiré (401 intercepté par ky) → logout + redirect /login
}

function requireOnboarding(): RouteGuard {
  // Si club.onboarding_completed === false → redirect /wizard
  // Sauf si déjà sur /wizard ou /login ou /register
}
```

### Routes non-MVP (réservées P1.5+)

Les routes suivantes ne sont PAS dans le scope du rebuild MVP. Elles sont listées pour
anticiper la structure mais ne doivent pas être implémentées :

- `/schedules/:id/periods` — périodes d'exception (P1.5)
- `/venues` — CRUD salles + matrice trajets (P1.5)
- `/coaches` — CRUD coaches + indisponibilités (P1.5)
- `/audit` — audit trail (P1.5)
- `/admin` — super-admin dashboard (P2)

---

## 3. State Management Strategy

Deux couches distinctes, responsabilités non chevauchantes.

| Couche | Outil | Responsabilité | Règle |
|--------|-------|----------------|-------|
| Server state | TanStack Query 5 | Données issues de l'API (resources, collections, mutations) | **Toujours** via Query. Jamais de state local pour des données serveur. |
| Client state | Zustand 5 | État UI pur, auth, tenant context, préférences | **Jamais** de données serveur en Zustand. Sync via Query callbacks. |

### Frontière stricte

```typescript
// Illustration — frontière Zustand / TanStack Query

// ✅ Zustand : état UI pur, pas de données serveur
type AuthStore = {
  token: string | null;
  clubId: string | null;
  seasonId: string | null;
  login: (token: string, clubId: string) => void;
  logout: () => void;
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
| JWT token après login | Zustand | Persistant côté client, pas une resource API |
| clubId / seasonId actifs | Zustand | Contexte tenant, injecté en header, pas une query |
| État sidebar/panel ouvert | Zustand | UI pure, pas de persistence serveur |
| Liste des équipes | TanStack Query | Donnée serveur, cacheable, invalidable |
| Statut d'une génération | TanStack Query + Mercure | Donnée serveur temps réel |
| Formulaires wizard | React Hook Form | État formulaire local, soumis puis invalidé via Query |

---

## 4. HTTP Client Strategy

ky 2 comme unique client HTTP. Configuration centralisée, jamais instancié ad-hoc dans les composants.

### Instance configurée

```typescript
// Illustration — configuration ky, pas un fichier .ts
import ky from 'ky';

const api = ky.create({
  prefixUrl: '/api',           // proxy Vite dev → backend:8080
  hooks: {
    beforeRequest: [
      (request) => {
        const { token, clubId, seasonId } = useAuthStore.getState();
        if (token) request.headers.set('Authorization', `Bearer ${token}`);
        if (clubId) request.headers.set('X-Club-Id', clubId);
        if (seasonId) request.headers.set('X-Season-Id', seasonId);
      },
    ],
    afterResponse: [
      async (request, options, response) => {
        if (response.status === 401) {
          useAuthStore.getState().logout();
          window.location.href = '/login';
        }
      },
    ],
  },
  retry: { limit: 2, methods: ['get'], statusCodes: [502, 503] },
});
```

### Règles

- **Toutes les requêtes passent par l'instance `api` ky.** Pas de `fetch()` direct dans les composants.
- **Headers tenant automatiques.** `X-Club-Id` et `X-Season-Id` injectés par le hook `beforeRequest` depuis Zustand.
- **401 → logout automatique.** Le hook `afterResponse` déconnecte et redirige vers `/login`.
- **Retry limité aux GET.** Les mutations (POST/PUT/DELETE) ne sont jamais retry automatiquement.
- **Pas de hardcodage d'URL.** `prefixUrl: '/api'` utilise le proxy Vite en dev et Nginx en prod.
- **Content-Type.** API Platform sert du JSON-LD (`application/ld+json`). ky négocie automatiquement.

### Proxy Vite (dev)

```typescript
// vite.config.ts — illustration
export default defineConfig({
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
      '/.well-known/mercure': 'http://localhost:3000',
    },
  },
});
```

En production, le Nginx frontend proxy `/api` → backend Nginx et `/.well-known/mercure` → Mercure hub.

---

## 5. Mercure SSE Strategy

Le frontend consomme les événements Mercure pour le temps réel (génération de planning, export PDF).

### Topic

Format : `club:{clubId}:schedule:{scheduleId}`

Référence : `backend-inventory.md` §5 — la publication est effectuée par `GenerateScheduleHandler` et `ExportPdfHandler`.

### Connexion EventSource

```typescript
// Illustration — hook useScheduleSSE, pas un fichier .ts
function useScheduleSSE(scheduleId: string) {
  const { clubId } = useAuthStore.getState();
  const queryClient = useQueryClient();

  useEffect(() => {
    const url = `/.well-known/mercure?topic=club:${clubId}:schedule:${scheduleId}`;
    const es = new EventSource(url);

    es.addEventListener('message', (event) => {
      const data = JSON.parse(event.data);
      // Invalide le cache du schedule pour re-fetch
      queryClient.invalidateQueries({
        queryKey: ['schedules', scheduleId],
      });
      // Met à jour le statut en temps réel
      queryClient.setQueryData(['schedule-status', scheduleId], data);
    });

    return () => es.close();
  }, [clubId, scheduleId, queryClient]);
}
```

### Règles

- **EventSource sur `/.well-known/mercure`.** Jamais d'URL hardcodée vers le hub Mercure directement.
- **Un EventSource par schedule actif.** Fermé au unmount du composant.
- **Invalidation Query sur événement.** Le SSE déclenche `invalidateQueries`, pas de mutation directe du cache sauf pour le statut.
- **Reconnexion automatique.** `EventSource` se reconnecte nativement. Pas de librairie supplémentaire.
- **Pas de polling de fallback.** Si SSE coupe, l'utilisateur attend la reconnexion. Le statut `generating` reste affiché.

### Événements attendus

| Événement | Source backend | Action frontend |
|-----------|---------------|-----------------|
| `{ status: 'queued' }` | `GenerateScheduleHandler` | Afficher spinner "En file d'attente" |
| `{ status: 'generating' }` | `GenerateScheduleHandler` | Afficher spinner "Génération en cours" |
| `{ status: 'done', score, unplaced, warnings }` | `GenerateScheduleHandler` | Re-fetch schedule + slots, afficher rapport |
| `{ status: 'failed' }` | `GenerateScheduleHandler` | Afficher erreur + diagnostics |
| `{ status: 'pdf_ready', url }` | `ExportPdfHandler` | Activer bouton téléchargement PDF |

---

## 6. Besoins identifiés par l'expérience (forward)

Cette section capture les besoins frontend qui émergent de l'expérience produit, pas du
code existant. Ils guident le rebuild.

### 6.1 Onboarding guidé non-négociable

Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le frontend doit
le guider étape par étape sans le perdre. Le wizard "Draft hybride" 4 étapes est figé
(réservé T12 — ne pas rouvrir le débat ici). Le frontend doit :

- Sauvegarder automatiquement à chaque étape (auto-save via `clubs.transition_data`)
- Permettre la navigation arrière sans perte
- Valider en temps réel chaque champ (Zod schemas)
- Afficher le mode démo comme option accessible

### 6.2 Visualisation planning = FullCalendar

Le planning est une semaine type (lun-sam). FullCalendar 6 avec vue `timeGridWeek` :

- Créneaux colorés par tier (S=rouge, A=orange, B=bleu, C=vert, D=gris)
- Drag-and-drop pour édition manuelle (déclenche dialogue post-modification)
- Click sur créneau → panel latéral avec détails (équipe, coach, salle, lock_level)
- Pas de vue mensuelle — le planning est hebdomadaire type

### 6.3 Tier list drag & drop intuitive

La priorisation des équipes (S/A/B/C/D) doit être visuelle et immédiate :

- @dnd-kit pour le drag-and-drop entre colonnes tier
- Compteur de sessions min par tier affiché en temps réel
- Couleurs cohérentes avec le planning (même palette tier)
- Sauvegarde automatique au drop (mutation `PUT /api/teams/{id}` avec `priority_tier_id`)

### 6.4 Diagnostics en langage gestionnaire

Le rapport post-génération affiche les `schedule_diagnostics` avec :

- Regroupement par severity (error > warning > info)
- Messages tels que rédigés côté backend (langage gestionnaire, pas technique)
- Liens directs vers l'entité à corriger (équipe, coach, salle)
- Pas d'auto-correction MVP — l'utilisateur clique → navigue vers l'entité

### 6.5 Export PDF asynchrone avec feedback

L'export PDF est asynchrone (Messenger queue). Le frontend doit :

- Afficher un statut "Génération PDF en cours" après `POST /api/schedules/{id}/export-pdf`
- Recevoir l'événement Mercure `{ status: 'pdf_ready', url }` 
- Activer le bouton de téléchargement à réception
- Timeout UX : si pas de réponse en 60s, afficher "Le PDF est encore en préparation, revenez plus tard"

### 6.6 Multi-tenant transparent

Le gestionnaire ne voit jamais le concept de `club_id` ou `season_id`. Le frontend :

- Stocke `clubId` et `seasonId` dans Zustand après login (depuis `/api/me`)
- Injecte automatiquement les headers `X-Club-Id` et `X-Season-Id` via ky
- N'affiche jamais de sélecteur de club (un user = un club en MVP)
- Le sélecteur de saison est implicite (saison active par défaut)

### 6.7 Optimistic updates pour édition manuelle

Quand le gestionnaire déplace un créneau dans FullCalendar :

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
// Illustration — hiérarchie de query keys
type QueryKey =
  | ['auth', 'me']                              // GET /api/me
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

// useInfiniteQuery pour les listes longues (teams, diagnostics)
// useQuery pour les collections courtes (tiers, categories)
```

### Règles

- **Pas de `useQuery` sans `queryKey` structuré.** Les keys sont typées et hiérarchiques.
- **Toutes les mutations invalident explicitement.** Pas d'invalidation globale (`invalidateQueries()` sans key).
- **Pas de `queryClient.setQueryData` sauf pour statut temps réel SSE.** Préférer invalidation + re-fetch.
- **`enabled` conditionnel pour les queries dépendantes.** Ex: slots query `enabled: !!scheduleId`.

---

## 8. Zustand Strategy

### Stores

| Store | Contenu | Persistence |
|-------|---------|-------------|
| `authStore` | `token`, `clubId`, `seasonId`, `user` (id, email, name) | `localStorage` (token only) |
| `uiStore` | `sidebarOpen`, `activeScheduleId`, `theme` | Non persisté |
| `wizardStore` | `currentStep`, `draftData` (auto-save) | Non persisté (auto-save API) |

### authStore

```typescript
// Illustration — authStore Zustand
type AuthStore = {
  token: string | null;
  clubId: string | null;
  seasonId: string | null;
  user: { id: string; email: string; firstName: string; lastName: string } | null;
  hasGenerated: boolean;
  login: (token: string) => void;
  setContext: (clubId: string, seasonId: string) => void;
  logout: () => void;
};

// Persistence partielle : seul le token survive au refresh
// clubId/seasonId sont re-fetch via /api/me au démarrage
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
| `backend-inventory.md` | Inventaire backward : 20 resources API Platform, 7 contrôleurs custom, sécurité JWT, Mercure, pagination | `specs/courantes/backend-inventory.md` |
| `openapi-snapshot.json` | Snapshot OpenAPI 3.1 complet (164KB) — toutes les routes, schemas, réponses | `specs/courantes/openapi-snapshot.json` |

### Endpoints consommés par le frontend (par route)

| Route frontend | Endpoints backend consommés |
|----------------|---------------------------|
| `/login` | `POST /api/login` (AuthController) |
| `/register` | `POST /api/register` (AuthController) |
| `/` (redirect) | `GET /api/me` (AuthController) — détermine `onboarding_completed` |
| `/wizard` | `GET/PUT /api/clubs/{id}`, `POST /api/clubs/{id}/import-teams`, `GET/POST /api/venues`, `GET/POST /api/coaches`, `GET/POST /api/teams`, `GET /api/priority-tiers`, `GET /api/sport-categories` (détail réservé T12) |
| `/schedules/:id` | `GET /api/schedules/{id}`, `GET /api/schedule-slot-templates?scheduleId={id}`, `POST /api/schedules/{id}/generate`, `POST /api/schedules/{id}/export-pdf`, `POST /api/schedule-slots/{id}/manual-edit/*` (ManualEditController) |
| `/schedules/:id/diagnostics` | `GET /api/schedule-diagnostics?scheduleId={id}` |
| `/profile` | `GET /api/me`, `GET/PUT /api/clubs/{id}`, `GET /api/users/{id}` |
| `/teams` | `GET /api/teams`, `POST /api/teams`, `PUT /api/teams/{id}`, `DELETE /api/teams/{id}`, `GET /api/team-coaches`, `GET /api/team-tags`, `GET /api/team-tag-assignments` |
| `/priorities` | `GET /api/priority-tiers`, `GET /api/teams`, `PUT /api/teams/{id}` (bulk update `priority_tier_id`) |
| `/dashboard` | `GET /api/me`, `GET /api/schedules?isActive=true&seasonId={id}`, `GET /api/schedule-diagnostics?scheduleId={id}` |

### Headers obligatoires

| Header | Source | Injection |
|--------|--------|-----------|
| `Authorization: Bearer {jwt}` | `authStore.token` | ky `beforeRequest` hook |
| `X-Club-Id: {uuid}` | `authStore.clubId` | ky `beforeRequest` hook |
| `X-Season-Id: {uuid}` | `authStore.seasonId` | ky `beforeRequest` hook |

Référence : `backend-inventory.md` §4 — `TenantFilterListener` résout `clubId` et `seasonId` depuis ces headers.

### Authentification

| Endpoint | Méthode | Body | Réponse | Action frontend |
|----------|---------|------|---------|-----------------|
| `/api/login` | POST | `{ email, password }` | `{ token }` (JWT) | Stocker token en Zustand, redirect `/` |
| `/api/register` | POST | `{ email, password, firstName, lastName, clubName, ara }` | `{ token }` (201) | Stocker token, redirect `/wizard` |
| `/api/me` | GET | — | `{ id, email, firstName, lastName, club: { id, name }, hasGenerated }` | Hydrate Zustand `authStore` + `clubId` |

Référence : `backend-inventory.md` §3 (AuthController).

### Génération asynchrone

| Étape | Endpoint | Statut HTTP | Frontend |
|-------|----------|-------------|----------|
| Lancer | `POST /api/schedules/{id}/generate` | 202 | Mutation TanStack Query, spinner |
| Suivi | Mercure SSE `club:{clubId}:schedule:{id}` | — | `EventSource`, invalidate Query |
| Résultat | `GET /api/schedules/{id}` | 200 | Re-fetch schedule + slots |
| Diagnostics | `GET /api/schedule-diagnostics?scheduleId={id}` | 200 | Afficher rapport |

Référence : `backend-inventory.md` §3 (GenerateScheduleController) + §5 (Mercure).

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

### Structure des dossiers (cible)

```
frontend/src/
├── main.tsx                    # Entry point
├── App.tsx                     # Router + providers
├── routes/                     # Pages (une par route)
│   ├── login/
│   ├── register/
│   ├── dashboard/
│   ├── wizard/                 # Réservé T12
│   ├── schedules/
│   │   ├── $id/
│   │   │   ├── index.tsx       # /schedules/:id
│   │   │   └── diagnostics.tsx # /schedules/:id/diagnostics
│   ├── teams/
│   ├── priorities/
│   └── profile/
├── features/                   # Logique métier par domaine
│   ├── auth/
│   ├── schedules/
│   ├── teams/
│   └── wizard/                 # Réservé T12
├── shared/                     # UI partagée, hooks, utils
│   ├── api/                    # Instance ky + query helpers
│   ├── hooks/                  # useScheduleSSE, useAuth, etc.
│   ├── stores/                 # Zustand stores
│   ├── ui/                     # Composants UI réutilisables
│   └── types/                  # Types partagés (HydraCollection, etc.)
└── assets/
```

### Alias

- `@/` → `src/` (configuré dans `vite.config.ts` et `tsconfig.json`)

### Naming

- Composants : `PascalCase` (`ScheduleCalendar.tsx`)
- Hooks : `camelCase` préfixé `use` (`useScheduleSSE.ts`)
- Stores : `camelCase` + `Store` (`authStore.ts`)
- Types : `PascalCase` (`ScheduleSlot`, `HydraCollection`)
- Query keys : `kebab-case` strings (`['schedule-slots', id]`)

### Tests

- React Testing Library + MSW (Mock Service Worker) pour les tests composants
- Pas de E2E dans le scope du rebuild (réservé P2 selon `ClubScheduler_v3.md` §13.3)
- Couverture minimale : composants critiques (auth, planning, tier list, diagnostics)

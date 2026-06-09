# ClubScheduler — Frontend

> Interface utilisateur React 18 + Vite. Application web pour gérer les clubs sportifs et leurs plannings.

## Rôle dans l'architecture

Le **frontend** est l'interface utilisateur de la plateforme. Il est servi en fichiers statiques par Nginx (conteneur dédié) et communique avec le backend via des URLs relatives proxyfiées par le même Nginx.

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Navigateur │ ───────▶│   Frontend  │ ───────▶│   Backend   │
│              │ 8081    │   Nginx     │  /api/  │  (Symfony)  │
│              │         │  (React SPA)│         │             │
│              │ ◀────── │             │ ◀────── │             │
│              │ HTML/JS  │             │  JSON   │             │
└─────────────┘         └─────────────┘         └─────────────┘
         ▲                      │
         │ Mercure (SSE)        │
         └──────────────────────┘
```

## Communication inter-services

### Frontend → Backend
- Le frontend utilise des **URLs relatives** (`/api/*`) qui sont proxyfiées par le **nginx du frontend** vers le **backend nginx** (`nginx:80`)
- Le client HTTP est `ky` (wrapper fetch) avec intercepteurs d'authentification
- Toutes les requêtes API incluent automatiquement le Bearer token depuis le store Zustand
- En mode dev (`npm run dev`), Vite proxy `/api` → `http://127.0.0.1:8080`

### Frontend → Mercure (SSE)
- Le frontend s'abonne au topic `club:{clubId}:schedule:{scheduleId}` via `EventSource`
- L'URL Mercure est relative : `/.well-known/mercure?topic=...`
- Quand une génération de planning est en cours, le backend publie des mises à jour en temps réel
- Le frontend invalide le cache React Query et rafraîchit le calendrier

### Frontend → Engine
- Le frontend **ne contacte jamais l'engine directement**. Il passe toujours par le backend.
- La génération de planning se fait via : `POST /api/schedules/{id}/generate` (backend appelle l'engine en interne)

## Routes de l'application

| Route | Page | Description |
|-------|------|-------------|
| `/` | HomePage | Page d'accueil |
| `/login` | LoginPage | Connexion utilisateur |
| `/wizard` | WizardPage | Assistant 4 étapes (configuration club) |
| `/schedules/:id` | ScheduleViewPage | Visualisation du calendrier (FullCalendar) |
| `/schedules/:id/diagnostics` | DiagnosticsPage | Diagnostics et erreurs du planning |

## Commandes principales

```bash
# Toutes les commandes peuvent s'exécuter sur la machine hôte
# (le dev server Vite tourne sur l'hôte, pas dans Docker)

npm install           # Installer les dépendances
npm run dev           # Démarrer le serveur de dev (Vite, port 5173)
npm run build         # Build production (tsc + vite build)
npm run lint          # ESLint
npm run preview       # Prévisualiser le build

# Via le Makefile (commandes Docker)
make start            # Démarrer tous les conteneurs (docker compose up -d)
make stop             # Arrêter le conteneur frontend
make logs             # Voir les logs du frontend
make shell            # Entrer dans le conteneur frontend
make status           # Statut du conteneur frontend
```

## Architecture interne

```
frontend/
├── src/
│   ├── app/
│   │   ├── router.tsx        # Configuration React Router
│   │   ├── routes.tsx        # Lazy-loaded components
│   │   └── AppLayout.tsx     # Layout (sidebar + header)
│   ├── features/
│   │   ├── auth/
│   │   │   └── pages/LoginPage.tsx
│   │   │   └── authStore.ts        # Auth state (Zustand)
│   │   ├── wizard/
│   │   │   ├── WizardPage.tsx      # Assistant 4 étapes
│   │   │   ├── components/
│   │   │   │   ├── VenueStep.tsx
│   │   │   │   ├── CoachStep.tsx
│   │   │   │   ├── TeamStep.tsx
│   │   │   │   └── SummaryStep.tsx
│   │   │   └── wizardStore.ts      # Wizard state + auto-save
│   │   ├── schedule/
│   │   │   ├── pages/
│   │   │   │   ├── ScheduleViewPage.tsx    # Calendrier + Mercure
│   │   │   │   └── DiagnosticsPage.tsx     # Diagnostics
│   │   │   ├── components/
│   │   │   │   ├── ExportPdfButton.tsx
│   │   │   │   └── DiagnosticsPanel.tsx
│   │   │   ├── api/
│   │   │   │   └── useScheduleDiagnostics.ts
│   │   │   ├── useSchedule.ts        # Hooks React Query
│   │   │   └── SlotDetailModal.tsx
│   │   └── priorities/
│   │       ├── TierListPage.tsx      # Drag & drop priorités
│   │       ├── TierColumn.tsx
│   │       ├── TeamCard.tsx
│   │       └── priorityApi.ts        # API hooks
│   ├── shared/
│   │   ├── api/
│   │   │   └── client.ts           # ky client + interceptors
│   │   ├── components/
│   │   │   ├── ErrorBoundary.tsx
│   │   │   └── LoadingSpinner.tsx
│   │   └── lib/
│   │       └── queryClient.ts      # TanStack Query config
│   └── main.tsx                  # Entry point
├── public/
├── index.html
├── package.json
├── vite.config.ts              # Vite config + proxy dev
└── Makefile
```

## Intégration API

### Client HTTP (`ky`)

```typescript
// src/shared/api/client.ts
import ky from 'ky'

const apiClient = ky.create({
  prefix: '/api',              // URL relative (proxy nginx)
  timeout: 15000,
  hooks: {
    beforeRequest: [
      // Injecte Bearer token
      ({ request }) => {
        const token = useAuthStore.getState().token
        if (token) request.headers.set('Authorization', `Bearer ${token}`)
      }
    ],
    afterResponse: [
      // 401 → déconnexion
      ({ response }) => {
        if (response.status === 401) {
          useAuthStore.getState().clearAuth()
          window.location.href = '/login'
        }
      }
    ],
  },
})
```

### React Query

```typescript
// Exemple : récupérer un planning
function useSchedule(id: string) {
  return useQuery({
    queryKey: ['schedule', id],
    queryFn: async () => {
      return apiClient.get(`schedules/${id}`).json<Schedule>()
    },
  })
}

// Exemple : mettre à jour une équipe
function useUpdateTeam() {
  return useMutation({
    mutationFn: async ({ id, data }) => {
      return apiClient.patch(`teams/${id}`, { json: data }).json()
    },
  })
}
```

### Mercure (SSE)

```typescript
// src/features/schedule/pages/ScheduleViewPage.tsx
function useMercureSubscription(clubId, scheduleId, onEvent) {
  useEffect(() => {
    const url = `/.well-known/mercure?topic=club:${clubId}:schedule:${scheduleId}`
    const es = new EventSource(url)
    es.onmessage = () => onEvent()  // Invalide le cache
    return () => es.close()
  }, [clubId, scheduleId])
}
```

## Proxy Vite (mode dev)

```typescript
// vite.config.ts
server: {
  proxy: {
    '/api': {
      target: 'http://127.0.0.1:8080',      // Backend nginx
      changeOrigin: true,
    },
    '/.well-known/mercure': {
      target: 'http://127.0.0.1:3000',      // Mercure
      changeOrigin: true,
    },
    '/engine': {
      target: 'http://127.0.0.1:8000',      // Engine
      changeOrigin: true,
    },
  },
}
```

## Flux utilisateur typique

```
1. Utilisateur ouvre http://localhost:8081
2. Se connecte sur /login → JWT stocké dans Zustand
3. Utilise le Wizard (/wizard) pour configurer le club
   - Étape 1 : Salles et disponibilités
   - Étape 2 : Entraîneurs
   - Étape 3 : Équipes et contraintes
   - Étape 4 : Récapitulatif
4. Va sur /schedules/:id
   - Le calendrier FullCalendar s'affiche
   - Si pas de planning généré : clique "Générer"
   - Le backend lance l'engine
   - Mercure notifie l'avancement
   - Le calendrier se met à jour automatiquement
5. Peut exporter en PDF : POST /api/schedules/:id/export-pdf
6. Va sur /schedules/:id/diagnostics pour voir les avertissements
```

## Environnement

- **React** : 18.3.1
- **TypeScript** : ~6.0
- **Build** : Vite 8
- **Routing** : React Router 6
- **Data fetching** : TanStack Query 5
- **State** : Zustand 5
- **HTTP client** : ky 2
- **UI** : Tailwind CSS + FullCalendar
- **Drag & drop** : @dnd-kit
- **Port** : 8081 (Nginx) / 5173 (dev server)
- **Build** : Fichiers statiques dans `dist/`

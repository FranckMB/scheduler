# Prometheus Frontend Vision — Planning Research Record

Last verified @ 2026-06-30

> **CE DOCUMENT N'EST PAS BINDING.** Il capture la vision de planification que Prometheus
> a considérée AVANT l'exécution du rebuild frontend. C'est un enregistrement de recherche
> pour traçabilité — pas une spécification exécutoire. Les spécifications binding sont dans
> `specs/courantes/` (`frontend-spec.md`, `frontend-wizard.md`, `frontend-strategy.md`,
> `frontend-components.md`).
>
> **Décisions non-réouvrables :**
> - Wizard : **Draft hybride** — fixé, ne pas rouvrir (voir `frontend-wizard.md`).
> - Raz vs rebuild : **OPEN par T17** — laissé ouvert par la tâche T17, ne pas rouvrir ici.

---

## 1. Origin

Ce document préserve la recherche de planification Prometheus pour le rebuild du frontend
ClubScheduler. Prometheus a analysé le codebase existant (React 18, wizard 9 étapes,
FullCalendar v6, Mercure SSE) et a considéré plusieurs approches avant que les spécifications
forward ne soient figées dans `specs/courantes/`.

La recherche Prometheus a couvert :

- **Stack technologique** : versions à figer, migrations nécessaires, compatibilités
- **Pattern wizard** : 3 approches considérées, 1 choisie (Draft hybride)
- **Stratégie de rebuild** : raz sélectif vs raz total, préservation d'infrastructure
- **Anti-patterns** : patterns du code V1 à bannir dans le rebuild
- **Pitfalls projet** : pièges spécifiques identifiés dans le code existant

Ce document est le point de départ intellectuel. Les specs `courantes` sont le point
d'arrivée binding.

---

## 2. Stack Considered & Fixed

Prometheus a considéré la stack suivante. Les versions sont figées dans
`frontend-strategy.md` §2 et `frontend-spec.md` §1. Cette section capture le raisonnement
de planification, pas les décisions elles-mêmes.

### React 19.2

**Considération :** Le codebase actuel utilise React 18.3.1 (`package.json` ligne 28).
React 19 apporte `use()` hook, concurrent features améliorées, et supprime
`ReactDOM.render` (remplacé par `createRoot`). Le projet utilise déjà `createRoot` dans
`main.tsx` — la migration vers React 19.2 est donc principalement une mise à jour de
version, pas une refonte API.

**Points de friction identifiés :**
- `@types/react` et `@types/react-dom` doivent passer de `^18.3.x` à `^19.x`
- Les types `React.FC` et `React.FunctionComponent` sont dépréciés en React 19
- `useRef` requiert un argument initial en React 19 (pas de `useRef<T>()` sans argument)
- Les Suspense boundaries existantes (`AppLayout.tsx` ligne 178) sont compatibles

**Fixé à :** React 19.2, react-dom 19.2. `createRoot` obligatoire.

### Vite 8 + Rolldown

**Considération :** Le codebase utilise déjà Vite 8.0.12 (`package.json` ligne 52) avec
`@vitejs/plugin-react` et `@tailwindcss/vite`. Vite 8 intègre Rolldown (bundler Rust)
comme backend de production, remplaçant Rollup. Le `vite.config.ts` actuel (33 lignes)
est minimal et compatible.

**Points de friction identifiés :**
- `vite.config.ts` utilise `defineConfig` standard — pas de migration nécessaire
- Le proxy dev (`/api`, `/.well-known/mercure`, `/engine`) est déjà configuré correctement
- Rolldown active le build incrémental — pas de changement API visible
- `npm run build` exécute `tsc -b && vite build` — le `tsc -b` reste nécessaire car
  Vite ne type-check pas

**Fixé à :** Vite 8.1 (avec Rolldown intégré). Plugin `@tailwindcss/vite`.

### Tailwind 4 CSS-first

**Considération :** Le codebase utilise déjà Tailwind 4 avec la configuration CSS-first.
`index.css` ligne 1 utilise `@import "tailwindcss"` et lignes 4-50 définissent les tokens
via `@theme { ... }`. Il n'y a pas de `tailwind.config.js` — c'est déjà correct.

**Points confirmés :**
- `@theme` définit les couleurs, surfaces, borders, foreground — pas de config JS
- Les utilitaires `glass`, `text-fg-primary`, `bg-bg-deep` sont générés depuis les tokens
- `@apply` n'est pas utilisé dans le code existant — bon
- Le plugin `@tailwindcss/vite` est déjà intégré dans `vite.config.ts`

**Fixé à :** Tailwind CSS 4.3. Configuration via `@theme` dans `src/index.css`.
Interdiction de `tailwind.config.js` et `@apply` dans composants.

### @dnd-kit v0.5

**Considération :** Le codebase actuel utilise `@dnd-kit/core` ^6.3.1, `@dnd-kit/sortable`
^10.0.0, `@dnd-kit/utilities` ^3.2.2 (`package.json` lignes 16-18). La spec fixe
`@dnd-kit/core` à v0.5 — c'est une incohérence de version majeure qui nécessite une
investigation.

**Hypothèses de Prometheus :**
- La spec `frontend-strategy.md` §2 liste `@dnd-kit/core 0.5` — c'est peut-être une
  notation de version interne ou une référence à l'API v0.5 (qui correspondrait à
  @dnd-kit/core 6.x avec l'API stable 0.5)
- Le code existant (`TierListPage.tsx`, `TierColumn.tsx`, `TeamCard.tsx`) utilise
  `@dnd-kit/core` + `@dnd-kit/sortable` pour la tier list drag & drop
- L'API DnD (`useDndContext`, `useDraggable`, `useDroppable`, `DndContext`, `SortableContext`)
  est stable entre les versions — la migration devrait être transparente

**Fixé à :** @dnd-kit/core v0.5 (version à clarifier lors de l'exécution — la spec
`frontend-strategy.md` est binding). Accessible, moderne, keyboard-navigable.

### ky v2 hooks

**Considération :** Le codebase utilise déjà ky ^2.0.2 (`package.json` ligne 27) avec
l'API hooks (`beforeRequest`, `afterResponse`). Le client configuré dans
`shared/api/client.ts` injecte le Bearer token via `beforeRequest` et gère le 401 via
`afterResponse`.

**Points confirmés :**
- `hooks.beforeRequest` injecte `Authorization: Bearer {token}` depuis `authStore`
- `hooks.afterResponse` gère 401 → `clearAuth()` + redirect `/login`
- `prefix: '/api'` utilise les URLs relatives (proxy Vite dev, Nginx prod)
- Le pattern est correct et compatible ky v2

**Fixé à :** ky 2.0. API hooks (`beforeRequest`, `afterResponse`) pour auth et 401.
Pas de `fetch()` direct dans les composants.

---

## 3. Wizard Consideration

Prometheus a considéré trois approches pour le wizard d'onboarding. La décision finale
est **Draft hybride** — elle est figée dans `frontend-wizard.md` et ne doit pas être
rouvertée.

### Approche 1 — Stepper linéaire strict

**Description :** Wizard traditionnel avec étapes obligatoires en ordre séquentiel.
L'utilisateur ne peut pas avancer sans valider l'étape courante. Pas de retour arrière
libre — seulement étape précédente.

**Avantages considérés :**
- Simple à implémenter (state = `currentStep++` / `currentStep--`)
- Guide l'utilisateur sans ambiguïté
- Validation par étape claire

**Inconvénients identifiés :**
- Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire) — il veut
  pouvoir saisir dans l'ordre qui lui convient, pas dans un ordre imposé
- Pas de sauvegarde intermédiaire — si l'utilisateur quitte à l'étape 3, tout est perdu
- Le wizard V1 actuel (9 étapes, `WizardPage.tsx`) utilise ce pattern et il est trop
  rigide : `nextStep()` bloque si `validateStep()` échoue
- Fragmentation excessive : 9 étapes (Venue, VenueConstraint, Team, TeamConstraint,
  Coach, CoachConstraint, TierList, Validation, Summary) = trop de clics

**Verdict :** Rejeté. Trop rigide pour le cas d'usage réel (gestionnaire avec données
en vrac).

### Approche 2 — Free-form (single page, no enforced order)

**Description :** Une seule page avec tous les formulaires accessibles simultanément.
L'utilisateur remplit dans l'ordre qu'il veut. Pas de stepper, pas de navigation
forcée.

**Avantages considérés :**
- Flexibilité maximale pour l'utilisateur
- Pas de friction de navigation
- L'utilisateur voit tout le périmètre d'un coup

**Inconvénients identifiés :**
- Charge cognitive trop élevée — le gestionnaire novice est noyé
- Pas de progression visible — l'utilisateur ne sait pas où il en est
- Validation globale complexe à implémenter UX-wise
- Le onboarding est par nature un flux guidé — le free-form contredit cet objectif
- FullCalendar, tier list, Excel import sur une même page = page monolithique
  ingérable

**Verdict :** Rejeté. Trop de charge cognitive pour un onboarding initial.

### Approche 3 — Draft hybride (final decision)

**Description :** Wizard à étapes avec navigation libre entre étapes visitées,
auto-save systématique (debounce 2s → server draft + sessionStorage fallback),
et validation par étape non-bloquante pour la navigation. La validation globale
Zod s'exécute uniquement à la soumission finale (étape 4).

**Avantages considérés :**
- Guide l'utilisateur (stepper visible) sans le bloquer (navigation libre vers étapes
  visitées via `JUMP` action)
- Auto-save = pas de perte de données si l'utilisateur quitte
- Draft serveur (`PUT /api/clubs/{id}/draft`) + sessionStorage = double sécurité
- 4 étapes consolidées (Infrastructure, Ressources, Contraintes, Récapitulatif) au
  lieu de 9 — réduit la fragmentation
- State machine `useReducer` avec 11 actions explicites (NEXT, PREV, JUMP,
  UPDATE_DATA, MARK_COMPLETED, SET_ERRORS, CLEAR_ERRORS, SET_DRAFT_STATUS,
  LOAD_DRAFT, TOGGLE_DEMO, RESET)
- Mode démo intégré (pré-remplissage fictif)

**Inconvénients identifiés :**
- Complexité de mise en œuvre supérieure (reducer, auto-save, draft restore)
- Gap backend : `PUT /api/clubs/{id}/draft` n'existe pas dans l'OpenAPI
- Gap backend : `onboarding_completed` champ absent de l'OpenAPI
- Le fallback sessionStorage seul (sans server draft) est un compromis temporaire

**Verdict :** **Choisi.** Fixé dans `frontend-wizard.md`. Ne pas rouvrir.

### Consolidation 9 → 4 étapes

Le wizard V1 a 9 étapes (`WizardPage.tsx` lignes 13-23) :
1. VenueStep, 2. VenueConstraintStep, 3. TeamStep, 4. TeamConstraintStep,
5. CoachStep, 6. CoachConstraintStep, 7. TierListStep, 8. ValidationStep,
9. SummaryStep

Le wizard Draft hybride consolide en 4 étapes (`frontend-wizard.md` §1) :
1. **Infrastructure** = Venue + VenueConstraint + fermetures
2. **Ressources** = Team + Coach + TeamCoach + Excel import
3. **Contraintes** = TeamConstraint + CoachConstraint + TierList
4. **Récapitulatif** = Validation + Summary

---

## 4. Rebuild Selectif Thoughts

> **Note :** La décision raz-vs-rebuild a été laissée **OPEN par T17**. Cette section
> capture les pensées de Prometheus sur le sujet — elle ne rouvre pas la décision.

Prometheus a considéré le périmètre du rebuild avec deux axes :

### Ce qui doit être razé (consensus)

- `frontend/src/` — tout le code source applicatif (composants, hooks, stores, routes,
  styles, types, utils). Le code V1 a accumulé de la dette technique : wizard 9 étapes
  trop fragmenté, auto-save séquentiel sans debounce, types incohérents avec l'OpenAPI,
  hardcoded `localhost:3000` pour Mercure dans `ScheduleViewPage.tsx` ligne 51.

### Ce qui doit être préservé (consensus)

- `docker/frontend/Dockerfile` — image Docker multi-stage + Nginx, validée et
  fonctionnelle
- `docker/frontend/nginx.conf` — config proxy (`/api` → backend, `/.well-known/mercure`
  → hub, `/engine` → engine, SPA fallback), validée et fonctionnelle
- `frontend/index.html` — point d'entrée HTML
- `frontend/package.json` et `frontend/package-lock.json` — mis à jour selon les versions
  figées, pas razés
- `frontend/public/` — assets statiques si présents

### Zone grise (laissée OPEN par T17)

- `frontend/vite.config.ts` — minimal et correct, mais pourrait nécessiter des
  ajustements pour Rolldown ou de nouveaux plugins
- `frontend/eslint.config.js` — flat config correcte, mais les règles custom
  (anti-patterns) doivent être ajoutées
- `frontend/vitest.config.ts` — infrastructure de test présente mais 0 tests écrits
- `frontend/tests/e2e/` — 4 specs Playwright existants, non validés (bloqués)

### Approche considérée

Prometheus a envisagé un raz sélectif : raz `frontend/src/` entièrement, préserver
l'infrastructure Docker, et traiter les fichiers de config au cas par cas pendant
l'exécution. La décision finale sur les fichiers de config est laissée à l'exécuteur
du rebuild (T17 a laissé OPEN).

---

## 5. Anti-patterns identifiés

Prometheus a identifié les anti-patterns suivants dans le code V1 et dans les patterns
de migration. Ils sont figés dans `frontend-strategy.md` §3.

### Anti-patterns du code V1 observés

| # | Anti-pattern observé dans V1 | Localisation | Correction |
|---|-------------------------------|--------------|------------|
| 1 | Hardcoded Mercure URL | `ScheduleViewPage.tsx` ligne 51 : `import.meta.env.VITE_MERCURE_URL \|\| 'http://localhost:3000/.well-known/mercure'` | URL relative `/.well-known/mercure` uniquement (DashboardPage.tsx ligne 93 est correct) |
| 2 | Auto-save séquentiel sans debounce | `wizardStore.ts` `autoSave()` lignes 693-805 : boucle `for...of` avec `await` séquentiel | Debounce 2s + draft serveur (Draft hybride) |
| 3 | Wizard 9 étapes trop fragmenté | `WizardPage.tsx` lignes 13-23 : 9 composants step | Consolidation en 4 étapes |
| 4 | Types incohérents avec OpenAPI | `wizardStore.ts` utilise `DayKey` ('mon'\|'tue'\|...) mais l'API utilise `dayOfWeek` (int 1-6) | Types alignés sur l'OpenAPI |
| 5 | Inline styles dans FullCalendar render | `ScheduleViewPage.tsx` lignes 290-329 : `style={{...}}` au lieu de classes Tailwind | Classes Tailwind ou composants React réutilisables |

### Anti-patterns de migration à bannir

| # | Anti-pattern | Pourquoi banni | Correct à la place |
|---|-------------|----------------|-------------------|
| 1 | `ReactDOM.render(...)` | Supprimé en React 19 | `createRoot(container).render(...)` |
| 2 | `onSuccess` dans `useQuery`/`useMutation` (TanStack v5) | Supprimé en v5 — effets de bord implicites | `useEffect` sur `data`/`isSuccess`, ou `select` |
| 3 | `migrate()` sans null check (Zustand 5) | `persist` v5 passe `persistedState` potentiellement `null` | Null check explicite avant accès |
| 4 | `@apply` dans composants Tailwind v4 | Déprécié en v4 — casse l'extraction utility-first | Classes utility directement |
| 5 | `eslint-config-prettier` pas en dernière position | Conflits silencieux si config chargée après prettier | `extends: [..., 'prettier']` — toujours dernier |
| 6 | `tailwind.config.js` | Remplacé par `@theme` CSS en v4 | `@theme { ... }` dans `src/index.css` |

---

## 6. Pitfalls spécifiques au projet

Prometheus a identifié des pièges spécifiques au codebase ClubScheduler qui ne sont
pas des anti-patterns génériques mais des problèmes propres à ce projet.

### FullCalendar v6 — dayOfWeek mapping

**Piège :** Le backend utilise `dayOfWeek` en entier (1=Lundi, 6=Samedi, 0=Dimanche).
JavaScript `Date.getDay()` retourne 0=Dimanche, 1=Lundi, ..., 6=Samedi. FullCalendar
utilise `firstDay: 1` (Lundi) et `hiddenDays: [0]` (Dimanche caché).

**Code V1 problématique :** `ScheduleViewPage.tsx` lignes 126-128 :
```typescript
// dayOfWeek: 0=Dimanche, 1=Lundi, ... 6=Samedi
// Convert to days from Monday: Lundi=1 -> 0, Mardi=2 -> 1, ..., Dimanche=0 -> 6
const daysFromMonday = slot.dayOfWeek === 0 ? 6 : slot.dayOfWeek - 1
```

Cette conversion manuelle est fragile et dupliquée entre `ScheduleViewPage.tsx` et
`DashboardPage.tsx` (ligne 170). Le rebuild doit centraliser cette logique dans un
utilitaire partagé.

**Recommandation :** Créer `shared/utils/dayMapping.ts` avec une fonction
`dayOfWeekToDaysFromMonday(dayOfWeek: number): number` et
`dateToDayOfWeek(date: Date): number`. FullCalendar config : `firstDay: 1`,
`hiddenDays: [0]` (Dimanche caché — le planning est lun-sam).

### FullCalendar v6 — eventContent render function

**Piège :** FullCalendar v6 permet de personnaliser le rendu des événements via
`eventContent: (arg: EventContentArg) => ReactNode`. Le code V1
(`ScheduleViewPage.tsx` lignes 290-329) utilise cette fonction avec des **inline styles**
au lieu de classes Tailwind, ce qui casse la cohérence du design system et empêche
le tree-shaking CSS.

**Problèmes spécifiques :**
- `style={{ fontSize: '11px', lineHeight: '1.3' }}` — hardcoded, pas responsive
- `style={{ display: 'inline-block', width: '8px', height: '8px' }}` — lock level dot
  rendu avec inline style au lieu d'une classe Tailwind
- `eventInfo.event.extendedProps.slot` — accès non-typé au slot via extendedProps
- La fonction est recréée à chaque render (`useCallback` avec `[]` deps — correct mais
  le contenu n'est pas testé)

**Recommandation :** Le rebuild doit :
1. Extraire le rendu d'événement dans un composant React dédié
   (`ScheduleEventContent.tsx`)
2. Utiliser des classes Tailwind au lieu d'inline styles
3. Typer `extendedProps` via une interface explicite
4. Tester le rendu avec React Testing Library (couleur de lock, texte, formatage)

### FullCalendar v6 — reference week anchoring

**Piège :** Le planning est une semaine type (lun-sam), pas une semaine calendaire.
FullCalendar attend des dates concrètes. Le code V1 calcule un "lundi de référence"
à partir de `new Date()` pour ancrer les événements :

```typescript
// ScheduleViewPage.tsx lignes 118-123
const today = new Date()
const dayOfWeek = today.getDay()
const mondayOffset = dayOfWeek === 0 ? 1 : 1 - dayOfWeek
const monday = new Date(today)
monday.setDate(today.getDate() + mondayOffset)
```

Cette logique est dupliquée entre `ScheduleViewPage.tsx` (ligne 118) et
`DashboardPage.tsx` (ligne 163). De plus, `visibleRange` (ligne 414) recalcule
indépendamment un lundi — risque de désynchronisation.

**Recommandation :** Centraliser dans `shared/utils/referenceWeek.ts`. Une seule
fonction `getReferenceMonday(): Date` utilisée partout. `visibleRange` doit utiliser
la même référence que les événements.

### Mercure auto-reconnect

**Piège :** `EventSource` (API navigateur) se reconnecte nativement en cas de
déconnexion. Le code V1 exploite cela mais avec deux implémentations incohérentes :

**ScheduleViewPage.tsx (ligne 51) — incorrect :**
```typescript
const mercureUrl = import.meta.env.VITE_MERCURE_URL || 'http://localhost:3000/.well-known/mercure'
```
Hardcode `localhost:3000` en fallback — casse en production (le Nginx frontend proxy
`/.well-known/mercure` → Mercure hub).

**DashboardPage.tsx (ligne 93) — correct :**
```typescript
const url = `/.well-known/mercure?topic=${encodeURIComponent(topic)}`
```
URL relative — fonctionne en dev (proxy Vite) et en prod (proxy Nginx).

**Problèmes additionnels :**
- `es.onerror` ne fait que `console.debug` — pas de feedback utilisateur sur perte
  de connexion
- `eventSourceRef` est stocké dans un `useRef` mais jamais lu — le ref est inutile
  car le cleanup se fait via le return du `useEffect`
- Le hook est dupliqué entre les deux pages — devrait être partagé dans
  `shared/hooks/useMercureSubscription.ts`
- Pas de gestion du cas où `EventSource` est undefined (DashboardPage ligne 90 a un
  check `typeof EventSource === 'undefined'`, ScheduleViewPage n'en a pas)

**Recommandation :** Le rebuild doit :
1. Extraire `useMercureSubscription` dans `shared/hooks/` — une seule implémentation
2. Utiliser **toujours** l'URL relative `/.well-known/mercure` — jamais de hardcode
3. Ajouter un état de connexion (`connected` / `reconnecting` / `error`) pour feedback
4. Garder le check `typeof EventSource === 'undefined'` pour SSR safety
5. Supprimer le `useRef` inutile — le cleanup via `useEffect` return suffit
6. Ne pas ajouter de librairie de reconnexion — `EventSource` se reconnecte nativement
7. Pas de polling de fallback — si SSE coupe, l'utilisateur attend la reconnexion

### Duplication de code entre pages

**Piège transversal :** Les hooks `useMercureSubscription`, la logique de mapping
`dayOfWeek`, et le calcul de semaine de référence sont dupliqués entre
`ScheduleViewPage.tsx` et `DashboardPage.tsx`. Le rebuild doit éliminer cette
duplication via des hooks et utilitaires partagés dans `shared/`.

---

## 7. Références croisées

| Document | Relation |
|----------|----------|
| `specs/courantes/frontend-spec.md` | Spec forward binding — routes, stack, state management |
| `specs/courantes/frontend-wizard.md` | Spec wizard binding — Draft hybride 4 étapes |
| `specs/courantes/frontend-strategy.md` | Stratégie binding — TDD, stack figée, anti-patterns |
| `specs/courantes/frontend-components.md` | Composants binding — conventions, ARIA, lock levels |
| `specs/evolution/fait-vs-v2.md` | Traçabilité V1 vs V2 — frontend-raz-cleanup délégué V2 |
| `specs/evolution/features-futures.md` | Features non-implémentées — P1.5, P2, V2 |
| `AGENTS.md` | Contexte agent — commandes dev, architecture, gotchas |

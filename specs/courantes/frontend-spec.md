# Frontend Spec — Forward

> Specification FORWARD pour le rebuild du frontend ClubScheduler, réconciliée avec le code
> livré (`frontend/src/`). L'inventaire backward du backend est dans
> `backend-inventory.md` — ce document le référence sans le dupliquer.

Last verified @ 2026-08-12 (recalé ce jour par P2-2/F2b : le déplacement sous verdict moteur et la bannière de score périmé ; précédemment : 2026-08-12 (recalé ce jour par P2-2/F1 : le wrap de créneau dit l'origine du verrou ; précédemment : 2026-08-11 (recalé ce jour par P2-24 : la page publique de doléances passe en STEPPER par équipe — envoi unique, confirmation vide, sessionStorage ; précédemment : 2026-08-08 (stores re-vérifiés contre `frontend/src/shared/stores/` — la règle « `persist` pour le token » contredisait le tableau des stores depuis SEC-16, audit DOC-28 ; routes/stack/pagination/export re-vérifiés le 2026-07-29))))

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
| Routing | react-router | 8 | Data router (`createBrowserRouter`), **`lazy` par route** (P4-6), nested layouts. ⚠ paquet `react-router`, **pas** `react-router-dom` |
| Icons | lucide-react | 1.x | Icônes SVG tree-shakeable |
| Reporting d'erreurs | @sentry/react | 10.x | **Erreurs seules** — pas d'APM ni de replay (`tracesSampleRate: 0`, quota free tier). DSN absent → init sautée, SDK inerte. ⚠ L'activer demande **le DSN ET l'hôte d'ingestion en CSP** — voir §Sentry (P4-65) |
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

Chaque route a un objectif produit précis. Le routing utilise **react-router 8** en *data
router* avec nested layouts (`src/app/router.tsx`).

**Découpage du bundle par route (P4-6).** Tout est en `lazy` **sauf** `/login` et les gardes
(`AuthGuard`, `AdminGuard` — leur code doit être là pour décider). Trois filets rendent ce
découpage sûr et **aucun n'est optionnel** quand on ajoute une route :

| Filet | Sans lui |
|---|---|
| `errorElement` (racine **et** imbriqué sous `AppLayout`) | Un chunk 404 (déploiement pendant la session) remplace **toute l'app** par l'écran anglais non stylé du router, invisible de Sentry. L'imbriqué préserve en-tête, navigation et bandeaux quand une seule page échoue. |
| `HydrateFallback` | react-router rend `null` → **page blanche** à chaque ouverture directe ou F5 d'une route lazy. |
| Indicateur d'attente (`useNavigation`, dans `AppLayout`) | Un clic de navigation ne produit **aucun retour** tant que le chunk n'est pas arrivé. |

> Compromis assumé : le data router résout le `lazy` de **toutes** les routes appariées avant
> d'en rendre une seule — un visiteur anonyme sur `/planning` télécharge donc la page avant
> d'être redirigé vers `/login`. Ce JS est public et sans donnée ; l'éviter demanderait de
> dupliquer la décision d'auth dans un `loader` par route.

| Route | Objectif | Auth | Layout |
|-------|----------|------|--------|
| `/login` | Connexion gestionnaire (email + password) — **seule page eager** | Public | `AuthLayout` |
| `/register` | Inscription (A3) : soumet le formulaire → écran « vérifie tes emails » (aucune session ; le club et le JWT sont créés à la vérification) | Public | `AuthLayout` |
| `/verify-email/:token` | Consomme le lien email → crée/rejoint le club, connecte, redirige (`/waiting` si pending, sinon `/`) | Public | `AuthLayout` |
| `/forgot-password` | Demande de réinitialisation de mot de passe (`POST /api/password/forgot`) | Public | `AuthLayout` |
| `/reset-password/:token` | Saisie du nouveau mot de passe (`POST /api/password/reset`) | Public | `AuthLayout` |
| `/waiting` | Attente d'approbation (`WaitingApprovalPage`) — poll `/api/me` toutes les 5 s, redirige vers `/` dès `membershipStatus === "active"` | Token requis | `AuthLayout` |
| `/` | **Cockpit temporel** (`CockpitPage`) : bandeau planning principal (Ouvrir/**Modifier** = reopen · Tous les plannings) · calendrier mensuel des exceptions · radar (à traiter). **Débloqué dès `me.seasonPlan.hasFinishedVersion`** (le plan de saison porte ≥1 version terminée — dérivé, indépendant du pointeur : rouvrir ne re-verrouille pas) ; sinon redirige vers `/wizard`. **Palier B** : CTAs radar « Adapter » actifs (→ wizard mode période) ; « Voir le plan » (overlay généré → consultation) ; « Modifier » le socle avec overlays → **confirmation proportionnée** (409 `overlays_exist` → dialog « supprimera N secondaires »). Overlays exclus du sélecteur de plannings (badge « Période ») | Required | `AppLayout` |
| `/planning` | **Boucle de travail planning** (`PlanningPage`, ex-`/`) : grille `WeekGrid` (un planning **FAILED** sans créneau y montre les **réservations** en pseudo-créneaux HARD lecture seule — couche socle/période du payload, gymnases désactivés filtrés, bandeau « seuls vos créneaux réservés sont affichés » + état vide dédié sans réservation — retour fondateur 2026-08-06), toolbar (**sélecteur de versions** « V3 — 10 juil. 14:32 » — versions non renommables, suppression d'une version de travail avec confirmation ; régénérer, valider — **le plan pointe la version et ses sœurs sont supprimées** (ADR-0002 inv. 1) —, rouvrir = **dépointer**, planning principal ★), **nom du planning éditable au header** (porté par le plan, `PUT /api/schedule_plans/{id}`), bandeau divergence structure, diagnostics, détail créneau | Required | `AppLayout` |
| `/matchs` | **Module matchs** (`MatchesPage`) : placement des rencontres domicile (grille week-end), radar de conflits coach/joueur, import FBI (`ImportFbiDialog`) | Required | `AppLayout` |
| `/wizard` | Assistant de saisie 6 étapes : Équipes → Gymnases → Coachs → Contraintes → Récapitulatif → Génération (`AuthGuard` y redirige tant que `me.seasonPlan.hasFinishedVersion === false`, c.-à-d. tant que le club n'a jamais généré) | Required | `AppLayout` |
| `/club` | Identité du club : logo (upload + recadrage `LogoCropper` + suppression), couleur d'accent (+ palette), **section « Informations du club »** (champs FFBB — voir ci-dessous, admin) **et section « Demandes »** (approbation des adhésions `pending`, admin — l'ancienne route `/pending-members` a été repliée ici) | Required | `AppLayout` |
| `/profile` | Profil utilisateur | Required | `AppLayout` |
| `/confidentialite` | Politique de confidentialité (`PrivacyPage`) — atteignable depuis le menu compte | Public | aucun (autonome) |
| **`/doleances/:token`** | **Page publique SANS login** (#10, lot C2 ; **stepper P2-24, 2026-08-11**) : parcours en ÉTAPES — intro (le pourquoi, + bandeau « déjà répondu le… » si `respondedAt`) → une étape PAR équipe (ses semaines, pré-remplies ; « Rien à signaler » avance sans rien modifier) → récap (« aucune modification » par équipe intacte, « Modifier » qui saute à l'équipe puis REVIENT au récap) qui porte la validation : « Valider et envoyer », ou « Confirmer sans modification » (envoie `submissions: []` — le coach passe ✓ répondu au lieu de rester silencieux). Envoi UNIQUE à la fin, seules les sections modifiées partent (payload inchangé, gardé par test NR) ; filet `sessionStorage` par token (restauré au montage, purgé au succès — jamais côté serveur). Route **plate, hors `AuthGuard`**. Contrat : `types-de-planning.md` §E5 | **Public** | aucun (autonome) |
| `/admin/login` | Authentification **superadmin SA0** (mot de passe + TOTP obligatoire) | Public | `AdminAuthLayout` |
| `/admin` | **Console superadmin** derrière `AdminGuard` → `AdminShell` : santé des services et conteneurs, dépendances externes, journaux (audit · messenger failed · erreurs système). Identité **globale et séparée** — un JWT club ne franchit jamais ce pare-feu, et la session admin ne pose jamais `app.club_id`. Client HTTP dédié (`adminApi`, préfixe `/api/admin`, cookie de session) qui **ne lit jamais** le store JWT club. Contrat : `superadmin-auth.md` | Session SA0 | `AdminShell` |
| `/admin/*` (inconnue) | Redirige vers `/admin` — **hors du shell lazy**, pour qu'une URL admin inconnue ne télécharge pas la console entière | Session SA0 | — |

> Toute URL authentifiée inconnue (dont l'ancienne `/pending-members`) redirige vers `/` (catch-all `router.tsx`).

### Guards et redirects (`src/app/AuthGuard.tsx`)

- `isAuthenticated` faux dans `authStore` → redirect `/login` ; 401 API (hors `/api/login`) → clear + redirect `/login` (hook ky `afterResponse`). Le drapeau n'autorise rien : le cookie httpOnly est la seule identité, et le serveur tranche.
- `membershipStatus === "pending"` → `/waiting`.
- **Onboarding** : `AuthGuard` verrouille l'app au wizard tant que `me.seasonPlan.hasFinishedVersion === false` (le club n'a jamais généré). Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage**.
- **Gate cockpit** : `CockpitPage` redirige vers `/wizard` tant que `me.seasonPlan.hasFinishedVersion === false`. Le critère est **dérivé** (le plan de saison porte ≥1 version terminée) et **indépendant du pointeur** : rouvrir un planning ne re-verrouille **pas** le cockpit — voir `planning-lifecycle-validated.md` et `specs/courantes/accueil-cockpit-temporel.md` §2ter.
- **Gate matchs / plans secondaires** : bloqués tant que `me.seasonPlan.chosenScheduleId === null` (front désactivé + `SocleGuard` **409** côté serveur).
- **Routes exemptées du verrou d'onboarding** : `AuthGuard` autorise `/wizard`, `/profile` et `/club` (constante `ONBOARDING_ALLOWED`).
  ⚠️ **Écart connu, non tranché** : `/confidentialite` figure au **menu compte** (`AppLayout`) mais **pas** dans `ONBOARDING_ALLOWED` — un club en cours d'onboarding qui clique « Confidentialité » est renvoyé vers `/wizard`. Décision fondateur en attente (l'ajouter à la liste, ou le retirer du menu tant que l'onboarding n'est pas terminé).
- **`/doleances/:token` et `/admin*` sont hors de cet arbre** : la page doléances est publique (aucune session), la console superadmin a sa propre garde (`AdminGuard`) et sa propre session.

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
| Client state | Zustand 5 | État UI pur, drapeau de session, thème, préférences | **Jamais** de données serveur en Zustand. Sync via Query callbacks. |

### Frontière stricte

```typescript
// Illustration — frontière Zustand / TanStack Query

// ✅ Zustand : état UI pur, pas de données serveur (authStore réel : un booléen,
// PLUS AUCUN jeton — le JWT est un cookie httpOnly, SEC-16)
type AuthStore = {
  isAuthenticated: boolean;
  setAuthenticated: (value: boolean) => void;
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
| Identité après login | **Cookie httpOnly posé par le serveur** (SEC-16) ; Zustand ne garde qu'un booléen `isAuthenticated` (persist `cs-auth`) | Un jeton lisible par le JS était exfiltrable ; le drapeau n'est qu'un indice d'UI, l'autorisation reste au serveur → [`jwt-cookie.md`](../../docs/security/jwt-cookie.md) |
| Contexte tenant (club/saison) | **Aucun état client** | Résolu côté serveur depuis le JWT (`TenantFilterListener`) — le frontend n'envoie aucun header tenant |
| Thème clair/sombre | Zustand (`themeStore`) | UI pure ; l'accent club vient de `/api/me` via `useApplyClubTheme` |
| État UI wizard / planning | Zustand (stores de feature `store.ts`) | UI pure, pas de persistence serveur |
| Liste des équipes | TanStack Query | Donnée serveur, cacheable, invalidable |
| Statut d'une génération | TanStack Query + **flux Mercure** (FRT-04), polling en fallback | Donnée serveur temps réel ; le publieur est best-effort, donc le poll ne meurt pas |
| Formulaires wizard | État local contrôlé | Formulaires simples, soumis puis invalidés via Query |

---

## 4. HTTP Client Strategy

ky 2 comme unique client HTTP. Configuration centralisée, jamais instancié ad-hoc dans les composants.

### Instance configurée (`src/shared/api/client.ts`)

```typescript
// Extrait fidèle au code livré
export const api = ky.create({
  prefix: "/api", // proxy Vite dev, Nginx prod — jamais de host en dur
  credentials: "include", // SEC-16 : l'identité est un cookie httpOnly, plus un en-tête
  hooks: {
    beforeRequest: [
      (state) => {
        // Plus d'Authorization : seul X-Season-Id est injecté ici.
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
- **Aucun header `X-Club-Id`.** Le club actif est résolu **côté serveur** depuis la membership
  du JWT (`backend-inventory.md` §4) — un header falsifié est refusé en 403.
- **`X-Season-Id` est envoyé, mais seulement s'il y a une sélection explicite.** Le hook
  `beforeRequest` pose l'en-tête depuis `seasonStore.selectedSeasonId` quand il est non nul et
  que la requête n'en porte pas déjà un (un appel cross-saison ponctuel — re-datation lors
  d'une transition — gagne donc sur la sélection courante). Absent = le serveur dérive la
  saison courante (pivot du 15 juillet). Il est **validé côté serveur dans tous les cas**,
  jamais fait confiance côté client.
- **Auto-guérison d'une saison périmée** : si le backend répond **403 avec l'en-tête
  `X-Season-Rejected`** (saison purgée côté serveur), le hook `afterResponse` vide
  `seasonStore` et recharge. Le déclencheur est ce marqueur, **pas** un 403 quelconque — sinon
  un refus d'autorisation légitime effacerait la sélection au lieu de remonter son erreur.
  Sans ce filet, l'app ne pourrait plus jamais se rétablir : le serveur 403-erait *toutes* les
  requêtes, `/api/me` compris.
- **401 → logout automatique** (sauf sur `/api/login`). Le hook `afterResponse` vide le store et redirige vers `/login`.
- **Pas de hardcodage d'URL.** `prefix: '/api'` utilise le proxy Vite en dev et Nginx en prod.
- **Content-Type.** API Platform sert du JSON-LD (`application/ld+json`). Le déballage hydra vit dans `src/shared/api/collection.ts`.

### Proxy Vite (dev)

```typescript
// vite.config.ts — réel (extrait)
export default defineConfig({
  server: {
    proxy: {
      '/api': { target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080', changeOrigin: true },
      // Fichiers PDF/PNG exportés, servis depuis le `public/exports` du backend.
      '/exports': { target: process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8080', changeOrigin: true },
      '/.well-known/mercure': { target: process.env.MERCURE_PROXY_TARGET ?? 'http://127.0.0.1:3000', changeOrigin: true },
      // FRT-17 : PAS de proxy `/engine` — le frontend ne contacte JAMAIS l'engine
      // directement (frontière §2 de CLAUDE.md). Le proxy mort a été supprimé.
    },
  },
});
```

En production, le Nginx frontend proxy `/api` → backend Nginx, `/exports` → backend et
`/.well-known/mercure` → Mercure hub.

> ⚠️ **Écart connu, non tranché** : `docker/frontend/nginx.conf` conserve un bloc
> `location /engine/` → `http://engine:8000/`. Il n'est appelé par aucun code de
> `frontend/src/`, mais il ouvre une route que la frontière §2 interdit. Décision fondateur
> en attente (le retirer, ou documenter pourquoi il reste).

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

Le planning est une semaine type (**lundi→dimanche** — `lib/grid.ts:312` filtre `dayOfWeek >= 1 && <= 7` ; le samedi était la borne avant P4-37, alors qu'une séance du dimanche était placée par le solveur et imprimée par l'export tout en étant escamotée de l'écran), rendu par le composant maison `WeekGrid`
(`src/features/planning/WeekGrid.tsx` + `lib/grid.ts`) — pas de FullCalendar :

- Créneaux colorés, filtre par ressource (`ResourceFilter` : équipe / coach / salle). Il vit **ligne 1 de `PlanningToolbar`, contre le sélecteur de vue** dont il suit le libellé (« Par gymnase » → « Gymnases : … ») — séparés, c'étaient deux contrôles sur les mêmes ressources à deux endroits, dont le second passait inaperçu (P4-43). Un filtre **posé se voit** : bordure et texte en accent, graisse medium. ⚠ **Sans fond teinté, délibérément** — mesuré, `text-accent` sur `bg-accent/10` tombe à 4.18:1 en thème clair, sous les 4.5:1 de WCAG 1.4.3 ; le jeton est verrouillé par `a11y-contrast.spec.ts`. ⚠ **L'export ne connaît pas ce filtre** : `ExportMenu` porte son propre périmètre gymnase et le rendu est serveur.
- Click sur créneau → détail (`SlotDetail` : équipe, coach, salle, verrou) — **enrichi par P2-2/F1 (2026-08-12)** : le wrap dit **POURQUOI** le créneau est verrouillé (« Réservation gymnase » / « Épinglé manuellement » / « Origine inconnue ») et liste les **contraintes applicables**, composées côté client depuis `GET /api/constraints` (aucun calcul serveur nouveau). ⚠ « Origine inconnue » se lit comme une **ignorance**, jamais comme une absence de verrou — c'est cette nuance qui décide si le gestionnaire ose déplacer. **Et depuis F2b (2026-08-12) il PEUT déplacer sûrement** : le geste passe par `/move`, donc par le verdict du moteur — un refus s'affiche **avec ses motifs nommés** (« le coach X a déjà… »), une génération en cours bloque le geste, et un déplacement accepté pose une bannière **« score périmé »** (le score affiché décrivait le planning d'avant)
- Lecture seule quand le plan **pointe** la version affichée (`Schedule.isChosen` — le verrou d'édition)
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

### 6.5 Export du planning — LIVRÉ

`ExportMenu` (`src/features/planning/ExportMenu.tsx`) → hook `useScheduleExport`
(`features/planning/queries.ts`) → `POST /api/schedules/{id}/export-pdf` (asynchrone,
Messenger ; handler backend `ExportPdfHandler`).

- **Périmètre au choix** : tous les gymnases, ou **un seul** (`{ venueId }` dans le body) —
  chaque export tient sur une page paysage.
- Les fichiers produits sont servis sous **`/exports`** : proxifié par Vite en dev
  (`vite.config.ts`) et par le Nginx frontend en prod (`docker/frontend/nginx.conf`).

### 6.6 Multi-tenant transparent

Le gestionnaire ne voit jamais le concept de `club_id` ou `season_id`. Le frontend :

- N'envoie **aucun** header `X-Club-Id` : le backend dérive le club de la membership du JWT
  (`TenantFilterListener`)
- N'affiche jamais de sélecteur de club (un user = un club en MVP)
- La **saison**, elle, est visible et choisissable : `SeasonSelector` (dans `app/`) écrit dans
  `seasonStore`, qui alimente `X-Season-Id`. Sans sélection, le serveur dérive la saison
  courante (pivot du 15 juillet) — un club mono-saison ne voit donc jamais le sujet. Le
  bandeau `ReadonlySeasonBanner` signale une saison archivée (écritures → 409).

### 6.6 bis Cycle de vie du planning (le pointeur du plan)

- Un planning `COMPLETED` peut être **validé** (bouton « Valider » de la toolbar) → modale de
  confirmation (`ValidateDialog`, avertit si des alertes subsistent) → `POST /api/schedules/{id}/validate`
  → **le plan pointe cette version** et **ses versions sœurs sont supprimées** (ADR-0002 inv. 1) ;
  le planning passe en **lecture seule** (grille non éditable, renommage et régénération masqués).
  Le statut, lui, **reste `COMPLETED`** : « validé » se lit sur le pointeur (`Schedule.isChosen`).
- « Rouvrir » (`POST /api/schedules/{id}/reopen`) **dépointe** le plan (inv. 2) : la version survit
  et redevient éditable.
- Il n'existe **pas** de « Définir principal » : le pointeur se déplace **en validant**, et par rien
  d'autre (`set-baseline` supprimé, inv. 18). Le ★ de la saison = `seasonPlan.chosenScheduleId`
  de `/api/me`.

### 6.6 ter Informations du club (fiche FFBB — lot B)

La route `/club` expose une section **« Informations du club »** (admin uniquement, `AccordionSection`)
qui édite les métadonnées FFBB du club, regroupées : **Identité** (code FFBB + ligue + zone de vacances
en lecture — auto-dérivés à l'onboarding ; code comité éditable), **Contact**, **Correspondant**,
**Président**, **Salle principale**. Un bouton « Enregistrer » envoie un `PATCH /api/club/info`
(management-gated SEC-07) qui met à jour **uniquement les champs présents** dans le body (partiel ;
`''` réinitialise à `null`), valide les emails et les longueurs (`422` sinon), puis invalide `["me"]`.
Les valeurs sont lues depuis le bloc `club` de `/api/me`. Saisie **manuelle** aujourd'hui ; l'autofill
depuis la fiche FFBB est prévu en lot C.

> **RGPD (minimisation).** Président et correspondant sont des **contacts professionnels** (données
> publiques de la fiche FFBB : nom, téléphone, email). **Aucune adresse de domicile** n'est stockée —
> seule l'adresse du club et de la salle principale (lieux publics) le sont. Base légale actée avec P0-1 (DP1 soldé) — [`../../docs/security/rgpd.md`](../../docs/security/rgpd.md) §2.

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

API Platform 4 sert les collections en JSON-LD avec la clé **`member`** — **sans** le
préfixe `hydra:`.

```typescript
// Fidèle au code livré (src/shared/api/collection.ts)
// collection()  : déballe `member`, tolère aussi un tableau nu, sinon [].
// collectionAll(): pagine par `?page=N` (PAGE_SIZE = 30), dédoublonne par `id`,
//                  s'arrête sur une page courte OU une page n'apportant rien de
//                  neuf (garde contre un `page` no-op côté serveur).
const raw = await api.get(path, { searchParams }).json<unknown>();
if (Array.isArray(raw)) return raw as T[];
if (Array.isArray((raw as { member?: unknown }).member)) return (raw as { member: T[] }).member;
return [];
```

Le frontend n'utilise **pas** `useInfiniteQuery` (aucune occurrence dans `src/`) :
`collectionAll()` agrège toutes les pages en une requête logique.

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
| `authStore` | `src/shared/stores/authStore.ts` | `isAuthenticated` uniquement — **aucun jeton** (SEC-16) | `localStorage` (`persist`, clé `cs-auth`, `version: 2` dont la migration EFFACE un jeton legacy) |
| `themeStore` | `src/shared/stores/themeStore.ts` | mode clair/sombre + slot `accent` du club | persisté (clé `cs-theme`, relue avant le premier rendu — voir §10) |
| `seasonStore` | `src/shared/stores/seasonStore.ts` | saison sélectionnée (`selectedSeasonId`) — alimente l'en-tête `X-Season-Id` | persisté |
| `toastStore` | `src/shared/stores/toastStore.ts` | file des notifications, rendue par `ui/toaster` | Non persisté |
| `transitionUiStore` | `src/shared/stores/transitionUiStore.ts` | état UI du bandeau de bascule de saison | persisté |
| wizard `store` | `src/features/wizard/store.ts` | étape courante, étape max atteinte, **mode** (`season`/`period`) + `calendarEntryId` — **aucune donnée métier** | persisté (`version: 4`) |
| planning `store` | `src/features/planning/store.ts` | planning sélectionné + état UI (vue, filtres) | Non persisté |
| matches `store` | `src/features/matches/store.ts` | état UI du module matchs | Non persisté |
| admin `store` | `src/features/admin/store.ts` | état UI de la console superadmin | Non persisté |

### authStore

```typescript
// Fidèle au code livré — SEC-16 : plus aucun jeton côté client, juste un indice d'UI.
type AuthState = {
  isAuthenticated: boolean;
  setAuthenticated: (value: boolean) => void;
  clear: () => void;
};

// Tout le reste (user, club, membershipStatus, seasonPlan, accent)
// vient de GET /api/me via TanStack Query (queryKey ["me"]).
```

### Règles

- **Un store par domaine.** Pas de store global "app" qui mélange tout.
- **Pas de données serveur en Zustand.** Si ça vient de l'API, c'est en TanStack Query.
- **Actions dans le store, pas dans les composants.** `login()`, `logout()`, `setContext()` vivent dans le store.
- **Pas de middleware complexe.** `persist` pour les préférences (thème, saison, drapeau de session), c'est tout. Pas de `devtools` en prod. ⚠ **Jamais de jeton dans un store** : depuis SEC-16 le JWT est un **cookie httpOnly** que le JS ne voit pas (§ tableau ci-dessus) — `authStore` ne porte qu'un booléen.
- **Sélecteurs fins.** `useAuthStore((s) => s.isAuthenticated)` pour éviter les re-renders inutiles.

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
| `/planning` | `GET /api/me`, `GET /api/schedules` (poll 2,5 s si génération en vol), `GET /api/schedule_slot_templates?scheduleId={id}`, `GET /api/schedule_diagnostics?scheduleId={id}`, `POST /api/schedules/{id}/generate`, `POST /api/schedules/{id}/validate`, `POST /api/schedules/{id}/reopen`, `POST /api/schedules/{id}/export-pdf` (`ExportMenu`), `PUT /api/schedule_plans/{id}` (renommage du plan), `PUT /api/schedules/{id}` (renommage de la version), `DELETE /api/schedules/{id}` (suppression d'une version de travail), `POST /api/schedule-slots/{id}/manual-edit/lock`, `POST /api/schedule-slots/{id}/manual-edit/one-time`, collections référentiels (`teams`, `venues`, `coaches`, `sport_categories`, `team_coaches`, `coach_player_memberships`) |
| `/` (cockpit) | `GET /api/me`, `GET /api/schedules`, `GET /api/schedule_plans`, `GET /api/calendar_entries` (+ conflits d'entrée), campagnes de doléances (badge radar), `GET /api/venue_unavailabilities` + `venue-unavailability-impact` (carte radar « gymnase indisponible » — P4-68) |
| `/wizard` | CRUD `teams`/`venues`/`coaches`/`constraints`/`venue_training_slots`…, `GET /api/priority_tiers`, `GET /api/sport_categories`, `POST /api/teams/reorder` (mode tri), `POST /api/constraints/validate`, `POST /api/schedules` + `generate` (étape Génération) |
| `/club` | `PATCH /api/club/appearance`, `POST/DELETE /api/club/logo`, `GET /api/clubs/{clubId}/logo` (public, cache-buster sur l'URL après upload), `PATCH /api/club/info` (fiche FFBB, management-gated), `GET /api/memberships/pending`, `POST /api/memberships/{id}/approve`, `POST /api/memberships/{id}/reject` (section « Demandes » — l'ancienne route `/pending-members` a été repliée ici) |
| `/profile` | `GET /api/me` |
| `/doleances/:token` | Endpoints **publics** de la campagne de doléances (lecture du formulaire pré-rempli + soumission des seules sections modifiées) — aucun JWT |
| `/admin*` | `POST /api/admin/auth/password`, `POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `GET /api/admin/{overview,health,clubs,jobs,actions}`, `POST /api/admin/jobs/{key}/run` (en-tête `X-CSRF-Token`) — client `adminApi` dédié, cookie de session `same-origin` |

### Headers obligatoires

| Header | Source | Injection |
|--------|--------|-----------|
| *(plus d'`Authorization`)* | **cookie httpOnly `BEARER`** posé par le serveur (SEC-16) | le navigateur l'envoie seul, `credentials: "include"` |
| `X-Season-Id` | `seasonStore.selectedSeasonId` | ky `beforeRequest` — **conditionnel** : uniquement si une saison est explicitement sélectionnée et que la requête n'en porte pas déjà une |

**Aucun header `X-Club-Id`** : le club est dérivé du JWT côté serveur (`backend-inventory.md`
§4) ; le frontend ne l'envoie jamais. `X-Season-Id`, lui, est envoyé quand le gestionnaire a
choisi une saison (voir §4) — et validé côté serveur dans tous les cas.

### Authentification

| Endpoint | Méthode | Body | Réponse | Action frontend |
|----------|---------|------|---------|-----------------|
| `/api/login` | POST | `{ email, password }` | **204 sans corps** — le JWT part en **cookie httpOnly** (SEC-16) | Poser `isAuthenticated`, redirect `/` — **il n'y a aucun jeton à stocker** |
| `/api/register` | POST | `{ email, password, firstName, lastName, ara, club_name?, consent }` (consent obligatoire — RGPD) | **202** `{ status:"verification_pending" }` (aucun token — A3) | Afficher l'écran « vérifie tes emails » ; **pas de redirect** (le JWT vient de la vérification) |
| `/api/register/verify` | POST | `{ token }` (du lien email) | `{ membershipStatus, user }` + **cookie httpOnly** posé par `JwtCookieFactory` (aucun jeton dans le corps) | Poser `isAuthenticated` ; `pending` → `/waiting`, sinon `/` |
| `/api/me` | GET | — | `{ id, email, firstName, lastName, membershipStatus, role, club: {…} \| null, seasonPlan: { id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash } \| null, seasons, … }` — **forme complète : `src/features/auth/api.ts` (`MeResponse`)**, source de vérité (le bloc `club` porte aussi l'accent sombre, la fiche FFBB, la ligue et le comité) | Query `["me"]` — source des guards, du thème (accent) et de l'état du plan de saison (ADR-0002) |

Les trois champs **structurants** de cette réponse : `club.accentColor` / `club.accentColorDark`
(thème appliqué par `useApplyClubTheme`), `seasonPlan.hasFinishedVersion` (verrou d'onboarding
et gate cockpit) et `seasonPlan.chosenScheduleId` (gate matchs et plans secondaires).

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

- `member` : items de la page (clé **sans** préfixe `hydra:` — API Platform 4)
- Query param `page` pour la pagination — c'est celui que suit `collectionAll()`

Le frontend passe par `collection()` / `collectionAll()` (§7 ci-dessus) — **pas**
d'`useInfiniteQuery`.

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
├── app/                        # router (lazy + filets), RootShell, RouteErrorBoundary,
│                               # ErrorBoundary, AppLayout, AuthGuard, providers,
│                               # SeasonSelector, SeasonTransitionBanner, ReadonlySeasonBanner
├── features/                   # Logique métier par domaine (liste : ls src/features/)
│   ├── admin/                  # Console superadmin : AdminGuard, AdminShell, AdminLoginPage,
│   │                           # AdminDashboardPage, sections/, Journaux/, client `adminApi` dédié
│   ├── auth/                   # Login/Register/ForgotPassword/ResetPassword/WaitingApproval/VerifyEmail + api/queries
│   ├── club/                   # ClubPage (logo + accent + infos FFBB + section Demandes), LogoCropper
│   ├── coach-wishes/           # #10 doléances : CoachWishesModal, CampaignDialog, CoachWishForm,
│   │                           # PublicWishPage (route publique), RadarCoachWishAction
│   ├── cockpit/                # CockpitPage : bandeau planning socle, calendrier mensuel, radar overlays
│   ├── legal/                  # PrivacyPage (/confidentialite)
│   ├── matches/                # MatchesPage : grille week-end, radar conflits, ImportFbiDialog
│   ├── planning/               # PlanningPage, PlanningToolbar, WeekGrid, SlotDetail, DiagnosticsPanel,
│   │                           # ResourceFilter, GenerationWaiting, ExportMenu, store, lib/grid
│   ├── profile/                # ProfilePage
│   ├── season-transition/      # RedateEventsDialog + api (le bandeau et le sélecteur vivent dans app/)
│   └── wizard/                 # WizardLayout, steps/ (Teams, Venues, Coaches, Constraints, Recap,
│                               # Generate + PeriodStructure, StructureSummary), lib/, store
├── shared/
│   ├── api/                    # client ky, collection (JSON-LD clé `member`), errors
│   ├── components/ui/          # Primitives (shadcn-style) — dont delete-confirm, load-error-hint,
│   │                           # team-select, modal, menu, accordion, toaster, empty-hint
│   ├── hooks/                  # useApplyTheme, useApplyClubTheme
│   ├── lib/                    # readState, teamTiers, color, palette, duration, errorMessage,
│   │                           # download, clipboard, passwordPolicy, useModalA11y, queryClient, utils
│   └── stores/                 # authStore, themeStore, seasonStore, toastStore, transitionUiStore
└── test/                       # setup vitest, helpers de rendu, suite a11y
```

### Trois pièces transverses à connaître avant de coder

- **`shared/lib/readState.ts`** — « cette lecture est-elle exploitable ? », une seule réponse
  pour toute l'app. Trois états sur un unique critère (*a-t-on une donnée ?*) : `loading`
  (rien à montrer encore), `failed` (échec **et** rien en cache — le seul cas où un écran doit
  céder la place à une erreur), `ready` (on a une donnée, même périmée). Deux conséquences :
  un `isError` de **refetch d'arrière-plan** ne doit pas détruire un écran qui fonctionne, et
  `data ?? []` pendant un premier chargement fabrique un **vide crédible** (« aucun créneau »)
  qui pousse à re-saisir (doublons) ou à valider une période qu'on croit vide.
- **`shared/components/ui/delete-confirm`** — confirmation destructive qui **annonce ses
  impacts** (« N réservations seront retirées »). À réutiliser plutôt qu'un `confirm()` nu.
- **`shared/components/ui/team-select`** — tout sélecteur d'équipes de l'app (contraintes,
  coachs, matchs, import FBI) passe par lui : optgroups par rang, même ordre que l'étape
  Équipes. Reclasser une équipe met l'ordre à jour **partout**.

### Alias

- `@/` → `src/` (configuré dans `vite.config.ts` et `tsconfig.json`)

### Thème appliqué avant le premier rendu

`src/main.tsx` lit le mode persisté (`readPersistedThemeMode`, clé `cs-theme`) et pose la
classe `.dark` **avant** le premier paint de React. Sans cela l'arbre se rend en clair puis un
effet bascule la classe : flash du mauvais thème **et** animation `transition-colors` qui
laisse les surfaces à des couleurs intermédiaires **sub-AA** (A11Y-06). Le pré-paint et
`useApplyTheme` partagent le même prédicat et la même forme de stockage, pour ne jamais
diverger.

### Reporting d'erreurs

`main.tsx` initialise Sentry **uniquement si `VITE_SENTRY_DSN` est posé au build** : erreurs
seules, `tracesSampleRate: 0`, pas de replay (quota free tier préservé, INF-01). DSN absent =
init sautée, SDK inerte — tout est câblé d'avance.

⚠ **Mais le DSN seul ne l'active PAS** (P4-65). `docker/frontend/csp.conf` déclare
`connect-src 'self' blob:` et **n'autorise aucun hôte tiers** : sans l'hôte d'ingestion du DSN
dans cette directive, le navigateur **jette chaque envoi, en silence**. Le SDK s'initialise,
l'application paraît instrumentée, rien n'arrive — et on le découvre le jour où on cherche
une erreur de production.

**Activer Sentry = deux gestes dans le même changement** : le DSN, et son hôte dans
`connect-src`. Un garde de build (`frontend/tooling/sentryCspGuard.ts`, appelé par
`vite.config.ts`) **fait échouer le build** si le DSN est posé sans son hôte — la panne
silencieuse est devenue bruyante. Il est inerte tant qu'aucun DSN n'est posé.

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

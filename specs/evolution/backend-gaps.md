# Backend Gaps — Frontend-Forward Spec vs Backend/Engine

Last verified @ 2026-06-30

> Identifie les gaps entre les specs frontend-forward (`frontend-spec.md`,
> `frontend-wizard.md`, `frontend-components.md`) et l'état réel du backend
> (snapshot OpenAPI `openapi-snapshot.json`, inventaire `backend-inventory.md`).
> Ce document guide les plans backend futurs et alerte Claude Code des blockers.

---

## Definition

Un **gap** est l'un des quatre types suivants :

1. **Missing endpoint** — un endpoint requis par une spec frontend n'existe
   ni dans `openapi-snapshot.json` ni dans `backend-inventory.md` §3
   (custom controllers).
2. **Unstable shape** — un endpoint existe mais sa forme (path, method, body,
   response) diffère de ce que la spec frontend décrit.
3. **Type mismatch** — un champ existe dans le schema OpenAPI mais son type
   ou sa structure ne correspond pas au besoin frontend.
4. **Unimplemented feature** — une fonctionnalité décrite dans une spec
   frontend n'a aucun support backend (ni endpoint, ni entity, ni migration).

---

## Gap list

### G1 — Server draft endpoint for wizard auto-save (Missing endpoint)

**Description :** Le wizard d'onboarding (`frontend-wizard.md` §7) implémente
un auto-save "Draft hybride" qui persiste le `WizardState` côté serveur via
`GET/PUT /api/clubs/{id}/draft`. Aucun de ces deux endpoints n'existe dans
l'OpenAPI snapshot. Le frontend peut fonctionner avec `sessionStorage`
uniquement (fallback), mais la restauration cross-session et cross-device est
impossible sans ce endpoint.

**Spec source :** `frontend-wizard.md` §7 (Auto-Save, tableau de contrat
d'interface), §9 (Endpoints synthèse — ligne "Gap — voir §7").

**Current backend state :** Aucun path `/api/clubs/{id}/draft` dans
`openapi-snapshot.json` (40 paths vérifiés, aucun ne contient "draft"). Aucun
`DraftController` dans `backend-inventory.md` §3 (7 custom controllers listés,
aucun lié au draft). La ressource `Club` expose `GET/PUT/DELETE` sur
`/api/clubs/{id}` sans opération custom de draft.

**Required shape :**

| Endpoint | Method | Body | Response | Status |
|----------|--------|------|----------|--------|
| `/api/clubs/{id}/draft` | GET | — | `WizardState` JSON | 200 ou 404 |
| `/api/clubs/{id}/draft` | PUT | `WizardState` JSON | — | 204 |

Le `WizardState` sérialisé contient : `currentStep`, `visited`, `completed`,
`draftStatus`, `step1Data`, `step2Data`, `step3Data`, `errors`, `isDemoMode`.
C'est un objet JSON arbitraire (arrays, nested objects, booleans).

**Délégué à backend future plan :** Créer un `DraftController` (ou opération
custom API Platform sur `Club`). Stocker le draft dans une nouvelle colonne
`clubs.draft_data` (jsonb, free-form) — ne pas utiliser `Season.transitionData`
(voir G2). Migration + entity + controller + test.

---

### G2 — transition_data schema mismatch : Club vs Season, type too narrow (Type mismatch)

**Description :** La spec wizard §7 indique "Le backend stocke dans
`clubs.transition_data` (jsonb)". En réalité, `transitionData` existe sur
`Season`, pas sur `Club`. De plus, `Season.transitionData` est typé comme
`Map<string, string|null>` (`additionalProperties: {type: ["string", "null"]}`),
ce qui ne peut pas stocker un `WizardState` sérialisé contenant des arrays,
objets imbriqués et booléens.

**Spec source :** `frontend-wizard.md` §7 ("Le backend stocke dans
`clubs.transition_data` (jsonb)").

**Current backend state :**
- `Club` schema (`openapi-snapshot.json`) : 15 propriétés, **aucune**
  `transitionData` ou `draftData`. Propriétés : `id`, `name`, `slug`,
  `timezone`, `locale`, `schoolZone`, `billingCycle`, `planId`, `planExpiresAt`,
  `ffbbClubCode`, `generationCountSeason`, `onboardingCompleted`, `createdAt`,
  `updatedAt`, `version`.
- `Season` schema : `transitionData: object` avec `additionalProperties:
  {type: ["string", "null"]}` — soit `Map<string, string|null>`, pas du
  jsonb free-form.

**Required shape :** Soit (a) ajouter `draftData: object` (jsonb free-form,
sans contrainte `additionalProperties`) à `Club`, soit (b) relaxer
`Season.transitionData` pour accepter du JSON arbitraire et utiliser
`/api/seasons/{id}` pour le draft. L'option (a) est préférable car le draft
est lié au club (onboarding), pas à la saison.

**Délégué à backend future plan :** Migration pour ajouter `draft_data` jsonb
à `clubs`. Mettre à jour `Club` API resource + entity. Ne pas modifier
`Season.transitionData` (utilisé pour la transition de saison, pas
l'onboarding).

---

### G3 — Venue closures endpoint (Missing endpoint + Unimplemented feature)

**Description :** Le wizard étape 1 (`frontend-wizard.md` §2) permet au
gestionnaire de saisir des fermetures exceptionnelles de salles
(`VenueClosure[]` : date + raison). L'endpoint `POST /api/venues/{id}/closures`
est référencé dans la table des données saisies mais marqué "gap — voir §7".
Aucune entity, resource ou endpoint de closure n'existe dans le backend.

**Spec source :** `frontend-wizard.md` §2 (Step 1 — Infrastructure,
`VenueClosureSchema`, table des données saisies ligne "Fermetures").

**Current backend state :** Aucun path contenant "closures" dans
`openapi-snapshot.json`. Aucun fichier `backend/src/Entity/*Closure*` (glob
vérifié). Le schema `Venue` n'a aucun champ lié aux fermetures. La resource
`VenueTrainingSlot` gère les créneaux récurrents (dayOfWeek, startTime,
durationMinutes) mais pas les fermetures exceptionnelles ponctuelles.

**Required shape :**

| Endpoint | Method | Body | Response |
|----------|--------|------|----------|
| `/api/venues/{id}/closures` | GET | — | `VenueClosure[]` (200) |
| `/api/venues/{id}/closures` | POST | `{ date, reason? }` | `VenueClosure` (201) |
| `/api/venues/{id}/closures/{closureId}` | DELETE | — | 204 |

Ou alternativement : une resource API Platform `VenueClosure` avec CRUD
standard sur `/api/venue_closures`.

**Délégué à backend future plan :** Créer `VenueClosure` entity (id, venueId,
date, reason) + migration + API Platform resource. Le frontend peut désactiver
l'UI de fermetures jusqu'à ce que le backend soit prêt.

---

### G4 — /api/register and /api/me absent from OpenAPI snapshot (Unstable shape)

**Description :** Les specs frontend référencent `/api/register` (POST) et
`/api/me` (GET) pour les flux d'authentification et l'hydratation du
`authStore` Zustand. Ces endpoints existent dans le code backend
(`AuthController.php`) et sont documentés dans `backend-inventory.md` §3, mais
ils n'apparaissent **pas** dans `openapi-snapshot.json`. Le frontend ne peut
pas valider les schemas de request/response depuis le snapshot OpenAPI.

**Spec source :** `frontend-spec.md` §9 (Authentification table :
`/api/register`, `/api/me`), §6.6 (Multi-tenant transparent : "Stocke `clubId`
et `seasonId` dans Zustand après login (depuis `/api/me`)").

**Current backend state :**
- `AuthController.php` implémente les deux routes (backend-inventory.md §3).
- `/api/register` : POST, body `{email, password, firstName, lastName,
  clubName, ara}`, retourne JWT (201). Crée User + Club + ClubUser + Season +
  Sport + 9 SportCategory.
- `/api/me` : GET, retourne `{id, email, firstName, lastName, club: {id,
  name}, hasGenerated}`.
- **Ni l'un ni l'autre** n'apparaît dans les 40 paths de
  `openapi-snapshot.json`. Ils sont déclarés comme routes Symfony classiques
  (`#[Route]`), pas comme opérations API Platform, donc exclus du snapshot
  auto-généré.

**Required shape :** Les deux endpoints doivent être documentés dans
l'OpenAPI snapshot avec schemas de request/response complets. Soit via
`#[OpenApi\Attributes]` sur `AuthController`, soit via un provider OpenAPI
custom, soit via NelmioApiDocBundle.

**Délégué à backend future plan :** Ajouter la documentation OpenAPI pour
`AuthController`. Les endpoints fonctionnent déjà — c'est un gap de
documentation, pas d'implémentation. Le frontend peut utiliser les endpoints
en s'appuyant sur `backend-inventory.md` §3 en attendant.

---

### G5 — manual-edit/* sub-routes absent from OpenAPI snapshot (Unstable shape)

**Description :** La spec frontend §9 (Édition manuelle) référence trois
sub-routes `manual-edit` sur les créneaux de planning. Ces routes existent dans
`ManualEditController.php` (backend-inventory.md §3) mais n'apparaissent pas
dans `openapi-snapshot.json`. De plus, le controller utilise
`/api/schedule-slots/{id}/manual-edit/*` (kebab-case) tandis que la resource
CRUD OpenAPI est `/api/schedule_slot_templates/{id}` (snake_case) — le segment
de path est inconsistent.

**Spec source :** `frontend-spec.md` §9 (Édition manuelle table :
`/api/schedule-slots/{id}/manual-edit/constraint|lock|one-time`), §6.7
(Optimistic updates : mutation `POST /api/schedule-slots/{id}/manual-edit/
one-time`).

**Current backend state :**
- `ManualEditController.php` implémente 3 routes (backend-inventory.md §3) :
  - `POST /api/schedule-slots/{id}/manual-edit/constraint` → 201
    `{constraintId}` (body : `type`, `reason`, `createdBy`)
  - `POST /api/schedule-slots/{id}/manual-edit/lock` → 200 (body : `lockLevel`)
  - `POST /api/schedule-slots/{id}/manual-edit/one-time` → 200 / 409 conflit
    (body : `startTime?`)
- Aucune de ces routes n'apparaît dans `openapi-snapshot.json`.
- La resource CRUD est `/api/schedule_slot_templates` (snake_case) mais les
  routes manual-edit utilisent `/api/schedule-slots` (kebab-case) — le path
  segment ne correspond pas.

**Required shape :** Les 3 routes doivent être documentées dans l'OpenAPI
snapshot. Le path naming doit être unifié : soit `schedule_slot_templates`
partout, soit `schedule-slots` partout. Le `ScheduleSlotTemplate` schema
OpenAPI contient déjà `lockLevel` (enum string), `temporaryLock` (boolean),
`temporaryLockFor` (string|null) — les champs existent pour supporter lock et
one-time edit.

**Délégué à backend future plan :** Ajouter documentation OpenAPI pour
`ManualEditController`. Résoudre l'inconsistance de naming
`schedule-slots` vs `schedule_slot_templates` (voir G6).

---

### G6 — snake_case vs kebab-case path discrepancy (Unstable shape)

**Description :** L'OpenAPI snapshot utilise snake_case pour tous les paths
multi-mots des resources API Platform, tandis que les specs frontend utilisent
kebab-case. `backend-inventory.md` §2 utilise également kebab-case dans sa
colonne "Endpoint", contredisant l'OpenAPI réel. C'est un mismatch de contrat
systématique qui affecte 10 resources sur 20.

**Spec source :** `frontend-spec.md` §9 (Endpoints consommés par route),
`frontend-wizard.md` §1 (table des 4 étapes, colonne "Endpoints backend"),
`frontend-components.md` §1 (note de discrepancy).

**Current backend state :** OpenAPI paths en snake_case :

| OpenAPI (snake_case) | Spec frontend (kebab-case) |
|----------------------|---------------------------|
| `/api/club_users` | `/api/club-users` |
| `/api/coach_player_memberships` | `/api/coach-player-memberships` |
| `/api/priority_tiers` | `/api/priority-tiers` |
| `/api/schedule_diagnostics` | `/api/schedule-diagnostics` |
| `/api/schedule_slot_templates` | `/api/schedule-slot-templates` |
| `/api/sport_categories` | `/api/sport-categories` |
| `/api/team_coaches` | `/api/team-coaches` |
| `/api/team_tags` | `/api/team-tags` |
| `/api/team_tag_assignments` | `/api/team-tag-assignments` |
| `/api/venue_training_slots` | `/api/venue-training-slots` |

Les custom controllers utilisent kebab-case : `import-teams`, `export-pdf`,
`manual-edit` — créant une inconsistency interne au backend lui-même.

**Required shape :** Le frontend HTTP client (`ky`) doit utiliser les paths
réels de l'OpenAPI (snake_case). Soit (a) les specs frontend sont corrigées
pour utiliser snake_case, soit (b) le backend API Platform est reconfiguré
pour utiliser kebab-case (`uriTemplate` overrides ou `shortName` changes).
L'option (a) est la plus simple et la moins risquée.

**Délégué à backend future plan :** Décider d'une convention unique. Si
snake_case reste, mettre à jour `frontend-spec.md`, `frontend-wizard.md` et
`backend-inventory.md` pour matcher. Si kebab-case est préféré, configurer
API Platform avec `uriTemplate` custom sur chaque resource.

---

### G7 — onboardingCompleted : spec correction, not a backend gap (Unstable shape)

**Description :** La spec wizard §5 et §7 indiquent que `onboarding_completed`
est absent de l'OpenAPI et du backend. En réalité, `Club.onboardingCompleted`
(boolean, default false) **est présent** dans l'OpenAPI snapshot. C'est une
erreur de documentation dans `frontend-wizard.md`, pas un gap backend. Le
champ est utilisable via `PUT /api/clubs/{id}` avec `{ onboardingCompleted:
true }`. La spec utilise snake_case `onboarding_completed` mais l'OpenAPI
utilise camelCase `onboardingCompleted`.

**Spec source :** `frontend-wizard.md` §5 (note "Gap backend"), §7 (table
"Gap — champ absent"), §9 (synthèse "Gap — champ absent").

**Current backend state :** `Club.onboardingCompleted: boolean (default:
false)` est présent dans `openapi-snapshot.json`. `PUT /api/clubs/{id}` accepte
le champ (opération Put sur Club resource).

**Required shape :** Aucun changement backend nécessaire. `frontend-wizard.md`
§5, §7 et §9 doivent être corrigés pour noter que `onboardingCompleted` EST
présent (camelCase, pas snake_case `onboarding_completed`). Le frontend
utilise `PUT /api/clubs/{id}` avec `{ onboardingCompleted: true }` pour
marquer l'onboarding comme terminé.

**Délégué à backend future plan :** Aucun — c'est une correction de spec, pas
un gap backend. Documenté ici pour alerter Claude Code que les claims de gap
dans `frontend-wizard.md` concernant `onboarding_completed` sont obsolètes
(vérifié dans T14, confirmé dans T19).

---

## Summary table

| Gap | Type | Severity | Spec source | Backend action needed |
|-----|------|----------|-------------|----------------------|
| G1 | Missing endpoint | High | wizard §7 | Create draft controller + migration |
| G2 | Type mismatch | High | wizard §7 | Add `draft_data` jsonb to Club |
| G3 | Missing endpoint + Unimplemented | Medium | wizard §2 | Create VenueClosure entity + resource |
| G4 | Unstable shape (OpenAPI doc) | Low | spec §9 | Add OpenAPI docs for AuthController |
| G5 | Unstable shape (OpenAPI doc + naming) | Medium | spec §9 | Add OpenAPI docs + resolve naming |
| G6 | Unstable shape (systematic) | Medium | spec §9, wizard §1 | Decide convention, update specs or API Platform |
| G7 | Spec correction (not a gap) | Info | wizard §5, §7, §9 | None — correct frontend-wizard.md |

> **Note :** G1 et G2 sont les blockers principaux pour le wizard auto-save.
> Le frontend peut implémenter l'auto-save avec `sessionStorage` uniquement
> (fallback) jusqu'à ce que G1+G2 soient résolus. G3 peut être désactivé
> côté frontend (UI des fermetures masquée). G4 et G5 sont des gaps de
> documentation OpenAPI — les endpoints fonctionnent déjà. G6 est un
> mismatch de naming à résoudre avant que le frontend ne code les appels API.

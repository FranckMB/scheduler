# Wizard — saisie des données (tranche 3, LIVRÉ)

> ⚠️ **Réalité livrée — canonique.** Le draft "4 étapes" plus bas est **historique/superseded** : le wizard a été reconstruit dans `frontend/src/features/wizard` avec un flux plus granulaire, décidé avec le PO. Les sections 1+ ci-dessous ne décrivent plus l'implémentation.

## Flux réel (6 étapes, `WizardLayout` + registre `lib/steps.ts`)

1. **Équipes** — CRUD + classement : groupées par **rang** (`PriorityTier` S/A/B/C/D, affiché « S · Fanion » … « D · Bonus » via `TIER_MEANING`, **home unique** `shared/lib/teamTiers`). Le rang se choisit **à la création** (sélecteur « Rang » du formulaire d'ajout) ; il ne se change **plus** via un dropdown en ligne sur la ligne équipe — la reclassification passe **uniquement** par le mode **Trier** (drag&drop inter-rang + monter/descendre), ordre **intra-rang** via `Team.tierOrder`, le tri en attente **committé au démontage** de l'étape (plus seulement au bouton « Terminer le tri »). Champs par équipe : **catégorie** (tranches d'âge **non genrées** — Baby/U5…U21/Senior/Vétéran/Loisir, source unique `App\Sport\BasketballCategoryCatalog`, seedée par `BasketballInit` + `AuthController::seedNewClub`), **genre** (Homme/Femme/Mixte, champ autonome depuis le dégenrage des catégories), **niveau de jeu** (`TeamLevel` : Élite/National/Régional/…/Loisir — lu+écrit via `TeamResource.level` + `TeamStateProcessor`), séances/sem. **Warning non bloquant** si équipe compétitive (niveau ≠ Loisir) classée rang D (Bonus). Le titre interne « Équipes » est retiré (le header sticky `WizardLayout` porte déjà « Étape 1/6 · Équipes »). Validation : ≥1 équipe. **Ajout sans nom** → erreur « Le nom de l'équipe est obligatoire » sous le formulaire + autofocus sur le champ (plus de no-op silencieux). **Découpage S/A/B/C/D partagé** : tout sélecteur qui liste des équipes (contraintes « Équipe »/« Cible », coachs, matchs `FixtureFormDialog`/`ImportFbiDialog`) réutilise ce classement via `shared/components/ui/TeamSelect` (optgroups par rang, même ordre que l'étape équipes) — reclasser une équipe met à jour l'ordre dans **tous** les sélecteurs.
2. **Gymnases** — CRUD + **grille hebdo cliquable par gymnase** (`VenueAvailabilityGrid`) : clic sur une case = pose un créneau `VenueTrainingSlot`, clic sur un créneau = l'édite. Case **« Terrain divisible »** (`Venue.canSplit`) : décochée → créneaux en terrain entier (capacité figée à 1) ; cochée → choix 1/2 équipes par créneau. Sélecteur de couleur doublé d'un champ hexa. **Durées de créneau affichées en heures** (`shared/lib/formatDuration` : `30 min`/`45 min` sous 1h, puis `1h`, `1h15`…). Éditeur de créneau titré « Modification du créneau ». Validation : chaque gymnase a ≥1 créneau — message « Ce gymnase doit avoir au moins un créneau » affiché **sous le formulaire d'ajout**.
3. **Coachs** — CRUD (+ `Coach.isEmployee` salarié, writable) + liens `TeamCoach` (coach/adjoint, bouton « Lier ») et `CoachPlayerMembership` (joueur). Validation : ≥1 coach ; coach sans équipe = warning.
4. **Contraintes** — onglets par famille (TIME/DAY/FACILITY/COACH_AVAILABILITY — **plus de FACILITY_CAPACITY**, la capacité vit sur l'écran Gymnases). Cible = **Toutes les équipes / un groupe (tag) / une équipe** : un groupe pose une contrainte CLUB + `config.targetTag` que `ScheduleConstraintBuilder::resolveTagToTeamIds` éclate en N contraintes équipe (ex. groupe `JEUNE` → pas de créneau après 19h50). Onglet **« Réserver »** : fixer une équipe sur un créneau de dispo existant → mémorisé dans le store wizard, appliqué au lancement comme `ScheduleSlotTemplate` lock **HARD** (repris par le snapshot solveur, lu par club+season). Nom auto-généré, règle par défaut **PREFERRED**. **Édition** (#120) : chaque contrainte existante est **modifiable** (bouton crayon → formulaire partagé pré-rempli, `PUT /api/constraints/{id}`) — pas seulement ajout/suppression. Modes durs (toujours HARD, pas de sélecteur de règle) : FACILITY **« impose »** = `forcedVenueId` (l'équipe joue dans ce gymnase ; sur un groupe, le gymnase lui est **réservé** — interdit hors tag) ; DAY **« uniquement »** = `allowedDays` (**whitelist** : l'engine interdit tous les autres jours — ⚠ **pas** `forcedDays` qui ne veut dire QUE « au moins une séance ces jours-là », audit ENG-16). Modes soft : FACILITY préfère/évite, DAY à éviter (`forbiddenDays`).
5. **Récapitulatif** — compteurs + accordéons (composant partagé `AccordionSection`, accent au survol) + **gate pré-solveur** (`POST /api/constraints/validate`). Listes enrichies : **équipes triées par rang** (`orderedTeams`) avec coach principal en *italique* + niveau de jeu ; **gymnases** avec pastille de couleur ; **coachs** en « Prénom (équipes) » (ex. « Maxime (SM1) », « Emerick (SF1, U15F1) ») + statut salarié/coach-joueur. Le bouton **« Continuer vers la génération »** avance vers l'étape 6 (ne lance plus directement).
6. **Génération** — étape **pleine largeur** (nav gauche masquée, retour via « ← Retour aux étapes »). Bouton **Lancer** → crée un `Schedule` DRAFT, POST les réservations, lance la génération. **Écran d'attente animé** (mark du club pulse/fade, phrases défilantes, « 1 à 3 min ») + **poll de statut** (`useScheduleStatus`) + **garde anti-boucle** (timeout 5 min · statut FAILED · erreur POST → « Réessayer »). Dès qu'un schedule est COMPLETED (ou en cours), le **planning s'affiche inline** dans cette même étape (`PlanningPage` embarqué, transition ajax) — la boucle de travail vit dans le flow, sans changer de route ; régénérer garde le planning affiché.

**Chrome commun (`WizardLayout`) :** un **seul** titre d'étape, porté par le **header sticky** « Étape N/6 · … » — aucun `<h2>` interne dans les 6 steps (dédup). **Footer sticky** Précédent/Suivant collé au **bas réel** (colonne `min-h-[calc(100vh-5.5rem)] flex-col` + `mt-auto` → footer flush en bas de viewport même sur étape courte, épinglé au scroll ; `5.5rem` = header AppLayout 3.5rem + padding-haut du main 2rem). Nav gauche `md:w-44` (repliable « Plein écran »). Sur l'étape Génération, `PlanningPage` reçoit `embedded` → grille raccourcie (`calc(100vh-24rem)`) pour ne pas passer sous le footer.

**Principes actés (divergent du draft historique) :**
- **Sauvegarde au fil de l'eau, par entité** (POST/PUT/DELETE immédiats, mutations TanStack). « Suivant » ne fait que **valider + naviguer**. → **pas** de draft-blob (`/api/clubs/{id}/draft` **abandonné**, jamais implémenté).
- **3 modes, mêmes écrans** : *libre* (club onboardé) · *onboarding guidé* (nav verrouillée vers l'avant) selon `club.onboardingCompleted` · **mode période (palier B)** = adaptation d'un `CalendarEntry`. Le mode vit dans le store wizard (`mode`, `calendarEntryId`, `startPeriodMode`/`exitPeriodMode`, persist v3), déclenché par le cockpit (radar « Adapter » → `startPeriodMode(entryId)` + navigate `/wizard`).
  - **Mode période** : bandeau « Mode période — {titre} · fenêtre » + « Quitter » ; Équipes/Gymnases/Coachs **hérités en lecture seule** (vues `StructureSummary` partagées avec le récap #121 : équipes **par tier en accordéon**, gymnases avec pastille couleur + nb de créneaux et gymnase fermé rendu **INTERDIT** — ligne rouge + icône STOP — via l'endpoint conflits, coachs triés salariés→coach-joueurs→reste) ; **Contraintes** actives et scopées à l'entrée (`listConstraints({calendarEntryId})`, chaque contrainte créée porte `calendarEntryId`) ; **Génération** produit l'**overlay** (`POST /api/schedules {calendarEntryId}` ou régénère l'overlay existant), complétion keyée sur l'id de l'overlay, sélection dans le work-loop puis grille embarquée. Jamais `["me"]` invalidé (onboarding intact) ; invalide `["calendar-entries"]`.
- **2 modes de base, mêmes écrans** : *libre* (club onboardé) vs *onboarding guidé* (nav verrouillée vers l'avant) selon `club.onboardingCompleted`, exposé dans `/api/me`. Bascule à `true` **au lancement** de la 1ère génération (`GenerateScheduleController`). En guidé, `AuthGuard` verrouille sur `/wizard` **sauf les routes du menu compte** (`/profile`, `/club`, avec `/wizard`) qui restent accessibles ; toute autre route (dont l'accueil `/`) redirige vers `/wizard`, et une tentative d'accès au **cockpit `/`** ajoute un toast éphémère « Lancez votre première génération d'abord ».
- **Reprise sur le premier trou** (guidé) : à l'entrée du wizard on se positionne sur la première étape incomplète (pas d'équipe → Équipes, gymnase sans créneau → Gymnases, pas de coach → Coachs) via `store.jumpTo` ; tout rempli → on ne bouge pas. Les clubs ayant déjà généré arrivent sur le planning (AuthGuard).
- **Tenant** : le front n'envoie **aucun** header `X-Club-Id` (club résolu serveur depuis le JWT — voir `backend/docs/TENANT.md`).
- **URIs API** : snake_case (`/api/team_coaches`, `/api/venue_training_slots`, `/api/sport_categories`, `/api/priority_tiers`…), **pas** les tirets du draft.
- **Différé (évolution)** : import Excel/CSV, mode démo, fermetures exceptionnelles, rôles non-admin & gestion des membres, transition de saison — tous suivis dans [`../evolution/roadmap.md`](../evolution/roadmap.md).
- Garanti par : `backend/tests/.../OnboardingFlowTest`, `backend/scripts/onboarding-smoke.sh`, `frontend/.../WizardPage.test`.

---

<details><summary>Historique — draft "4 étapes" (superseded, conservé pour trace)</summary>

## 1. Wizard Decision — Draft hybride, 4 étapes (HISTORIQUE)

### Décision

Le wizard initial passe de 6 étapes (v3 spec §9.1) à **4 étapes** consolidées.
Le gestionnaire arrive avec ses données en vrac (Excel, papier, mémoire). Le
wizard le guide sans le perdre, sauvegarde automatiquement, et valide en temps
réel.

**Pourquoi 4 et non 6 :** les étapes Club, Salles et Coaches étaient trop
fragmentées. Le gestionnaire saisit ses salles et ses entraîneurs dans la même
session "Infrastructure". Les priorités (tier list) sont des contraintes de
scheduling, pas des données d'onboarding — elles vont dans l'étape Contraintes.

### Les 4 étapes

| Étape | Nom | Contenu | Endpoints backend |
|-------|-----|---------|-------------------|
| 1 | Infrastructure | Salles (venues) + disponibilités + fermetures | `GET/POST /api/venues`, `GET/POST /api/venue-training-slots` |
| 2 | Ressources | Équipes (teams) + Entraîneurs (coaches) + import Excel moderne | `GET/POST /api/teams`, `GET/POST /api/coaches`, `GET/POST /api/team-coaches`, `POST /api/clubs/{id}/import-teams`, `GET /api/sport-categories` |
| 3 | Contraintes | Contraintes permanentes + priorités (tier list S/A/B/C/D) | `GET/POST /api/constraints`, `GET /api/priority-tiers` |
| 4 | Récapitulatif | Review global + validation Zod + submit | `PUT /api/clubs/{id}` (marquer `onboarding_completed`) |

> Référence endpoints : `specs/courantes/openapi-snapshot.json` (paths
> `\/api\/venues`, `\/api\/teams`, `\/api\/coaches`, `\/api\/constraints`,
> `\/api\/priority-tiers`, `\/api\/sport-categories`, `\/api\/clubs`).
> Référence contrôleurs : `specs/courantes/backend-inventory.md` §2 (20
> ressources API Platform) et §3 (custom controllers).

### Mode démo

Club basket fictif pré-rempli accessible depuis l'étape 1. Génération en 30s
pour démontrer la valeur avant saisie des vraies données. Le bouton "Mode démo"
pré-remplit les 4 étapes avec des données fictives (Gymnase A, U13 Masculin,
etc.) et permet de soumettre directement.

### Guard de redirection

`clubs.onboarding_completed === false` → redirect `/wizard` (voir
`frontend-spec.md` ligne 68-69). Le guard s'active sur `/`, `/dashboard`,
`/schedules/:id`, `/teams`, `/priorities`, `/profile`. `/login` et `/register`
sont exemptés.

---

## 2. Step 1 — Infrastructure

### Objectif

Le gestionnaire saisit ses salles (venues), leurs disponibilités hebdomadaires
(tranches 15min, lun-sam), et les fermetures exceptionnelles.

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Nom de la salle | `string` | `z.string().min(1).max(100)` | `POST /api/venues` body `name` |
| Adresse | `string` | `z.string().min(1)` | `POST /api/venues` body `address` |
| Disponibilités | `VenueSlot[]` | `z.array(VenueSlotSchema).min(1)` | `POST /api/venue-training-slots` |
| Fermetures | `VenueClosure[]` | `z.array(VenueClosureSchema)` | `POST /api/venues/{id}/closures` (gap — voir §7) |

### Schéma de validation Zod (step 1)

```typescript
// Illustration — pas un fichier .ts
const VenueSlotSchema = z.object({
  venueId: z.string().uuid(),
  dayOfWeek: z.number().int().min(1).max(6),   // 1=lun, 6=sam
  startTime: z.string().regex(/^\d{2}:\d{2}$/), // "18:00"
  endTime: z.string().regex(/^\d{2}:\d{2}$/),
});

const VenueClosureSchema = z.object({
  venueId: z.string().uuid(),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), // "2026-07-14"
  reason: z.string().max(200).optional(),
});

const Step1Schema = z.object({
  venues: z.array(z.object({
    name: z.string().min(1).max(100),
    address: z.string().min(1),
  })).min(1, "Au moins une salle est requise"),
  slots: z.array(VenueSlotSchema),
  closures: z.array(VenueClosureSchema),
});
```

### UX

- Grille de disponibilité visuelle : 6 colonnes (lun-sam) × tranches 15min
- Click sur une cellule = toggle disponible/indisponible
- Bouton "Ajouter une salle" ouvre un formulaire inline
- Bouton "Importer CSV" pour import en masse de salles
- Validation temps réel : bordure rouge sur champ invalide + message `role="alert"`

### Test Cases

#### Test Cases — Step 1

**Given** le gestionnaire "Maxence Dupont" (`maxence.dupont@example.com`) est sur l'étape 1 Infrastructure avec zéro salle saisie
**When** il clique sur "Suivant"
**Then** un message d'erreur s'affiche avec `role="alert"` : "Au moins une salle est requise"
**And** le wizard reste sur l'étape 1

**Given** le gestionnaire saisit la salle "Gymnase A" avec l'adresse "12 Rue du Sport, Lyon" et ajoute une disponibilité le lundi 18:00-19:30
**When** il clique sur "Suivant"
**Then** l'étape 2 Ressources s'affiche
**And** `POST /api/venues` a été appelé avec `{ name: "Gymnase A", address: "12 Rue du Sport, Lyon" }`
**And** `POST /api/venue-training-slots` a été appelé avec le créneau lundi 18:00-19:30
**And** `step1Completed` passe à `true` dans le state

**Given** le gestionnaire a saisi "Gymnase A" et "Gymnase B" avec des disponibilités
**When** il ajoute une fermeture pour "Gymnase A" le 2026-07-14 (Fête Nationale)
**Then** la fermeture apparaît dans la liste des fermetures de "Gymnase A"
**And** l'auto-save déclenche après 2s d'inactivité (voir §7)

**Given** le gestionnaire est sur l'étape 1 avec "Gymnase A" saisi
**When** il clique sur "Mode démo"
**Then** les salles "Gymnase A", "Gymnase B", "Gymnase C" sont pré-remplies avec des disponibilités fictives
**And** le wizard reste sur l'étape 1 pour validation manuelle

---

## 3. Step 2 — Ressources

### Objectif

Le gestionnaire saisit ses équipes (teams) et ses entraîneurs (coaches), les
associe via TeamCoach, et peut importer un fichier Excel avec mapping de
colonnes moderne.

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Équipe — nom | `string` | `z.string().min(1).max(100)` | `POST /api/teams` body `name` |
| Équipe — catégorie | `SportCategory` | `z.string().uuid()` | `POST /api/teams` body `sportCategoryId` |
| Équipe — tier | `PriorityTier` | `z.enum(["S","A","B","C","D"])` | `POST /api/teams` body `priorityTierId` |
| Coach — prénom | `string` | `z.string().min(1)` | `POST /api/coaches` body `firstName` |
| Coach — nom | `string` | `z.string().min(1)` | `POST /api/coaches` body `lastName` |
| Coach — email | `string` | `z.string().email()` | `POST /api/coaches` body `email` |
| Assignation TeamCoach | `TeamCoach` | `z.object({ teamId, coachId, role })` | `POST /api/team-coaches` |

### Import Excel moderne

L'import Excel (`POST /api/clubs/{id}/import-teams`) est modernisé côté frontend :

1. **Upload fichier** : drag-and-drop ou click pour sélectionner un `.xlsx`
2. **Column mapping** : le frontend détecte les colonnes du fichier et propose
   un mapping automatique vers les champs `Team` (nom, catégorie, tier). Le
   gestionnaire peut ajuster le mapping manuellement.
3. **Paste-rows** : le gestionnaire peut coller des lignes directement depuis
   Excel (clipboard) sans upload de fichier. Le frontend parse les lignes
   tab-separated.
4. **Preview** : tableau de prévisualisation avant soumission, avec lignes
   valides en vert et lignes en erreur en rouge (`role="alert"` sur les
   erreurs).
5. **Submit** : `POST /api/clubs/{id}/import-teams` avec le fichier mappé.

> Référence backend : `backend-inventory.md` §3 — `ImportController` accepte
> `file` (.xlsx) + `seasonId`, délègue à `FfbbExcelImporter`, retourne
> `{ created, skipped, errors }`.

### Schéma de validation Zod (step 2)

```typescript
// Illustration — pas un fichier .ts
const TeamSchema = z.object({
  name: z.string().min(1).max(100),
  sportCategoryId: z.string().uuid(),
  priorityTierId: z.string().uuid(),
  minSessionsPerWeek: z.number().int().min(1).max(7).optional(),
});

const CoachSchema = z.object({
  firstName: z.string().min(1).max(100),
  lastName: z.string().min(1).max(100),
  email: z.string().email(),
  isEmployee: z.boolean().optional(),
});

const TeamCoachSchema = z.object({
  teamId: z.string().uuid(),
  coachId: z.string().uuid(),
  role: z.enum(["head", "assistant"]),
  isRequired: z.boolean().default(true),
});

const Step2Schema = z.object({
  teams: z.array(TeamSchema).min(1, "Au moins une équipe est requise"),
  coaches: z.array(CoachSchema).min(1, "Au moins un entraîneur est requis"),
  assignments: z.array(TeamCoachSchema),
});
```

### UX

- Deux onglets dans l'étape : "Équipes" et "Entraîneurs"
- Onglet Équipes : liste + formulaire inline + bouton import Excel
- Onglet Entraîneurs : liste + formulaire inline + bouton import CSV
- Drag-and-drop d'un coach sur une équipe pour créer une assignation TeamCoach
- Validation temps réel sur chaque champ

### Test Cases

#### Test Cases — Step 2

**Given** le gestionnaire est sur l'étape 2 Ressources, onglet "Équipes", avec zéro équipe saisie
**When** il saisit l'équipe "U13 Masculin" avec la catégorie "U13" (`sportCategoryId` from `GET /api/sport-categories`) et le tier "B"
**Then** l'équipe apparaît dans la liste avec un badge vert "Validé"
**And** `POST /api/teams` a été appelé avec `{ name: "U13 Masculin", sportCategoryId: "<uuid>", priorityTierId: "<uuid-tier-B>" }`

**Given** le gestionnaire est sur l'onglet "Entraîneurs" et saisit le coach "Maxence Dupont" avec l'email `maxence.dupont@example.com`
**When** il clique sur "Ajouter"
**Then** le coach apparaît dans la liste
**And** `POST /api/coaches` a été appelé avec `{ firstName: "Maxence", lastName: "Dupont", email: "maxence.dupont@example.com" }`

**Given** le gestionnaire a saisi l'équipe "U13 Masculin" et le coach "Maxence Dupont"
**When** il glisse le coach "Maxence Dupont" sur l'équipe "U13 Masculin" et sélectionne le rôle "Head Coach"
**Then** une assignation TeamCoach est créée
**And** `POST /api/team-coaches` a été appelé avec `{ teamId: "<uuid-u13>", coachId: "<uuid-maxence>", role: "head", isRequired: true }`
**And** l'équipe "U13 Masculin" affiche le badge "Head: Maxence Dupont"

**Given** le gestionnaire clique sur "Importer Excel" et sélectionne le fichier `equipes_saison_2026.xlsx`
**When** le frontend parse le fichier et détecte les colonnes "Nom", "Catégorie", "Niveau"
**Then** un écran de column mapping s'affiche avec les correspondances proposées : "Nom" → `name`, "Catégorie" → `sportCategoryId`, "Niveau" → `priorityTierId`
**And** le gestionnaire peut ajuster le mapping manuellement

**Given** le column mapping est validé et le gestionnaire clique sur "Confirmer l'import"
**When** `POST /api/clubs/{id}/import-teams` répond 200 avec `{ created: 8, skipped: 2, errors: [] }`
**Then** 8 équipes apparaissent dans la liste avec un badge vert
**And** un message de succès s'affiche : "8 équipes importées, 2 ignorées"
**And** si `errors` n'est pas vide, chaque erreur s'affiche avec `role="alert"`

**Given** le gestionnaire colle 5 lignes tab-separated depuis Excel dans le champ "Paste-rows"
**When** le frontend parse les lignes
**Then** un tableau de prévisualisation s'affiche avec 5 lignes
**And** les lignes valides ont un fond vert, les invalides un fond rouge avec `role="alert"`

---

## 4. Step 3 — Contraintes

### Objectif

Le gestionnaire saisit les contraintes permanentes de scheduling et définit les
priorités des équipes via la tier list drag & drop (S/A/B/C/D).

### Données saisies

| Champ | Type | Validation Zod | Endpoint |
|-------|------|----------------|----------|
| Contrainte — type | `enum` | `z.enum(["venue_exclusion", "coach_unavailability", "team_link", "max_consecutive_days", ...])` | `POST /api/constraints` body `type` |
| Contrainte — scope | `enum` | `z.enum(["global", "venue", "coach", "team"])` | `POST /api/constraints` body `scope` |
| Contrainte — params | `object` | `z.record(z.unknown())` (dépend du type) | `POST /api/constraints` body `params` |
| Contrainte — reason | `string` | `z.string().max(500)` | `POST /api/constraints` body `reason` |
| Tier assignment | `Record<teamId, tier>` | `z.record(z.enum(["S","A","B","C","D"]))` | `PUT /api/teams/{id}` body `priorityTierId` |

### Regroupement par scope/family

Les contraintes sont regroupées visuellement par famille :

| Famille | Types de contraintes | Couleur |
|----------|----------------------|---------|
| Salle | `venue_exclusion`, `venue_closure_recurring` | Bleu |
| Entraîneur | `coach_unavailability`, `coach_max_consecutive` | Vert |
| Équipe | `team_link`, `team_min_sessions`, `team_max_consecutive_days` | Orange |
| Globale | `max_daily_slots`, `rest_day_constraint` | Gris |

### Tier list drag & drop

- 5 colonnes : S, A, B, C, D (de la plus haute à la plus basse priorité)
- Les équipes créées à l'étape 2 apparaissent dans la colonne D par défaut
- `@dnd-kit` pour le drag-and-drop entre colonnes
- `PUT /api/teams/{id}` avec `priorityTierId` au drop
- `GET /api/priority-tiers` pour résoudre les UUIDs des tiers

### Schéma de validation Zod (step 3)

```typescript
// Illustration — pas un fichier .ts
const ConstraintSchema = z.object({
  type: z.enum([
    "venue_exclusion",
    "coach_unavailability",
    "team_link",
    "max_consecutive_days",
    "rest_day_constraint",
  ]),
  scope: z.enum(["global", "venue", "coach", "team"]),
  params: z.record(z.unknown()),
  reason: z.string().max(500).optional(),
});

const TierAssignmentSchema = z.record(
  z.string().uuid(),
  z.enum(["S", "A", "B", "C", "D"])
);

const Step3Schema = z.object({
  constraints: z.array(ConstraintSchema),
  tierAssignments: TierAssignmentSchema,
});
```

### UX

- Accordion par famille de contraintes (Salle, Entraîneur, Équipe, Globale)
- Bouton "Ajouter une contrainte" dans chaque accordion
- Tier list en bas de page, draggable
- Validation temps réel sur les paramètres des contraintes

### Test Cases

#### Test Cases — Step 3

**Given** le gestionnaire est sur l'étape 3 Contraintes avec l'équipe "U13 Masculin" dans la colonne D
**When** il glisse "U13 Masculin" de la colonne D vers la colonne B
**Then** l'équipe "U13 Masculin" apparaît dans la colonne B
**And** `PUT /api/teams/{id}` a été appelé avec `{ priorityTierId: "<uuid-tier-B>" }`
**And** l'auto-save déclenche après 2s

**Given** le gestionnaire ouvre l'accordion "Entraîneur" et clique sur "Ajouter une contrainte"
**When** il sélectionne le type `coach_unavailability`, le coach "Maxence Dupont", et les créneaux indisponibles : mercredi toute la journée
**Then** la contrainte apparaît dans l'accordion "Entraîneur" avec le libellé "Maxence Dupont — Indisponible le mercredi"
**And** `POST /api/constraints` a été appelé avec `{ type: "coach_unavailability", scope: "coach", params: { coachId: "<uuid>", dayOfWeek: 3 }, reason: "Indisponible le mercredi" }`

**Given** le gestionnaire ouvre l'accordion "Salle" et ajoute une contrainte `venue_exclusion` pour "Gymnase A" le samedi
**When** il valide la contrainte
**Then** la contrainte apparaît dans l'accordion "Salle" avec le libellé "Gymnase A — Exclu le samedi"
**And** `POST /api/constraints` a été appelé avec `{ type: "venue_exclusion", scope: "venue", params: { venueId: "<uuid-gymnase-a>", dayOfWeek: 6 } }`

**Given** le gestionnaire a saisi 3 contraintes et assigné les tiers de 8 équipes
**When** il clique sur "Suivant"
**Then** l'étape 4 Récapitulatif s'affiche
**And** `step3Completed` passe à `true`

---

## 5. Step 4 — Récapitulatif

### Objectif

Le gestionnaire révise l'ensemble des données saisies, corrige si nécessaire,
et soumet le wizard pour marquer l'onboarding comme terminé.

### Données affichées

| Section | Contenu | Source |
|---------|---------|--------|
| Salles | Liste des venues + dispos + fermetures | `wizardState.step1Data` |
| Équipes | Liste des teams + catégorie + tier | `wizardState.step2Data` |
| Entraîneurs | Liste des coaches + assignations | `wizardState.step2Data` |
| Contraintes | Liste groupée par famille | `wizardState.step3Data` |
| Tier list | Récapitulatif visuel S/A/B/C/D | `wizardState.step3Data` |

### Validation Zod globale

Avant soumission, une validation Zod globale s'exécute sur l'ensemble des 4
étapes réunies :

```typescript
// Illustration — pas un fichier .ts
const WizardDataSchema = z.object({
  step1: Step1Schema,
  step2: Step2Schema,
  step3: Step3Schema,
});

// À l'étape 4, on valide tout :
const result = WizardDataSchema.safeParse(wizardState.allData);
if (!result.success) {
  // Afficher les erreurs par étape avec role="alert"
}
```

### Soumission

1. Validation Zod globale → si erreurs, afficher par étape avec `role="alert"`
2. Si valide, `PUT /api/clubs/{id}` avec `{ onboardingCompleted: true }`
3. Redirection vers `/dashboard`

> **Correction (ex-gap G7, fermé) :** le champ **existe** dans l'OpenAPI en
> camelCase `Club.onboardingCompleted` (boolean, default false) — l'ancien
> claim de gap (snake_case `onboarding_completed` absent) était une erreur de
> doc. Décisions tracées dans `specs/evolution/roadmap.md`.

### UX

- Vue récapitulative en lecture seule, organisée par étape
- Bouton "Modifier" sur chaque section → retour à l'étape correspondante
- Bouton "Générer le planning" (submit) en bas de page
- Si erreurs de validation globale : panneau d'erreurs en haut avec `role="alert"`

### Test Cases

#### Test Cases — Step 4 et Intégration

**Given** le gestionnaire a complété les étapes 1-3 avec "Gymnase A", l'équipe "U13 Masculin", le coach "Maxence Dupont", et 2 contraintes
**When** il arrive sur l'étape 4 Récapitulatif
**Then** un récapitulatif s'affiche avec 4 sections : Salles (1), Équipes (1), Entraîneurs (1), Contraintes (2)
**And** chaque section a un bouton "Modifier"

**Given** le gestionnaire est sur l'étape 4 et le récapitulatif est complet
**When** il clique sur "Générer le planning"
**Then** la validation Zod globale s'exécute sur `WizardDataSchema`
**And** si valide, `PUT /api/clubs/{id}` est appelé avec `{ onboarding_completed: true }`
**And** la redirection se fait vers `/dashboard`

**Given** le gestionnaire est sur l'étape 4 et l'étape 2 a 0 équipe (invalidation Zod)
**When** il clique sur "Générer le planning"
**Then** la validation Zod globale échoue
**And** un panneau d'erreurs s'affiche en haut avec `role="alert"` : "Étape 2 — Ressources : Au moins une équipe est requise"
**And** le bouton "Modifier" de l'étape 2 est mis en évidence

**Given** le gestionnaire clique sur "Modifier" sur la section Contraintes
**When** le wizard revient à l'étape 3
**Then** les données précédemment saisies sont restaurées depuis `wizardState.step3Data`
**And** `currentStep` passe à 3
**And** le focus se déplace sur le titre de l'étape 3

**Given** le gestionnaire a quitté le wizard à l'étape 2 sans soumettre, puis rouvre `/wizard` dans une nouvelle session
**When** le wizard se charge
**Then** les données de l'étape 1 sont restaurées depuis le draft serveur (ou sessionStorage en fallback)
**And** `currentStep` est restauré à 2
**And** un message discret s'affiche : "Brouillon restauré"

---

## 6. State Machine — useReducer

### Type WizardState

```typescript
// Illustration — pas un fichier .ts
type WizardStep = 1 | 2 | 3 | 4;

type DraftStatus = "idle" | "dirty" | "saving" | "saved" | "error";

interface WizardState {
  currentStep: WizardStep;
  visited: Set<WizardStep>;
  completed: Record<WizardStep, boolean>;
  draftStatus: DraftStatus;
  step1Data: Step1Data | null;
  step2Data: Step2Data | null;
  step3Data: Step3Data | null;
  errors: Partial<Record<WizardStep, ZodError>>;
  isDemoMode: boolean;
}

const initialWizardState: WizardState = {
  currentStep: 1,
  visited: new Set([1]),
  completed: { 1: false, 2: false, 3: false, 4: false },
  draftStatus: "idle",
  step1Data: null,
  step2Data: null,
  step3Data: null,
  errors: {},
  isDemoMode: false,
};
```

### Actions et transitions

| Action | Payload | Transition | Guard |
|--------|---------|------------|-------|
| `NEXT` | — | `currentStep++`, `visited.add(currentStep+1)` | `currentStep < 4` && `completed[currentStep] === true` |
| `PREV` | — | `currentStep--` | `currentStep > 1` |
| `JUMP` | `target: WizardStep` | `currentStep = target` | `visited.has(target)` (navigation libre vers étapes visitées) |
| `UPDATE_DATA` | `{ step, data }` | `step{N}Data = data`, `draftStatus = "dirty"` | — |
| `MARK_COMPLETED` | `{ step }` | `completed[step] = true` | Validation Zod de l'étape passe |
| `SET_ERRORS` | `{ step, errors }` | `errors[step] = errors` | — |
| `CLEAR_ERRORS` | `{ step }` | `delete errors[step]` | — |
| `SET_DRAFT_STATUS` | `status: DraftStatus` | `draftStatus = status` | — |
| `LOAD_DRAFT` | `WizardState` | Remplace tout le state | — |
| `TOGGLE_DEMO` | — | `isDemoMode = !isDemoMode` | — |
| `RESET` | — | Retour à `initialWizardState` | — |

### Reducer

```typescript
// Illustration — pas un fichier .ts
function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    case "NEXT":
      if (state.currentStep >= 4 || !state.completed[state.currentStep]) return state;
      const next = (state.currentStep + 1) as WizardStep;
      return { ...state, currentStep: next, visited: new Set([...state.visited, next]) };

    case "PREV":
      if (state.currentStep <= 1) return state;
      return { ...state, currentStep: (state.currentStep - 1) as WizardStep };

    case "JUMP":
      if (!state.visited.has(action.target)) return state;
      return { ...state, currentStep: action.target };

    case "UPDATE_DATA":
      return {
        ...state,
        [`step${action.step}Data`]: action.data,
        draftStatus: "dirty",
      };

    case "MARK_COMPLETED":
      return { ...state, completed: { ...state.completed, [action.step]: true } };

    case "SET_ERRORS":
      return { ...state, errors: { ...state.errors, [action.step]: action.errors } };

    case "CLEAR_ERRORS":
      const { [action.step]: _, ...rest } = state.errors;
      return { ...state, errors: rest };

    case "SET_DRAFT_STATUS":
      return { ...state, draftStatus: action.status };

    case "LOAD_DRAFT":
      return action.state;

    case "TOGGLE_DEMO":
      return { ...state, isDemoMode: !state.isDemoMode };

    case "RESET":
      return initialWizardState;

    default:
      return state;
  }
}
```

### Test Cases

#### Test Cases — State Machine

**Given** le state est `{ currentStep: 1, completed: { 1: false, 2: false, 3: false, 4: false } }`
**When** l'action `NEXT` est dispatchée
**Then** le state reste inchangé car `completed[1] === false` (guard échoue)

**Given** le state est `{ currentStep: 1, completed: { 1: true, 2: false, 3: false, 4: false } }`
**When** l'action `NEXT` est dispatchée
**Then** `currentStep` passe à 2 et `visited` contient `{ 1, 2 }`

**Given** le state est `{ currentStep: 3, visited: { 1, 2, 3 } }`
**When** l'action `JUMP` avec `target: 1` est dispatchée
**Then** `currentStep` passe à 1 car `visited.has(1) === true`

**Given** le state est `{ currentStep: 3, visited: { 1, 2, 3 } }`
**When** l'action `JUMP` avec `target: 4` est dispatchée
**Then** le state reste inchangé car `visited.has(4) === false` (guard échoue)

**Given** le state a `draftStatus: "idle"` et `step1Data: null`
**When** l'action `UPDATE_DATA` avec `{ step: 1, data: { venues: [{ name: "Gymnase A" }] } }` est dispatchée
**Then** `step1Data` contient les données et `draftStatus` passe à `"dirty"`

---

## 7. Auto-Save

### Stratégie : Draft hybride (server + sessionStorage)

L'auto-save fonctionne en trois couches :

1. **Debounce 2s** : après chaque modification (`UPDATE_DATA`), un timer de 2s
   démarre. Si aucune nouvelle modification n'arrive, l'auto-save déclenche.
2. **Server draft** : `PUT /api/clubs/{id}/draft` avec le `WizardState` sérialisé
   en JSON. Le backend stocke dans `clubs.transition_data` (jsonb).
3. **sessionStorage crash recovery** : en parallèle, le state est écrit dans
   `sessionStorage` sous la clé `wizard:draft:{clubId}`. Si le serveur est
   injoignable, le fallback sessionStorage permet de restaurer au refresh.

### Flux d'auto-save

```
UPDATE_DATA → draftStatus = "dirty"
  → debounce 2s
    → sessionStorage.setItem("wizard:draft:{clubId}", JSON.stringify(state))
    → PUT /api/clubs/{id}/draft (body: WizardState)
      → 200: draftStatus = "saved"
      → erreur réseau: draftStatus = "error", sessionStorage reste valide
```

### Restauration au chargement

```
Wizard mount
  → GET /api/clubs/{id}/draft
    → 200: LOAD_DRAFT avec les données serveur
    → 404 ou erreur: fallback sessionStorage.getItem("wizard:draft:{clubId}")
      → si présent: LOAD_DRAFT avec les données sessionStorage
      → si absent: initialWizardState
```

### Ex-gap backend — Server draft endpoint (tranché : abandonné)

> **Décision (2026-07, ex-gaps G1/G2, fermés)** : le draft serveur
> `GET/PUT /api/clubs/{id}/draft` est **abandonné** — le wizard persiste **par
> entité** (chaque salle/équipe/coach est POST/PUT à la saisie ; le store
> wizard ne tient aucune donnée d'étape), ce qui couvre déjà « ne rien
> perdre » ; un draft-blob serait une 2e source de vérité. Le champ
> `onboardingCompleted` existe (camelCase, voir §5). Trace :
> `specs/evolution/roadmap.md` §3.

### Test Cases

#### Test Cases — Auto-Save

**Given** le gestionnaire modifie le nom de la salle "Gymnase A" en "Gymnase Central" sur l'étape 1
**When** 2 secondes s'écoulent sans nouvelle modification
**Then** `draftStatus` passe à `"saving"`
**And** `sessionStorage` est mis à jour avec la clé `wizard:draft:{clubId}`
**And** `PUT /api/clubs/{id}/draft` est appelé avec le `WizardState` sérialisé
**And** sur succès 204, `draftStatus` passe à `"saved"`

**Given** le serveur est injoignable (réseau coupé) et l'auto-save tente `PUT /api/clubs/{id}/draft`
**When** la requête échoue
**Then** `draftStatus` passe à `"error"`
**And** un message discret s'affiche : "Sauvegarde locale — connexion perdue"
**And** `sessionStorage` contient toujours les données les plus récentes

**Given** le gestionnaire refresh la page `/wizard` après avoir saisi l'étape 1 et 2
**When** le wizard se remonte
**Then** `GET /api/clubs/{id}/draft` est appelé
**And** si 200, les données sont restaurées et `currentStep` revient à 2
**And** si 404, le fallback `sessionStorage` est utilisé
**And** un message "Brouillon restauré" s'affiche

**Given** le gestionnaire a soumis le wizard avec succès (`onboarding_completed: true`)
**When** le wizard se démonte
**Then** `sessionStorage.removeItem("wizard:draft:{clubId}")` est appelé
**And** le draft serveur peut être purgé par le backend

---

## 8. ARIA / Accessibility

### Structure ARIA

| Élément | Attribut ARIA | Valeur |
|---------|---------------|--------|
| Stepper (liste des étapes) | `role="navigation"` `aria-label="Étapes du wizard"` | — |
| Étape courante dans le stepper | `aria-current="step"` | Appliqué sur l'`<li>` de l'étape active |
| Étape complétée | `aria-current="step"` + classe `.completed` | — |
| Étape non visitée | `hidden` sur le contenu de l'étape | — |
| Panneau d'erreurs | `role="alert"` | Annonce automatique par le screen reader |
| Message d'erreur par champ | `role="alert"` + `aria-describedby` sur le champ | — |
| Bouton "Suivant" | `aria-disabled="true"` si guard échoue | — |
| Contenu de l'étape | `role="region"` `aria-label="Étape {N}: {nom}"` | — |

### Focus management

1. **Changement d'étape** : le focus se déplace sur le titre `<h2>` de la
   nouvelle étape (`h2.focus()`).
2. **Erreur de validation** : le focus se déplace sur le panneau d'erreurs
   `role="alert"`.
3. **Retour à une étape** : le focus se déplace sur le titre de l'étape.
4. **Modal d'import Excel** : trap focus dans la modal, `Escape` ferme.

### Keyboard navigation

| Touche | Action |
|--------|--------|
| `Tab` | Navigation séquentielle dans l'étape |
| `Shift+Tab` | Navigation arrière |
| `Enter` sur "Suivant" | `NEXT` si guard passe |
| `Enter` sur "Précédent" | `PREV` |
| `Escape` dans modal import | Ferme la modal |

### Test Cases

#### Test Cases — ARIA/Accessibility

**Given** le gestionnaire est sur l'étape 1 et un lecteur d'écran est actif
**When** le wizard se charge
**Then** le stepper annonce "Étape 1 sur 4 : Infrastructure" via `aria-current="step"`
**And** le contenu de l'étape 1 a `role="region"` et `aria-label="Étape 1: Infrastructure"`
**And** les contenus des étapes 2, 3, 4 ont `hidden`

**Given** le gestionnaire clique sur "Suivant" sans avoir saisi de salle
**When** l'erreur de validation s'affiche
**Then** le panneau d'erreurs a `role="alert"` et le focus se déplace dessus
**And** le lecteur d'écran annonce "Au moins une salle est requise"

**Given** le gestionnaire passe de l'étape 2 à l'étape 3
**When** la transition `NEXT` s'exécute
**Then** le focus se déplace sur le titre `<h2>` "Étape 3 : Contraintes"
**And** l'étape 3 a `aria-current="step"` dans le stepper
**And** l'étape 2 perd `aria-current="step"`

**Given** le gestionnaire ouvre la modal d'import Excel
**When** il appuie sur `Escape`
**Then** la modal se ferme et le focus revient sur le bouton "Importer Excel"

---

## 9. Endpoints consommés — synthèse

| Endpoint | Méthode | Étape | Statut OpenAPI |
|----------|---------|-------|----------------|
| `/api/venues` | GET, POST | 1 | ✅ Présent dans `openapi-snapshot.json` |
| `/api/venue-training-slots` | GET, POST | 1 | ✅ Présent |
| `/api/teams` | GET, POST | 2 | ✅ Présent |
| `/api/coaches` | GET, POST | 2 | ✅ Présent |
| `/api/team-coaches` | GET, POST | 2 | ✅ Présent |
| `/api/clubs/{id}/import-teams` | POST | 2 | ✅ Présent (custom controller) |
| `/api/sport-categories` | GET | 2 | ✅ Présent |
| `/api/constraints` | GET, POST | 3 | ✅ Présent |
| `/api/priority-tiers` | GET | 3 | ✅ Présent |
| `/api/teams/{id}` | PUT | 3 | ✅ Présent |
| `/api/clubs/{id}` | GET, PUT | 4 | ✅ Présent |
| `/api/clubs/{id}/draft` | GET, PUT | Auto-save | ✂️ **Abandonné (ex-G1/G2)** — persistance par entité, voir §7 |
| `/api/clubs/{id}` (`onboardingCompleted`) | PUT | 4 | ✅ Présent (camelCase — ex-G7, erreur de doc corrigée) |

> Référence : `specs/courantes/openapi-snapshot.json` (paths vérifiés au
> 2026-06-30, backend SHA `6e35a6ce`). Décisions sur les ex-gaps :
> `specs/evolution/roadmap.md`.

---

## 10. File structure (réservé au frontend)

```
frontend/src/
├── routes/
│   └── wizard/
│       ├── index.tsx              # WizardLayout + stepper
│       ├── step1-infrastructure.tsx
│       ├── step2-ressources.tsx
│       ├── step3-contraintes.tsx
│       └── step4-recapitulatif.tsx
├── features/
│   └── wizard/
│       ├── reducer.ts             # wizardReducer + WizardState
│       ├── schemas.ts             # Zod schemas (Step1-4 + WizardData)
│       ├── useAutoSave.ts         # Hook debounce 2s + server draft
│       ├── useDraftRestore.ts     # Hook restauration au mount
│       └── components/
│           ├── Stepper.tsx        # Stepper ARIA
│           ├── VenueGrid.tsx      # Grille dispos 15min
│           ├── ExcelImport.tsx    # Upload + column mapping + paste-rows
│           ├── TierList.tsx       # Drag & drop S/A/B/C/D
│           └── ErrorPanel.tsx     # role="alert" errors
```

> Aucun fichier `.test.ts` ou `.test.tsx` n'est créé dans le cadre de cette
> spécification. Les test cases sont en prose Given/When/Then dans ce fichier.

</details>

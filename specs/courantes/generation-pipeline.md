# Génération d'un planning — conduite normalisée (bout en bout)

> Vérité courante. Décrit ce qui **doit** se passer, zone par zone, quand un
> gestionnaire lance une génération : ce que fait le frontend, ce que fait le
> backend, ce que répond le moteur, comment le résultat est importé puis affiché.
> Sert de référence pour diagnostiquer une génération qui « ne s'affiche pas ».
> Détail archi transverse : `CLAUDE.md §6` + `docs/architecture/adr-0001-single-pass-solve.md`.

## 1. Vue d'ensemble (le contrat de bout en bout)

```
Frontend (wizard/planning)                Backend (Symfony)                 Engine (FastAPI/CP-SAT)
──────────────────────────                ─────────────────                ──────────────────────
POST /api/schedules (DRAFT)  ───────────▶ crée Schedule (club+season stampés)
POST /api/slot_templates (×N) ──────────▶ enregistre les réservations
POST /api/schedules/{id}/generate ──────▶ GenerateScheduleController
                                          └▶ GenerateScheduleMessage (Messenger/Redis)
                                             └▶ GenerateScheduleHandler
                                                ├─ gèle un snapshot (données figées)
                                                ├─ POST http://engine:8000/generate ─▶ solveur CP-SAT
                                                │                                     ◀─ { status, slots[], diagnostics }
                                                ├─ importe les slots placés
                                                └─ Mercure publish  ────────────┐
GET /api/schedules/{id} (poll PENDING/GENERATING) ◀───── status COMPLETED/FAILED│
Planning : GET /api/schedules (collection) ◀────────────────────────────────────┘
└▶ atterrissage sur le plan de saison → affichage des créneaux
```

**Frontières (jamais franchies) :** frontend → backend via `/api/*` ; backend → engine
via `POST /generate` ; backend → frontend via Mercure SSE `club:{clubId}:schedule:{scheduleId}`.
**Le moteur ne rappelle jamais le backend. Le frontend n'appelle jamais le moteur.**

## 2. Frontend — ce qu'il fait

- **Lancement** (`features/wizard/queries.ts` → `useLaunchGeneration`) : `createSchedule(name)`
  puis un `POST /api/slot_templates` par réservation, puis `generateSchedule(id)`.
  Invalide `["schedules"]` en `onSuccess`.
- **Attente** (`features/wizard/steps/GenerateStep.tsx` + `useScheduleStatus`) : poll
  `GET /api/schedules/{id}` tant que le statut ∈ `{PENDING, GENERATING}`. Garde-fou
  client `TIMEOUT_MS = 5 min` → sinon écran d'échec + réessai.
- **Affichage** : dès qu'un schedule est `COMPLETED`, `GenerateStep` bascule sur
  `<PlanningPage embedded />`. La page choisit le plan à ouvrir via
  `pickLandingScheduleId` (`features/planning/PlanningPage.tsx`) : **jamais un overlay
  de période**, toujours le plan de saison de référence (baseline sinon le dernier
  plan de saison terminé).

## 3. Backend — ce qu'il fait

- `GenerateScheduleController` → `GenerateScheduleMessage` → `GenerateScheduleHandler`
  (Symfony Messenger sur Redis, conteneur `messenger-worker`).
- Le handler : **gèle un snapshot** des données, `POST http://engine:8000/generate`,
  **importe** les slots renvoyés, **publie** sur Mercure. Verrou par club
  `ClubGenerationLock` (Redis `SETEX NX` + jeton de libération).
- Multi-tenant : le Schedule est stampé `club_id` + `season_id` (filtres Doctrine +
  RLS PostgreSQL). Écriture sur saison archivée → 409.

## 4. Engine — ce qu'il répond

- Solveur CP-SAT, **pas de fallback de relaxation** (toutes les contraintes HARD à
  chaque tentative). Réponse conforme au contrat Pydantic (`engine/CONTRACT_VERSION`,
  gardé par `ContractSchemaTest`) : `status`, `slots[]` (placements), `diagnostics[]`.
- INFEASIBLE → `status="failed"` + diagnostics. COMPLETED possible **avec** des
  warnings (un plan sous-optimal reste un plan).

## 5. Invariants de contrat — les erreurs *silencieuses* à surveiller

Ces invariants ne cassent **pas** un test « le schedule atteint COMPLETED » : le
pipeline réussit, mais l'UI n'affiche rien. Ils exigent des tests dédiés.

### 5.1 `calendarEntryId` : `null` omis par API Platform (régression UX-02)

**API Platform 4 omet les champs `null` du JSON.** Un plan de saison a
`calendarEntryId = null` en base → le champ arrive **ABSENT** côté frontend
(`undefined`), pas `null`. Tout test `null === s.calendarEntryId` (« est-ce un plan
de saison ? ») échoue alors silencieusement : `pickLandingScheduleId` renvoie `null`,
le planning s'ouvre sur rien après une génération réussie.

- **Conduite normalisée** : normaliser à la **frontière**. `listSchedules`
  (`features/planning/api.ts`) mappe `calendarEntryId: s.calendarEntryId ?? null`
  → le type `string | null` redevient honnête, les 7 consommateurs voient un vrai `null`.
- **Règle générale** : tout champ nullable consommé côté frontend via une comparaison
  `=== null` doit être normalisé à la frontière de son endpoint, ou testé avec un
  check *nullish* (`!x` / `== null`), jamais `=== null` seul.

### 5.2 Autres invariants silencieux (même classe)

- **Contrat backend↔engine** : un champ renommé/retiré ne fait pas planter le solveur,
  il fait perdre un placement. Gardé par `ContractSchemaTest` (`CONTRACT_VERSION`).
- **Snapshot figé** : éditer les données pendant la génération ne doit pas affecter le
  run en cours (le handler travaille sur le snapshot).

## 6. Tests qui détectent ces erreurs silencieuses

| Erreur | Test garde |
|--------|-----------|
| `calendarEntryId` absent (`undefined`) non normalisé | `frontend/src/features/planning/api.test.ts` (mappe absent → `null`) |
| Atterrissage planning cassé (overlay/undefined) | `frontend/src/features/planning/pickLanding.test.ts` |
| Génération qui n'affiche pas le plan (bout en bout) | `frontend/tests/e2e/journey.spec.ts` (wizard → génération réelle → planning affiché → validé → cockpit) |
| Contrat schémas engine⇄backend | `backend` `CrossStack/ContractSchemaTest` |
| Solveur répond et produit un plan | `backend/scripts/smoke-solver.sh` → COMPLETED |

**Principe** : un test de pipeline qui n'assert que le **statut** (`COMPLETED`) est
aveugle aux erreurs de contrat de *données*. Toujours doubler d'un test qui assert le
**rendu final** (le plan visible), unitaire à la frontière (§6 ligne 1) et e2e (journey).

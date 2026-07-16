# Cycle de vie des plannings — le pointeur du plan (N3)

> **Bascule 2026-07-16 (ADR-0002, `docs/architecture/adr-0002-pattern-plan.md`)** : le **plan de
> type SEASON** (`schedule_plan`) et **la version qu'il pointe** (`chosen_schedule_id`) SONT le
> calendrier de la saison. **« Validé » n'est plus un statut** : ça se dérive du pointeur, et de
> rien d'autre — une version choisie reste `COMPLETED`. **Valider = POINTER + SUPPRIMER les
> versions sœurs** (inv. 1, plus d'archivage) ; **rouvrir = DÉPOINTER** (inv. 2, la version
> survit). Les statuts `VALIDATED`/`ARCHIVED`, `Season.baselineScheduleId`,
> `Season.socleValidatedAt`, `Season.planningName` et la route `set-baseline` **n'existent plus**.
> Les sections ci-dessous ont été corrigées ; ce qui décrit encore l'ancien modèle est daté et
> signalé comme tel.

> **But** : permettre au gestionnaire de **choisir** la version qui fait foi pour la saison, de la **rouvrir** pour l'éditer, et distinguer le **« Planning de la saison »** (le plan SEASON pointé) des **plannings secondaires** (les plans de période).
> **Source de vérité** : le **code** (confronté au besoin exprimé). La roadmap n'est qu'une base de discussion.

## 0. Machine à états accueil/onboarding (cockpit) — 2026-07-08

Le point d'entrée après login dérive de **deux critères portés par le plan SEASON**, exposés ensemble par `/api/me` dans `seasonPlan` : le plan **porte-t-il au moins une version terminée** (`hasFinishedVersion`, ADR-0002 inv. 8/16) et **pointe-t-il une version** (`chosenScheduleId`, inv. 13). Le flag legacy `club.onboardingCompleted` **n'est plus lu pour le routage**.

Les deux critères sont **indépendants** : `hasFinishedVersion` est dérivé des versions du plan et jamais retiré par un dépointage — **rouvrir ne re-verrouille donc pas le cockpit** et ne renvoie personne à l'onboarding.

| État | Condition | Landing | « Ouvrir » (carte planning principal) | Restrictions |
|---|---|---|---|---|
| **1. Jamais généré** | `hasFinishedVersion = false` | **/wizard** — étape **Récap** (ou 1ʳᵉ étape incomplète) | — | app verrouillée au wizard (`AuthGuard`, burger profil/club autorisé) |
| **2. A généré, rien de choisi** | `hasFinishedVersion = true`, `chosenScheduleId = null` | **cockpit** (déverrouillé) + bandeau « valider pour débloquer » | → **/wizard** étape **Génération** | **matchs** + **plannings secondaires** bloqués (front désactivé + `SocleGuard` 409) |
| **3. Une version choisie** | `chosenScheduleId ≠ null` | **cockpit** (tout ouvert) | → /planning | aucune |

Ancrages : `AuthGuard.tsx` (onboarding = `!seasonPlan.hasFinishedVersion`), `CockpitPage.tsx` (idem → /wizard), `WizardLayout.tsx` (guided = `!hasFinishedVersion`, landing Récap), `SeasonPlanBanner.tsx` (« Ouvrir » état 2→wizard génération), `AppLayout.tsx` + `MatchesPage.tsx` (matchs verrouillés tant que `chosenScheduleId` est null), `App\Service\SocleGuard::assertSeasonPlanChosen` (409 création match/import/overlay tant qu'aucune version n'est pointée, appliqué dans `FixtureStateProcessor`, `ImportFixturesController`, `ScheduleStateProcessor` et `GenerateScheduleController`). Fixtures : BCCL et FakeClub sont seedés en **état 1** (données saisies, aucune génération).

## 1. Modèle produit (validé avec le gestionnaire)

- **Valider = POINTER** (inv. 1) : le plan nomme la version qui fait foi (`schedule_plan.chosen_schedule_id`). **« Validé » n'est pas un statut** — ça se dérive du pointeur, et de rien d'autre ; la version choisie reste `COMPLETED`. Un plan pointe **au plus une** version.
- **Valider SUPPRIME les versions sœurs** du même périmètre (inv. 1) : le plan porte la version qui compte, pas un cimetière. Plus d'archivage, plus de filet. Une sœur encore en génération bloque le choix (409).
- **Rouvrir = DÉPOINTER** (inv. 2) : le plan redevient un **espace de travail** et la version **survit**, éditable, puis re-choisissable.
- **Aucun pointage automatique** (inv. 2) : générer ne pointe **jamais**. Seul le gestionnaire choisit.
- **« Planning de la saison »** = le plan **SEASON** et sa version choisie. Les **plans de période** (`CLOSURE`/`HOLIDAY`, plans d'exception bornés du cockpit) = **plannings secondaires** ; chacun porte **son propre** pointeur, sur son propre plan.
- **Invariant (UX-02)** : le pointeur du plan SEASON ne peut nommer qu'une version de saison (`calendarEntryId` null) — un overlay de période appartient au plan de sa période, pas au plan SEASON. Côté front, la sélection d'atterrissage (`pickLandingScheduleId`) ignore une version choisie qui serait un overlay ou en vol → jamais d'ouverture sur un « ★ · période » vide.
- Le gestionnaire consulte **tous** les plannings de l'année ; il édite ceux que leur plan ne pointe pas.

### Hors scope (reporté, raison technique)
- **Cascade « éditer le baseline ⇒ répercuter sur les plannings secondaires »** : suppose que les secondaires dérivent du baseline (modèle **templates → occurrences**, roadmap §2, **absent**). Impossible proprement aujourd'hui → différé, documenté.

## 2. État du code au moment de la rédaction (ancrages)

> *Instantané daté d'avant la PR-3, conservé pour la traçabilité — **périmé par la bascule ADR-0002 (2026-07-16)**. Le `/validate` qui pose le baseline, l'auto-assignation `assignBaselineIfFirst` et `set-baseline` n'existent plus ; voir §0/§1 et §3 pour le modèle en vigueur.*

| Élément | Réel |
|---------|------|
| `ScheduleStatus` | `DRAFT/PENDING/GENERATING/COMPLETED/FAILED` (`Enum/ScheduleStatus.php:9-13`) — **pas de VALIDATED** |
| `Schedule` | `name` (`:40`, len 180), `status` (`enumType`, `:42-43`), `version` (optimistic `:24`), `seasonId` (`:36`) |
| `/validate` actuel | `POST /api/schedules/{id}/validate` → **pose le baseline** (`season.setBaselineScheduleId`, `ValidateScheduleController.php:57`), garde `status==COMPLETED` sinon 409 (`:48`), garde tenant `resolveCurrentClubId` + 403 (`:43-46`, pattern `:63-78`) |
| baseline auto | `assignBaselineIfFirst()` au 1er succès si `baselineScheduleId` null (`GenerateScheduleHandler.php:211-216`) |
| Chemins d'édition **sans garde de statut** | `GenerateScheduleController` (regen `:46`) · `ManualEditController` (constraint `:27`, lock `:65`, one-time `:97`) · `ScheduleSlotTemplateStateProcessor` (CRUD slots) · `ScheduleStateProcessor` PUT (`name/status/solverSeed` `:48-61`) |
| `ScheduleInput.status` | `Choice` = 5 statuts actuels (`Dto/ScheduleInput.php:17-19`) |
| Contrat engine | `ScheduleInputSchema` **sans status** ; output engine = littéral `queued/generating/completed/failed` ≠ enum backend ; `ContractSchemaTest` le vérifie → **VALIDATED n'impacte pas l'engine** |
| Front | unions `ScheduleStatus` (`planning/api.ts:43`, `wizard/api.ts:205`) · `validateSchedule()`+`useValidateSchedule()` **existent mais inutilisés** (`planning/queries.ts:91`) · `pickDefaultSchedule` ne matche que `COMPLETED` (`PlanningPage.tsx:21-27`) · `IN_FLIGHT` dupliqué (`PlanningPage:18`, `queries:6`) · `SlotDetail`/`WeekGrid` **sans conscience du statut** · badge statut = texte brut (`PlanningToolbar:73`) · badge Base/Secondaire (`:75-82`) |

## 3. Décisions de conception

### 3.1 Les actions et leur effet (ADR-0002)

| Action | Endpoint | Effet | Garde |
|--------|----------|-------|-------|
| **Valider (choisir)** | `POST /api/schedules/{id}/validate` | le **plan POINTE** cette version (`chosen_schedule_id`) **et ses versions sœurs du même périmètre sont SUPPRIMÉES** (inv. 1) | 409 si status ≠ `COMPLETED` · 409 si une sœur est `PENDING`/`GENERATING` · 409 `overlays_exist` si déplacer le pointeur de la saison détruirait des plans secondaires |
| **Rouvrir** | `POST /api/schedules/{id}/reopen` | le plan **DÉPOINTE** — il redevient un espace de travail, la version survit (inv. 2) | 409 si la version n'est pas celle que pointe son plan · 409 `overlays_exist` (voir §6) |
| **Renommer le plan** | `PUT /api/schedule_plans/{id}` | `plan.name` — le **nom appartient au plan** (inv. 12) | gate management (SEC-07) |

- « Valider » reste le mot FR du bouton demandé par le gestionnaire ; ce qu'il fait, c'est **pointer**.
- **`POST /api/schedules/{id}/set-baseline` est supprimé** (inv. 18) : il n'y a plus qu'une vérité — le pointeur — donc plus de second geste pour la déplacer.
- **Aucun pointage automatique** (inv. 2) : la génération ne pointe jamais.
- **Tenant** : les deux endpoints de cycle de vie réutilisent le pattern `resolveCurrentClubId` (null → skip, RLS 404 ; mismatch → 403). Les deux exigent en plus le rôle management (SEC-07).

### 3.2 Verrou lecture seule **côté serveur** (les 4 chemins)
Le verrou se dérive du **pointeur** : « verrouillé » = **le plan pointe cette version** (`SchedulePlanProvisioner::isChosen`), jamais un statut. Les mutations de **contenu** sont alors refusées (409 « planning en vigueur ») :
- `GenerateScheduleController` : refuse la régénération de la version choisie — la rouvrir (dépointer) d'abord.
- `ManualEditController` (constraint/lock/one-time) : refuse si le `schedule` du slot est la version choisie de son plan.
- `ScheduleSlotTemplateStateProcessor` : refuse create/update/delete si le schedule parent est la version choisie.
- `ScheduleStateProcessor` PUT : refuse **toute** modification si la version est choisie — le verrou est **total** (« read only means read only »). Le **nom du plan** se renomme, lui, par `PUT /api/schedule_plans/{id}` (inv. 12), indépendamment de ce verrou. Les transitions de statut passent par `generate`/`validate`/`reopen`, jamais via PUT : le champ `status` est **accepté mais tout changement → 409**. Fabriquer un `COMPLETED` sans génération est donc impossible.
- `ScheduleStateProcessor` DELETE : la **version choisie ne se supprime pas** (409 — la rouvrir d'abord) et une version en cours de génération non plus (409). Gardé par `ScheduleLifecycleGuardTest` (phase1).

> Le verrou front seul serait une illusion (contrarian-review) : l'enforcement est **serveur**.

### 3.3bis Confirmation de validation (responsabilité gestionnaire)
Le bouton **« Valider »** ouvre une **modale de confirmation** qui matérialise le choix du gestionnaire :
- Planning portant des **diagnostics/alertes** (partiel, dégradé, contraintes non satisfaites) → la modale **avertit explicitement** qu'il valide **malgré les contre-indications du solveur, sous sa responsabilité**.
- Planning sans alerte → confirmation simple.

Le « Valider » de la modale déclenche `POST /validate`. (Détection des alertes côté front via `useDiagnostics` déjà chargé sur `PlanningPage`.)

### 3.3 Machine à états
```
DRAFT ──generate──▶ PENDING ──▶ GENERATING ──▶ COMPLETED
                                        └──▶ FAILED
```
- `validate` / `reopen` **ne changent aucun statut** : ils posent et retirent le **pointeur du plan** (`schedule_plan.chosen_schedule_id`). Une version choisie reste `COMPLETED` ; « validé » se lit sur le pointeur (`Schedule.isChosen` en lecture d'API).
- `COMPLETED` inclut les plannings **partiels/dégradés** (commit `0fd895f`) → on peut choisir un planning partiel (assumé).

### 3.4 Pas de nouveau statut
`ScheduleStatus` reste `DRAFT/PENDING/GENERATING/COMPLETED/FAILED` : « validé » se dérive du pointeur, donc **aucun statut à ajouter** (inv. 1).

## 4. Frontend

- **Unions** : `ScheduleStatus` reste à **5** statuts (`planning/api.ts`, `wizard/api.ts`) — « validé » n'en est pas un.
- **API/hooks** : `reopenSchedule()` + `useReopenSchedule()` ; `useValidateSchedule`. Le pointeur se lit sur `/api/me` (`seasonPlan.chosenScheduleId`) et, par version, sur le champ de lecture `Schedule.isChosen`.
- **PlanningToolbar** :
  - Boutons contextuels : **« Valider »** si `COMPLETED` et non choisie (→ ouvre la **modale** §3.3bis) · **« Rouvrir »** si la version est choisie (+ indicateur 🔒 « Lecture seule »).
  - Badge statut **traduit** (FR) pour les 5 statuts (voir §5).
  - Badge **« Planning principal »** vs **« Secondaire »**.
  - **Nom éditable** en ligne **uniquement si la version n'est pas choisie** (verrou total) ; le **nom du plan** se renomme par `PUT /api/schedule_plans/{id}`.
- **pickLandingScheduleId** : la version choisie du plan SEASON (hors overlay, hors vol) → sinon `pickDefaultSchedule` (`COMPLETED` le plus récent).
- **Read-only gating** : si la version sélectionnée est celle que pointe son plan → désactiver régénérer + renommage + passer `readOnly` à `SlotDetail` (move/lock off) et `WeekGrid` (clic slot off).
- Dédupliquer `IN_FLIGHT` (une source).

## 5. Libellés FR des statuts
`DRAFT`=Brouillon · `PENDING`=En attente · `GENERATING`=Génération… · `COMPLETED`=Terminé · `FAILED`=Échec (`STATUS_LABELS`, `planning/api.ts`). « Validé » ne figure pas dans cette liste : ce n'est pas un statut mais l'état du **pointeur**, affiché à part (badge / 🔒 « Lecture seule »).

## 6. Tests

**Backend** (`--group phase1` pour l'isolation) :
- `ValidateScheduleTest` : `/validate` **pointe** la version sur son plan et **supprime les versions sœurs** ; 409 si non-`COMPLETED` ; 409 si une sœur est en génération.
- `ReopenScheduleTest` : `/reopen` **dépointe** le plan (la version survit) ; 409 si la version n'est pas celle que pointe son plan.
- `SchedulePlanLifecycleTest` / `SchedulePlanReadModelTest` / `SchedulePlanProvisionerTest` : pointeur, compteur de versions, provisioning et modèle de lecture du plan.
- **Gardes** (`ScheduleLifecycleGuardTest`) : régénération / manual-edit / slot-template / PUT / DELETE → **409** quand le plan pointe la version.
- **Tenant isolation** (blocking) : `/validate` et `/reopen` cross-club → 403.
- **Déblocage du cockpit** (cockpit palier A) : `seasonPlan.hasFinishedVersion` = le plan SEASON porte ≥1 version terminée (`COMPLETED`/`FAILED`). **Dérivé, jamais posé, indépendant du pointeur** — `/reopen` ne re-verrouille pas. Exposé sur `/api/me`. Débloque l'accueil cockpit (vs work-loop). Voir `specs/courantes/accueil-cockpit-temporel.md` §2ter.
- **Reopen destructeur du calendrier de saison** (cockpit palier B) : rouvrir la **version choisie du plan SEASON** alors que des calendriers secondaires (overlays de période) existent les **supprime** (spec §2bis, inv. 14). `POST /api/schedules/{id}/reopen` renvoie **409 `{code:"overlays_exist", count, overlays:[{entryId,title,overlayScheduleId}]}`** ; le client confirme avec le body `{"confirmDeleteOverlays": true}` → les overlays sont supprimés (schedules + slots + diagnostics ; **les entrées de période et leurs contraintes datées sont conservées** — la période redevient « signalée, non adaptée ») puis le reopen procède. Même garde, même code, sur `/validate` quand choisir une **autre** version déplacerait le calendrier de la saison. Zéro overlay, ou reopen d'un overlay de période : comportement inchangé.

**Frontend** :
- Toolbar : bouton Valider (COMPLETED) / Rouvrir (VALIDATED), read-only gating, libellés statut, badge « Planning de la saison ».

## 7. Vérification (backend touché ⇒ obligatoire)
- `cd backend && make test` (CS-Fixer + PHPStan lvl8 + PHPUnit)
- **`backend/scripts/smoke-solver.sh` → planning `COMPLETED`**
- `frontend` : `npm run test` + `npm run build`
- Contrat engine inchangé (VALIDATED backend-only) — aucun bump `CONTRACT_VERSION`.

## 8. Checklist de scope (§9 CLAUDE.md)
- **Zone** : `backend/src` (Enum, Entity, Controller, State, Dto) + `backend/tests` + `frontend/src/features/planning` (+ union `wizard/api.ts`).
- **Interdit** : `engine/**` (aucun changement) · `specs/initiales/**` · la **cascade** baseline→secondaires · le modèle occurrences.
- **Fichiers probables** : `Enum/ScheduleStatus.php`, `Controller/{ValidateSchedule,Reopen,SetBaseline}Controller.php`, `Controller/GenerateScheduleController.php`, `Controller/ManualEditController.php`, `State/Processor/{Schedule,ScheduleSlotTemplate}StateProcessor.php`, `Dto/ScheduleInput.php` ; front `planning/{api,queries,PlanningToolbar,PlanningPage,SlotDetail,WeekGrid}.tsx`, `wizard/api.ts`. **Tests** : `ValidateScheduleTest`, `ReopenScheduleTest`, `SetBaselineTest`, gardes, tenant.
- **Doc** : cette spec + `roadmap.md` (N3).
- **Revalidation si** : besoin de la cascade, ou fuite du statut dans le contrat engine, ou impact multi-zone non prévu.
- **Aucun refactoring hors scope.**
- **Ordre commits** : (1) backend enum+endpoints+gardes+tests → (2) frontend → (3) doc.

# Cycle de vie des plannings — statut `VALIDATED` (N3)

> **But** : permettre au gestionnaire de **finaliser/verrouiller** un planning (lecture seule), de le **rouvrir** pour l'éditer, et distinguer le **« Planning de la saison »** (baseline) des **plannings secondaires**. Spec d'exécution pour la PR-3 de la série wizard/boucle-de-travail.
> **Source de vérité** : le **code** (confronté au besoin exprimé). La roadmap n'est qu'une base de discussion.

## 1. Modèle produit (validé avec le gestionnaire)

- **`VALIDATED`** = planning **fini + verrouillé (lecture seule)**. **Plusieurs** plannings peuvent être VALIDATED (c'est bon signe).
- **Rouvrir** un planning validé → il **redevient éditable** (repasse `COMPLETED`), puis re-validable.
- **« Planning principal de la saison »** (main planning / baseline, `season.baselineScheduleId`) = le planning **général de référence** de la saison, auto-assigné au **1er** succès de génération. **Badge distinct** du statut VALIDATED. Les autres = **plannings secondaires**.
- Le gestionnaire consulte **tous** les plannings de l'année ; il édite ceux qui ne sont pas verrouillés.

### Hors scope (reporté, raison technique)
- **Cascade « éditer le baseline ⇒ répercuter sur les plannings secondaires »** : suppose que les secondaires dérivent du baseline (modèle **templates → occurrences**, roadmap §2, **absent**). Impossible proprement aujourd'hui → différé, documenté.

## 2. État réel du code (ancrages)

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

### 3.1 Séparer les deux actions orthogonales
Le `/validate` actuel **mélange** deux concepts. On sépare :

| Action | Endpoint | Effet | Garde |
|--------|----------|-------|-------|
| **Valider (verrouiller)** | `POST /api/schedules/{id}/validate` *(repurposé)* | `status COMPLETED → VALIDATED` | 409 si status ≠ `COMPLETED` |
| **Rouvrir** | `POST /api/schedules/{id}/reopen` *(nouveau)* | `status VALIDATED → COMPLETED` | 409 si status ≠ `VALIDATED` |
| **Désigner planning principal** | `POST /api/schedules/{id}/set-baseline` *(extrait de l'ancien /validate)* | `season.baselineScheduleId = id` | 409 si status ∉ {`COMPLETED`,`VALIDATED`} |

- « Valider » = le mot FR du bouton demandé par le gestionnaire → `/validate` = verrouiller (aligné).
- L'ancien comportement baseline **n'est pas perdu** : déplacé sur `/set-baseline` (capacité de re-promotion préservée, cf. contrarian-review).
- Baseline auto (1er succès) **inchangé** — orthogonal à la validation.
- **Tenant** : les 3 endpoints réutilisent le pattern `resolveCurrentClubId` (null → skip, RLS 404 ; mismatch → 403).

### 3.2 Verrou lecture seule **côté serveur** (les 4 chemins)
`VALIDATED` bloque les mutations de **contenu** (409 « planning verrouillé ») :
- `GenerateScheduleController` : refuse la régénération si `schedule.status == VALIDATED`.
- `ManualEditController` (constraint/lock/one-time) : refuse si le `schedule` du slot est `VALIDATED`.
- `ScheduleSlotTemplateStateProcessor` : refuse create/update/delete si le schedule parent est `VALIDATED`.
- `ScheduleStateProcessor` PUT : refuse **toute** modification, **nom inclus**, si `VALIDATED` — le verrou est **total** (« read only means read only »). Le nom s'édite **pendant l'édition** (états non verrouillés) ; pour renommer un planning validé → le **rouvrir** d'abord. Les transitions de statut passent par `generate`/`validate`/`reopen`, jamais via PUT (`status` retiré des champs librement writables).

> Le verrou front seul serait une illusion (contrarian-review) : l'enforcement est **serveur**.

### 3.3bis Confirmation de validation (responsabilité gestionnaire)
Le bouton **« Valider »** ouvre une **modale de confirmation** qui matérialise le choix du gestionnaire :
- Planning portant des **diagnostics/alertes** (partiel, dégradé, contraintes non satisfaites) → la modale **avertit explicitement** qu'il valide **malgré les contre-indications du solveur, sous sa responsabilité**.
- Planning sans alerte → confirmation simple.

Le « Valider » de la modale déclenche `POST /validate`. (Détection des alertes côté front via `useDiagnostics` déjà chargé sur `PlanningPage`.)

### 3.3 Machine à états
```
DRAFT ──generate──▶ PENDING ──▶ GENERATING ──▶ COMPLETED ──validate──▶ VALIDATED
                                        └──▶ FAILED           ◀──reopen────┘
```
- `set-baseline` : transversal, ne change pas `status` (agit sur la saison).
- `COMPLETED` inclut les plannings **partiels/dégradés** (commit `0fd895f`) → on peut valider un planning partiel (assumé).

### 3.4 Pas de migration
`ScheduleStatus` = colonne string `enumType`, sans contrainte DB → ajouter `VALIDATED` = **0 migration**. Mettre à jour la liste `Choice` de `ScheduleInput`.

## 4. Frontend

- **Unions** : ajouter `VALIDATED` (`planning/api.ts:43`, `wizard/api.ts:205`).
- **API/hooks** : `reopenSchedule()` + `useReopenSchedule()` (nouveau) ; `setBaseline()` + hook ; réutiliser `useValidateSchedule` (déjà là, à brancher).
- **PlanningToolbar** :
  - Boutons contextuels : **« Valider »** si `COMPLETED` (→ ouvre la **modale** §3.3bis) · **« Rouvrir »** si `VALIDATED` (+ indicateur 🔒 « Lecture seule »).
  - Badge statut **traduit** (FR) pour les 6 statuts (voir §5).
  - Badge baseline renommé **« Planning principal »** vs **« Secondaire »**.
  - **Nom éditable** en ligne (PUT `name`) **uniquement si le planning n'est pas `VALIDATED`** (verrou total).
- **pickDefaultSchedule** : priorité baseline → `VALIDATED` → `COMPLETED` → plus récent.
- **Read-only gating** : si `selected.status == VALIDATED` → désactiver régénérer + renommage + passer `readOnly` à `SlotDetail` (move/lock off) et `WeekGrid` (clic slot off).
- Dédupliquer `IN_FLIGHT` (une source).

## 5. Libellés FR des statuts
`DRAFT`=Brouillon · `PENDING`=En attente · `GENERATING`=Génération… · `COMPLETED`=Terminé · `FAILED`=Échec · **`VALIDATED`=Validé (verrouillé)**.

## 6. Tests

**Backend** (`--group phase1` pour l'isolation) :
- `ValidateScheduleTest` **mis à jour** : `/validate` pose désormais `VALIDATED` (plus le baseline) ; 409 si non-`COMPLETED`.
- `ReopenScheduleTest` (nouveau) : `VALIDATED → COMPLETED` ; 409 sinon.
- `SetBaselineTest` (nouveau) : pose `season.baselineScheduleId` ; 409 si status invalide.
- **Gardes** : régénération / manual-edit / slot-template → **409** quand le schedule est `VALIDATED`.
- **Tenant isolation** (blocking) : `/validate`, `/reopen`, `/set-baseline` cross-club → 403.
- **Jalon sticky cockpit** (cockpit palier A) : `Season.socleValidatedAt` est posé **une fois** quand le planning **baseline** de la saison est **validé** (`/validate`) ou qu'un planning déjà `VALIDATED` est désigné baseline (`/set-baseline`) ; **jamais retiré** — `/reopen` le conserve. Exposé sur `/api/me`. Débloque l'accueil cockpit (vs work-loop). Voir `specs/evolution/accueil-cockpit-temporel.md` §2ter.
- **Reopen destructeur du baseline** (cockpit palier B) : rouvrir le **baseline** alors que des calendriers secondaires (overlays de période) existent les **supprime** (spec §2bis). `POST /api/schedules/{id}/reopen` renvoie **409 `{code:"overlays_exist", count, overlays:[{entryId,title,overlayScheduleId}]}`** ; le client confirme avec le body `{"confirmDeleteOverlays": true}` → les overlays sont supprimés (schedules + slots + diagnostics ; **les entrées de période et leurs contraintes datées sont conservées** — la période redevient « signalée, non adaptée ») puis le reopen procède. Zéro overlay ou reopen d'un overlay non-baseline : comportement inchangé. Overlay = `Schedule.calendarEntryId` non null, jamais baseline.

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

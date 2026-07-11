# Versions de l'espace de travail planning (savepoints) — spécification décidée

> **Statut** : **décidé** (6 décisions produit validées le 2026-07-10, retours de tests manuels). Implémentation **phasée D1 → D2 → D3** ; D1 en cours.
> **Nature** : le sélecteur de la page planning cesse de lister des « plannings » nommés — il liste les **versions de travail** d'UN planning de saison. Une version = **point de sauvegarde de l'espace de travail** : le résultat de placement **et** les conditions (structure club) qui l'ont produit.
> **Réutilise l'existant** : chaque génération crée déjà une ligne `Schedule` (versions de fait) · `DELETE /api/schedules/{id}` + cascade artifacts (`OverlayManager::purgeScheduleArtifacts`) · `snapshotData` (payload solveur figé) · cascade E1 (`EntityCascadeDeleter`) · `DeleteConfirm` (E2) · `SeasonDataPurger` (purge déjà TOUTES les versions d'une saison).

---

## 1. Le besoin (reformulé, validé)

« Le sélecteur ne sont que des **versions de travail** du planning en cours. Quand je clique sur une version, je retrouve **ses** données. Le gestionnaire teste, se trompe, revient à la version précédente, supprime une version sans intérêt. C'est un **espace de travail**. À la validation, on confirme que **seule la version en cours est conservée** et les autres sont perdues. »

Précision décisive (Q/R) : « Si je suis sur V1, j'ai **les conditions qui ont permis de générer V1** — sinon on crée un écart entre ce que le gestionnaire voit et ce que le logiciel fait. » → une version porte AUSSI la **structure** (équipes/gymnases/coachs/contraintes/réservations de l'époque), pas seulement les placements.

## 2. Décisions produit (verrouillées)

| # | Décision |
|---|---|
| 1 | **Nom du planning** = `Season.planningName`, éditable **à côté du logo** (pas dans le sélecteur). Défaut « Planning {saison} ». |
| 2 | Versions **non renommables**, étiquetées **« V3 — 10 juil. 14:32 »** + badge « Validé ». |
| 3 | **Suppression manuelle** d'une version = **définitive** + confirmation (`DeleteConfirm`). Interdite sur baseline / VALIDATED / in-flight. |
| 4 | **Validation** = confirmation « seule cette version sera conservée » → la version devient **VALIDATED + baseline** ; les sœurs (plans de saison) passent **ARCHIVED** (invisibles, jamais ressuscitées au reopen, purgées avec la saison). Filet anti-erreur : l'archivage n'est pas une suppression. |
| 5 | **Clic sélecteur = consultation** (grille + bandeau divergence). **« Travailler sur cette version »** = action explicite : **savepoint auto** de l'état courant, puis **restauration de la structure** depuis le snapshot de la version (confirmation d'impact chiffrée, ex. 22→12 équipes). Rien n'est jamais perdu : tout état écrasé vit dans un savepoint. |
| 6 | **« Régénérer aux conditions de cette version »** → version **enfant V2.1** (lignée `parentScheduleId`). Une régénération normale (structure actuelle) → V6 (linéaire). **Édition manuelle en place** : modifier V2 reste V2 (« modifiée le … ») — seule la génération crée une version. |

Hors périmètre (PR dédiées ultérieures) : comparaison/diff entre versions · restauration d'une version ARCHIVED post-validation. *(Les **versions d'overlay** — plusieurs versions par période + purge à `endDate` passée — sont **livrées**, voir §D3ter.)*

## 3. Contrainte technique structurante

`Schedule.snapshotData` = le **payload solveur** (vocabulaire engine, contraintes déjà transformées : targetTag expansé, venue_closed → forbiddenVenueId…). Il est **infidèle pour reconstruire** les entités backend (liens coachs, réservations, tiers, catégories). → La restauration (D3) exige un **snapshot structurel backend** dédié (D2), capturé au même moment que le freeze du payload.

## 4. Phasage

### D1 — fondation versions UX *(PR en cours)*
- `ScheduleStatus::ARCHIVED` (posé serveur uniquement — jamais accepté du client).
- Validation d'un plan de saison ⇒ VALIDATED + **baseline = ce schedule** + socle stampé si premier + **sœurs → ARCHIVED** (même transaction) ; sœur PENDING/GENERATING ⇒ 409.
- Delete version : +409 in-flight (guards baseline/VALIDATED existants).
- `Season.planningName` + PUT saison + `/api/me`.
- Étiquettes V{n} (client, ordre `createdAt`), poubelle + `DeleteConfirm`, dialog de validation enrichi, filtrage ARCHIVED partout, **bandeau divergence léger** (`generatedTeamCount` lu de snapshotData, read-only : « Générée le {date} avec {N} équipes — la structure a changé depuis »).

### D2 — snapshot structurel *(fait — 2026-07-10)*
- Table `schedule_structure_snapshot` (unique par schedule, RLS FORCE) — json par famille : SportCategory, Team, Venue, VenueTrainingSlot, Coach, TeamCoach, CoachPlayerMembership, Constraint permanentes, Reservation base, TeamTagAssignment. Sérialisation générique ClassMetadata (dates ATOM, enums value).
- Écrite par `GenerateScheduleHandler` après le freeze (plans de saison, non-fatal). Écrite seulement si le solve aboutit (COMPLETED) — un échec n'écrase jamais la photo du plan encore affiché. Aucun UI. ~35 kB pour un gros club (fixture BCCL). Purgée avec le schedule (cascade artifacts) et par `SeasonDataPurger`.

### D3 — régénérer aux conditions d'une version *(fait — 2026-07-10)*
- **« Régénérer aux conditions de cette version »** : `POST /api/schedules/{id}/regenerate-from` (route custom, management-gated, saison writable) → `StructureRestorer` restaure la structure de la photo D2 (wipe structure-only + ré-insertion avec ids d'origine, transactionnel, RLS) → nouveau `Schedule` DRAFT + `GenerateScheduleMessage` → **nouvelle version LINÉAIRE** (pas de lignée V2.1, décision produit). 409 si overlay ou version sans photo (pré-D2).
- **Confirmation d'impact chiffrée** côté front (structure actuelle N équipes → version M équipes) via `generatedTeamCount` (D1) + `useTeams`.
- Wipe préserve : schedules/versions, calendrier (entries, contraintes datées, réservations d'overlay). Gardes inchangées.
- **Reporté en D4** (design produit) : « Travailler sur cette version » (restauration pour édition manuelle) + savepoint auto de l'état vivant.

### D3bis — « Régénérer » (simple) crée une nouvelle version *(fait — 2026-07-11)*
Décision 6, **clause 2** (« une régénération normale → V linéaire ») : elle était **décidée mais jamais implémentée** — le « Régénérer » simple régénérait **en place** (`GenerateScheduleController` réutilise le schedule courant, écrase ses créneaux), donc aucune V2 n'apparaissait. Corrigé : `POST /api/schedules/{id}/regenerate` (`RegenerateController`, management-gated) crée une **nouvelle ligne `Schedule` PENDING** avec la **structure actuelle** (pas de restauration de photo, contrairement à D3), puis `GenerateScheduleMessage` → nouvelle version linéaire (V2, V3…). Le front bascule sur la nouvelle version (attend le refetch avant de sélectionner). Gardes : overlay/VALIDATED/DRAFT/in-flight refusés (409), complexité (A10). La 1re génération (DRAFT) reste le chemin en place du wizard.

Les **verrous HARD survivent sans copie** : la charge de génération (`ScheduleConstraintBuilder::findBaseSlotTemplates`) alimente déjà les créneaux HARD de **toutes** les versions base (`calendarEntryId IS NULL`) comme pins — le solveur les re-fixe, exactement comme le faisait la régé en place. `RegenerateController` ne clone donc **rien**.

> **✅ Dette résolue (P0-5, 2026-07-11) — ids de créneau par-schedule.** Les ids de créneau étaient **déterministes globaux** (`uuid5(team,venue,day,start)`, engine `result_builder._slot_id`) : deux versions partageant un placement produisaient le même id, et l'import (`ScheduleResultImporter`, qui chargeait toute la saison) **réassignait** la ligne de la version source à la nouvelle → perte de créneau (souvent un verrou HARD sur une VALIDATED). Corrigé **côté backend seul** : l'id de la ligne est désormais **scopé par schedule** (`SlotIdScoper::scope(scheduleId, idEngine)` = `uuid5(scheduleId:idEngine)`), l'importer ne charge plus que les slots du schedule courant, et une migration a re-clé les lignes existantes. Déterminisme conservé **dans** un schedule (les verrous HARD re-matchent à la régé), distinct **entre** schedules (zéro collision). **Engine, contrat, golden fixtures inchangés.** Voir §D3quater.

### D3ter — versions d'overlay (plusieurs versions par période) *(fait — 2026-07-11)*
La machinerie de versions D1 est **déclinée aux overlays** : une même `CalendarEntry` (période closure/vacances) porte désormais **plusieurs versions d'overlay** (V1, V2…), pas une seule.
- **Création** : la garde d'unicité 422 (`ScheduleStateProcessor`) est **levée** ; l'index DB partial-unique `uniq_schedule_calendar_entry` est **droppé** (migration `Version20260711120000`) → remplacé par un index non-unique. Une nouvelle version = un `Schedule` de plus avec le même `calendarEntryId` ; `CalendarEntry.overlayScheduleId` cesse d'être « l'unique » pour désigner la **version active**. Seule garde restante : une **sœur in-flight** de la période (409), miroir de la saison.
- **Génération** : inchangée — `GenerateScheduleHandler` route déjà sur `buildForOverlay` quand `calendarEntryId` est posé.
- **Validation** : `ValidateScheduleController` branche la voie overlay (jusque-là inerte) — valider une version d'overlay **archive les sœurs de LA MÊME période** et pose le pointeur actif, **sans** toucher le baseline/socle saison, les plans de saison, ni les overlays des autres périodes.
- **Reopen** : rouvrir une version d'overlay (VALIDATED→COMPLETED) ne **ressuscite pas** les sœurs ARCHIVED.
- **Suppression / purge** : `OverlayManager::deleteOverlayForEntry` supprime **toutes** les versions d'une période (forward-marker + pointeur inverse legacy) ; supprimer la version active repointe vers la plus récente survivante. Nouvelle commande **`app:overlays:purge`** (`PurgeOverlaysCommand`, idempotente, `--dry-run`/`--club`/`--date`) purge les overlays des périodes dont `endDate` est passée — manuelle aujourd'hui, cron via la future console superadmin.
- **Front** : le sélecteur de versions liste les versions d'overlay de la période sélectionnée (`visibleOverlayVersions`, étiquettes `overlayVersionLabels`) ; « Régénérer » sur un overlay crée une **nouvelle version d'overlay** (`useRegenerateOverlay` → POST `/schedules` + generate) au lieu de la régé saison ; Valider/Rouvrir opèrent déjà par id.
- **NR (`--group phase1`)** : `OverlayVersionsTest` (2ᵉ overlay ≠ 422 + version active ; validation archive les sœurs de la période et rien d'autre ; sœur in-flight → 409 ; reopen ne ressuscite pas) + `PurgeOverlaysCommandTest` (périodes terminées uniquement ; dry-run no-op).
- **P0-5** : deux versions d'overlay d'une même période partageant un placement subissaient le même vol de créneau — **résolu** par §D3quater (ids par-schedule).

### D3quater — ids de créneau par-schedule (P0-5) *(fait — 2026-07-11)*
Fin du vol de créneau inter-version (perte de données). L'id d'un `ScheduleSlotTemplate` était l'id **déterministe global** de l'engine (`uuid5(team:venue:day:start)`) ; deux schedules (versions saison, `regenerate-from`, versions d'overlay d'une même période) partageant un placement produisaient le même id → à l'import, `ScheduleResultImporter` (qui chargeait toute la saison) réassignait la ligne de la version source à la nouvelle. Corrigé **backend seul** :
- **`SlotIdScoper::scope(scheduleId, idEngine)`** = `uuid5(scheduleId:idEngine)` — id de ligne unique par (schedule, placement), déterministe dans un schedule (les verrous HARD re-matchent à la régé), distinct entre schedules.
- **`ScheduleResultImporter`** : ne charge plus que les slots du schedule courant (le merge saison, seul vecteur du vol, est retiré) et re-clé la sortie solveur par l'id scopé.
- **Migration** `Version20260711130000` : re-clé les lignes existantes (`id → uuid5(scheduleId:id)`), PK feuille sans FK enfant.
- **Engine / contrat / golden fixtures : inchangés** (l'engine sort toujours l'id-placement ; le backend le namespace en interne). L'engine ignore l'id d'entrée d'un pin HARD (il recalcule via `_slot_id`).
- **NR (`--group phase1`)** : `ScheduleResultImporterCrossVersionTest` (import dans B ne vole pas le slot de A ; ré-import de A préserve son verrou HARD sans doublon ; unicité/stabilité de `scope`).

## 5. Invariants (à tester à chaque palier)

1. Les **overlays ne sont jamais** archivés/supprimés/restaurés par le mécanisme versions.
2. **Reopen ne ressuscite pas** les ARCHIVED.
3. Un delete/restore ne franchit **jamais** le club (RLS + scope explicite).
4. `generationCountSeason` n'est jamais re-crédité.
5. Toute restauration est précédée d'un **savepoint automatique** — aucune perte silencieuse.
6. Axe §7.1 « planning lifecycle » : NR `--group phase1` à chaque palier.

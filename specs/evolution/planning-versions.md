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

Hors périmètre (PR dédiées ultérieures) : **versions d'overlay** (lever la contrainte 1-overlay-par-période ; purge des archives quand `endDate` passée) · comparaison/diff entre versions · restauration d'une version ARCHIVED post-validation.

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

### D3 — restauration + lignée *(PR dédiée)*
- **« Travailler sur cette version »** : savepoint auto (snapshot structurel de l'état courant, sans solve — Schedule « structure seule » ou entrée snapshot dédiée) → restauration **transactionnelle** de la structure depuis le snapshot (remplacement par saison ; les entités disparues suivent la **cascade E1** — fixtures d'une équipe supprimée incluses) → confirmation d'impact chiffrée.
- **« Régénérer aux conditions »** : restauration + génération → enfant ; `Schedule.parentScheduleId` + numérotation V2.1.
- Gardes inchangées : SocleGuard, VALIDATED, saison archivée (409).

## 5. Invariants (à tester à chaque palier)

1. Les **overlays ne sont jamais** archivés/supprimés/restaurés par le mécanisme versions.
2. **Reopen ne ressuscite pas** les ARCHIVED.
3. Un delete/restore ne franchit **jamais** le club (RLS + scope explicite).
4. `generationCountSeason` n'est jamais re-crédité.
5. Toute restauration est précédée d'un **savepoint automatique** — aucune perte silencieuse.
6. Axe §7.1 « planning lifecycle » : NR `--group phase1` à chaque palier.

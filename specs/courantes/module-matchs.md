# Module matchs (FFBB) — état livré

> Graduation du comportement livré (skill `documentation-update`). Le besoin et la vision restent dans
> [`../evolution/gestion-matchs-ffbb.md`](../evolution/gestion-matchs-ffbb.md) (paliers A/B/C). Ici = ce qui
> **existe** aujourd'hui. Module **autonome**, gating découplé du socle d'entraînement.

## Palier A — PR-1 (socle backend, 2026-07-06)

### Modèle (entités season-scoped, tenant-owned)

- **`Competition`** (`competition`) — phase/championnat d'une équipe : `teamId`, `name`, `competitionType`
  (`CHAMPIONSHIP`/`CUP`/`BRASSAGE`), `startDate`/`endDate` nullables. N par équipe.
- **`Fixture`** (`fixture` — `match` est un mot-clé PHP) : `teamId`, `competitionId` **nullable = amical**,
  `matchDate`, `homeAway` (`HOME`/`AWAY`), `opponentLabel` (label libre ; l'annuaire adverse global = palier B),
  `status` (`UNPLACED → PLACED → SUBMITTED → VALIDATED`, cf. workflow 2-temps), `venueId`/`kickoffTime`
  nullables (domicile posé, extérieur estimé).
- API Platform 5-fichiers pour chaque (Resource/Input/Processor/Provider) → CRUD `/api/competitions`,
  `/api/fixtures`, filtrage tenant+season **automatique** (filtres SQL) + garde readonly-saison héritée (409).

### Empreinte-temps — `MatchFootprint`

Service pur (spec §4bis) : fenêtre d'occupation d'une personne pour un match. Domicile = **2h15**
(30 échauffement + 1h45 match, de `kickoff−30` à `kickoff+105`). Extérieur = + **30 douche + 15 battement +
trajet aller-retour** (trajet injecté, 0 jusqu'au palier B). C'est l'atome que le moteur de conflits (PR-2)
chevauchera entre coachs/joueurs.

### Catalogue-ligue — `LeagueMatchWindow` (table GLOBALE)

Fenêtres de coup d'envoi imposées par la fédé (jour + `kickoffMin`/`kickoffMax`) par `league × category ×
level × gender`. **Hors tenant** (pas de club_id/season_id, pas de RLS — patron `public_holiday`), seedé via
`app:league-windows:seed` depuis `backend/data/league-match-windows.aura.json`. **Seed AURA = base par défaut
de TOUT club** (couche 1 des 3 couches). Ligue dérivée du `ffbbClubCode` par **`LeagueResolver`** (préfixe
3 lettres) → `Club.league` (posé au register). `GET /api/league-match-windows` → l'envelope héritée, fallback
AURA si la ligue n'est pas cataloguée.

## Palier A — PR-2 (moteur de conflits, à la volée, coach seul, 2026-07-07)

### Détection — `MatchConflictDetector` (service pur)

Croise l'empreinte-temps `MatchFootprint` d'un `Fixture` avec les autres occupations d'un **même coach**
(périmètre coach seul ; les joueurs = plus tard). Dans un club amateur match et entraînement ne peuvent
**jamais** se superposer → la valeur est de le voir dès la saisie. Deux types :

- **`MATCH_MATCH`** : deux `Fixture` d'équipes partageant un coach (via `TeamCoach.coachId`) dont les fenêtres
  d'occupation se chevauchent.
- **`MATCH_TRAINING`** : un `Fixture` chevauchant un entraînement d'une équipe du coach, lu dans le **planning
  effectif à la date du match** — l'overlay de la période ACTIVE qui couvre cette date (`CalendarEntry.
  overlayScheduleId`) si elle existe, **sinon le planning de base** (`Season.baselineScheduleId`). Le créneau
  hebdo (`ScheduleSlotTemplate`, `dayOfWeek`+`startTime`+`durationMinutes`) est **projeté sur la date du match**
  (même jour ISO), puis chevauché.

Chevauchement demi-ouvert (créneaux jointifs = pas de conflit). Un `Fixture` AWAY sans `kickoffTime` n'a pas
d'empreinte (trajet = palier B) → il ne génère aucun conflit — voulu.

### Endpoint — `GET /api/fixtures/conflicts`

Contrôleur invokable `FixtureConflictsController` (route `priority: 10` pour passer avant `/api/fixtures/{id}`
d'API Platform). Recalcul **à la volée** à chaque appel, **rien n'est persisté**. Charge fixtures + `TeamCoach`
+ périodes-overlay actives + slots du planning effectif via les repos (scope club+saison **automatique**).
Réponse : `{ clubId, seasonId, conflicts: [{ type, coachId, start, end (segment de chevauchement),
left/right | fixture/training }] }`.

## Vérifs / gardes

- NR bloquant (phase1, CI) : `MatchTenantIsolationTest` (Competition/Fixture scopés club+saison, POST stampe,
  écriture saison archivée → 409). Le catalogue global reste hors tenant : garanti par `RlsIsolationTest` +
  `TenantOwnedInterfaceCompletenessTest` (il n'a pas de club_id) + `LeagueMatchWindowsApiTest` (partagé,
  aucune donnée club).
- PR-2 : `FixtureConflictsApiTest` (phase1) — structure du radar **+ isolation club** (un club ne voit jamais
  les conflits d'un autre). `MatchConflictDetectorTest` (unit) — match↔match, match↔entraînement, projection
  jour de semaine, overlay > base, demi-ouvert, away-sans-kickoff ignoré.
- Unit : `MatchFootprintTest`, `LeagueResolverTest`. Command : `SeedLeagueWindowsCommandTest`. Api :
  `FixtureApiTest`.
- Smoke-solveur COMPLETED (les nouvelles tables/RLS ne cassent pas le pipeline ; payload solveur inchangé).

## Reste palier A (à venir)

`Team.preferredMatchWindow` + application de l'**envelope HARD** (catalogue-ligue) au placement · PR-3 **grille
week-end** UI · PR-4 **import FBI**. **Joueurs** dans le moteur de conflits (nécessite un modèle de
rattachement joueur→équipes) + paliers B (dérogation + trajet + annuaire adverse global) / C (effet réseau)
plus tard.

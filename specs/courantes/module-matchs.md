# Module matchs (FFBB) — état livré

Last verified @ 2026-08-02 (import FBI réel une passe — P1-4 PR A)

> Graduation du comportement livré (skill `documentation-update`). Le besoin et la vision restent dans
> [`../evolution/gestion-matchs-ffbb.md`](../evolution/gestion-matchs-ffbb.md) (paliers A/B/C), **cadrés
> pour l'exécution le 2026-08-02** par
> [`../evolution/p1-4-cadrage-module-matchs.md`](../evolution/p1-4-cadrage-module-matchs.md) (P1-4 —
> notamment : le format FBI livré ici est **invalidé par un vrai export**, et le placement devient
> solveur + boucle manuelle). Ici = ce qui **existe** aujourd'hui. Module **fonctionnellement autonome** : ses entités, son moteur de conflits et sa
> grille week-end ne dépendent pas du solveur d'entraînement, et rien de ce module n'entre dans le payload
> solveur.

> ⚠ **Le module est autonome dans ses DONNÉES, pas dans son OUVERTURE.** Décision fondateur du
> 2026-07-31 (arbitrage DOC-1) : le couplage livré fait foi, la spec d'évolution a été alignée
> dessus — **le gating reste**. Créer un match (`FixtureStateProcessor`) comme importer un fichier
> FBI (`ImportFixturesController`) appellent `App\Service\SocleGuard::assertSeasonPlanChosen`, qui
> rend **409** tant que le plan SEASON ne **pointe** aucune version (ADR-0002 inv. 13, seuil 2 du
> cockpit — voir [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) §0). Le front
> verrouille l'entrée « matchs » sur le même critère (`chosenScheduleId`).
> **Le motif** : un match se *place* dans un calendrier — le radar de conflits compare la rencontre
> aux séances d'entraînement. Sans socle en vigueur, il n'a rien à comparer. L'autonomie promise en
> évolution porte sur le **modèle** (entités séparées, rien dans le payload solveur) et sur l'**UI**
> (workspace « Compétition » distinct), pas sur la porte d'entrée.

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
  effectif à la date du match**. Une période ACTIVE **capture** les dates qu'elle couvre : à l'intérieur le
  planning de base ne s'applique pas — son **overlay**, c'est-à-dire la **version choisie du plan de la
  période** (`SchedulePlanProvisioner::chosenByPeriodPlans`, ADR-0002 lot D-b du 2026-07-18 ; le champ
  `CalendarEntry.overlayScheduleId` a été **supprimé** à cette occasion, et un plan de période qui ne pointe
  rien = **aucun overlay**), s'il existe,
  **sinon aucun entraînement** (une coupure = « pas d'entraînement », donc aucun conflit fantôme). Hors période = la
  **version choisie du plan SEASON** (`SchedulePlanProvisioner::chosenOfSeasonPlan`, ADR-0002). Le créneau hebdo (`ScheduleSlotTemplate`, `dayOfWeek`+`startTime`+`durationMinutes`)
  est **projeté sur la date**, puis chevauché. Le coach en conflit = le `coachId` **assigné au créneau** s'il
  existe, sinon les coachs de l'équipe du créneau (pas de faux positif sur un co-coach qui ne tient pas la séance).

Chevauchement demi-ouvert (créneaux jointifs = pas de conflit). Une empreinte qui **passe minuit** (coup d'envoi
tardif) est vérifiée sur les **deux jours** qu'elle couvre. Périodes qui se chevauchent → résolution
**déterministe** (ordre `startDate, id` via `CalendarEntryRepository::findActivePeriodsOrdered`). Un `Fixture`
AWAY sans `kickoffTime` n'a pas d'empreinte (trajet = palier B) → il ne génère aucun conflit — voulu.

### Endpoint — `GET /api/fixtures/conflicts`

Contrôleur invokable `FixtureConflictsController` (route `priority: 10` pour passer avant `/api/fixtures/{id}`
d'API Platform). Recalcul **à la volée** à chaque appel, **rien n'est persisté**. Charge fixtures + `TeamCoach`
+ périodes-overlay actives + slots du planning effectif via les repos (scope club+saison **automatique**).
Réponse : `{ clubId, seasonId, conflicts: [{ type, coachId, start, end (segment de chevauchement),
left/right | fixture/training }] }`.

## Palier A — PR-3 (grille week-end UI, 2026-07-07)

Feature frontend `frontend/src/features/matches/` (route `/matchs`, entrée nav). **Frontend seul**, consomme
les endpoints PR-1/PR-2 — aucun ajout backend.

- **Grille week-end** (`WeekendGrid` + `lib/weekendGrid.ts`) : calendrier daté week-end-centrique (colonnes =
  date × gymnase, lignes = créneaux), distinct du canevas lun-sam de l'entraînement. Chaque match placé =
  bloc de son **empreinte 2h15** (`kickoff−30 → kickoff+105`), libellé au coup d'envoi. Navigation ‹ › entre
  week-ends. Les matchs non placés / AWAY-sans-heure vivent dans la liste « À placer ».
- **Pose domicile** (`PlacementPanel`) : clic sur un match à placer → panneau (salle + heure) →
  `PUT /api/fixtures/{id}` (full-replace, statut `PLACED`, corps reconstruit pour ne pas effacer opponent/
  competition). **Envelope-ligue** : garde **HARD** (bouton désactivé hors fenêtre) quand l'équipe mappe une
  fenêtre du catalogue ; **dégradation en repère indicatif** (non bloquant) quand le mapping catégorie/niveau
  ne résout pas de façon fiable (`lib/envelope.ts`). Le radar serveur reste la vérité dure.
- **Saisie manuelle** (`FixtureFormDialog`) : `POST /api/fixtures` (équipe, date, HOME/AWAY, adversaire,
  compétition optionnelle = amical) — complément de l'import FBI (amicaux, manquants).
- **Radar affiché** (`ConflictRadar`) : `GET /api/fixtures/conflicts` en direct (invalidé à chaque mutation).
- Tests : Vitest `lib/{weekendGrid,envelope}.test.ts`, `PlacementPanel`/`FixtureFormDialog`/`MatchesPage`
  (.test.tsx) ; e2e Playwright `tests/e2e/matches.spec.ts` (login → créer → placer / garde hors-fenêtre).
  ⚠ L'API omet les props null → `getFixtures` re-normalise `venueId`/`kickoffTime`/`competitionId` en `null`.

## Import FBI réel — une passe (P1-4 PR A, 2026-08-02, remplace le PR-4 du 2026-07-07)

> **Format MESURÉ** sur un vrai export (« Saisie des résultats pour tout le club », gelé en fixture de test
> `backend/tests/Fixtures/fbi/rechercherRencontre.xlsx`, 124 rencontres BCCL 2026-27 — faits F1-F9 du
> [cadrage](../evolution/p1-4-cadrage-module-matchs.md) §3) : fichier **GLOBAL club**, colonnes
> `Division · N° de match · Equipe 1 · Equipe 2 · Date de rencontre · Heure · Salle · e-Marque V2 ·
> Scores/Forfaits (ignorés)`. L'import « un fichier par équipe » de PR-4 est supprimé.

- **Flux une passe** (décision fondateur 2026-08-02) : `analyze()` (dry-run, ZÉRO écriture) rend la table
  des groupes de divisions résolus contre la correspondance persistée → le gestionnaire complète les
  nouvelles dans le dialog → `import()` reçoit le MÊME fichier + les `mappings` et fait tout : persiste les
  correspondances (`Competition`) puis crée/met à jour chaque rencontre.
- **Correspondance Division↔équipe = `Competition`** (name = division, `teamId`), créée par l'appariement,
  **jamais** par find-or-create aveugle. Type inféré (`BRASSAGE` si le nom contient « Brassage », sinon
  `CHAMPIONSHIP`). **Deux équipes du club dans la même division** : le libellé FBI côté club
  (« BCCL - 2 ») désambiguïse — stocké dans `Competition.fbiTeamLabel`, une entrée d'appariement par
  libellé ; nominal (une équipe par division) → label null, robuste au drift de libellé.
- **Diff/update par `(team, externalRef)`** — re-upload ≠ skip :
  - date changée ou switch HOME↔AWAY → mise à jour + **dé-placement** (`UNPLACED`, `venueId` effacé) +
    warning `RESCHEDULED`/`SWITCHED` (« la ligue a re-décidé ») ;
  - heure réelle changée → mise à jour **en place** (la salle reste le choix du club) + warning si placé ;
  - **`00:00` = sentinelle « heure non fixée »** (F2) → `kickoffTime` null à la création, et n'écrase
    JAMAIS une heure posée par le club ;
  - salle/adversaire dérivés → mise à jour silencieuse ; rien → `unchanged`.
- **`Exempt`** (journée de repos) → sauté, compté `exempted`, jamais une erreur (F5). **Salle stockée**
  domicile ET extérieur dans `Fixture.fbiVenueLabel` (F3 — matière trajet, jamais une référence `Venue`).
- **Multi-fichiers incrémental** : la ligue d'abord, le comité quand il répond — chaque fichier complète
  (créations) et corrige (diff) ; divisions inconnues → `unmappedDivisions`, ni créées ni en erreur.
- Gardes de ligne conservées de PR-4 : HOME/AWAY par needle mot-entier du nom du club (derby → erreur de
  ligne), dates/heures `jj/mm/aaaa`/`HH:MM` + serials Excel, n° > 64 car. → erreur de ligne, reader épinglé
  XLSX. En-têtes tolérants (« N° de match » avec espace traînant, « Numéro » legacy accepté).
- **Endpoints** (opérations API Platform sur `FixtureResource`, gate partagée `FixtureImportGate` —
  refus byte-identiques) : `POST /api/fixtures/import/analyze` (multipart `file`) et
  `POST /api/fixtures/import` (multipart `file` + `mappings` JSON). Séquence : pas de club/adhésion → 404,
  membre non-management → 403, saison archivée → 409, **socle non validé → 409 (`SocleGuard`)**,
  fichier/mappings invalides → 400. Un `teamId` étranger dans `mappings` est invisible (filtres tenant) →
  400, aucune écriture cross-club.
- **Rapport** `{created, updated, unchanged, exempted, errors[], warnings[{type, division, externalRef,
  message}], unmappedDivisions[{name, fbiTeamLabel, rowCount}]}`.
- **UI** : « Importer FBI » dans `/matchs` → `ImportFbiDialog` **une passe** : fichier choisi → analyse
  auto → table des correspondances (connues en texte, nouvelles en `TeamSelect`) → « Importer » envoie
  fichier + nouveaux mappings → rapport affiché en place. Invalidation `fixtures` + `wizard/teams`
  (engagement) + `competitions`.

## Vérifs / gardes

- NR bloquant (phase1, CI) : `MatchTenantIsolationTest` (Competition/Fixture scopés club+saison, POST stampe,
  écriture saison archivée → 409). Le catalogue global reste hors tenant : garanti par `RlsIsolationTest` +
  `TenantOwnedInterfaceCompletenessTest` (il n'a pas de club_id) + `LeagueMatchWindowsApiTest` (partagé,
  aucune donnée club).
- PR-2 : `FixtureConflictsApiTest` (phase1) — structure du radar **+ isolation club** (un club ne voit jamais
  les conflits d'un autre). `MatchConflictDetectorTest` (unit) — match↔match, match↔entraînement, projection
  jour de semaine, overlay > base, demi-ouvert, away-sans-kickoff ignoré.
- Import (PR A P1-4) : `ImportFixturesAuthorizationTest` (phase1, §7.1 tenant) — non-management 403 (import
  ET analyze), saison archivée 409, **mapping vers une équipe étrangère → 400 sans écriture cross-club**.
  `FbiFixtureImporterTest` — le nominal tourne sur la **fixture réelle gelée** (124 lignes : 14 divisions,
  2 exempts, DF2=22/PNM=10, `00:00`→null, salle stockée) + diff/update (re-programmation, switch, heure en
  place, `00:00` n'écrase pas), deux-équipes-une-division par label, brassage inféré, et les successeurs de
  chaque garde PR-4 (derby, club absent, dates, colonnes, reader épinglé, en-tête legacy).
  `ImportFixturesApiTest` (HTTP bout-en-bout : analyze → import une passe → re-import diff + **NR périmètre
  engagé** : DELETE équipe → 409 après import). `ImportErrorMessageLeakTest` (P4-5 sur la nouvelle route).
  `ImportFbiDialog.test.tsx` (analyse au choix du fichier, mappings pré-remplis vs sélecteurs, rapport V2).
- Unit : `MatchFootprintTest`, `LeagueResolverTest`. Command : `SeedLeagueWindowsCommandTest`. Api :
  `FixtureApiTest`.
- Smoke-solveur COMPLETED (les nouvelles tables/RLS ne cassent pas le pipeline ; payload solveur inchangé).

## Le périmètre engagé — `TeamEngagementGuard` (2026-07-16)

**Réalité du terrain** : valider le planning de la saison valide aussi un **périmètre**, les équipes qui
font de la compétition. Une fois les matchs envoyés à la fédération, on n'y revient plus — « une équipe qui
joue ne peut pas être supprimée, ni avoir son niveau modifié ; elle peut être déplacée ou changer de
créneau ». Le planning de saison, lui, ne change **quasiment jamais** ; il s'ajuste dans de rares cas.

**Engagée** = elle porte **au moins un `Fixture`**, quel qu'en soit le statut. Décision fondateur : « si
import FBI pour les matchs, l'équipe est engagée d'office. Une correspondance pour les matchs implique que
l'équipe est engagée pour la fédération. Même si le statut est `UNPLACED`, même si le match n'est pas placé. »

⚠️ **Ne PAS filtrer sur le statut.** `FbiFixtureImporter` crée TOUT en `UNPLACED` — domicile **et** extérieur
(« Status is always UNPLACED : placing requires a CLUB venue + an explicit manager action »). Seul un geste
du gestionnaire (`FixtureStateProcessor`) pose un autre statut. Une garde exigeant `PLACED` serait donc
**inerte au moment précis où elle doit mordre** : juste après l'import, quand la fédération connaît déjà les
rencontres. *(Le docblock de `FixtureStatus` a longtemps prétendu qu'un match extérieur naissait `PLACED` —
c'était faux, et ça a coûté une règle bâtie sur du vide.)*

Conséquence assumée : **dès l'import FBI**, toutes les équipes de la compétition sont figées.

| Sur une équipe engagée | |
|---|---|
| Suppression (`DELETE /api/teams/{id}`) | **409** — sans ça, `EntityCascadeDeleter::purgeChildrenOfTeam` emporterait ses `Fixture`, y compris ceux déjà connus de la fédé |
| Changement de `Team.level` | **409**, sans exception — c'est sous ce niveau qu'elle est inscrite, et il se saisit AVANT de générer (il alimente le tag NIVEAU, donc les contraintes, donc la photo de structure de la version). Le laisser bouger après ferait diverger la photo — qui l'a figé — et la base, puis « Charger cette version » ramènerait l'ancienne valeur en silence. Vaut aussi pour un niveau jamais renseigné (`null` → REGIONAL refusé) et pour un effacement. Seule tolérance, qui n'est pas une exception : un PUT qui ré-écho le MÊME niveau passe — le front renvoie le payload complet, refuser l'écho casserait un renommage sans rien protéger. Le jour où l'import FFBB devra changer un niveau, ce cas sera traité **avec** la photo |
| `priorityTierId` / `tierOrder` | **libres** — perception interne du club |
| `isActive` | **libre** — sert aux plannings de période, pas au périmètre de la saison |
| Nom, créneaux, gymnase | **libres** |

La règle vit à **un seul endroit** (`TeamEngagementGuard`) : la garde d'écriture et le contrat de lecture
(`TeamResource.isEngaged`, rempli en lot par `TeamStateProvider`) la consultent. Le front grise « Supprimer »
et le sélecteur de niveau à partir de ce champ — il ne re-dérive rien, sinon un second endroit répondrait
« engagée ? » et finirait par contredire le serveur.

Les purges de masse (`SeasonDataPurger`, `ErasedClubPurger`) ne passent pas par la garde : la saison entière
part, matchs compris.

**Le restore aussi** : `StructureRestorer::wipeStructure` (« Charger cette version ») supprime les `Team` de
la saison en DQL de **masse**, donc sans passer par le processor où vit la garde. `Fixture` n'est ni dans ce
wipe ni dans la photo (`StructureSnapshotter::FAMILIES`) : une équipe engagée que la photo ignore serait
supprimée et ses matchs survivraient en nommant un `team_id` mort (aucune FK ne l'arrête). L'invariant serait
alors vrai par l'API et faux ici — donc faux, et contournable en un clic.

`StructureRestorer::assertRestoreKeepsEngagedTeams` refuse le chargement (**409**) quand la photo ne contient
pas une équipe engagée. On refuse plutôt que d'épargner l'équipe : la garder hors de la photo rendrait la
structure incohérente avec la version qu'on prétend recharger. Il refuse **aussi** quand la photo porte un
**autre niveau** pour une équipe engagée : `level` est un champ mappé, donc la photo le réinsère tel quel, et le
gel du niveau (voir plus haut) serait contourné par le restore — le club se retrouverait inscrit sous un niveau
que l'API refuse ensuite de corriger. Une équipe engagée présente dans la photo **avec son niveau** ne bloque rien.

## Reste palier A (à venir)

`Team.preferredMatchWindow` (backend). **Joueurs** dans le moteur de conflits (nécessite un modèle de
rattachement joueur→équipes) + paliers B (dérogation + trajet + annuaire adverse global) / C (effet réseau)
plus tard. ⚠ Envelope strictement HARD & fiable = nécessiterait une clé de jointure normalisée
équipe↔fenêtre côté backend (aujourd'hui : dégradation indicative en UI). ⚠ Import FBI : format à valider
contre un vrai export (cf. encadré PR-4) ; update de re-programmation au re-import = évolution.

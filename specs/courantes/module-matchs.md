# Module matchs (FFBB) — état livré

Last verified @ 2026-08-19 (**rotation de fraîcheur**, tout juste — re-vérifié contre le code : `SocleGuard::assertSeasonPlanChosen` existe (`backend/src/Service/SocleGuard.php:26`), `Fixture.status` part bien de `UNPLACED` (`Entity/Fixture.php:70-71`), `Competition` est `TenantOwnedInterface`, et `engine/CONTRACT_VERSION` reste le fichier unique lu par `engine/app/main.py`) ; précédemment : 2026-08-19 (**re-vérifié au code, rien de faux** — le rail matchs a bien son propre solve (`POST /place-matches`, endpoint confirmé côté engine) ✓ et son verrou DÉDIÉ, distinct de celui de génération : `backend/src/Service/MatchPlacementLock.php:18` ✓ (ADR-0003). Redaté suite à l'édition de la ligne de stamp par la passe DOC-33) ; précédemment : 2026-08-18 (re-vérifié contre `backend/src/Deletion/CascadePlan.php` + `backend/src/Deletion/DeletionImpactCounter.php` — **DOC-2 SOLDÉ** : la section « équipe engagée » gagne son pendant côté SALLE — supprimer un gymnase dépointe `Fixture.venueId` y compris sur un match déjà déclaré à la fédération, le geste n'est PAS refusé (le match redevient « à placer », donc récupérable) mais il est ANNONCÉ et compté à part avant confirmation ; le match SURVIT, gardé par `DeletionImpactParityTest`) ; précédemment : 2026-08-13 (stamp recalé — le commit du contenu du 2026-08-12 a franchi minuit : PR #536 mergée à 00:03 ; contenu inchangé depuis) (recalé ce jour : contrat 2.5 — retrait du levier mort `allowMultipleSessionsPerDay`, P4-79 ; précédemment : 2026-08-12 (P4-85 : les mentions « contrat 2.2 » / « payload 2.2 » recalées — le placement partage le MÊME contrat backend⇄engine que le solve hebdo, un seul `CONTRACT_VERSION` (2.5), plus de MINOR décoratif ici ; précédemment 2026-08-08 : statut posé ; fenêtres d'accès match visibles sur la grille des gymnases, 2026-08-04 ; P1-4 PR A import réel · PR B capacité · PR C habitudes+passerelles · PR D solveur de placement · PR E1 boucle manuelle · PR E2 diagnostic gradué · PR F1 appariement FFBB · PR F2 garde-fou poule + complétude — **lot P1-4 SOLDÉ**))

> Graduation du comportement livré (skill `documentation-update`). Le besoin et la vision restent dans
> [`../evolution/gestion-matchs-ffbb.md`](../evolution/gestion-matchs-ffbb.md) (paliers A/B/C), **cadrés
> pour l'exécution le 2026-08-02** par
> [`../evolution/p1-4-cadrage-module-matchs.md`](../evolution/p1-4-cadrage-module-matchs.md) (P1-4 —
> notamment : le format FBI livré ici est **invalidé par un vrai export**, et le placement devient
> solveur + boucle manuelle). Ici = ce qui **existe** aujourd'hui. Module **fonctionnellement autonome** : ses entités, son moteur de conflits et sa
> grille week-end ne dépendent pas du solveur d'entraînement, et rien de ce module n'entre dans le payload
> du solve hebdo. Depuis la PR D il a **son propre solve** (`POST /place-matches`, second problème engine,
> **même contrat backend⇄engine** que le solve hebdo — un seul `CONTRACT_VERSION`, cf. § Solveur de placement).

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

## Couche capacité (P1-4 PR B, 2026-08-03)

- **Fenêtres d'accès match** (`VenueMatchWindow`, tenant+saison, RLS) : jour ISO 1-7 + plage `start<end`
  même jour (famille P4-61) — les créneaux accordés LES JOURS DE MATCH, distincts des créneaux
  d'entraînement. ⚠ Les libellés **ne nomment pas le propriétaire des lieux** (« la mairie ») : c'est le cas
  du BCCL, pas de tous les clubs — conseil départemental, lycée, salle privée (2026-08-04).
  **Gymnase de match = dérivé** (≥ 1 fenêtre), aucun booléen sur `Venue`. **Recopiées à la
  bascule de saison** (`SeasonTransitionService` — la convention se renouvelle). Saisie à DEUX
  endroits (décision fondateur) : section « Accès match » de l'étape Gymnases du wizard, et dialog
  « Accès match » de `/matchs` — même composant `MatchWindowsEditor`, une seule vérité.
- **Règle wizard assouplie** : « gymnase sans créneau » devient « sans créneau d'entraînement NI fenêtre
  match » — un gymnase loué pour les matchs seulement ne bloque plus la validation (gate
  `useStepValidation` + bandeau `VenuesStep`, les deux sites ensemble ; en échec de lecture des fenêtres,
  AUCUNE exemption — plus strict, jamais moins).
- **Indisponibilité gymnase** (`VenueUnavailability`, tenant+saison, RLS) : plage de dates INCLUSES + motif,
  **toutes circonstances** — posée au cockpit (carte « Indisponibilités gymnase », écriture
  management-gated SEC-07), **jamais recopiée** en N+1 (fait daté). **Alerte seulement**, ne bloque ni ne
  régénère rien :
  - carte cockpit : « N créneau(x) d'entraînement/sem. (M séances) · K match(s) placé(s) à repositionner »,
    servie par `GET /api/venue-unavailability-impact` (`VenueUnavailabilityImpact`, pur) ;
  - radar matchs : finding **`VENUE_UNAVAILABLE`** (match posé sur un gymnase fermé à sa date — le cas que
    la garde de placement ne peut pas attraper : l'indispo posée APRÈS le placement).
- **Garde de placement** (`PlacementPanel` + `lib/matchAccess.ts`, HARD sans dégradation — donnée du club,
  pas de mapping incertain) : sélecteur restreint aux gymnases de match (repli : club sans AUCUNE fenêtre →
  liste complète, donnée non adoptée) ; bloqué si jour sans fenêtre, heure hors plage, ou gymnase indispo à
  la date. Pas de verrou serveur sur le geste MANUEL (décision fondateur) — le solveur (PR D), lui, ne
  sort jamais du HARD ; le verrou serveur du geste manuel reste une dette PR E (roadmap, dette (i)).
- **Source unique ADR-0002** : la règle « quel planning s'applique à telle date » est extraite en
  `EffectiveScheduleResolver` (pur) + `TrainingCalendarContext` (chargement scopé), consommés par le
  radar ET l'impact — deux copies auraient divergé.

## Habitudes + passerelles (P1-4 PR C, 2026-08-03)

- **Habitude d'équipe** (`TeamMatchHabit`, tenant+saison, RLS) : jour ISO + **heure-point** (pas une plage)
  + gymnase optionnel — « SF3 = dimanche 17h30 à Coubertin ». **Une par jour et par équipe** (unique DB,
  422 lisible avant), N par équipe. **Recopiée à la bascule** (remap équipe+gymnase ; gymnase pendu →
  l'habitude survit en jour+heure). Solde le `Team.preferredMatchWindow` de P3-1.
- **Passerelle** (`TeamLink`, nom NEUTRE — cross-module par conception, le solveur d'entraînement la
  consommera un jour) : couple d'équipes **symétrique** (normalisation `teamAId < teamBId` → SM1–SM2 ≡
  SM2–SM1, unique DB), deux types `TeamLinkType` : **`NOT_SIMULTANEOUS`** (« joueurs partagés » — aucune
  entité joueur n'existe, le gestionnaire DÉCLARE le pont) et **`BACK_TO_BACK`** (« l'un après l'autre »,
  implique la non-simultanéité). Équipe liée à elle-même → 422 ; équipe étrangère invisible → 422 sans
  écriture. Cascade : suppression d'équipe purge habitudes + liens (les DEUX colonnes). Recopiée en N+1
  re-normalisée. Solde le « volet joueur » de P3-1 **par décision** : pas de joueurs individuels, le lien
  déclaré porte le besoin.
- **Effets immédiats, sans solveur** :
  - **Estimation d'heure extérieure** (résorbe l'angle mort PR-2) : un AWAY sans heure emprunte l'heure
    habituelle de son équipe **du même jour de semaine** → l'empreinte naît (`MatchFootprint::occupancyAt`),
    les conflits deviennent visibles, marqués **`estimatedKickoff: true`** (« heure estimée » dans le
    radar). **Rien n'est persisté** (écrire l'estimation dans `kickoffTime` la ferait passer pour une heure
    réelle et polluerait le diff de ré-import F2). Pas d'habitude ce jour-là → pas d'empreinte, mais
    l'angle mort est NOMMÉ au diagnostic (`AWAY_NO_FOOTPRINT`, PR E2).
    Une heure RÉELLE n'est jamais supplantée. Un HOME non placé n'est pas estimé (son heure est le prochain
    geste du gestionnaire).
  - **Finding `TEAM_LINK_OVERLAP`** : deux matchs d'équipes liées `NOT_SIMULTANEOUS` dont les empreintes
    (réelles ou estimées) se chevauchent (demi-ouvert — enchaînés dos-à-dos = pas de conflit). Indépendant
    des coachs. `BACK_TO_BACK` ne produit AUCUN finding (préférence du solveur de placement, pas une règle).
  - **Pré-remplissage au placement** : `PlacementPanel` initialise gymnase+heure depuis l'habitude du jour
    du match (champs vides seulement) + ligne « Habitude : samedi 15:30 · Mateo ». **Les gardes restent
    souveraines** (enveloppe, accès, indispo — l'habitude pré-remplit, ne débloque jamais).
  - **Blocs fantômes** (`WeekendGrid`) : une habitude À GYMNASE dont l'équipe n'a AUCUN match ce jour-là
    projette un bloc translucide pointillé « Habitude SF3 · fenêtre protégée » (empreinte 2h15, lanes
    partagées avec les vrais matchs — un placement manuel atterrit À CÔTÉ, pas dessus). **La réalité
    dissout le fantôme** : tout match de l'équipe à cette date — extérieur compris, la fenêtre se LIBÈRE.
    Habitude sans gymnase → pas de fantôme (grille en colonnes gymnase).
  - **Inférence** (`lib/habitInference.ts`, pure, front) : suggère l'habitude majoritaire quand **≥ 3
    matchs horodatés ET ≥ 50 %** concordent (seuils fondateur) ; gymnase joint si ≥ 50 % des HOME placés du
    groupe le partagent (le libellé texte `fbiVenueLabel` n'est JAMAIS résolu — décision PR A). Suggestion
    = bouton « Accepter », jamais une écriture ; un jour déjà déclaré n'est pas re-suggéré.
- **Saisie** : `/matchs` uniquement, bouton « Habitudes & passerelles » (`HabitsLinksDialog`) — l'inférence
  exige des matchs importés, rien au wizard. Écritures non management-gated (patron `VenueMatchWindow`),
  derrière le verrou socle de la page. Engine intouché par la PR C — habitudes et passerelles sont
  consommées par le solveur de placement depuis la PR D (§ suivant).

## Solveur de placement — P1-4 PR D (2026-08-03, [ADR-0003](../../docs/architecture/adr-0003-match-placement-solve.md))

- **Second problème solveur engine** : `POST /place-matches` (`engine/app/solver/match_placement.py`,
  schémas `match_input_schema.py`/`match_output_schema.py`), **un seul** `CONTRACT_VERSION` pour les deux
  endpoints → bump **2.2**. Le solve hebdo est intouché (mêmes fichiers, mêmes golden).
- **Rail SYNCHRONE** : `POST /api/fixtures/place` (`PlaceMatchesController` — management + saison
  écrivable + socle pointé) répond dans la requête, sans Messenger ni Mercure. Anti-double-clic par
  `MatchPlacementLock` (Redis, préfixe dédié — PAS le verrou de génération). Seuil de bascule async ~20 s
  (ADR-0003 §2).
- **Best-effort à poids dominant** : l'objectif maximise `10 000 × Σ placés + SOFT` — **aucune contrainte
  HARD n'est jamais violée en sortie**. Un match sans candidat licite sort **NOMMÉ**
  (`no_access_window` · `no_league_intersection` · `venue_unavailable` · `venue_full`), la raison affichée
  sur sa ligne « À placer ». Ce n'est pas la relaxation qu'interdit ADR-0001 : rien n'est relâché,
  l'impossible est épelé (le signal dérogation-tôt EST le produit).
- **HARD** : fenêtres d'accès match (l'empreinte 2h15 entière dedans — l'échauffement occupe la salle),
  indisponibilités gymnase, no-overlap par (gymnase, date), fenêtre ligue quand l'enveloppe est résolue
  (`LeagueEnvelopeResolver`, portage serveur de la jointure tolérante d'`envelope.ts` — non résolue =
  aucun HARD + diagnostic INFO `league_envelope_unresolved`). **SOFT** (golden-épinglés — en changer un =
  changer le PRODUIT) : conflit coach MAIN −60 · passerelle `NOT_SIMULTANEOUS` violée −40 · habitude
  heure +15 / gymnase +5 · fenêtre habituelle protégée −25 · `BACK_TO_BACK` enchaîné +15 · coach
  ASSISTANT −10 · stabilité re-solve +8 (+ hint) · compactage −1 par pas de 15 min de trou. Candidats au
  pas de 15 min, 30 s de budget, 1 worker + seed (bit-stable).
- **Ancres — `Fixture.placementSource`** (`MANUAL`/`SOLVER`, null legacy = manuel) : tout geste API du
  gestionnaire stampe MANUAL ; MANUAL + `SUBMITTED`/`VALIDATED` = **FIXED**, consomment leur créneau et ne
  bougent JAMAIS (un déposé qui a perdu sa salle est ignoré du payload — ni ancre ni plaçable). SOLVER =
  re-plaçable (bonus stabilité). Écriture directe en `PLACED` (patron du planning) ; l'applier recharge
  chaque fixture et n'écrit que si le solveur y est encore autorisé — un geste manuel pendant le solve
  gagne toujours.
- **Le backend PROJETTE, l'engine reste plat** (`MatchPlacementPayloadBuilder`) : occupations
  d'entraînement **datées** via `TrainingCalendarContext` + `EffectiveScheduleResolver` (ADR-0002 jamais
  ré-implémenté côté engine), heure extérieure estimée par le MÊME `AwayKickoffEstimator` que le radar,
  enveloppe ligue résolue côté serveur.
- **UI** : bouton « Placer automatiquement » sur `/matchs` (spinner pendant le solve, toast « N placés ·
  M non plaçables », raisons par match dans la liste À placer).

## Boucle manuelle — P1-4 PR E1 (2026-08-03)

- **Chaque match de la grille est cliquable** (badge cadenas = ancre manuelle) et ouvre le panneau,
  étendu aux matchs placés : **Déplacer** (salle/heure, gardes du placement initial souveraines),
  **Dé-placer** (retour « À placer », marqueur effacé), **Verrouiller / Rendre au solveur**,
  **Échanger avec…** (mode swap : bandeau + 2e clic sur un autre match placé), **Modifier**
  (`FixtureFormDialog` en édition), **Supprimer** (confirmation). Un match `SUBMITTED`/`VALIDATED`
  est en lecture seule — déposé à la fédération.
- **Verrou = `placementSource`** (aucun nouveau champ) : cadenas = PUT écho (le stamp MANUAL existant
  fait le travail) ; « rendre au solveur » = PUT écho + `placementSource: "SOLVER"`, accepté par le
  serveur **seulement à placement (salle/heure/date) inchangé et statut PLACED** — 422 sinon (on ne
  peut pas étiqueter SOLVER un placement choisi à la main ; `FixtureStateProcessor`). À la création,
  `SOLVER` est refusé (422). `null` legacy = manuel (cadenas affiché).
- **Éditer** : équipe figée (une autre équipe = un autre engagement — supprimer + recréer) ; **changer
  la date à la main CONSERVE le placement** (le gestionnaire EST la décision, à l'inverse du ré-import
  FBI qui dé-place) — le radar signale ce que la nouvelle date casse. **Exception** : basculer
  domicile → extérieur libère le créneau (même règle que le switch du ré-import ; règle pure
  `editFixtureBody`, testée unitairement).
- **Échanger** = swap (salle + heure), **jamais les dates** (elles appartiennent à la ligue). Deux PUT
  séquentiels côté client, pas d'endpoint transactionnel : rien n'étant bloquant, un échec réseau au
  milieu laisse un état visible et rattrapable (invalidation en `onSettled`, toast d'alerte).
- **Rien ne bloque** : une collision de salle créée à la main passe (décision fondateur 2026-08-03) —
  le diagnostic gradué (PR E2) l'affichera en sévérité max. ⚠ Conséquence engine (bug attrapé par le
  smoke) : les ancres FIXED **élaguent les candidats** au lieu d'entrer au NoOverlap — deux ancres en
  collision ne rendent plus le solve entier infaisable (ADR-0003 §5, amendé).
- Suppression d'un match : DELETE direct (la garde socle ne s'applique qu'aux écritures) ;
  l'engagement étant **dérivé**, supprimer le dernier match d'une équipe la rend à nouveau supprimable
  (aucune garde ne survit à tort — NR `EngagedTeamGuardTest`).

## Diagnostic gradué + extérieur visible + week-end type — P1-4 PR E2 (2026-08-03)

- **La sévérité est émise par le SERVEUR** (`MatchConflictDetector`, `severity` 1..7 + `coachRole`
  MAIN/ASSISTANT — MAIN sur N'IMPORTE quelle équipe impliquée gagne) ; l'UI groupe et libelle
  (`lib/diagnostic.ts`, pur), elle ne re-dérive JAMAIS la gravité. Groupes triés pire-d'abord, tons
  1-2 rouges / 3-5 warning / 7 neutre, **groupe 7 replié avec compteur** (40 extérieurs aveugles =
  une ligne, pas 40 cartes).
- **Échelle (cadrage §8)** : 1 `VENUE_OVERLAP` (deux matchs même gymnase qui se chevauchent — la
  boucle manuelle ne bloque jamais, le diagnostic crie) · 2 `LEAGUE_WINDOW_VIOLATION` (domicile placé
  d'une équipe MAPPÉE hors de toute fenêtre ligue — non mappée = silencieuse, même tolérance que le
  solveur) · 3 coach **MAIN** (`MATCH_MATCH`/`MATCH_TRAINING`) · 4 `VENUE_UNAVAILABLE` +
  **`ACCESS_WINDOW_LOST`** (dette (ii) soldée : placé dont la fenêtre d'accès a changé APRÈS — règle du
  PANNEAU mirrorée : heure-point, demi-ouvert, club sans aucune fenêtre = rien à faire respecter, PAS
  la règle empreinte du solveur : un match que le panneau vient d'autoriser ne doit pas alerter) ·
  5 coach ASSISTANT + `TEAM_LINK_OVERLAP` · 7 **`AWAY_NO_FOOTPRINT`** (dette (v) : l'angle mort —
  extérieur sans heure ni habitude du bon jour — est NOMMÉ, plus un silence pris pour de la santé).
  La sévérité 6 (`COMPETITION_INCOMPLETE`, PR F2) juge les compétitions APPARIÉES sous leur attendu.
- **Enveloppe fiable côté UI (dette (iv) soldée)** : `GET /api/league-match-windows` porte
  `resolvedTeamWindows` (teamId → ids de fenêtres), calculé par le MÊME `LeagueEnvelopeResolver` que
  le solveur et le diagnostic — la jointure n'existe qu'à UN endroit ; `lib/envelope.ts` devient un
  simple lookup (la jointure tolérante CLIENTE est supprimée — deux implémentations avaient déjà
  commencé à diverger). Non résolue = indicatif, jamais bloquant (inchangé).
- **Extérieur visible** : bande « À l'extérieur ce week-end » sous la grille (`AwayList`) — équipe,
  date, adversaire + salle FBI (`fbiVenueLabel`), heure réelle sinon habituelle taguée « heure
  estimée » (même règle que le radar), sinon « heure inconnue » ; Modifier/Supprimer (confirmation).
- **Vue « week-end type »** (reformulation fondateur de « semaine type ») : bascule sur `/matchs` —
  le gabarit IDÉAL du gestionnaire, toutes les habitudes Sam/Dim × gymnases, sans dates, empreintes
  2h15, chevauchements posés côte à côte (une collision de gabarit doit se VOIR). Lecture seule
  (`lib/typicalWeekend.ts` pur + `TypicalWeekendGrid`) — l'édition reste dans « Habitudes &
  passerelles » ; habitudes sans gymnase listées à part.

## Appariement FFBB — P1-4 PR F1 (2026-08-03)

- **La FFBB fait autorité sur le PÉRIMÈTRE** (équipes engagées, poules, adversaires), l'export FBI sur
  le calendrier. Deux appels Meilisearch de plus (`FfbbApiClient` — mêmes hosts SSRF, à la demande,
  zéro cache/cron : décision juridique fermée), joints par `FfbbEngagementReader` (saison filtrée par
  `FfbbSeasonCode`, double encodage réparé à l'entrée). Détail des sondes et des pièges (champ non
  filtrable, id non filtrable) : `backend/docs/ffbb-api.md`.
- **Dialog « Engagements FFBB » sur `/matchs`** (`FfbbEngagementsDialog`, fetch à l'ouverture seulement) :
  chaque engagement (compétition · poule · N clubs · catégorie/niveau/sexe) se rattache à une équipe,
  **confirmation en bloc**. Aux phases suivantes tout est pré-rempli (réf déjà connue, sinon égalité
  normalisée stricte du nom canonique) — « on ré-apparie à chaque phase, assumé : 1 clic ». Ligne vide =
  non rattachée, RIEN modélisé (l'absence de lien EST l'état). Mention obligatoire : « Données de la
  ligue — un écart se corrige auprès d'elle. »
- **Le confirm écrit sur la `Competition` de l'équipe** (réutilisée par nom canonique, sinon créée) :
  `ffbbCompetitionId`/`ffbbPouleId`/`ffbbPouleName`/`ffbbCompetitionName`, **`expectedMatchdays` =
  2×(N−1) figé à l'appariement**, et **`ffbbPouleOpponents`** (la liste des clubs de la poule, copiée —
  le garde-fou d'import restera hors-réseau, PR F2). Taille et adversaires viennent d'un **re-fetch
  serveur** — un client forgé ne peut pas éteindre la complétude. Un engagement = une équipe : ré-apparier
  ailleurs efface les réfs de l'ancienne ligne (ses fixtures survivent). Champs exposés en LECTURE sur
  `CompetitionResource`, jamais écrits par le CRUD.
- **Garde-fou poule (PR F2, 6.1)** : à l'analyze ET à l'import, pour une division résolue vers une
  compétition APPARIÉE, les adversaires DISTINCTS du fichier sont confrontés à la liste des clubs de
  la poule (containment mot-entier normalisé, l'idiome `containsClub` — « FIRMINY … - 1 » matche le
  club de poule « FIRMINY … »). **> 50 % d'inconnus → division refusée NOMMÉE et SAUTÉE** (« mauvais
  fichier, mauvaise équipe ou mauvaise phase ? ») — les autres divisions passent ; **1..50 % → warning
  `POULE_MISMATCH`** listant les inconnus ; division sans appariement = jamais contrôlée. **Hors-réseau
  par construction** (la liste fut copiée à l'appariement).
- **Complétude (PR F2, 6.2)** : au rapport d'import (« 9/22 journées — fichier partiel ou phase pas
  encore sortie », compté sur les `Fixture` PERSISTÉS) ET en **sévérité 6** du diagnostic
  (`COMPETITION_INCOMPLETE`, groupe « Calendriers incomplets » replié) — seules les compétitions à
  `expectedMatchdays` sont jugées.
- **Pré-remplissage de l'analyze (PR F2, 6.3)** : division NON mappée dont le libellé égale (normalisé)
  le **nom canonique FFBB** d'une compétition appariée → `suggestedTeamId` + `suggestedCompetitionId`
  (badge « proposé par la FFBB ») — une suggestion, jamais une résolution ; **jamais pour une division
  multi-labels** (le canonique ne sait pas dire laquelle des deux équipes — même refus que le
  résolveur) ; deux canoniques normalisés identiques = ambigu = aucune suggestion. Ce que le sélecteur
  AFFICHE est ce qui s'importe — une suggestion dont l'équipe n'est plus offrable n'est ni affichée ni
  envoyée. **La suggestion acceptée voyage AVEC son `competitionId`** : la compétition appariée est
  RÉUTILISÉE (renommée vers le libellé FBI — la clé du résolveur —, canonique/réfs/attendus/poule
  conservés), jamais dupliquée.
- **Le garde-fou précède l'écriture** (revue F2 round 1) : un mapping dont la division est refusée par
  le garde-fou poule n'est PAS persisté (erreur nommée, division ni importée ni re-signalée « à
  mapper ») — le dialog n'a pas de geste de re-mapping, une écriture fautive collerait. Deux mappings
  (équipe, division) identiques dans un même lot = une seule `Competition` (dedupe en mémoire, le
  lookup DB ne voit pas les frères non flushés).

## Vérifs / gardes

- NR bloquant (phase1, CI) : `MatchTenantIsolationTest` (Competition/Fixture scopés club+saison, POST stampe,
  écriture saison archivée → 409 ; **+ PR B** : fenêtres/indispos scopées et stampées, `venueId` étranger →
  422 sans écriture cross-club, indispo management-gated 403 + saison archivée 409). PR B aussi :
  `VenueUnavailabilityImpactTest` (unit — sémantique ADR-0002 épinglée : période sans overlay = zéro alerte
  fantôme, overlay = SES créneaux), `VenueCapacityApiTest` (CRUD + validations + flux d'impact),
  `MatchConflictDetectorTest` étendu (VENUE_UNAVAILABLE, bornes incluses, sans-kickoff affecté),
  `SeasonTransitionServiceTest` (fenêtres copiées, indispos non), `useStepValidation.test` (exemption
  fenêtre match socle + période), `PlacementPanel.test` (filtre sélecteur, gardes jour/heure/indispo, repli). Le catalogue global reste hors tenant : garanti par `RlsIsolationTest` +
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
- Préférences (PR C) : `MatchTenantIsolationTest` +2 (phase1 — habitude scopée+stampée+unique/jour,
  passerelle symétrique/unique/anti-self, équipe étrangère → 422 sans écriture cross-club).
  `MatchConflictDetectorTest` +5 (estimation même-jour seulement + flag, heure réelle jamais supplantée,
  **NR : AWAY sans habitude reste invisible**, TEAM_LINK_OVERLAP sans coach, BACK_TO_BACK silencieux +
  demi-ouvert). `SeasonTransitionServiceTest` (habitude+lien recopiés remappés, normalisation conservée).
  Front : `habitInference.test` (seuils, non-horodatés ignorés, jour déclaré non re-suggéré, gymnase ≥ 50 %),
  `weekendGrid.test` (fantôme rendu/dissous par la réalité/lanes partagées), `PlacementPanel.test`
  (pré-remplissage + gardes souveraines).
- Diagnostic gradué (PR E2) : `MatchConflictDetectorTest` +4 (unit — VENUE_OVERLAP sévérité 1,
  LEAGUE_WINDOW_VIOLATION mappée-seulement, ACCESS_WINDOW_LOST parité panneau — dedans/demi-ouvert/
  club-sans-fenêtre, coachRole MAIN-gagne-partout 3 vs 5 ; + l'ancien NR « AWAY sans habitude
  invisible » amendé : plus de conflit horaire mais AWAY_NO_FOOTPRINT nommé),
  `FixtureConflictsApiTest` (severity+coachRole au contrat HTTP, **phase1**),
  `LeagueMatchWindowsApiTest` +1 (**phase1** — `resolvedTeamWindows` : U13 mappée → ids du
  catalogue, « Loisir » → []). Front : `envelope.test` réécrit (lookup serveur : [], absente, id
  fantôme), `diagnostic.test` (tri, tons, repli sév. 7, legacy sans severity → 5),
  `typicalWeekend.test` (empreinte, hors week-end exclu, lanes, sans-gymnase à part),
  `AwayList.test` (salle FBI + heure estimée/réelle/inconnue, suppression confirmée),
  `MatchesPage.test` +1 (bande extérieur + bascule week-end type).
- Boucle manuelle (PR E1) : `FixtureApiTest` +6 (**phase1** — déverrou accepté sur écho seul, 422 si
  le placement bouge ou si le statut quitte PLACED, écho → MANUAL, UNPLACED → null, SOLVER refusé au
  POST), `MatchPlacementContractSchemaTest` +1 (**phase1, NR contrat** — verrou/déverrou bascule les
  kinds FIXED/TO_PLACE du payload), `EngagedTeamGuardTest` +1 (**phase1, NR périmètre engagé** —
  DELETE fixture ≠ DELETE équipe, engagement dérivé relâché au dernier match), engine
  `test_colliding_fixed_anchors_never_sink_the_whole_solve` + `…pruned_not_infeasible` (NR — ancres
  en collision n'évincent plus tout le solve). Front : `editFixtureBody.test` (date conservée,
  HOME→AWAY libère), `weekendGrid.test` (badge verrou, null = manuel), `PlacementPanel.test` +6
  (Déplacer désactivé sans changement, actions, bascule verrou, confirmation de suppression,
  SUBMITTED lecture seule), `FixtureFormDialog.test` +2 (édition pré-remplie équipe figée, warning
  bascule extérieur), `MatchesPage.test` +1 (clic grille → panneau boucle manuelle). E2e : verrou
  aller-retour + dé-placer sur la vraie stack.
- Placement (PR D) : `MatchPlacementContractSchemaTest` (**phase1** : forme du payload au contrat backend⇄engine ; groupe
  `contract` : POST au VRAI engine, kickoff rendu DANS la fenêtre — sémantique, pas un 200),
  `PlaceMatchesControllerTest` (gardes 403/409, samedi placé dans [14:30,16:15] + dimanche
  `no_access_window`, **ancre manuelle jamais réécrite**, 502 si engine down sans écriture),
  `LeagueEnvelopeResolverTest` (jointure tolérante). Engine : `test_match_placement.py` (unit),
  `test_match_placement_semantics.py` (week-end réaliste + invariant `assert_no_hard_violation`),
  golden épinglé (`test_match_placement_golden.py` — chaîne BACK_TO_BACK sans trou sur ancre 20:30).
- Unit : `MatchFootprintTest`, `LeagueResolverTest`. Command : `SeedLeagueWindowsCommandTest`. Api :
  `FixtureApiTest`.
- Smokes : `backend/scripts/smoke-place-matches.sh` (bout-en-bout SÉMANTIQUE : samedi placé
  SOLVER dans la fenêtre d'accès, dimanche non plaçable NOMMÉ — restaure le pointeur socle qu'il pose) **et**
  smoke-solveur COMPLETED (le pipeline hebdo survit au bump 2.2 ; payload hebdo inchangé).

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

**La salle d'un match, elle, n'est PAS protégée — et c'est délibéré (DOC-2, soldé le 2026-08-18).** Supprimer un gymnase dépointe `Fixture.venueId`, y compris sur un match `SUBMITTED`/`VALIDATED` déjà déclaré à la ligue. Le geste **n'est pas refusé** (un gymnase qui ferme, ça arrive, et le match redevient visiblement « à placer », donc récupérable) : il est **annoncé**. La modale de suppression compte ces matchs à part (`DeletionImpactCounter::declaredFixturesOnVenue`) et dit qu'ils devront être re-soumis à la fédération. Le match SURVIT à la suppression, il perd sa salle — gardé par `DeletionImpactParityTest`.

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

**Joueurs** dans le moteur de conflits : décision fermée — pas d'entité joueur, la passerelle déclarée
(`TeamLink`, PR C) couvre le besoin. Paliers B (dérogation + trajet + annuaire adverse global) / C (effet
réseau) plus tard. ⚠ Envelope strictement HARD & fiable côté UI = clé de jointure normalisée
équipe↔fenêtre (dette (iv), PR E — côté solveur la jointure tolérante `LeagueEnvelopeResolver` est
livrée). *(`Team.preferredMatchWindow` → devenue `TeamMatchHabit` (P3-1 soldée en PR C) ; format FBI
validé sur vrai export + diff de ré-import → livrés en PR A.)*

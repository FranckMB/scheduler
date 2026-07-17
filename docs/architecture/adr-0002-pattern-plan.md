# ADR-0002 — Pattern « Plan » : un plan nommé, des versions, un pointeur

- **Status**: accepted — Date: 2026-07-12
- **Décidé avec le fondateur** point par point (session 2026-07-12) ; référence produit :
  [`specs/courantes/types-de-planning.md`](../../specs/courantes/types-de-planning.md).

## Contexte

Le « planning » n'existe pas comme objet de premier ordre : c'est un regroupement implicite
de lignes `Schedule` par `(seasonId, calendarEntryId)`. Conséquences constatées :

- **Le nom n'a pas de maison.** Tentative E6 (nom sur `Schedule.name`) : 2 rounds de revue,
  16 findings, abandonnée (PR #214) — `Schedule` est une *version*, le nom du plan divergeait
  entre baseline, dernière version et liste.
- **Trois pointeurs à sémantique croisée** : `Season.baselineScheduleId`,
  `CalendarEntry.overlayScheduleId`, `Season.liveContextScheduleId` (★) — plus deux statuts
  de cycle de vie (`VALIDATED`, `ARCHIVED`) qui re-disent la même chose que les pointeurs.
- **Vocabulaire flou** (baseline / socle / overlay / version / planning) : le fondateur et le
  code ne parlaient pas de la même chose.
- **Le futur immédiat casse le modèle** : le découpage hebdomadaire (types-de-planning E1 —
  N plannings par vacances, réglages différents par semaine) est impossible tant que les
  réglages de période sont accrochés à la `CalendarEntry` (partagés par toute la fenêtre).

Contexte V0 : hors socle (flux saison), tout peut être reconstruit proprement — décision
explicite du fondateur : « définir un pattern simple identifiable », pas corriger un bug.

## Décision

### Le modèle : Plan → Versions → Pointeur

```
Plan                                    ← l'objet de premier ordre (nouveau)
  id, clubId, seasonId
  type              SEASON | CLOSURE | HOLIDAY
  name              le nom PUBLIC, éditable (pinceau) — « Planning de la saison 2025-2026 »,
                    « Ajustement Barros du 21/10 au 27/10 », « Planning de vacances de Toussaint… »
  startDate/endDate SA période d'application (SEASON = la saison ; sinon la semaine)
  calendarEntryId?  le DÉCLENCHEUR (l'indispo/les vacances du calendrier) — N plans possibles
                    par déclencheur (2 semaines ⇒ 2 plans) ; null pour SEASON
  chosenScheduleId? le POINTEUR : la version choisie (validée) ; NULL = espace de travail

Schedule (= Version)                    ← existant, recentré
  planId            rattachement au Plan (remplace la dérivation par calendarEntryId)
  versionNumber     STOCKÉ, compteur monotone par plan — V3 reste V3, le suivant est V4
  status            cycle SOLVEUR uniquement : DRAFT → PENDING → GENERATING → COMPLETED | FAILED
  name              devient un label technique auto « V{n} - {date} » (jamais édité)
  + snapshot figé, score, diagnostics, slots, photo de structure (inchangés)
```

### Les invariants (décisions fondateur, verbatim où possible)

1. **Valider = pointer.** Choisir une version ⇒ `Plan.chosenScheduleId = version` **et les
   autres versions du plan sont supprimées**. Pas de statut `VALIDATED`, pas d'`ARCHIVED` :
   « validé » se **dérive** du pointeur (une seule vérité).
2. **Pointeur NULL = espace de travail.** On (re)travaille ⇒ pointeur remis à null, on
   génère des versions (V4, V5…), on choisira. **Aucun pointage automatique** (l'auto-baseline
   au 1er COMPLETED disparaît) — seul le gestionnaire pointe.
3. **1 Plan de type SEASON par saison.** Créé avec la saison (onboarding / transition N+1).
4. **Deux plans ne se chevauchent jamais** (dates d'application), hors plan SEASON qui
   couvre tout par nature.
5. **Les réglages de période s'accrochent au Plan** (pas au déclencheur calendrier) :
   coches équipes (`TeamPeriodOverride`), contraintes gardées/enlevées
   (`ConstraintPeriodOverride`), créneaux prêtés (`VenueTrainingSlot` scopé période),
   réservations et contraintes datées, flag de seed (`teamSelectionInitialized`) →
   **re-keyés `calendarEntryId` → `planId`**. Chaque plan-semaine a SES réglages.
6. **La structure (équipes/gymnases/coachs/contraintes permanentes) reste PARTAGÉE**
   (état vivant du club par saison) — **pas de duplication par version**. L'indépendance
   des versions passe par la **photo** (JSON, D2, existante) : chaque version COMPLETED
   garde la photo de ses conditions ; « charger » une photo restaure ces conditions (D3).
7. **L'étoile ★ = la version dont la photo est chargée dans le wizard** (l'espace de
   travail). Ce n'est PAS un pointeur de plan. Avant un chargement qui écraserait des
   données jamais photographiées (générées nulle part) → **avertissement explicite**
   (perte assumée si confirmée).
8. **Déblocage du cockpit** : le plan SEASON possède **≥ 1 version terminée**
   (COMPLETED ou FAILED). *Changement assumé vs aujourd'hui (c'était la validation).*
9. **Périodes sans plan** : `cutoff`/`mutualisation` restent des rappels calendrier —
   seuls SEASON/CLOSURE/HOLIDAY portent des plans.
10. **Suppressions** : les vacances (référentiel) ne se suppriment pas ; supprimer une
    **indisponibilité** supprime ses plans et leurs versions (cascade complète :
    slots, diagnostics, photos, réglages).
11. **Exports** : nom de fichier = **nom du Plan**.
12. **Défauts de nom** (à la création du plan) : SEASON `Planning de la saison {saison}` ·
    CLOSURE `Ajustement {gymnase} du {début} au {fin}` · HOLIDAY
    `Planning de vacances de {nom} du {début} au {fin}`.

### Rôle de `CalendarEntry` (conservée, amincie)

`CalendarEntry` = **le calendrier**, pas le planning. Elle porte le **FAIT** ; le Plan porte
la **RÉPONSE**. Découle de l'invariant fondateur « l'indisponibilité est déclarée d'abord,
puis le gestionnaire décide » : le fait existe avant tout plan, et parfois sans plan
(indispo ignorée, semaine blanche, event, cutoff).

- **Garde** : les événements (`kind=event`, marqueurs AG/tournoi) ; la déclaration des
  périodes (closure/holiday/cutoff/mutualisation) ; l'affichage cockpit + radar ; le lien
  vacances scolaires (`schoolHolidayId`, zone) et les relances ; les contraintes **datées
  du fait** (« Barros fermé ») qui alimentent le radar de conflits.
- **Perd** (part au Plan) : `overlayScheduleId` (pointeur), `teamSelectionInitialized`
  (seed), la relation 1:1 avec un planning (→ 0..N plans par entry), et l'accroche des
  réglages de période.

### Vocabulaire (fait foi — à reporter dans `docs/glossary.md` à l'implémentation)

| Terme | Définition |
|---|---|
| **Plan** | LE planning nommé (type + période + nom + pointeur). |
| **Version (Vn)** | Une résolution du solveur (`Schedule`) : « V3 - 10 juil. ». Jamais nommée par l'humain. |
| **Version choisie** | Celle que pointe le plan (= validée). |
| **Espace de travail** | Plan au pointeur null : on génère/compare des versions. |
| **★ / photo chargée** | La version dont la photo de structure est chargée dans le wizard. |
| **Réglages de période** | Coches équipes/contraintes + créneaux prêtés d'un plan CLOSURE/HOLIDAY. |
| **Termes bannis** | *baseline*, *planningName*, *overlayScheduleId*, *liveContext*, statuts *VALIDATED/ARCHIVED*. |

### Règles inter-plans & consommateurs (complétées après sweep exhaustif des ~320 usages)

13. **Créer un plan CLOSURE/HOLIDAY exige que le plan SEASON soit pointé** (version
    choisie non-null) — reprend la règle actuelle « les plans secondaires attendent la
    validation du socle ».
14. **Toucher au socle quand des plans secondaires existent = destruction confirmée** :
    remettre le pointeur SEASON à null (re-travailler) ou le changer, alors que des plans
    CLOSURE/HOLIDAY existent → avertissement proportionné (« supprime N plannings ») puis
    **suppression de ces plans et de leurs versions** (reprend les règles 409+confirm
    reopen/validate actuelles ; « le premier plan secondaire fige le socle » reste vrai).
15. **Module matchs & radars de conflits** : ils **lisent le plan SEASON** (sa version
    choisie). Le comportement en espace de travail (pointeur null) sera **confirmé au
    cadrage du module matchs**. Consommateurs recensés : `MatchConflictDetector`,
    `FixtureConflictsController`, `CalendarEntryConflictsController`.
16. **Onboarding / mode guidé du wizard** : même règle dérivée que le cockpit (inv. 8) —
    le mode guidé s'arrête quand le plan SEASON a ≥ 1 version terminée (aujourd'hui gaté
    sur la baseline auto, qui disparaît).
17. **L'auto-★ reste, l'auto-pointeur meurt** : chaque génération COMPLETED du socle
    continue de pointer la ★ (sa photo EST la structure chargée) ; c'est l'ancrage
    automatique du **pointeur de plan** qui disparaît (inv. 2).
18. **Supprimés avec la reconstruction** (« on ne parle plus de baseline ») :
    `SetBaselineController` (désigner une référence sans valider n'a plus de sens —
    valider = pointer) ; le champ `Season.exportPdfUrl` (orphelin) — **l'export « du
    plan » = l'export de sa version choisie** (le fichier porte le **nom du Plan**,
    inv. 11 ; le lien d'export vit sur la version, comme aujourd'hui) ;
    `PurgeOverlaysCommand` → devient une purge de **plans** échus ; l'erreur 409
    `overlays_exist` renvoie des **plans**, plus des schedules.
19. **RGPD** : la table `plan` entre dans l'export club (`RgpdExportService`) et dans les
    purges (`SeasonDataPurger`, cascades) comme toute donnée club.
20. **Validation pré-solve** (`ValidateConstraintsController`) : filtre par **plan**
    (ses réglages + datées du fait), plus par entry — absorbe la dette P4-13.

## Mapping legacy → modèle (livré par la bascule du 2026-07-16)

Le legacy de la colonne de gauche **n'existe plus** : la bascule a déplacé tous ses
lecteurs et l'a supprimé dans le même commit. Le tableau se lit désormais comme une
table de correspondance pour relire du code ou des specs antérieurs.

| Legacy (supprimé) | Ce qui le remplace |
|---|---|
| Regroupement implicite `(seasonId, calendarEntryId)` | `Schedule.schedulePlanId` |
| `Season.baselineScheduleId` + auto-assignation 1er COMPLETED | `Plan(SEASON).chosenScheduleId`, posé par validation seule |
| `Season.planningName` | `Plan.name` |
| `CalendarEntry.overlayScheduleId` (1:1) | `Plan.chosenScheduleId` (N plans par entry via `Plan.calendarEntryId`) |
| `Schedule.status VALIDATED` + `ARCHIVED` (validation archive les frères) | supprimés — valider = pointer + **supprimer** les autres versions |
| `Season.liveContextScheduleId` | ★ conservée (version dont la photo est chargée) — hors du Plan |
| `Season.socleValidatedAt` (gate cockpit, sticky) | dérivé : plan SEASON a ≥ 1 version terminée |
| Réglages sur `calendarEntryId` (overrides, créneaux, datées, seed flag) | re-keyés sur `planId` — **pas encore fait, lot C** |
| « V3 » dérivé de l'ordre de création | `Schedule.versionNumber` stocké (côté serveur ; les libellés du front dérivent encore de l'ordre de création — voir Questions ouvertes) |
| `GenerateScheduleHandler` branche sur `calendarEntryId` | branche sur `plan.type` (payload engine **inchangé** — zéro engine) — **pas encore fait, lot C** |

## Conséquences

- **Refactor structurant** (axe §7.1 planning lifecycle) : ValidateSchedule/Reopen/Regenerate,
  guards read-only, cockpit (radar, DayDialog, SeasonSchedulesModal), écran planning
  (sélecteur de versions, pinceau), GenerateStep, `/api/me`, exports, purges/cascades — à
  découper **en lots** avec NR phase1 à chaque lot.
- **Table `plan`** : club_id → TenantOwnedInterface + policy RLS FORCE (couvert
  automatiquement par `RlsIsolationTest`/`TenantOwnedInterfaceCompletenessTest`).
- **Migration V0** : liberté de reconstruction hors socle. Le flux SEASON est migré
  fidèlement (Plan créé par saison, pointeur depuis l'ex-baseline validée, nom depuis
  l'ex-planningName) ; les overlays existants peuvent être reconstruits.
- **Tests & fixtures à adapter** : `ValidateScheduleTest`, `ScheduleLifecycleGuardTest`,
  `ScheduleReadOnlyGuardTest`, `RegenerateTest`, `RegenerateFromVersionTest`, les fixtures
  d'import/démo (seed d'un Plan par saison) et le smoke-solveur (create → generate → poll
  passe par le Plan) ; blocking-tests inchangés sur le fond (tenant/season isolation).
- **Engine : zéro impact** — le contrat backend↔engine (payload, `CONTRACT_VERSION`) ne
  change pas ; seul l'endroit d'où le backend dérive le type de build (plan.type au lieu
  de calendarEntryId) bouge.
- **Ce que ça exclut** : plus AUCUN nom/état de plan porté par une version ; plus de
  pointage implicite ; plus d'`ARCHIVED` (une version non choisie est supprimée à la
  validation, point).

## Alternatives considérées

1. **Nom du plan sur `Schedule.name`** (tentée, PR #214) : rejetée — une version n'est pas
   le plan ; divergences baseline/dernière-version, 16 findings en 2 rounds.
2. **Conteneurs implicites nettoyés** (nom sur Season + CalendarEntry, pas d'entité) :
   rejetée par le fondateur — pattern non identifiable, 3 types traités différemment.
3. **Duplication de la structure par version** (chaque version possède SES équipes…) :
   rejetée — explosion de données, ambiguïté « quelle version j'édite », et la photo D2
   donne déjà l'indépendance recherchée pour ~35 ko/version. Le scénario fondateur
   (V3 = 25 équipes, V4 = 29, recharger V3 → 25, revenir V4 → 29) est couvert par les photos.

## Questions ouvertes (non bloquantes, à trancher au cadrage des lots)

1. ~~Chevauchement CLOSURE × HOLIDAY~~ **Tranché (fondateur, 2026-07-12)** : rien de
   spécial — l'invariant 4 tient (jamais deux plans qui se chevauchent). Un gym
   indisponible pendant une semaine de reprise se gère **dans le plan de reprise
   lui-même** (on y redéfinit les créneaux / une contrainte) — pas de plan CLOSURE
   concurrent, pas de mécanisme dédié.
2. **Découpage hebdomadaire (E1)** — précisé (fondateur, 2026-07-12), livré dans un lot
   ultérieur mais le modèle le porte dès maintenant :
   - un découpage qui implique 2 semaines **crée les 2 plans automatiquement** ; le
     gestionnaire traite le premier ;
   - le système voit alors que le fait X a « un planning validé + un planning en cours »
     et **notifie l'action restante** (radar) ;
   - **un fait est « ajusté »** quand le/les plans qui couvrent le changement sont tous
     créés **et pointés** — état **dérivé** sur le fait (aucun flag stocké : on compte
     les plans du fait dont le pointeur est null).
3. ~~Vieilles versions supprimées à la validation~~ **Tranché (fondateur, 2026-07-12)** :
   aucun besoin de comparaison post-validation — la suppression des versions non choisies
   à la validation est confirmée (la photo de la version choisie suffit).

## Découpage & avancement de la reconstruction

La reconstruction se livre en **4 lots (A→D)**, dans l'ordre, un PR par lot, chacun avec
validation du besoin → plan → code → NR phase1 → code-review → go utilisateur.

- **Lot A — Fondations (livré 2026-07-12)** : entité `SchedulePlan` + `Schedule.schedulePlanId`
  / `Schedule.versionNumber` (nullable pendant la transition), migration RLS `FORCE` + backfill
  des données existantes, **provisioning automatique** à la création (saison → plan SEASON ;
  schedule → lien plan + numéro de version) via `SchedulePlanProvisioner` — *le plan de
  période, lui, naissait alors de la première version ; le **lot C** l'a avancé au geste
  (voir la note ci-dessous), et `linkSchedule` ne fait plus que le chercher*, API **lecture**
  (`/api/schedule_plans`, `Schedule` expose `schedulePlanId`/`versionNumber`). **Strictement
  additif** : rien de l'ancien monde n'est retiré — `baselineScheduleId`, `overlayScheduleId`,
  les statuts `VALIDATED/ARCHIVED` et `planningName` **font toujours foi**. `chosenScheduleId`
  est backfillé au snapshot mais pas encore vivant. NR : `SchedulePlanProvisionerTest`
  (+ `RlsIsolationTest` / `TenantOwnedInterfaceCompletenessTest` qui couvrent la nouvelle table).
- **Lot B1 — fondations du pointeur (ADDITIF, livré 2026-07-16)** : le pointeur est
  **maintenu** (valider le pose, rouvrir le relâche, supprimer une version le libère) et
  la **numérotation devient monotone** (`SchedulePlan.lastVersionNumber` — une version
  supprimée ne rend jamais son numéro : déféré du lot A). **Rien ne lit encore le
  pointeur pour décider** : aucun comportement ne change (aucun test existant n'a bougé).
  `/api/me` expose le plan de saison (voir le point suivant).
  **Correction d'un vrai bug du lot A (déjà sur main)** : le backfill avait seedé
  `chosenScheduleId` depuis `baselineScheduleId`, or cette baseline est posée
  **automatiquement** à la 1re génération COMPLETED — ce n'est pas une validation. La
  migration **répare** le pointeur (`chosen` := la version `VALIDATED`, sinon `null`) et
  ne supprime **rien**.

- **Modèle de lecture du plan (livré 2026-07-16, ADDITIF)** : `/api/me` expose
  `seasonPlan { id, name, chosenScheduleId, hasFinishedVersion }` — LE calendrier de base
  de la saison. **Lecture seule.** Rien ne bascule : le legacy reste exposé et reste la
  vérité. But : que la bascule n'ait plus qu'à **déplacer des lecteurs**, pas à inventer
  son contrat en même temps.
  **Le renommage part AVEC la bascule** (pas avant) : tant que `Season.planningName`
  possède le nom et le pousse sur le plan (`syncSeasonPlan` à chaque édition de saison),
  un `PUT` sur le plan serait un **second écrivain** — rename non durable, gate SEC-07
  contournable par le `PUT /api/seasons` qui écrit le même champ, et contraintes
  divergentes (varchar 180 vs 120). C'est la demi-migration en miniature. Le nom devient
  éditable sur le plan dans le commit qui **supprime** `planningName` (et qui devra
  backfiller `schedule_plan.name` depuis `planningName` une dernière fois).

- **Limites assumées du lot B1** (revues, tracées, à reprendre au lot de bascule) :
  - `SetBaselineController` déplace encore la baseline **sans toucher au pointeur** →
    les deux peuvent diverger. Sans effet aujourd'hui (**zéro appelant** : ni le
    frontend ni aucun service ne l'appelle) ; la bascule le supprime (inv. 18).
  - Si le plan disparaît en cours de requête (reset de saison concurrent), la version
    est laissée **non liée** (`schedulePlanId` null) plutôt que de lever : lever
    fermerait l'EntityManager et transformerait une création qui marchait en 500. Le
    lien est nullable pendant toute la transition ; le lot D le rendra NOT NULL et
    devra backfiller ces rares orphelins.
  - Le seed du compteur part du MAX des versions **survivantes** : les numéros des
    versions supprimées AVANT la migration sont irrécupérables (une réutilisation
    résiduelle possible, une seule fois, puis plus jamais).
  - `app:purge-overlays` attrape-et-continue : une transaction en échec ferme
    l'EntityManager et fait échouer le reste du passage. Pré-existant (le flush était
    déjà dans la boucle) ; à traiter avec la commande, hors scope B1.

- **Lot B-bascule — LA LEÇON, puis la bascule (livrée 2026-07-16)** :
  une **demi-migration est structurellement impossible**. Le legacy n'était PAS un miroir
  passif : `baselineScheduleId`/`socleValidatedAt` étaient **lus pour décider** par ~16
  fichiers — radar de conflits matchs (`FixtureConflictsController`,
  `CalendarEntryConflictsController`, `MatchConflictDetector`), routing et mode guidé
  (`AuthGuard`, `CockpitPage`, `WizardLayout`), bannière et atterrissage
  (`BaselineBanner`, `PlanningPage`, `PlanningToolbar`, `seasonPlannings`),
  `GenerateScheduleHandler` (auto-baseline). Une tentative de basculer les seules gardes
  backend en gardant le legacy vivant a produit **deux vérités divergentes** et ~15
  défauts confirmés en 4 rounds de revue (gardes destructives désarmées, baseline
  pendante ⇒ zéro conflit match détecté, V1 indélébile…). La bascule a donc déplacé
  **tous** les consommateurs et **supprimé** le legacy dans le même commit — une seule
  vérité, par construction.

  Ce qu'elle a emporté : valider = pointer **+ supprimer les autres versions** (inv. 1) ;
  rouvrir = dépointer (inv. 2) ; aucun pointage automatique (inv. 2) ; gate des plans
  secondaires et du module matchs sur le pointeur (inv. 13) ; destruction confirmée
  (inv. 14) ; déblocage cockpit sur « ≥ 1 version terminée », donc insensible au pointeur
  (inv. 8/16) ; le nom sur le plan, renommé par `PUT /api/schedule_plans/{id}` (inv. 12) ;
  suppression de `SetBaselineController` (inv. 18) ; disparition de `Season.baselineScheduleId`,
  `socleValidatedAt`, `planningName` et des statuts `VALIDATED`/`ARCHIVED`.

  **Deux gardes s'appuyaient en silence sur le statut `VALIDATED`** pour refuser d'écraser
  le planning en vigueur (`/regenerate`, `/regenerate-from`), plus le bouton « Valider » du
  front : supprimer le statut les désarmait. Elles ont été restaurées sur le pointeur dans
  le même lot — c'est exactement la classe de défaut que l'atomicité sert à rendre visible,
  et ce sont les tests qui l'ont attrapée.

  **Effet de bord assumé** : créer un plan secondaire sans socle en vigueur rend **409**
  (avant : 422 sans baseline, 409 sans socle). Les deux conditions legacy fusionnent en une
  seule, donc un seul code — celui du module matchs, même garde et même message actionnable.

  Nouveau champ de lecture **`Schedule.isChosen`** (batché par `ScheduleStateProvider`) : le
  statut ne pouvait pas répondre « cette version-ci est en vigueur » pour un overlay, dont le
  pointeur vit sur le plan de sa période et n'est pas visible depuis `/api/me`.

- **Lot C** — réglages de période & génération pilotés par plan. **C1 livré (2026-07-17)** :
  **LE PLAN NAÎT DU GESTE** — *décision fondateur, à lire comme un invariant de plein droit* :
  **un plan naît en réponse à un événement du calendrier**. Le plan SEASON naît avec la saison
  (inv. 3) ; le plan CLOSURE/HOLIDAY naît au geste « ajuster une période de vacances / un souci
  du calendrier » — c'est-à-dire à la **création de la `CalendarEntry`** — et c'est la **seule**
  façon de créer les deux autres types. Le lot A le faisait apparaître à la **première version** :
  trop tard, puisque les réglages de la période (inv. 5) se saisissent **avant** toute génération
  et doivent s'accrocher à un plan existant. Trois conséquences structurelles :
  - **`linkSchedule` ne crée plus jamais un plan de période, il le cherche.** Un second site de
    naissance laisserait passer inaperçu un plan manquant — et masquerait le vrai défaut.
  - **La naissance est atomique avec celle de l'entrée** (une transaction englobe les deux dans
    `CalendarEntryStateProcessor`). C'est la contrepartie obligatoire du point précédent : le
    self-heal de `choose()` ne peut plus réparer une période sans plan, donc une période sans
    plan ne doit pas pouvoir exister. En cas d'échec, on préfère ne pas créer la période.
  - **Toute écriture sur l'entrée réconcilie son plan** (`syncPeriodPlan`) : naissance,
    synchronisation de la **fenêtre** (le plan naissant plus tôt, ses dates deviendraient sinon
    obsolètes dès une correction — symétrique de `syncSeasonPlan` pour la saison), ou
    **suppression** si la période est rétrogradée hors closure/holiday (inv. 9). Le **nom**, lui,
    n'est jamais synchronisé : il appartient au plan (inv. 12), un second écrivain le rendrait
    non durable.

  Le flag de seed `teamSelectionInitialized` a suivi les réglages sur le plan (inv. 5).
  **Reste C2/C3** (re-keyage `calendarEntryId` → `planId`) **et C4** (génération sur `plan.type`,
  suppression de `Schedule.calendarEntryId`).
- **Lot D** — nettoyage résiduel : la bascule a déjà supprimé baseline / socle / nom legacy
  et les statuts `VALIDATED`/`ARCHIVED`. Restent : `CalendarEntry.overlayScheduleId` (pointeur
  inverse, encore utile tant que le lot C n'a pas re-keyé les périodes) et les colonnes
  `schedule_plan_id`/`version_number` à rendre `NOT NULL`. La ★ (`liveContextScheduleId`)
  **reste par décision** (inv. 17) — c'est l'auto-pointeur qui est mort, pas la ★.

### Note de nommage (résolution de collision)

Le concept est « le Plan » dans tout ce document, mais l'entité technique s'appelle
**`SchedulePlan`** (table `schedule_plan`). Le nom `Plan`/`plan` était déjà pris par le
**catalogue de facturation** (tiers d'abonnement : `maxTeams`, prix, features) ; ce catalogue
a été renommé **`SubscriptionPlan`** (table `subscription_plan`, route `/api/subscription_plans`)
dans le même lot, pour qu'aucun des deux sens de « plan » ne soit ambigu dans le code.

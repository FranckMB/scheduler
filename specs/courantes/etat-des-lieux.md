# État des lieux — ce que ClubScheduler fait aujourd'hui

> **Rôle de ce fichier (créé le 2026-07-31, refonte roadmap).** Il tient **le livré** et **les décisions fermées**.
> Son jumeau [`../evolution/roadmap.md`](../evolution/roadmap.md) ne tient plus que **l'ouvert** — ce qui reste à faire et
> mérite qu'on s'y attarde. Les deux ne se recouvrent jamais : un item livré **quitte** la roadmap et atterrit ici.
>
> **Ce fichier est une CARTE, pas une spec.** Le comportement détaillé vit dans les autres `specs/courantes/` —
> ici on dit *ce qui existe* et *où c'est décrit*. Si vous écrivez ici des règles métier, des noms de champs ou
> des cas limites, vous êtes dans le mauvais fichier.
>
> **Pourquoi garder les décisions fermées (§2)** : une décision « abandonné » vaut autant qu'une livraison — sans
> elle, le sujet se re-pose tous les trois mois. Elles ne sont pas de la dette, elles sont le contraire.
>
> Maintenu par le skill `documentation-update`, exécuté **avant chaque PR**.

---

## 1. Ce que l'application sait faire

### 1.1 Solveur & contraintes

Le modèle de contraintes est **entièrement tranché** (série ENGINE, 2026-07-03) : 4 scopes
(CLUB/TEAM/COACH/FACILITY), 4 types (HARD/PREFERRED/BONUS/LOCK), 5 familles
(TIME/DAY/FACILITY/COACH_AVAILABILITY/FACILITY_CAPACITY), liste **fermée** de types.

- **Passe unique, aucun fallback de relaxation** — INFEASIBLE rend des diagnostics nommés (équipe, salle,
  jour, heure, raison), jamais un plan aux contraintes relâchées en douce. → [ADR-0001](../../docs/architecture/adr-0001-single-pass-solve.md)
- **Budget adaptatif** 60/180/600 s selon `n_teams × n_venues`, `num_search_workers` adaptatif (1 ≤200, 8 au-delà) —
  le portefeuille à 8 workers a fait tomber un prove-stall de 612 s à ~2 s à objectif identique.
- **Salle divisible** (`Venue.canSplit`) + capacité par créneau ; le parallélisme borné passe par `FACILITY_CAPACITY`.
- **Règles implicites** appliquées sans saisie : coach principal présent à toutes les séances de son équipe,
  repos après jour de match (poids 3), regroupement même-coach-même-salle (bonus de chaînage, phase 2 plafonnée 10 s).
- **Verrous HARD pré-placés hors solveur** (`_extract_hard_locks`) : un verrou est **souverain** mais ne se tait plus —
  `diagnose_locked_slot_violations` émet un `constraint_not_honored` INFO nommant la contrainte que l'épinglage rend
  inatteignable, et le récap refuse la génération quand un verrou met un coach à deux endroits.
- **Matrice contrainte UI↔engine** gelée par un test paramétré généré. → [`constraint-matrix.md`](../../docs/architecture/constraint-matrix.md)
- **Déterminisme exposé** : `score_formula_version` + `constraint_version` dans la sortie.

→ [`engine-inventory.md`](engine-inventory.md) · [`generation-pipeline.md`](generation-pipeline.md) · [`constraint-coverage.md`](../../backend/docs/constraint-coverage.md)

### 1.2 Modèle temporel — cockpit, périodes, overlays

L'accueil **est** le cockpit : bandeau socle, calendrier d'exceptions, radar de rappels. Le calendrier est une
**projection**, jamais une matérialisation ; une occurrence n'existe qu'en delta.

- **Vacances scolaires** (zones A/B/C + 13 codes DOM/TOM) et **jours fériés** importés depuis les API publiques,
  rendus au cockpit. → [`vacances-scolaires-jours-feries.md`](vacances-scolaires-jours-feries.md)
- **Périodes d'exception** = `CalendarEntry` `kind=period` : `closure` (fermeture) et `holiday` (vacances) sont
  **générantes**, `cutoff` est une fenêtre vide purement informative.
- **Une période POSSÈDE sa grille** : ses `VenueTrainingSlot` sont copiés du modèle de saison à la naissance du plan
  (`schedulePlanId`), **jamais unis** avec les créneaux saisonniers. Les réglages par période sont des jumeaux sparse :
  `VenuePeriodOverride` (DISABLED/BLANK), `TeamPeriodOverride` (activation + séances), `ConstraintPeriodOverride` (toggle).
- **La semaine est l'unité hors socle** : une période > 7 j se découpe en semaines (une entrée enfant + un plan par semaine).
- **Rappels** : cron quotidien J-14/J-7/J-3 par email + radar in-app, jamais d'auto-action.
- **Mutualisation de créneau** (SM1+SM2 ensemble) : par réservation sur créneau à capacité 2 — **zéro changement engine**.

→ [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md) · [`types-de-planning.md`](types-de-planning.md) · [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)

### 1.3 Cycle de vie du planning (ADR-0002)

Le **plan SEASON et sa version pointée SONT le calendrier de la saison**. « Validé » se dérive du pointeur — il n'y a
plus de statut. Valider = pointer + **supprimer** les sœurs ; rouvrir = dépointer ; aucun pointage automatique.

- **Une seule porte** vers le planning de saison : tant que le plan en pointe une version, `POST /api/schedules`
  répond **409** — « la seule manière de modifier le planning de saison est Rouvrir » est vrai *par construction*.
- **Défense en profondeur** : `SocleGuard::assertSeasonPlanNotChosen` sur `generate`/`regenerate`/`regenerate-from`.
- **Confirmation forte** : rouvrir en présence de plannings secondaires exige de taper la phrase de confirmation.
- **Rouvrir ne détruit ni le passé ni les périodes en cours** (`findWithPlanNotStarted`, pivot = date de début).
- **Versions de travail** (savepoints) : `regenerate-from`, snapshots de structure, versions d'overlay, purge cron.

→ [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) · [`reprise-perimetre-engage.md`](../evolution/reprise-perimetre-engage.md) (mémoire produit)

### 1.4 Périmètre engagé

Une équipe portant **≥ 1 match, quel qu'en soit le statut**, ne peut plus être **supprimée** ni changer de **niveau** —
elle est inscrite à la fédération sous ce niveau. Rang, `isActive`, nom et créneaux restent libres. Une seule définition,
consommée par la garde d'écriture **et** par `TeamResource.isEngaged` que le front lit pour griser les deux gestes.

→ [`module-matchs.md`](module-matchs.md)

### 1.5 Module matchs

- Entités `Competition`/`Fixture` season-scoped ; amical = competition null.
- **Empreinte-temps** `MatchFootprint` (domicile 2h15, extérieur + douche + battement + trajet paramétré).
- **Catalogue-ligue** `LeagueMatchWindow` (table globale hors tenant, seed AURA, dérivation ligue par préfixe `ffbbClubCode`).
- **Radar de conflits** `MatchConflictDetector` à la volée (rien persisté) : MATCH_MATCH et MATCH_TRAINING, ce dernier lu
  dans le planning **effectif à la date** (overlay ACTIVE sinon version choisie du plan SEASON).
- **Grille week-end** UI : pose domicile clic→panneau, envelope-ligue, saisie manuelle.
- **Import FBI** (`FbiFixtureImporter`) : un export par équipe, équipe choisie à l'upload, idempotent par n° FBI.
  ⚠ Format **supposé** — jamais validé contre un vrai export (cf. roadmap P1-4).

→ [`module-matchs.md`](module-matchs.md) · [`gestion-matchs-ffbb.md`](../evolution/gestion-matchs-ffbb.md)

### 1.6 Collecte des demandes coach

Le coach émet un **souhait**, le gestionnaire **arbitre et tranche** — le lien n'écrit **jamais** de contrainte
(invariant tenu de bout en bout : zéro effet solveur).

- Todo-list « Doléances des coachs » (`CoachWish`, vues wizard + cockpit, coche « traité »).
- Campagne tokenisée **sans login** : lien personnel par coach, page publique pré-remplie, dépôt borné au périmètre du
  token, 410 à la deadline.
- Emails : envoi des liens (individuel/global), **digest quotidien 7h** conditionnel, récap final, relance des silencieux.

→ [`types-de-planning.md`](types-de-planning.md) E5

### 1.7 Onboarding, saisons, wizard

- **Wizard 6 étapes** (équipes / gymnases / coachs / contraintes / récap / génération), persistance **par entité**
  (aucun draft-blob), sticky header+footer, colonne d'étapes repliable.
- **Transition de saison** : résolution multi-saison (pivot 15 juillet), copie éditable N→N+1 avec remaps et filiation,
  saison N-1 **read-only serveur** (409), purge N-2, re-datation des événements club, alertes d'anticipation J-61/J-30/J-14.
- **Réinitialiser le club** : section danger de `/club`, confirmation nommant ce qui part et ce qui reste.
- **Autofill FFBB à la création** : `FfbbClubPopulator` remplit le club + les référentiels ligue/comité et ré-héberge
  les logos, depuis l'API publique FFBB. SSRF-safe (hosts en dur, redirections coupées), best-effort.

→ [`frontend-wizard.md`](frontend-wizard.md) · [`backend-inventory.md`](backend-inventory.md) · [`ffbb-api.md`](../../backend/docs/ffbb-api.md)

### 1.8 Sécurité, multi-tenant, exploitation

- **Isolation 3 couches** : filtre Doctrine + listener (priorité 7, après le firewall), **RLS PostgreSQL FORCE** sur les
  tables `club_id`, scoping applicatif pour Club/User. → [`TENANT.md`](../../backend/docs/TENANT.md) · [`rls.md`](../../docs/security/rls.md)
- **Superadmin SA0** : identité globale séparée, firewall stateful, TOTP obligatoire, throttle par IP, session-CSRF,
  audit fail-closed. Console en 6 onglets + monitoring santé/usage + catalogue fermé d'actions support.
  → [`superadmin-auth.md`](superadmin-auth.md)
- **RGPD** : effacement (anonymisation + purge différée 30 j), portabilité (exports JSON), rétention auto,
  audit trail append-only 12 mois, consentement + page confidentialité. → [`rgpd.md`](../../docs/security/rgpd.md)
- **Throttle API par utilisateur** sur `^/api` + limiteurs par IP sur les portes publiques.
- **Concurrence** : verrou de génération par club (Redis) + `asyncio.Lock` engine.
- **Production** : stack `docker-compose.prod.yml` à images immuables, déploiement une commande (tag `v*` → ghcr.io → SSH,
  dump pré-migration obligatoire), backups `pg_dump` pilotés par l'activité avec `restore-check` prouvé, Sentry 3 zones.
  → [`prod-stack.md`](../../docs/ops/prod-stack.md) · [`deploy.md`](../../docs/ops/deploy.md) · [`backup-restore.md`](../../docs/ops/backup-restore.md)

### 1.9 Transverse

- **Exports** PDF / PNG / Excel, chacun sur 1 page A4 paysage, tous gymnases ou un seul.
- **Push temps réel** Mercure (statut de génération).
- **Identité visuelle par club** : accent distinct clair/sombre + logo + palette extraite, contraste AA par thème.
  → [`identite-visuelle-club.md`](identite-visuelle-club.md)
- **Simulateur d'horloge** (dev seulement) pour rejouer les bascules annuelles.
- **Console superadmin** : stats d'usage append-only, board de fraîcheur des référentiels, alerting sur transition.

### 1.10 Tests

Gate bloquant CI (liste canonique : **CLAUDE.md §4**, jamais recopiée ici), e2e Playwright 9/9 en CI sur le parcours
produit réel (register → wizard → génération CP-SAT → validation → cockpit), gate perf `engine-perf` sur main,
golden fixtures + invariants Hypothesis côté engine, Vitest + RTL côté frontend.

→ [`testing-strategy.md`](../../docs/testing/testing-strategy.md)

---

## 2. Décisions fermées — ne pas re-poser

> Chacune a été tranchée **contre** une option qui paraissait évidente. Rouvrir demande un fait nouveau, pas une intuition.

| Sujet | Décision | Pourquoi |
|---|---|---|
| **Solliciter les coachs au-delà de 60 j** | **Abandonné** (fondateur 2026-08-01) | « On ne les sollicite pas au-delà de 60 j — en général ça se fait 3 semaines avant les vacances. » Soulevé par la revue #344 : l'horizon 60 j du radar masque aussi les doléances, `RadarCoachWishAction` n'étant rendu nulle part ailleurs. Le cas n'existe pas dans l'usage réel : **aucun second point d'entrée à créer**. Seul demeure le filet qui empêche de faire disparaître un travail ENGAGÉ — une vacance qui porte déjà une campagne garde sa carte, son badge « x à traiter » n'ayant pas d'autre surface |
| **Relaxation automatique** (D4) | **Abandonnée** | Un plan aux contraintes relâchées en douce est pire qu'un échec expliqué (ADR-0001) |
| **`allow_shared_court` par équipe** | **Abandonné** | Le partage passe par `canSplit` + capacité créneau. ⚠ Conséquence assumée : la capacité dit *combien*, jamais *avec qui* — le besoin est rouvert sous une **autre forme** (roadmap P3-8) |
| **Verrou HARD sur créneau divisible** | **Comportement assumé** | Une réservation HARD prend le créneau entier même si `capacity>1` ; partager = co-épingler explicitement les N équipes (ALIGN-07) |
| **Lock SOFT** | **Abandonné** | L'engine n'a jamais lu la pénalité soft — `ManualEditController` refuse SOFT par un 400. La règle « ≤30 min = SOFT auto » tombe avec lui |
| **`periodType=mutualisation` + fusion `team_ids[]` moteur** | **Abandonnés** | La réservation sur créneau à capacité 2 couvre le besoin sans toucher au solveur |
| **Draft-blob serveur du wizard** | **Abandonné** | La persistance par entité couvre déjà le besoin ; un blob serait une 2ᵉ source de vérité |
| **Import CSV salles & coachs** | **Abandonné** | 1-5 salles, ~10 coachs, aucun format standard — la saisie manuelle suffit |
| **Wizard de revue guidée à la transition de saison** | **Abandonné** | Le gestionnaire connaît son club avant l'outil ; l'édition libre + récap suffit. À rouvrir sur signal pilote |
| **Dimanche dans la grille** | **Abandonné** | ≈95 % des clubs amateurs ne s'entraînent pas le dimanche — 7 colonnes ne se justifient pas pour les 5 % |
| **Partition + purge 6 mois de `solver_metrics`** | **Abandonnées** | Append-only, rétention ≥ 13 mois : la purge contredisait les tendances d'usage sur une saison |
| **`migration_user`** (2ᵉ rôle DB) | **Supprimé** | Créé avec `GRANT ALL`, utilisé par aucune connexion, et inutilisable sous `FORCE RLS` sans `BYPASSRLS`. La séparation v3 §10.1 est tenue autrement : runtime = `app_user` (zéro DDL), ops = `clubscheduler` |
| **Rattrapage de données des sœurs héritées d'avant P2-7** | **Écarté** | V0, pas de migration, les fixtures repartent de zéro |
| **Volet matchs de la réouverture de socle** | **Abandonné** | Le module matchs est déjà gaté sur le pointeur : rouvrir le rend inaccessible **sans rien détruire** ; supprimer les matchs `UNPLACED` obligerait à refaire l'import FBI pour un résultat déjà acquis |
| **Suspension de club + approbation fallback** (console SA) | **Différées** au premier cas réel | Métier non tranché |
| **Restart de conteneur depuis la console admin** | **Retiré** | `docker.sock` non transposable en prod |
| **Scoper le SELECT RLS de `coach_wish_token`** | **Non fait, tracé** | La page publique n'a pas de JWT et doit lire le token pour découvrir le `club_id`. Documenté + gardé par un test d'égalité stricte des policies (roadmap SEC-12 pour le résiduel) |
| **husky + lint-staged** | **Retirés** | Le hook n'avait jamais tourné, et un hook Node contredit « l'hôte n'a besoin que de Docker » |
| **Split `manualChunks` vendor** | **Écarté** | Il faisait *grossir* l'entrée — rolldown extrait mieux seul |

---

## 3. Traces datées des livraisons

> Une livraison laisse **une ligne** ici (date · id · sujet · où c'est documenté). Le comportement, lui, vit dans la
> spec courante pointée. Les ids sont **stables et jamais réutilisés** — un trou dans la numérotation du backlog
> signifie « livré », pas « oublié ».

| Date | Id | Sujet | Documenté dans |
|------|----|-------|----------------|
| 2026-08-01 | P3-14 | **Doléances : l'ordre des listes, et un coach qui encadre vraiment l'équipe** — les deux filtres de la todo-list sortaient dans l'ordre BRUT de l'API alors que les regroupements existaient déjà ailleurs (staffing pour les coachs, RANG pour les équipes) : réutilisés, pas réinventés. À la saisie, le coach est borné aux **MAIN de l'équipe** (décision fondateur) — le select listait tout le club quand le défaut pré-sélectionne déjà le MAIN, donc « U18F1 — Emerick » se consignait sans que rien ne l'attrape. Une équipe sans coach principal sort du formulaire (« pas possible d'avoir une doléance sans coach ») : bouton désactivé et message, plutôt qu'un select vide. ⚑ **Deux asymétries voulues, héritées de la leçon #342** : le FILTRE garde toutes les équipes (il sert à LIRE, pas à créer) et le select conserve la valeur courante d'un coach qui n'encadre plus l'équipe, marquée — masquer n'est légitime que pour un CHOIX. Six règles, six chutes prouvées | [`types-de-planning.md`](types-de-planning.md) E5 |
| 2026-08-01 | P3-13 (+ P3-15 c, P3-11) | **Le radar ne montre que l'avenir ACTIONNABLE** — une to-do n'est pas un inventaire. (a) **Horizon 60 j** sur les vacances (les fériés avaient le leur depuis toujours) : en été, Toussaint et Noël s'affichaient, « TROP loin pour que je m'en occupe de suite ». ⚠ Il ne masque que les vacances **intactes** — dès qu'un plan existe, la période reste en carte « en cours » (non-régression épinglée : cacher un travail commencé serait pire que le bruit corrigé). (b) **Les semaines RÉVOLUES sont écartées** de la couverture, des semaines offertes à la création et de la sollicitation des coachs (« 0/7 couvertes » alors que 3 étaient derrière). La règle vit en fonctions pures `isActionableWeek`/`actionableWeeks`, à côté de `periodAdjustWeeks` qui était **déjà** la source unique lue par le radar ET la campagne — d'où un seul foyer, pas deux implémentations. ⚑ **Le critère a changé en revue** : le premier jet disait « la semaine n'a pas commencé » (`monday > today`) et rendait une fermeture du mercredi implanifiable dès le lundi, une collecte impossible sur une vacance démarrant un samedi, et une semaine rognée par un début de saison un mardi « commencée » la veille. Critère retenu : `endDate >= today` — le même test que le radar applique déjà aux périodes. Leçon : **« commencé » n'est pas « fini »**, et une règle de masquage doit se juger sur ce qu'elle rend inatteignable, pas sur ce qu'elle range. ⚑ **Le round 2 a montré la moitié manquante** : la règle n'avait pas été propagée à tous ses sites — le picker cochait encore des semaines révolues, dont la création produisait un plan que le radar filtrait ensuite partout (un artefact sans carte ni retour), et le filtre des vacances gardait `startDate >= today`. D'où `periodWeeksToAdjust`, point d'entrée unique de toute liste de semaines OFFERTE. Corollaire de §7.2 pt 1 : changer une règle, c'est la greper. (c) **Repli CIBLÉ** : seule la carte de couverture (N puces de semaine) démarre repliée — et les **doléances restent hors du repli**, leur badge de suivi n'existant nulle part ailleurs. Pour la même raison, une vacance qui porte une campagne **échappe à l'horizon** : sinon celui-ci effaçait la seule surface capable d'annoncer des souhaits en attente. Arbitrage pris à l'implémentation contre le plan initial — tout replier mettait CHAQUE geste du radar à deux clics (13 tests d'action tombaient) sans raccourcir ce qui est long. (d) **P3-11** : squelette pendant que les plans/versions/impacts arrivent — le panneau restait nu, et un cadre « À traiter » vide se lit comme « rien à faire ». ⚑ **L'horloge front est née de ce lot** (`shared/lib/clock.ts`, amorce de P4-16) : point de passage unique du « aujourd'hui », décalable en dev par `?today=`, lecture derrière `import.meta.env.DEV` donc absente du bundle de prod. Elle a payé immédiatement — deux tests dataient leurs fixtures en dur et l'un d'eux était **déjà** devenu faux avec le temps sans que personne le voie | [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md) §5.1 |
| 2026-07-31 | P2-15 | **L'UI d'une période décrit LA PÉRIODE** — le récap annonçait « 49 équipes » quand 6 étaient cochées, l'écran de génération montrait tous les gymnases alors qu'un seul sert, et les sélecteurs proposaient encore un gymnase désactivé. Le backend filtrait déjà correctement (`PeriodConstraintSelector`, P2-14) : c'est l'UI qui lisait les listes de SAISON. Deux hooks de lecture (`useActiveTeams`/`useActiveVenues`) APPLIQUENT les overrides déjà chargés — ils n'implémentent aucune règle métier, celles-ci restent serveur. Une équipe en pause reste **listée barrée** au récap (on doit voir ce qu'on a mis de côté) ; un gymnase désactivé **disparaît** des sélecteurs (décision fondateur) et reste barré dans l'onglet Gymnases. FAIL-CLOSED : lecture d'overrides ratée ⇒ on ne masque rien et on le dit. ⚑ **La règle vit en fonctions PURES** (`lib/activeLayer.ts`) parce que la mettre dans les hooks la laissait non gardée : les tests d'écran les mockent, et la neutraliser laissait les 697 tests verts — mesuré, pas supposé. **Deux rounds de revue ont redressé le lot** (20 défauts confirmés, dont la moitié nés des correctifs du round 1) et en ont tiré trois règles durables : (1) **CHOISIR / NOMMER / ATTEINDRE** — un sélecteur n'offre que l'actif, un libellé (et la valeur courante d'un formulaire d'édition) se construit sur la liste COMPLÈTE, et un geste correctif reste joignable mais **fermé au geste fautif** (le gymnase désactivé qui porte une réservation : on y retire, on n'y ajoute plus) ; (2) **on filtre ce qui est OFFERT, jamais ce qui EXISTE** — masquer les séances déjà placées faisait diverger l'écran de l'export (rendu serveur), donc le PDF remis aux coachs ; elles restent, annoncées, avec invitation à régénérer ; (3) **charger ≠ échouer** — replier `loading` sur `failed` faisait crier « n'a pas pu être lu » à chaque ouverture, ce qui apprend à ignorer le bandeau. Le **verdict** (`useStepValidation`) compte désormais les mêmes actifs que les compteurs : sans ça une période toutes équipes en pause affichait « Équipes 0 » et « Tout est prêt » ensemble, bouton Générer ouvert | [`frontend-wizard.md`](frontend-wizard.md) |
| 2026-07-31 | P2-9 (PR A — **P2-9 SOLDÉ**) | **Le volet capacité du récap, dans les deux sens** — le dernier 🔴 du backlog se ferme. Avant génération, le récap annonce la sous-capacité (« Vos équipes demandent X séances, vos gymnases n'en offrent que Y : **au moins Z** ne pourront pas être placées » — condition nécessaire, jamais suffisante) ET le surplus (décision fondateur : dès le premier créneau en trop, ton informatif — agrandir des créneaux, en ajouter, ou augmenter les séances). Les nombres viennent du PAYLOAD du solveur (`buildForClubSeason`/`buildForPeriodPlan`, lecture seule depuis P2-9ter) — jamais d'un recalcul à la main, la faute qui a tué trois tentatives. Aucun blocage, zéro changement frontend (rail #337). NR `RecapCapacityWarningTest` (phase1 + step CI) : borne offre=demande silencieuse, cas période prouvant le bon payload (surcharge de séances + grille copiée du plan), chutes prouvées. Le fichier de détail `p2-9-verrous-et-alertes.md` est **gradué** (volets 1, B, C, ter, bis et A tous livrés) | `constraint-coverage.md` · [`generation-pipeline.md`](generation-pipeline.md) |
| 2026-07-31 | P2-14 | **Le gate pré-solve ne recopie plus le builder** : la sélection « quelles contraintes partent au solveur pour cette période » vit dans **`PeriodConstraintSelector`** (source unique), consommée par le payload (`buildForPeriodPlan`, qui ne fait plus que sérialiser) ET par le récap (`ValidateConstraintsController`, devenu un adaptateur) ; la résolution tag→équipes, qui existait en TROIS exemplaires, ne vit plus que dans `TeamTagResolver`. **Deux divergences réelles alignées au passage** : une contrainte datée d'une équipe désactivée restait validée par le gate alors que le payload la filtrait, et une CLUB+tag HARD à gymnase dédié toutes-taguées-en-pause était sortie du gate alors que le payload émet encore ses lignes « interdit hors tag ». NR `PeriodGatePayloadParityTest` (phase1 + step CI) : parité payload↔gate sur les ids, chute prouvée sur les deux divergences | CLAUDE.md §4 · [`generation-pipeline.md`](generation-pipeline.md) |
| 2026-07-31 | P2-20 + P4-41 | **Le nom vient du plan AFFICHÉ** — le stylo « Renommer » passait `me.seasonPlan.id` en dur : renommer un planning de période renommait celui de la **saison** (constaté en usage club). Le titre, le nom de fichier exporté et la popup de suppression avaient la même racine, et la liste des plannings affichait le nom de la **version** — or toute version de période créée hors wizard naît « Version de période », si bien qu'un planning renommé se relisait sous ce libellé technique. Une ligne de la liste EST un plan : elle porte donc son nom, comme le socle le faisait déjà (ADR-0002 inv. 12). **Au passage, les libellés par défaut se lisent en clair** : une fenêtre couvrant exactement une semaine calendaire donne « Vacances d'été — Semaine du 17 août 2026 », toute autre garde ses deux bornes (la résumer à son lundi annoncerait 7 jours pour 14) ; le préfixe « Planning de » est tombé — dans une liste de plannings il ne distinguait rien. NR mutation-testés des deux côtés (3 tombent sans le correctif, 1 garde la non-régression du plan de saison) | [`types-de-planning.md`](types-de-planning.md) E6 |
| 2026-07-31 | P2-9 PR B+C | **Un verrou ne peut plus dédoubler un coach** : le récap refuse la génération (`CoachDoubleBookingDetector`, clé `blockers`), et la modale de réservation refuse **au clic** avec le motif — elle devient transactionnelle au passage (sélectionner une équipe écrivait immédiatement). Parité PHP/TS par cas de test identiques | code + NR `CoachDoubleBookingTest` (phase1) |
| 2026-07-31 | P2-9ter | **Une source de payload en LECTURE SEULE** (prérequis de PR A) : le build n'écrit plus (`syncTeamTags` sorti de `serializeTeam`), chemin scalaire `buildForPeriodPlan` dont `buildForOverlay` n'est qu'un adaptateur, cache scopé par saison et purgé par TAG. ⚑ A révélé que `teams[].tags` sortait **toujours vide** (49/49) et que `TeamTagSyncListener` **n'écrivait rien** (les `persist` en `postFlush` ne partaient jamais) | code + 5 NR (axe generation pipeline) |
| 2026-07-31 | P2-9bis | **Le socle en vigueur, invariant explicite sur les quatre portes** : `SocleGuard::assertSeasonPlanNotChosen` en défense en profondeur + `SeasonPlanInForceTest` (phase1) qui épingle la propriété émergente. Le defect présumé ne s'est jamais confirmé — l'invariant était déjà tenu, mais par accident de deux mécanismes | [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) |
| 2026-07-31 | SEC-12 (volet test) | **Les policies RLS ne peuvent plus dériver en silence** : `RlsIsolationTest` compare par égalité stricte chaque policy des tables `club_id` au canon lu à l'exécution ; un `USING (true)` copié-collé fait rougir le gate bloquant. Allowlist bidirectionnelle (une dérogation périmée rougit aussi) | [`rls.md`](../../docs/security/rls.md) §Exceptions |
| 2026-07-30 | P2-7 | **« Modifier mon planning de saison » — le geste** : une seule porte (409 sur `POST /api/schedules`), confirmation par phrase à taper, rien du passé ni des périodes en cours n'est détruit | [`reprise-perimetre-engage.md`](../evolution/reprise-perimetre-engage.md) · NR `SeasonVersionUniquenessTest` |
| 2026-07-27 | P4-32 | **Le gate Rector bloque enfin** — il tournait sans être dans les *required status checks*. ⚠ Le dépôt porte **deux** systèmes de protection en parallèle (ruleset « Basic » + protection de branche classique) ; c'est la seconde qui porte les checks | réglage dépôt · CLAUDE.md §4 |
| 2026-07-27 | P4-5 + P4-30 | **Deux dettes backend soldées** : les imports ne relaient plus `$e->getMessage()` en se fiant à la classe de l'exception (une `PhpSpreadsheet\Reader\Exception` étend `RuntimeException` — un chemin de fichier temporaire fuyait dans le toast), et les ancres `schedulePlanId` de `Reservation`/`VenueTrainingSlot` gagnent une FK CASCADE. ⚠ Doctrine ignore ces FK (colonne `guid` nue) et `migration-diff` propose de les DROPper — `SchedulePlanAnchorCascadeTest` échoue si quelqu'un committe ce DROP | code · migration `Version20260727120000` |
| 2026-07-27 | P4-31 | **Stack Symfony réaligné sur la LTS 7.4** — 19 paquets tournaient en 8.0.x sous des bundles 7.4.x. La vraie échappatoire de Flex est le **lock**, pas la require directe. Correctif = `composer update`, jamais un pin. NR `SymfonyStackAlignmentTest` lit l'**installé**, pas le lock | CLAUDE.md §5 |
| 2026-07-26 | P4-24 (+ correctifs) | **Style Rector adopté et bloquant** (job dédié sans `needs`), CS-Fixer aligné (`import_symbols`), périmètre élargi à `tests/`. ⚠ `RemoveEraseCredentialsRector` avait supprimé `User::eraseCredentials()` en lisant la version *installée* de `security-core` — méthode restaurée sur `User` **et** `SuperAdmin`, NR `UserInterfaceContractTest` | `.github/workflows/ci.yml` · CLAUDE.md §4/§5 |
| 2026-07-26 | P4-17 | **Le spécifique-basket rangé couche par couche** — sous-dossiers `Basketball/` locaux à chaque couche, jamais un dossier transverse. Le vrai risque n'était pas les `use` mais le routage Messenger par FQCN | code · specs `frontend-wizard` / `backend-inventory` |
| 2026-07-26 | P4-6 + P4-11 | **Bundle découpé par route** : entrée 834 kB → 302 kB (−64 %) par `lazy` de react-router. husky/lint-staged retirés | code |
| 2026-07-26 | P4-20 + P4-1 | **L'ancre de période dit toujours son état** — union discriminée `PeriodAnchor` (le booléen `ready` recouvrait 3 situations et s'oubliait : 4 fois en 4 revues) + `PeriodAnchorGate`. Les listes de période ne sont plus coercées en `[]` : un GET raté rendait une grille **vide et crédible** | code + 5 NR |
| 2026-07-26 | P4-21 + P4-22a + P4-29 | NR C3 passé par le chemin de prod · **UUID validés en écriture ET en lecture** (400 au lieu d'un 500 PostgreSQL qui avortait la transaction de test) · sonde mailpit fail-closed | code + `UuidQueryParamGuardTest` |
| 2026-07-26 | SEC-08 résiduel | **Un 5xx ne parle plus au nom du serveur** : `errorMessage` ne reprend le corps que pour les 4xx | code + tests |
| 2026-07-26 | P0-2 (deploy) | **Déploiement une commande** : tag `v*` → 6 images ghcr.io → SSH `remote-deploy.sh` fail-closed (dump pré-migration obligatoire, migrate via la nouvelle image avant bascule, pin VERSION en dernier). Rollback = redéployer le tag précédent | [`deploy.md`](../../docs/ops/deploy.md) |
| 2026-07-25 | P0-2 + INF-03 | **Stack de production** : compose autonome à images immuables, stage `prod` php, edge sans `/engine/`, init postgres paramétré, `mem_limit` + rotation logs | [`prod-stack.md`](../../docs/ops/prod-stack.md) |
| 2026-07-25 | ALIGN-07 | **Verrou HARD sur créneau divisible = comportement assumé** (pas un bug), figé par 3 NR sémantiques | [`engine-inventory.md`](engine-inventory.md) |
| 2026-07-25 | P2-1 (#10) | **Doléances coachs C1/C2/C3** : todo-list · collecte tokenisée sans login · emails + digest + relance | [`types-de-planning.md`](types-de-planning.md) E5 |
| 2026-07-25 | SA (console) | **Console super-admin en 6 onglets** + monitoring (`/api/admin/health` étendu, heartbeats, 3 journaux read-only) | [`superadmin-auth.md`](superadmin-auth.md) |
| 2026-07-25 | FF#19 (lot C) | **Autofill FFBB club/ligue/comité + logos** à la création (async au verifyEmail) + import manuel management-gaté | [`ffbb-api.md`](../../backend/docs/ffbb-api.md) |
| 2026-07-25 | P2-3 (D1→D3quater) | **Versions de l'espace de travail** (savepoints) : UX versions, snapshots de structure, `regenerate-from`, versions d'overlay + purge. *(D4 « travailler sur cette version » reste ouvert.)* | [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) |
| 2026-07-24 | #8 | **La période possède sa grille** : créneaux copiés à la naissance du plan (`schedulePlanId`), jamais d'union avec le saisonnier ; jumeaux sparse `TeamPeriodOverride` / `ConstraintPeriodOverride` | [`types-de-planning.md`](types-de-planning.md) · ADR-0002 inv. 5 amendé |
| 2026-07-19 | P2-5 (E3+E4+E6) | **Clôture des écarts types-de-planning** : défaut d'équipes conscient du type, séances/équipe, noms de plan par défaut côté serveur (source unique) | [`types-de-planning.md`](types-de-planning.md) |
| 2026-07-18 | P2-5 (E1+E2, 5b) | **Plans de période à la semaine** (une entrée enfant + un plan par semaine) et **granularité JOUR des fermetures** (incident ∩ fenêtre) | [`types-de-planning.md`](types-de-planning.md) |
| 2026-07-18 | P0-3 / P0-4 | **Backups PostgreSQL** pilotés par l'activité + preuve `restore-check` · **Observabilité** Sentry 3 zones, DSN-vide-inactif | [`backup-restore.md`](../../docs/ops/backup-restore.md) |
| 2026-07-18 | SA2-stats / SA4 v1 | Télémétrie d'usage append-only + section « Usage produit » · catalogue fermé d'actions support par club | [`superadmin-auth.md`](superadmin-auth.md) |
| 2026-07-17 | P3-12 | **Doc ops : tunnel Cloudflare pour les démos** — ⚠ le tunnel ne dispense pas d'avoir la machine allumée, et l'URL publique n'a **aucune auth** devant elle | [`demo-tunnel-cloudflare.md`](../../docs/technique/demo-tunnel-cloudflare.md) |
| 2026-07-16 | P2-6 (lot B) | **Pattern « Plan » — la bascule** : le plan SEASON et sa version pointée SONT le calendrier ; legacy droppé dans le même lot. **Leçon tracée à l'ADR** : le legacy n'est pas un miroir passif — une demi-bascule crée deux vérités divergentes (~15 défauts en 4 rounds) | [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) |
| 2026-07-16 | P2-7a | **Périmètre engagé** : une équipe qui joue ne peut être ni supprimée ni changer de niveau. L'import FBI **EST** l'engagement (filtrer sur `PLACED` rendait la garde inerte au moment précis où elle doit mordre) | [`module-matchs.md`](module-matchs.md) |
| 2026-07-11 | P0-1 (FF#6) | **RGPD socle** (5 PRs) : effacement, portabilité, rétention auto, audit trail append-only, consentement | [`rgpd.md`](../../docs/security/rgpd.md) |
| 2026-07-11 | P0-5 | Ids de créneau par-schedule (vol de créneau inter-version) | ADR-0002 · `ScheduleResultImporterCrossVersionTest` |
| 2026-07-08 | — | **Exports étendus** PDF / PNG / Excel sur 1 page A4 · **simulateur d'horloge** dev · **accent par thème** | [`identite-visuelle-club.md`](identite-visuelle-club.md) |
| 2026-07-07 | audit P0.1 / P0.2 | **Matrice contrainte UI↔engine** (44 tests générés) · **typage cœur engine** · **PERF prove-stall 612 s → 2 s** · **e2e Playwright en CI** · transition de saison P2 (re-datation + alertes) | [`constraint-matrix.md`](../../docs/architecture/constraint-matrix.md) |
| 2026-07-06 | cockpit A/B/C | **Le cockpit devient l'accueil** : calendrier d'exceptions, overlays de période, radar, rappels cron | [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md) |
| 2026-07-06 | transition P1 | **Multi-saison** : `SeasonResolver` (pivot 15 juillet), copie N→N+1, readonly serveur N-1, purge N-2 | [`backend-inventory.md`](backend-inventory.md) |
| 2026-07-03 | série ENGINE | **Modèle de contraintes tranché** + contraintes silencieusement ignorées rendues effectives (indispo coach, FACILITY_CAPACITY, LOCK, allowedDays), solve sorti de l'event loop, gate perf | [`engine-inventory.md`](engine-inventory.md) |

### Réf historiques

Les identifiants `FF#n` (ex-`features-futures.md`), `BG G#n` (ex-`backend-gaps.md`), `SEC-n`, `ALIGN-n` et `ENG-n`
viennent de fichiers absorbés ou d'éditions d'audit. Ceux qui portent encore un item **ouvert** sont cités sur sa
ligne de roadmap ; les autres appartiennent à des sujets livrés ou tranchés et vivent dans l'historique git
(`features-futures.md`, `backend-gaps.md`, `contraintes-modele-cible.md`, `technical-debt.md`) et dans les éditions
de `specs/audit/`. Livrés notables non détaillés ci-dessus : **SEC-07** (gate rôle management sur les écritures du
cockpit), **SEC-11** (throttle API par utilisateur), **BG G1/G2** (draft serveur — abandonné), **BG G3** (fermetures
de salle), **BG G4/G5/G6** (doc OpenAPI + naming snake_case), **FF#2** (périodes d'exception), **FF#3** (transition de
saison), **FF#7** (`solver_metrics`), **FF#9** (invalidation de cache ciblée), **FF#13** (e2e Playwright), **ALIGN-06**
(poids `spacing` de la formule de score — `engine/app/solver/objective.py` fait foi).

### Dette soldée (avant la fusion du 2026-07-11)

B1 (Rector 8.4) · B2 (PHPUnit unifié) · B3 (TenantCacheIsolationTest réel) · B6 (attributs PHPUnit 11) ·
B7 (tag LOISIR fixture) · E1-E6 (aliases morts, helpers dédupliqués, ADR-0001, doc timeout, TODOs PREFERRED TIME) ·
DP1 (contacts FFBB sur `club`, soldé avec P0-1) · P4-13/P4-14/P4-15 (validation pré-solve des overlays, `expandClosedVenues`,
fidélité checklist) · P4-19 (portée de la suppression d'une période) · P3-6 (`solver_metrics`).
Détail dans l'historique git de `docs/technical-debt.md` (supprimé le 2026-07-11) et de `roadmap.md`.

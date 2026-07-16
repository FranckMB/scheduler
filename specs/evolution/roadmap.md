# Roadmap — évolutions extraites des specs initiales

> **Règle du dossier `specs/evolution/`** : ce fichier est l'**index unique** — toute évolution, gap ou idée future y laisse une trace (une ligne de tableau au minimum). Un fichier à côté n'existe que pour **préciser un besoin** trop gros pour une ligne, et doit être **référencé depuis cette roadmap**. Quand un sujet est livré ou tranché, sa ligne est mise à jour ici et le fichier de détail devenu sans objet est supprimé (l'historique vit dans git). **La ligne mise à jour reste une ligne** (statut + pointeur) : le comportement livré se documente dans `specs/courantes/` (graduation, skill `documentation-update`), jamais dans la note roadmap.
> **Fichiers de détail actifs** : [`accueil-cockpit-temporel.md`](../courantes/accueil-cockpit-temporel.md) (§2 — modèle temporel, cockpit, périodes) · [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md) (§2 — plan de vacances éditable + collecte des demandes coach) · [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md) (§6 — bridage du plan gratuit) · [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) (§8 — placement des matchs, radar de conflits, dérogations — FF#21/FF#19) · [`enregistrement-ffbb.md`](enregistrement-ffbb.md) (§3 — refonte enregistrement : vérification FFBB + approbation) · [`import-ffbb-autofill.md`](import-ffbb-autofill.md) (§8 — autofill club/ligue/comité + logos, scraping cache-first — lot C) · [`console-superadmin.md`](console-superadmin.md) (§9 — monitoring & exploitation cross-tenant) · [`compte-demo.md`](compte-demo.md) (§3 — mode/compte démo, levier de vente). *(`transition-de-saison.md` supprimé le 2026-07-08 : P1 + P2 livrés, le seul reliquat — report des tags custom — vit dans la ligne §3 et la dette `TeamTagService` §9.)*
> **But** : base de réflexion écrite pour les prochaines features. Extraction des capacités décrites dans `specs/initiales/` (ClubScheduler v3 + Spécification des contraintes v2), confrontées à l'état **livré**.
> **Statut** : ✅ livré · 🟡 partiel · ⬜ à faire. Les réf `FF#n` et `BG G#n` sont les identifiants **historiques** des anciens fichiers `features-futures.md` / `backend-gaps.md`, absorbés dans cette roadmap le 2026-07-05 (fichiers supprimés) — conservés pour la traçabilité.
> **Effort** (annoté sur les items actionnables, pour arbitrer sans re-poser la question) : **🟢 léger** (≈ ≤2 j, ciblé, peu de risque) · **🟡 moyen** (quelques jours, plusieurs fichiers/zones) · **🔴 lourd** (structurant / semaine+ / nouvelle archi ou dépendance de zone). L'effort estime le **coût de mise en œuvre**, pas la valeur produit.
> **Sources** : `initiales/ClubScheduler_v3.md` (réf `v3 §x`), `initiales/ClubScheduler_Specification_des_contraintes_v2.md` (réf `contraintes-v2`). Ces specs sont **figées** — ne pas les modifier.

Ce fichier est le **document de suivi unique** (fusion du 2026-07-11 : `backlog-priorise.md` + `docs/technical-debt.md` absorbés — même fonction, un seul endroit) : la **carte** de la vision (§1-§10), la **coupe priorisée** effort × impact (§Backlog, cap commercialisation mi-2027) et la **dette technique ouverte** (§Dette). Un item livré quitte le backlog et laisse une **trace datée** dans la carte ou §Livrés ; les ids P*x-y* sont stables, jamais réutilisés.

---

## 1. Contraintes & solveur (cœur métier)

Le modèle cible de contraintes (scopes, types de règles, familles, liste fermée de types) est **entièrement tranché** (série ENGINE, 2026-07-03) — l'ancien doc de détail `contraintes-modele-cible.md` est absorbé ici (décisions dans le tableau ; historique dans git). Restent ouverts uniquement les items ⬜/🟡 en fin de tableau (ajoutés après la clôture).

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Redesign contraintes : 5 scopes (CLUB/CATEGORY/TEAM/COACH/FACILITY) + types HARD/PREFERRED/BONUS/LOCK + 7 familles + liste fermée de types | ✅ | contraintes-v2 | **Tranché (liste fermée actée dans le code)** : scopes CLUB/TEAM/COACH/FACILITY (`ConstraintScope`), types HARD/PREFERRED/BONUS/LOCK (`ConstraintRuleType`), familles TIME/DAY/FACILITY/COACH_AVAILABILITY/FACILITY_CAPACITY (`ConstraintFamily`). Scope CATEGORY + familles ALLOCATION_PRIORITY/DISTRIBUTION **non retenus** |
| Salles **divisibles** + `max_parallel_trainings` (FACILITY_MAX_PARALLEL_TRAININGS) | ✅ | contraintes-v2 | **Tranché** : « Terrain divisible » (`Venue.canSplit`) + capacité par créneau (`VenueTrainingSlot.capacity`, forcée à 1 si non divisible — garde-fou builder S4) ; le parallélisme borné passe par la famille **`FACILITY_CAPACITY`** (`maxTeams` par créneau) plutôt qu'un type `max_parallel_trainings` dédié |
| `allow_shared_court` par équipe (jeunes partagent, seniors non) | ✅ | contraintes-v2 | **Tranché : abandonné** — non retenu au modèle (le partage de terrain passe par `canSplit` + capacité créneau, pas par une règle par équipe) |
| `CLUB_YOUNG_MAX_START_TIME` / `TEAM_MAX_START_TIME` (« jeunes pas après 19h30 ») | ✅ | contraintes-v2 | **Tranché** : couvert par la famille **TIME** (`maxStartTime`/`minStartTime`, appliqué dans `constraints.py`) ciblée via le tag `JEUNE` (`TeamTagService`). Décision : pas de type dédié 1ère classe |
| `CLUB_TRAINING_DURATION_BY_CATEGORY` (durée par défaut par catégorie) | ✅ | contraintes-v2 | **Tranché : abandonné** — la durée reste saisie par créneau, pas de défaut par catégorie |
| `CLUB_SLOT_GRANULARITY` (granularité 30 min) — **discordance** : v3 grille 15 min vs contraintes-v2 30 min | ✅ | contraintes-v2 / v3 §11.1 | **Tranché : granularité fixée à 15 min** (v3 l'emporte sur les 30 min de contraintes-v2) |
| Règle implicite : **coach principal présent à toutes les séances de son équipe** | ✅ | contraintes-v2 | Livré (S2) : seul le coach `MAIN` alimente le no-overlap dur ; l'assistant est optionnel |
| Objectif mou : **repos après jour de match** (+3, `teams.match_day`) | ✅ | v3 §4.3 | Livré (série ENGINE E-feat) : règle implicite `add_match_day_rest_bonus` — pour `matchDay=m`, le jour `m%7+1` reste libre (réifié, poids `rest`=3, non bloquant). Cas dimanche→lundi évité |
| Objectif mou : **regroupement même-coach-même-salle** (+50) | ✅ | v3 §4.3 | Livré (PR #10) sous forme de **bonus de chaînage borné** : sessions back-to-back du même coach récompensées via `CHAINING_TIER_WEIGHTS` (poids < 21 ⇒ ne déplace jamais un placement) ; optimisé en **phase 2** plafonnée à 10 s après verrouillage du placement |
| Niveau 0 — extraction des locks HARD hors solveur | ✅ | v3 §4.1 | **Tranché** : `_extract_hard_locks` (`model.py`) pré-place les slots HARD (`fixed_slots`, forcés hors solveur), `is_team_satisfied_by_hard_locks` retire les équipes déjà couvertes ; slots préservés dans la sortie quel que soit le statut |
| INFEASIBLE D1–D4 : rapport dégradé, diagnostics de conflit, suggestions texte, **relaxation partielle (D4 = P2)** | ✅ | v3 §7 | **Tranché** : D1–D3 livrés (diagnostics précis S5 — équipes/salle/jour+heure nommés, raison du non-placement, indice de sous-capacité INFEASIBLE). **D4 (relaxation auto) écartée par décision** : passe unique sans fallback silencieux (`fallback_used=False`, ADR-0001) → INFEASIBLE renvoie des diagnostics, jamais un plan aux contraintes relâchées en douce |
| Timeout **adaptatif** (60/180/600s selon complexité) | ✅ | v3 §5.1 | Livré (S3) : complexité = équipes × salles → paliers 60/180/600s, plafonné par le budget du payload (650s) |
| Déterminisme : `score_formula_version`, `constraint_version` exposés | ✅ | v3 §4.5 | Livré (PR #11) : `score_formula_version` = `SCORE_FORMULA_VERSION` (`T24_LEVEL_2_FIXED_WEIGHTS_V6` — audit P0.1 `avoided_venue`) + `constraint_version` = version du contrat (`CONTRACT_VERSION`) dans la sortie. ⚠️ Écart spec : livré comme **version de contrat**, pas un SHA1 du payload comme évoqué en v3 §4.5 |
| `COACH_FORBIDDEN_TIME_RANGE` (plage horaire interdite coach, au-delà du jour entier) | ⬜ | contraintes-v2 | COACH_AVAILABILITY ne gère que les **jours** (`unavailableDays`), pas les plages horaires par jour. Config + application engine · **🟡** — même grille que le questionnaire coach (§2, collecte dispos) si celui-ci retient jours×plages |
| Matrice **temps de trajet** entre salles + **passerelles** équipes (U15→U18) — `venue_travel_times`, `team_links`, `category_passway_rules` | ⬜ | v3 §3.4, §4.1 · FF#5 | Tables absentes ; contraintes engine en stub (`travel_feasibility`, `required_bridge`). Lié au bridage « travel off » (§6) · **🔴** (modèle + solveur) — V2 |
| **Matrice contrainte UI↔engine** (famille × ruleType × config → honorée/warning/non-proposée) | ✅ | audit P0.1 ENG-10..13 | **Livré (2026-07-07, audit P0.1).** Fixes ENG-10 (PREFERRED DAY placebo → soft), ENG-11 (préférence gymnase escaladée dure → soft, plus d'INFEASIBLE), ENG-12 (BONUS retiré UI + alias engine ; LOCK+salle = figé), ENG-13 (union indispos coach), + case morte « Toutes les équipes » (expansion backend CLUB→TEAM). **Verrou anti-récidive** : matrice machine `engine/tests/semantic/constraint_matrix.py` → **test paramétré généré** (44 tests) + gel de l'offre UI (Vitest) + `docs/architecture/constraint-matrix.md`. Formule de score V6 (`avoided_venue`) · **✅** |
| **Typage cœur engine** — dataclass `AssignmentVariable` remplaçant `AssignmentLike = Any` dans `constraints.py` | ✅ | audit ENG-05 | **Livré (2026-07-07, Scope A).** `_normalise_assignments` convertit tout en `AssignmentVariable` (helper `_as_assignment_variable`, mêmes alias qu'avant → comportement identique) ; les 14 `add_*` typés `Sequence[AssignmentVariable]`, ~65 accès duck-typing → attribut direct, 9 accessors morts + `AssignmentLike` retirés. **mypy a attrapé 2 vrais bugs de narrowing** (str vs str|None) — la valeur du typage. Scores golden **inchangés** (byte-identiques), smoke COMPLETED. `BoolVarLike`/`RuleCollection` **laissés en alias documentés** (types ortools instables / forme variable — valeur faible, Scope B/C non retenus). `objective.py` garde son propre `AssignmentLike` (module distinct, hors scope) · **🟡→✅ (A)** |
| **PERF solveur — prove-stall sur problèmes denses riches en soft** : BCCL (49 équipes, 55 préférences gymnase soft) solvait en **612 s** — le worker unique **trouvait** l'optimum (score 50258) en ~2 s mais ne le **prouvait** jamais (borne à 1394 % qui ne se resserre pas). **PAS** une régression P0.1 (les préférences gymnase étaient déjà soft avant ; c'était la donnée du seed complet, jamais exercée car masquée par un cache Redis de payload allégé) | ✅ | audit / constaté 2026-07-07 | **Livré (2026-07-07).** `_adaptive_workers` : `num_search_workers` = 1 (≤200 complexité, déterministe) / 8 (au-delà) → **612 s → ~2 s**, optimum **prouvé**, score identique (50258). Diagnostic : profilage bound-vs-value (find-stall écarté, `relative_gap_limit` inefficace car borne trop lâche, forcer les préférences→HARD rejeté : ~24 équipes non plaçables). Assignation non-déterministe à 8 workers mais **valeur d'objectif stable** → goldens (score + nb créneaux) inchangés. Gate perf resserré (dense + **bccl** < 60 s, ratchet anti-régression). Smoke end-to-end : **11 min → 14 s** · **🔴→✅** |
| **Déterminisme exact du plan sur gros clubs** (option, si besoin produit) — restaurer « même entrée+seed → plan exactement identique » au-dessus du seuil 200 | ⬜ | réconciliation initiales §2 (2026-07-07) | Les workers adaptatifs (8 au-delà de 200) rendent l'assignation non-déterministe (valeur d'objectif stable). `interleave_search` seul ne suffit pas (déterministe uniquement avec `max_deterministic_time`, unités déterministes ≠ secondes → refonte du budget timeout adaptatif). À ne faire **que si** un club demande la repro exacte du plan — aujourd'hui jugé non nécessaire (le gestionnaire ajuste). Alternative : exposer un mode « repro exacte » optionnel (1 worker + budget élargi) · **🟡** |
| **Reaper génération bloquée** — cron : schedule coincé `PENDING`/`GENERATING` > N min → `FAILED` + diagnostic explicite | ⬜ | constaté 2026-07-07 (worker mort = spinner UI infini) | Aujourd'hui un worker mort laisse le schedule en attente pour toujours (l'UI polle sans fin). Compléter par le garde-fou outillage livré (watchdog PENDING 90 s dans `generate-schedule.sh`). Seuils à trancher (ex. PENDING > 5 min, GENERATING > timeout payload + marge) · **🟢** |
| **Reverse-engineering des contraintes** — dériver du planning principal des contraintes **PREFERRED** suggérées (agrégées + scorées) pour rendre le baseline reproductible (setup saison N+1 accéléré) | ⬜ | idée club (2026-07-07) | Le gestionnaire garde la main (accepter/rejeter) — réutilise le rail `pendingConstraintSuggestion` (déjà câblé backend↔engine↔front). **Décisions actées** : (1) suggestions **PREFERRED uniquement** — des contraintes HARD dérivées figeraient le plan et neutraliseraient le solveur (régénérer = no-op) ; (2) **agrégation obligatoire** (« équipe X : 4/4 séances mardi → 1 PREFERRED mardi », pas 4 contraintes ponctuelles) + score de confiance ; (3) analyse de slots stockés = **service backend pur, engine intouché**. Familles cibles : DAY (jour récurrent), TIME (créneau habituel), FACILITY (salle d'affinité), COACH (paire coach-équipe). Axe *constraint semantics* → NR requis · **🔴** |

---

## 2. Modèle temporel & périodes d'exception

Grosse zone quasi entièrement à faire — l'appli ne gère aujourd'hui qu'un plan de base hebdomadaire.

> **📖 Référence produit des 3 types de planning (socle / overlay / reprise), validée 2026-07-12 :
> [`types-de-planning.md`](../courantes/types-de-planning.md)** — déclenchement, manipulation, règle
> « la semaine est l'unité hors socle », et écarts E1-E5 (suivis en P2-1/P2-5).
>
> **⭐ Approche tranchée — voir [`accueil-cockpit-temporel.md`](../courantes/accueil-cockpit-temporel.md).** Cette
> spec **challenge et remplace** la stratégie « matérialiser d'abord » de la vision d'origine : l'accueil
> devient un **cockpit** (bandeau socle · calendrier d'exceptions · radar), le calendrier est une
> **projection** et non une matérialisation J+14, une **occurrence n'existe qu'en delta/override**, et
> les 4 tables `period_*` se réduisent à **une entité `CalendarEntry`** (+ réutilisation de `Constraint`
> et `Schedule`). Résultat : cette §2 **cesse d'être un monolithe 🔴** et se livre en **paliers A/B/C**
> (cockpit projeté → overlays de période → collecte coach). Les 🔴 ci-dessous sont donc à relire à
> travers cette approche.

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Architecture **templates → occurrences** (dates réelles, matérialisation glissante J+14) | ⬜ | v3 §3.5, §8.1 · FF#8 | **♻ Repensé (cockpit §3)** : matérialisation J+14 **abandonnée** au profit d'une **projection + occurrences éparses (deltas)**. `schedule_slot_occurrences` ne stocke, si conservée, **que les overrides**. **Cesse d'être le prérequis 🔴** de toute la §2 |
| Occurrences : exceptions, annulations, déplacements, remplacements coach/salle, fusions | ⬜ | v3 §3.5 · FF#8 | **♻** Grain fin déjà couvert par `ManualEditController` (`/manual-edit/one-time` + `temporaryLock`) ; une table d'occurrences éparses ne s'ajoute **que si** le besoin fin le justifie (palier B/C) · **🟡** |
| **Périodes d'exception** (`period_templates` + `is_cutoff`, `period_template_slots` avec `team_ids[]`, `period_assignments`, `period_coach_responses`) | 🟡 | v3 §3.6, §8 · FF#2 | **♻ = `CalendarEntry` `kind=period`** (cockpit §4, §9ter) : `periodType` + contraintes datées (`Constraint.calendarEntryId`) + overlay (`Schedule.calendarEntryId`). **Livré palier B (PR-4/5) pour closure + holiday** : overlay généré via wizard mode période, fermeture = additive + expansion `forbiddenVenueId`, vacances = remplaçante partielle. **Différés** : `cutoff` (fenêtre vide, pas d'overlay) et `mutualisation` (`team_ids[]` fusionnés) — voir lignes dédiées ci-dessous |
| Scheduler quotidien @8h : détecte période sous 14j → alerte (J-7 rappel, J-3 rouge), jamais d'auto-action | ✅ | v3 §8.2 · FF#2 | **Livré (PR-6 palier C)** : commande `app:periods:remind` (cron quotidien prod) — période sans overlay démarrant à J-14/J-7/J-3 → email aux gestionnaires (owner+admin), sujet 🔴 à J-3, lien cockpit (`FRONTEND_BASE_URL`). Jamais d'auto-action (le radar in-app reste la vue). `--dry-run`/`--date` pour rejeu |
| **Plans secondaires / alternatifs** (vacances, coupure, mutualisation) | 🟡 | v3 §8.1 · FF#2 | **♻ = overlays de période bornés** (cockpit §6). **Livré palier B (PR-4/5)** : `Schedule.calendarEntryId` = overlay (jamais baseline), généré via wizard mode période (structure R/O, contraintes de période actives), suppression centralisée `OverlayManager`, reopen destructeur du baseline (409 + confirm). closure + holiday seulement. **+ versions d'overlay (2026-07-11)** : plusieurs versions par période (V1, V2…) + validation/reopen d'overlay + purge `app:overlays:purge` — voir [`planning-versions.md`](planning-versions.md) §D3ter |
| **Overlay `periodType=cutoff`** (coupure = pas d'entraînement) | ✅ | v3 §8.1 · FF#2 | **Livré (reliquats cockpit, 2026-07-06)** : création via DayDialog (« Coupure (pas d'entraînement) »), icône 🛑 sur le calendrier, rappel radar **sans CTA** (fenêtre vide = rien à générer, jamais de wizard). Entrée `CalendarEntry` nue : ni contrainte datée, ni overlay. Purement informatif tant qu'aucune projection ne consomme les dates (PDF daté, etc.) |
| **Overlay `periodType=mutualisation`** (`team_ids[]` fusionnés) | ⬜ | v3 §3.6 · FF#2 | **Différé palier C.** Ex. : « pendant la coupure de janvier, SM1 + SM2 s'entraînent ensemble le mercredi 20h sur un seul créneau » → nécessite la fusion `team_ids[]` côté solveur (créneau partagé). Gros morceau moteur + UI · **🟡** |
| **DayDialog « Créer une période… »** (période générique `custom`) | ⬜ | — | **Différé palier B/C.** Ex. : « poser une période custom du 10 au 20 mars, ni fermeture ni vacances » → `periodType=custom` non générant aujourd'hui → 422 à la création d'overlay = impasse UX. **Mitigation livrée** (bouton désactivé + tooltip, gardé par test DayDialog). Reste : activer quand `custom` devient générant · **🟢** |
| **Plan de vacances éditable + collecte des demandes coach** (structure de période éditable · lien coach sans login · écran demandes) | ⬜ | v3 §8.2 · FF#2 | **⭐ Besoin spécifié — voir [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md).** Le terrain **corrige** la lecture initiale « dispos coach → contrainte dure » : c'est une **négociation** (le coach émet un **souhait** de volume — garde/rien/réduit ; le gestionnaire **arbitre et tranche**, le lien n'écrit jamais de contrainte). **Structure de période éditable** (créneaux salle + séances/activation par équipe) via override `calendarEntryId` en copie-sur-édition ; **mutualisation gratuite** (réservation sur créneau à capacité 2, pas d'engine) ; collecte = lien tokenisé (patron reset-password, **date limite** gestionnaire) + **écran demandes avec case « traité »** (pas d'auto-génération). **Zéro changement engine.** **Phasé** : P1 structure éditable (le gestionnaire fait tout à la main) → P2 collecte coach (le différenciateur). Gymnases dispo-seulement-vacances = différé (patron extensible à `Venue`). **+ 2ᵉ déclencheur : reprise progressive (validé 2026-07-11)** — même mécanisme P1, planning allégé de rentrée (Fanion → +important → tout le club, créneaux mairie additifs, < 8 équipes → moteur non-problème) + 2 ajouts (défaut équipes **par rang**, **toggle des contraintes** de période, zéro engine) — voir §6bis · **🟡** |
| Mutualisation de créneau (SM1+SM2 même créneau) | ✅ | v3 §3.6 · FF#2 | **Tranché : via réservation, pas d'engine** — salle divisible (`canSplit`) + capacité 2 sur le créneau + réservation des 2 équipes → le solveur place les deux (couvre le partiel : 1 des 2 créneaux partagé, l'autre solo). Le `periodType=mutualisation` dédié + la fusion `team_ids[]` moteur sont **abandonnés** (inutiles). Détail : [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md) §3 |
| `school_holiday_periods` (API Éducation Nationale, zones A/B/C) | ✅ | v3 §3.3 | **Livré (PR-2 cockpit palier A) — gradué dans [`../courantes/vacances-scolaires-jours-feries.md`](../courantes/vacances-scolaires-jours-feries.md)** (table globale, seed, `GET /api/school-holidays`, dérivation zone FFBB) |
| **MAJ automatique des vacances via l'API calendrier scolaire** (1×/saison) | ✅ | [API data.gouv](https://www.data.gouv.fr/dataservices/api-calendrier-scolaire) | **Livré (`app:school-holidays:import`, PR #62) — gradué dans [`../courantes/vacances-scolaires-jours-feries.md`](../courantes/vacances-scolaires-jours-feries.md)**. **Reste différé (⬜)** : cron annuel + bouton superadmin (rattachés à la future console superadmin) · **🟢** |
| **Calendriers vacances DOM/TOM** (hors zones A/B/C métropole) | ✅ | — | **Livré avec l'import ci-dessus — gradué dans [`../courantes/vacances-scolaires-jours-feries.md`](../courantes/vacances-scolaires-jours-feries.md)** (13 codes de zone, calendriers territoriaux spécifiques). Territoires non publiés côté API certaines années → saisie manuelle en attendant |
| **Jours fériés au calendrier** (afficher les fériés dans le cockpit) | ✅ | [API etalab jours-feries](https://calendrier.api.gouv.fr) | **Livré — backend (`app:public-holidays:import` + `GET /api/public-holidays`, PR #63) + rendu cockpit (pastille jour exact + rappel radar ≤ 30 j sans CTA, reliquats cockpit 2026-07-06) — gradué dans [`../courantes/vacances-scolaires-jours-feries.md`](../courantes/vacances-scolaires-jours-feries.md)** (y compris hors périmètre assumé : Alsace-Moselle, 977/978, fallback offline). Le pont reste une décision gestionnaire (`periodType=closure`) |
| **Vue calendrier annuel** (menu) — voir tous les plannings prévus sur l'année pour repérer les soucis + **vacances scolaires en cours** | ⬜ | — | **♻ = le cockpit lui-même** (cockpit §5) : calendrier mois entier, jour courant entouré, couche d'exceptions + vacances de la zone. **Livrable tôt (palier A)** en mode projection · **🟡** |
| **Régénération partielle guidée** (`PartialRegenService` — salle fermée / coach indispo → slots affectés identifiés + regen ciblée) | ⬜ | v3 §6.2, §14.2 · FF#1 | **♻ Partiellement couvert par les overlays** : une période (fermeture/vacances) génère un overlay borné (palier B livré) sans toucher le plan de base ; reste la regen **ciblée** du plan de base lui-même (hors période) — à requalifier quand un besoin réel se présente · **🔴** |

---

## 3. Onboarding & saisons

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Wizard initial de saisie | ✅ | v3 §9.1 | Livré (6 étapes, reconstruit) — dépasse le draft 4 étapes |
| Auto-save serveur du brouillon (`GET/PUT /api/clubs/{id}/draft` + `clubs.draft_data`) | ✅ | v3 §9.1 · BG G1/G2 | **Tranché : abandonné** — la persistance **par entité** (chaque salle/équipe/coach POST/PUT à la saisie, le store wizard ne tient aucune donnée) couvre déjà le besoin ; un draft-blob serait une 2e source de vérité. Ferme BG G1/G2 |
| **Mode / compte démo** (club fictif pré-rempli, génération 30s avant saisie) | ⬜ | v3 §9.1, §12.3 | **Besoin à spécifier → [`compte-demo.md`](compte-demo.md)** (vendeur vs prospect self-service, options A/B/C, anonymisation RGPD, flag `isDemo`). **🟡** — **fort levier de vente** |
| **Refonte enregistrement** (vérification gestionnaire via code/document FFBB + approbation par email si le club existe déjà) | ⬜ | v3 §9.1 · FF#19 | **Besoin à spécifier → [`enregistrement-ffbb.md`](enregistrement-ffbb.md)** (code vs doc FFBB, preuve de rôle, 1er gestionnaire, anti-squatting). **Croise A5/A6** (gate `/api/club_users`) et l'import FFBB §8. **🟡/🔴** |
| **Transition de saison** (copie éditable des entrées N→N+1 · multi-saison simultané · bascule mi-juillet non destructive · revue guidée) | ✅ | v3 §9.2 · FF#3 | **P1 livré intégralement (PR-1/2/3, 2026-07-06)** (fichier de détail supprimé le 2026-07-08, livré) : (PR-1) résolution multi-saison (`SeasonResolver` pivot 15 juillet, `SeasonFilter`, `X-Season-Id` validé, `/api/me` `seasons[]`). (PR-2) `SeasonTransitionService` (copie entrées N→N+1, remaps, `parentConstraintId`, permanentes seules) + `POST /api/seasons/{id}/transition` + sélecteur frontend. (PR-3) **readonly serveur** (`SeasonAccessGuard` 409 : `AbstractStateProcessor` + `SeasonReadonlyGuardListener`/`SeasonScopedWriteInterface`) + bannière lecture seule + **purge CLI `app:seasons:purge`** (rétention courante+N-1+futures, purge N-2). **Reste = P2 (⬜)** : revue guidée par type + re-datation des événements club + alertes d'anticipation mi-mai→mi-juillet. **🟡** (P2) |
| Capitalisation des contraintes entre saisons (copie éditable, `parent_*_id` self-FK) | ✅ | v3 §3.1, contraintes-v2 §3 · FF#3 | **Livré (transition PR-2)** : `Constraint.parentConstraintId` ajouté + les contraintes **permanentes** sont copiées N→N+1 par `SeasonTransitionService` (`scopeTargetId` + config remappés sur les entités copiées, filiation `parentConstraintId`) |
| **Transition de saison — P2 (revue guidée + confort)** | 🟡 | v3 §9.2 · FF#3 | **Suite du P1 livré — quasi bouclé, seul (4) reste.** Contenu (P2) : (1) **wizard de revue guidée** — **ABANDONNÉ (décision utilisateur 2026-07-07)** : le gestionnaire connaît son club (départs coachs, équipes dissoutes) avant l'outil ; l'édition libre via l'assistant existant + récap suffit. À rouvrir seulement sur signal pilote. (2) **re-datation des événements club** ✅ **livré (P2-PR1, 2026-07-07)** : dialog « Reconduire les événements » après « Préparer la saison suivante » — garder (coché) + date suggérée +364 j (même jour de semaine), création dans le brouillon via l'API calendar_entries standard (X-Season-Id explicite, zéro endpoint/migration) ; sautable, ré-ouvrable en relançant « Préparer » tant que N+1 est sans event. + **fix du 409→bascule** (ky 2.x : body d'erreur consommé → error.data/serverBody stashés ; existingSeasonId était illisible). (3) **alertes d'anticipation** ✅ **livré (P2-PR2, 2026-07-07)** : cron `app:seasons:remind-transition` (J-61/J-30/J-14 avant le pivot 15 juillet, dédup `transition_reminder_log`, patron `app:periods:remind`) + bannière permanente `SeasonTransitionBanner` (CTA → ConfirmDialog partagé via `transitionUiStore`). Silence dès que N+1 existe. (4) à décider : report des **tags custom** entre saisons (bloqué par la dette `TeamTagService`, cf. ligne team_tags). **Dépend du P1 (livré).** · **🟡** |
| Politique de rétention (2 saisons max, saison N-1 read-only, purge RGPD, `solver_metrics` 6 mois) | 🟡 | v3 §3.2, §9.3 | **Livré (transition PR-3)** : fenêtre glissante courante + N-1 readonly + futures, **purge N-2** via `app:seasons:purge` (manuelle, `--dry-run`/`--date`/`--club`), readonly serveur (`SeasonAccessGuard` 409). **Reste (⬜)** : rétention de `solver_metrics` 6 mois (table pas encore persistée, cf. §9) + éventuel cron de purge (rattaché à la future console superadmin) · **🟢** |
| **Réinitialiser son club** (RAZ des données pour repartir de zéro) | 🟡 | — | `ResetSeasonController` (`DELETE /api/reset-season`) existe côté données saison ; manque une action « reset club » assumée + son UI (avec confirmation) · **🟢** (l'ossature existe : élargir le controller + modale de confirmation) |

---

## 4. Édition manuelle (boucle de travail)

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| **Cycle de vie planning** : `VALIDATED` (fini/verrouillé lecture seule), rouvrir, planning principal (baseline) vs secondaires | ✅ | — | Livré (PR #15) : `/validate` · `/reopen` · `/set-baseline`, verrou serveur sur les 4 chemins d'édition, modale de responsabilité. Cascade principal→secondaires reportée (dépend des occurrences). → [`planning-lifecycle-validated.md`](../courantes/planning-lifecycle-validated.md) |
| Niveaux de lock NONE/SOFT/HARD (enum, ≤30 min = SOFT silencieux) | 🟡 | v3 §3.5, §11.4 | Schéma + lock HARD/SOFT posables ; règle « ≤30min = SOFT auto » à vérifier · **🟢** (vérif + petite règle applicative) |
| **Dialogue post-modification** (déplacement significatif → créer contrainte permanente / lock SOFT|HARD / occurrence one-time « convertir en contrainte ? ») | 🟡 | v3 §11.4 · FF#4 | Service de base existe ; manque création de contrainte permanente + `source_occurrence_id` · **🟡** (le `source_occurrence_id` dépend des occurrences 🔴) |
| **Alerte diagnostic cliquable → focus dans la grille** (boucle d'ajustement) | ⬜ | | **Discovery amorcée (2026-07-06), reportée par décision utilisateur — trop lourd pour l'instant.** État réel : le clic-diagnostic **existe déjà en partie** (`DiagnosticsPanel.onHighlight` + `concernedSlots` → surligne). **3 gaps** : (1) `concernedSlots` ne matche que les **séances placées** → `unused_slot` (créneau vide) pointe sur du néant ; (2) « focus » = **dim** des autres cellules, **pas de scroll-into-view** (cellule hors écran) ; (3) la grille **ne rend pas les créneaux vides**. **Fork à trancher** : clic → **naviguer** (montrer l'endroit, petit) vs **corriger sur place** (glisser une équipe, plus gros). **⭐ Insight clé** : « afficher les créneaux vides + cliquer pour affecter une équipe » = **le même primitive que la Grille de réservation ci-dessous** — sauf que celle-ci est **avant** génération (LOCK HARD) et la boucle **après** (réparer). Si « corriger sur place » → **une seule brique** (grille interactive + créneaux vides) utilisée avant ET après. · **🟡** — **transforme le rapport en outil de travail** |
| `ScheduleDiffService` (diff lisible entre 2 snapshots) | ⬜ | v3 §6.2 · FF#11 | **🟡** (les snapshots existent déjà — `snapshot_data`) |
| **Grille de réservation** (étape Contraintes, pré-génération) : grille type disponibilités, **clic = affecter une équipe** à un créneau ; affiche les **créneaux déjà placés** en base (`schedule_slot_templates`) | ⬜ | — | Remplace la liste déroulante actuelle (`ReservationPanel`). Réutiliser le style `VenueAvailabilityGrid`. Idée gestionnaire (recette) — les réservations posées ⇒ HARD locks à la génération · **🔴** (nouvelle grille interactive). **⭐ Partage son primitive avec la « boucle d'ajustement » §4** (grille + créneaux vides + clic=affecter) — à spécifier ensemble le jour venu : même brique, deux moments (pré vs post génération) |
| Routes manual-edit documentées (OpenAPI) + naming `schedule-slots` vs `schedule_slot_templates` | ✅ | BG G5/G6 | **Livré (PR #43, BCK-F)** : `CustomRoutesOpenApiFactory` documente les 3 routes `manual-edit` ; G6 tranché **snake_case canonique** (routes custom gardent leur kebab-case) |

---

## 5. Multi-tenant, sécurité, concurrence

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Isolation 3 couches (ClubVoter + TenantFilterListener + RLS FORCE) | ✅ | v3 §10.1 | Livré (listener priorité 7) |
| Verrou de génération (1/club, Messenger + asyncio.Lock engine) | ✅ | v3 §5.1 | `ConcurrentGenerationTest` |
| Snapshot gelé (`snapshot_data` + `snapshot_hash`) | ✅ | v3 §10.2 | |
| **Optimistic locking** (`version` + 409 sur conflit) | 🟡 | v3 §10.2 | `version` exposé ; gestion 409 à généraliser/vérifier · **🟡** |
| **Rate limiting** (api 200/min, generate 5/h, pdf 10/h par club) | ⬜ | v3 §10.2 | À vérifier/implémenter (register limité seulement) · **🟢** (le `RateLimiter` Symfony est déjà en place pour register — répliquer par route/club) — utile avant prod |
| **Deux users DB** (app_user sans DDL + migration_user) | ⬜ | v3 §10.1 | À vérifier · **🟢** (RLS tourne déjà en `app_user` vs connexion `admin` — surtout de la vérif/durcissement) |
| CacheInvalidationListener ciblé (Venue/Coach/Team/Schedule) | 🟡 | v3 §6.2 · FF#9 | Stub actuel · **🟡** |
| `ClubTimeService` (UTC ↔ fuseau club) | ⬜ | v3 §6.2 · FF#10 | **🟡** (transverse — toucher tout affichage horaire) |

### Rôles & membres (non couvert par les initiales — issu du hors-scope wizard)

| Évolution | Statut | Note |
|-----------|--------|------|
| **Rôles non-admin** (coach / lecteur au-delà d'`admin`) + modèle de permissions | ⬜ | Aujourd'hui `ClubUser.role` est **hardcodé `'admin'`** au register ; pas de différenciation de droits · **🔴** (modèle de permissions + voters à câbler partout ; `isManagementRole` existe déjà comme amorce) |
| **Gestion des membres** (inviter / changer rôle / désactiver) — écran admin club | ⬜ | `ClubUser` (membership) existe ; pas d'UI · **🟡** (CRUD membership + écran ; dépend en partie des rôles) |
| **Approbation des demandes d'adhésion** (rejoindre un club → membre `pending`) | ✅ | Flux register → membre pending + `WaitingApprovalPage` côté demandeur ; approbation admin livrée (`PendingMembersSection`, approuver/refuser), désormais section **Demandes** du hub `/club` (PR #36) |
| **Modifier ses informations personnelles** (nom, email, mot de passe) | ⬜ | Nav « Profil » existe + reset mot de passe ; manque l'édition du profil (prénom/nom/email) + changement de mot de passe connecté · **🟢** (formulaire + endpoint `PATCH /api/me` ; User self-only déjà en place) |

---

## 6. Pricing & bridage (business-critique)

Zone entièrement à faire — aucun bridage plan aujourd'hui.

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| 4 plans tarifaires (Découverte/Petit/Club/Grand) : max_teams/venues/generations, prix en DB | ⬜ | v3 §12.1 | Table `plans` + modèle à vérifier · **🟡** (`Club.planId` existe déjà — reste la table `plans` + les limites) |
| Bridage **Découverte** (freemium) : cap sur le **nombre de générations**, read-only à l'épuisement | ⬜ | v3 §12.2 | **⭐ Besoin spécifié — voir [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md).** Cadré au terrain : **club complet, aucun cap d'entité** (sinon le solveur paraît nul, tue le wow) ; gate = **`POST /generate` plafonné (~4)** ; **générer décompte, ajuster gratuit** (la work-loop sépare déjà solve/édition) ; **compteur total non rechargeable** (reset superadmin) ; **pas de limite de temps** ; **PDF export off** ; **read-only** à l'épuisement (réutilise le verrou VALIDATED), pas lockout ; **default freemium**, choix d'offre à la conversion. **Enforcement = 3 gardes, PAS transversal** (le modèle génération dissout le 🔴 d'origine). **🟡** (au lieu de 🔴) |
| Enforcement `generation_count` — compteur **total non rechargeable** (freemium) | ⬜ | v3 §3.2 | 1ʳᵉ brique du bridage : garde dans `GenerateScheduleController`. ⚠ `generation_count_season` existe mais se **remet à zéro par saison** → le freemium a besoin d'un compteur **total** qui ne recharge jamais (nouveau champ ou variante) · **🟢** — détail : [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md) §4 |
| `billing_cycle` / `plan_expires_at` | ⬜ | v3 §3.2 | Modèle · **🟢** (champs déjà sur `Club` — reste la logique d'expiration) |

---

## 7. Tests

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| 4 tests bloquants CI (TenantIsolation, TenantCache, ConcurrentGeneration, ContractSchema) | ✅ | v3 §13.2 | Livrés + CI vert |
| Golden datasets (simple/medium/dense/impossible/**vacation_week**/**partial_regen**) | 🟡 | v3 §13.5 | Base présente ; vacation_week/partial_regen à vérifier · **🟡** (vacation_week dépend du modèle occurrences 🔴) |
| Invariants Hypothesis (no double-booking, coach unique, coach-joueur cohérent, HARD préservé, tier-S jamais sacrifié si D placé) | 🟡 | v3 §13.5 | Partiel · **🟡** |
| Tests unitaires frontend (Vitest + RTL) | 🟡 | v3 §13.3 · FF#12 | Livrés depuis (wizard/planning testés) — FF à requalifier · **🟢** (compléter la couverture existante) |
| E2E Playwright (onboarding→génération→cockpit) | ✅ | v3 §13.3 · FF#13 · audit P0.2 | **Livré (2026-07-07, audit P0.2 FRT-05/07/11).** Suite 9/9 verte, câblée en **CI** (job `e2e`, needs blocking-tests, stack docker complète + Vite). `journey.spec` = LE parcours produit réel : register → wizard complet (équipe/gymnase+créneaux/coach/contraintes/récap) → **génération CP-SAT réelle** → planning placé → validation baseline → cockpit. auth.spec réparé (assertions périmées). Flakiness rate-limiter réglée (limite dev bornée). Reste optionnel : PDF dans le parcours · **✅** |
| Gate perf (dense_club < 180s) | ✅ | v3 §13.6 | **Livré (série ENGINE, cf. l.185)** : gate perf ajouté sur fixture dense. À re-confirmer dans une édition d'audit |
| **Simulateur d'horloge (dev)** — piloter la date/heure de l'app pour rejouer les bascules annuelles (pivot saison 15/7, rappels cron, readonly N-1) | ✅ | outillage (2026-07-08) | **Livré (PR #114)** : `ClockInterface` (Symfony Clock) aliasé sur `SimulatedClock` **en dev seulement** (prod = horloge native, inchangée) ; instant épinglé en Redis (partagé web ↔ cron-runner) ; endpoint `/api/dev/clock` gardé `%kernel.debug%` ; widget dev à côté du nom du club. Temps métier routé : `SeasonResolver` + crons rappels (`reconcile-stuck` reste sur l'heure réelle) |

---

## 8. Imports & intégrations

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Import Excel équipes (template basket) | 🟡 | v3 §9.1, §12.3 | Backend livré (`POST /clubs/{id}/import-teams`, `FfbbExcelImporter`) ; **UI wizard différée** — l'API FFBB à venir remplacera l'import manuel · **🟢** (brancher l'UI sur l'endpoint existant) |
| Import CSV salles & coachs | ✅ | v3 §9.1 | **Tranché : abandonné** — peu de lignes (1-5 salles, ~10 coachs), pas de format standard ; la saisie manuelle suffit |
| **Fermetures de salle** (`VenueClosure` date+raison → regen partielle) | ⬜ | v3 §3.4 · BG G3 | **♻ Débloqué (cockpit §4, §9ter)** : = `CalendarEntry` `kind=period`, `periodType=closure` (additive), contrainte datée `FACILITY` + overlay borné. Plus besoin de la matérialisation J+14 amont · **🟡** (palier B) |
| Connecteurs **API municipales** (`venues.source` manual/municipal/external_api + `external_ref`) | ⬜ | v3 §1.4 · FF#20 | V2 · **🔴** |
| Import équipes **FFBB** (code club, `ffbb_team_id`) | ⬜ | v3 §1.4 · FF#19 | V2 · **🟡** (infra `ffbb_team_id`/`ffbbClubCode` déjà anticipée) — **supprime la moitié de la saisie** |
| **Autofill infos club / ligue / comité + logos** (scraping FFBB cache-first) | ⬜ | v3 §1.4 · FF#19 | **⭐ Besoin spécifié → [`import-ffbb-autofill.md`](import-ffbb-autofill.md)** (lot C). Récupère depuis `competitions.ffbb.com`/`api.ffbb.com` les coordonnées + président **ligue/comité** et les **logos** ; **ligue/comité = tables de référence partagées cache-first** (pas de re-scrape si déjà en base), 3 blocs contact (Ligue·Comité·Club) sur la page club. **SSRF A12** (host fixe, rejet IP interne, MIME) + repli saisie manuelle (lot B). **Croise** [`enregistrement-ffbb.md`](enregistrement-ffbb.md) (même fetch FFBB). Prérequis produit : **CGU FFBB** à vérifier · **🟡** |
| Import **calendrier de matchs FFBB** (`competitions`, `ffbb_club_code`) | 🟡 | v3 §1.4 · FF#19 | **Livré PR-4 module matchs (2026-07-07) — SUR FORMAT SUPPOSÉ.** `FbiFixtureImporter` (patron `FfbbExcelImporter`) : un export FBI par équipe, équipe choisie à l'upload, `POST /api/teams/{id}/fixtures/import` + `ImportFbiDialog` dans `/matchs`. HOME/AWAY par nom de club (derby = erreur explicite), idempotence par `Fixture.externalRef` (n° FBI), Division → Competition find-or-create, statut UNPLACED (l'heure FBI préremplit `kickoffTime`), Salle ignorée (annuaire = palier B). ⚠ **Reste 🟡 tant que le format n'est pas validé contre un vrai export FBI** (aucun fichier réel disponible — colonnes actées : Division/Numéro/Équipe 1/Équipe 2/Date/Heure/Salle). Update de re-programmation au re-import = évolution. Distinct de l'annuaire adverse (heures/positions, effet réseau) · **🟡** |
| **Planification des matchs** (placement domicile + radar de conflits + dérogations) | 🟡 | v3 §1.4 · FF#21 | **⭐ Voir [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md). Palier A en cours.** **PR-1 socle backend livré (2026-07-06)** : entités `Competition`/`Fixture` (season-scoped, API Platform, amical = competition null, statut UNPLACED→…→VALIDATED), service **`MatchFootprint`** (empreinte-temps : domicile 2h15 = 30 échauffement + 1h45 ; extérieur + 30 douche + 15 battement + trajet paramétré — §4bis), **catalogue-ligue `LeagueMatchWindow`** (table globale hors tenant, seed AURA §6bis, `app:league-windows:seed`, `GET /api/league-match-windows`), dérivation ligue via **`LeagueResolver`** (préfixe `ffbbClubCode`) + `Club.league`. `Team.matchDay` conservé (superseded plus tard). **PR-2 moteur de conflits livré (2026-07-07)** : service pur **`MatchConflictDetector`** + `GET /api/fixtures/conflicts` (à la volée, rien persisté), **périmètre coach seul via `TeamCoach`** — `MATCH_MATCH` (deux matchs d'un coach qui se chevauchent) + `MATCH_TRAINING` (match↔entraînement lu dans le planning **effectif à la date** : overlay période ACTIVE sinon baseline, créneau projeté sur le jour du match). **PR-3 grille week-end UI livrée (2026-07-07)** : feature frontend `features/matches/` (route `/matchs`) — grille datée week-end-centrique (bloc = empreinte 2h15), pose domicile clic→panneau (`PUT` statut PLACED) avec **envelope-ligue** (garde HARD si l'équipe mappe une fenêtre, sinon repère indicatif), saisie manuelle (`POST /api/fixtures`, amical = competition null), radar de conflits affiché ; Vitest + e2e Playwright. **PR-4 import FBI livrée (2026-07-07, format supposé — cf. ligne FF#19)**. **Reste palier A** : volet **joueur** (`CoachPlayerMembership`) + `Team.preferredMatchWindow`. **Paliers B** (dérogation + trajet + annuaire adverse global) **/ C** (effet réseau) plus tard · **🟡** |
| Dérivation fuseau/zone depuis l'adresse (API Géo + timezonedb → `school_zone`) | 🟡 | v3 §3.2 | **Zone livrée (PR-2)** : `school_zone` dérivé du `ffbbClubCode` par `SchoolZoneResolver` (dép.→zone, table fixe), **pas d'API Géo** · reste le **fuseau** (`ClubTimeService`, ligne §6.2). ⚠ heuristique FFBB→dép. best-effort, fallback zone manuelle |

---

## 9. Transverse

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Export planning (worker Puppeteer mono-thread) | ✅ | v3 §2.1 | Livré + **étendu (PR #112, 2026-07-08)** : **PDF / PNG / Excel**, chaque rendu tenant sur **1 page A4 paysage** (grille jours×gymnases au pas 15 min, fit auto), périmètre **tous les gymnases** ou **un gymnase** ; XLSX = tableau plat (PhpSpreadsheet). Menu « Exporter » sur un planning terminé. **Reste** : **rétention** (base/period/manual/is_pinned) ⬜ ; logo club dans l'en-tête PDF ⬜ |
| **Audit trail** (`audit_logs` append-only, BRIN, purge RGPD, async) | ⬜ | v3 §3.2 · FF#6 | **🟡** (table + Doctrine listener + purge) — jalon RGPD |
| **Console super-admin** (monitoring santé/usage/conversion + métriques par club & globales + data ops auto + actions d'exploitation) | 🟡 | idée produit (2026-07-09) | **SA0 + SA1 + API complète SA2 livrés (2026-07-16)** → vérité courante [`../courantes/superadmin-auth.md`](../courantes/superadmin-auth.md), suite dans [`console-superadmin.md`](console-superadmin.md). Reste **écran React SA2 → SA3 (jobs data) → SA4 (actions support) → SA5 (impersonation)**. **🔴** |
| Table `solver_metrics` (perf par génération, partition mensuelle, purge 6 mois) | 🟡 | v3 §3.5 · FF#7 | **Capture livrée SA1** : métriques persistées et RLS tenant-scoped ; partitionnement mensuel et purge six mois à réaliser dans les jobs d'exploitation |
| Diagnostics avec **suggestions actionnables** (jsonb = actions + liens entités) | 🟡 | v3 §7 | Diagnostics texte livrés ; actions cliquables ⬜ · **🟡** (jumeau de l'« alerte cliquable » §4) |
| Validation temps réel à la saisie (coach-joueur = seule erreur bloquante ; le reste = warnings) | 🟡 | v3 §11.3 | Wizard valide par étape ; **warnings de réservation livrés** (PR #14 : créneau surchargé vs capacité, quota séances/semaine, 2 séances le même jour — non bloquants, le solveur reste l'autorité) ; **warning équipe compétitive classée rang D · Bonus** (PR #35, dérivé du label du tier, non bloquant). Liste complète de warnings à compléter · **🟢** (compléter la liste au fil de l'eau) |
| Push temps réel (Mercure statut/score) | ✅ | v3 §2, §6 | Livré |
| **Identité visuelle par club** (couleur d'accent + logo) | ✅ | Livré 2026-07-02 : accent + logo + extraction 3 couleurs + écran /club (reste : stockage prod S3) → [`identite-visuelle-club.md`](../courantes/identite-visuelle-club.md) |
| **Accent par thème + densité d'accent** — accent distinct clair/sombre + présence d'accent renforcée dans l'UI | ✅ | idée club (2026-07-07) | **Livré (PR #109, 2026-07-08)** : accent distinct clair/sombre (`Club.accentColorDark` + `accentPalette`) et présence d'accent renforcée dans l'UI, contraste AA préservé par thème |
| `team_tags` / `team_tag_assignments` + règles facility par tag | 🟡 | contraintes-v2 | Tags système + ciblage tag livrés ; FACILITY_FORBIDDEN/PREFERRED_TEAM_TAG dédiés ⬜ · **🟡** · ⚠ **dette constatée (transition PR-2)** : `TeamTagService::syncTeamTags` **efface tous** les tags (custom inclus) et ne re-dérive que les tags **système** à chaque édition d'équipe → les tags **custom** sont **éphémères** (ne survivent pas à une édition). Bloque le report des tags custom entre saisons (transition ne les copie donc pas). À corriger avant de fiabiliser les tags custom · **🟢** |
| `max_days_per_week` coach (calcul dynamique + override checkbox) | 🟡 | v3 §3.4 | À vérifier · **🟢** |
| **Réservation salle de convivialité** (self-service coach : réserver une soirée dans une salle non-sportive → notif gestionnaire) | ⬜ | idée club (2026-07-05) | **V2 « club hub » — différé, gardé.** La résa elle-même est **triviale** (salle = `Venue` avec horaires, réservation = date + heure début + durée, **pas de solveur**, juste un check de conflit + notif au gestionnaire). ⚠️ **Le vrai coût n'est pas la résa** : « le coach réserve lui-même » exige des **comptes coach + modèle de rôles/permissions** (aujourd'hui `ClubUser.role` hardcodé `admin`, cf. §5 🔴). Tant que §5 n'est pas fait, ce n'est **pas** gratuit. **Question stratégique** : veut-on que l'appli devienne le *hub du club* (self-service coach) ou rester l'*outil de planning* (piloté gestionnaire) ? Adjacent au cœur (n'exploite pas le solveur — commodité type Doodle). **Déclencheur de réouverture** : construction des comptes coach/rôles (§5), ou demande d'un club pilote — la feature devient alors quasi gratuite · **🟢 la résa / 🔴 le prérequis rôles** |
| App **mobile** (React Native/Expo, consultation → exceptions V2) | ⬜ | v3 §1.4 · FF#14 | P2 · **🔴** (à ne pas faire avant clients payants — web responsive + PDF suffisent) |
| Notifications coach (email PDF → push + lien consultation sans login) | ⬜ | v3 §1.4 · FF#17 | P2 · **🟡** |
| Stats & analytics (taux de remplissage, heures-coach/semaine) | ⬜ | v3 §1.4 · FF#16 | P2 · **🟡** (peu coûteux une fois les occurrences là ; demande d'AG) |
| Dashboard super-admin (multi-clubs) | ⬜ | v3 §14.3 · FF#15 | P2 · **🔴** |
| **Multi-sport** (handball/gym/volley, modèle générique) | ⬜ | v3 §1.4 · FF#18 | V2 · **🔴** (attendre une vraie demande) |
| **Suppression sûre (salle / équipe / coach)** — confirmation + liste d'impact avant hard-delete | ⬜ | idée club (2026-07-07) | Entités **saison-scoped** (partagées par tous les plannings — pas de « suppression pour un seul plan »). **Décisions actées** : **hard delete**, précédé d'une popup listant l'impact en cascade — contraintes liées (`Constraint.scopeTargetId`), liens coach (`TeamCoach`) pour équipe/coach, et **plannings secondaires supprimés** (règle rappelée : modifier le socle purge les overlays de la saison — cohérent avec le 409 `overlays_exist` existant). Coach : la suppression retire le lien équipe **et** liste les contraintes coach avant de purger. Axe *planning lifecycle* → NR requis · **🟡** |
| Doc OpenAPI de `AuthController` (`/api/register`, `/api/me`) | ✅ | BG G4 | **Livré (PR #43, BCK-F)** : `CustomRoutesOpenApiFactory` |
| **Doc OpenAPI des routes custom restantes** (`CustomRoutesOpenApiFactory` ne documente que register/me/me-password/manual-edit×3/school-holidays/public-holidays — manquent ~18 routes custom : club appearance/logo, teams/reorder, health, set-baseline/reopen/validate, reset-season, calendar-entries conflicts, memberships pending/approve/reject, constraints/validate, password forgot/reset) | ⬜ | — | Gap constaté 2026-07-06 en régénérant le snapshot : toute route `#[Route]` hors API Platform est invisible de `/api/docs` + du snapshot tant qu'elle n'est pas ajoutée à la factory. Rattrapage en lot + réflexe « nouvelle route custom = entrée factory » · **🟡** |

---

## 10. UX & navigation

Polish frontend discuté, non structurant mais confort d'usage.

| Évolution | Statut | Note |
|-----------|--------|------|
| **Refonte de la navigation** — menu principal **à gauche** (Accueil, Assistant…) ; header **réduit** pour gagner de l'espace au centre ; **burger en haut à droite** pour Profil + déconnexion + thème dark/light | 🟡 | **Burger haut-droite livré** (PR #36) : menu unifié Club · Profil · Thème · Logout (`shared/components/ui/menu.tsx`), header allégé (nav gauche = Accueil · Assistant). **Reste** : menu latéral gauche + sous-menu Assistant (l. suivantes) |
| **Assistant = menu avec ses étapes en sous-menu** (les 6 étapes du wizard sous l'entrée « Assistant » du menu gauche) | ⬜ | Remplace la colonne d'étapes interne du wizard · **🟡** (refonte nav wizard) |
| **Clic sur le nom / logo du club → Accueil** | ⬜ | Raccourci d'accueil · **🟢** (un `<Link>`) |
| **« Demandes » fusionné dans « Gestion du club »** | ✅ | Livré (PR #36) : `/club` devient un hub à sections dépliables (`AccordionSection`) — **Demandes** (admin-only, ouverte par défaut, `PendingMembersSection`) + **Visuel** (identité). Route `/pending-members` supprimée, entrée de nav retirée |
| **Wizard : titre d'étape fixe en haut + Précédent/Suivant fixes en bas** (sticky header/footer) | ✅ | Livré : header (titre + n° d'étape) et footer Précédent/Suivant en `sticky` dans `WizardLayout.tsx`. Affiné (PR #37) : titre **unique** (h2 internes retirés des 6 steps), footer collé au **bas réel** (plus de bar flottant sur étape courte), nav gauche `w-44`, grille Génération raccourcie (`PlanningPage embedded`) pour ne pas passer sous le footer |
| **Wizard : colonne d'étapes repliable / plein écran** | ✅ | Livré : toggle « Plein écran » replie la nav gauche (`navCollapsed`) ; le masquage forcé sur l'étape génération est retiré → l'utilisateur contrôle (résout aussi l'accès menu en régénération) |
| **Menu : entrée « calendrier annuel »** | ⬜ | **♻ = l'accueil cockpit** ([`accueil-cockpit-temporel.md`](../courantes/accueil-cockpit-temporel.md), §2/§5) — remplace l'écran d'accueil actuel (planning), calendrier d'exceptions + radar · **🟡** (palier A) |
| **i18n des diagnostics solveur** (fichier de traduction) | ⬜ | Alertes **en français** obligatoire. Aujourd'hui messages FR codés en dur dans l'engine + reste EN à éliminer (`soft_lock_moved`, `unused_slot`). Cible : clés + fichier de traduction (côté backend `DiagnosticMessageBuilder` ou front) plutôt que du texte en dur dans l'engine · **🟢** (éliminer 2-3 clés EN dans `DiagnosticMessageBuilder` — FR obligatoire produit) |
| **Radar — carte « jour férié » à retravailler** (cibler ce qu'on doit voir + texte) | ⬜ | Différé constaté à la livraison des reliquats cockpit (2026-07-06). Aujourd'hui : « 14 juillet · Dans 8 j · jour férié » pour **tout** férié à ≤ 30 j (`PUBLIC_HOLIDAY_HORIZON_DAYS`), carte info sans CTA. À cadrer : **quels fériés méritent le radar** (tous ? seulement ceux qui tombent un jour d'entraînement de la semaine type ?), **le texte** (dire l'impact — ex. « 2 séances ce jour-là » — plutôt que répéter la date) et l'horizon. Décision produit, pas technique · **🟢** |

### Audit UX du wizard (2026-07-04)

> Audit réel (parcours navigateur BCCL 49 équipes + lecture code `frontend/src/features/wizard`). Le socle est bon (6 étapes claires, aide contextuelle, grille gymnases type calendrier, génération inline, 100 % FR, `aria-label` sérieux, faux positifs de diagnostics corrigés → 1 alerte). Les points ci-dessous ciblent la **confiance du gestionnaire non-technique**. Effort estimé à côté.

| Point | Priorité | Effort | Note |
|-------|----------|--------|------|
| **Échecs de mutation silencieux** (zéro `onError`/toast dans l'app) | ✅ | **🟢** | **Livré (FRT-01/02)** : filet global `MutationCache.onError` + `QueryCache.onError` → toasts (`shared/lib/queryClient.ts`), anti-doublon, 401 centralisé |
| **Suppressions immédiates sans confirmation, cascade** | ✅ | **🟢/🟡** | **Livré** : `ConfirmDialog` sur les suppressions cascade (gymnase + créneaux dans `VenuesStep`, équipe, contraintes) |
| **Aucun état de chargement** (vide ≡ en cours) | 🟡 | **🟢** | **✅ Livré** : `useStepValidation` reste neutre tant que les queries chargent (`isLoading`) → plus de flash « Ajoutez au moins une équipe » avant l'arrivée des données. Gardé par `useStepValidation.hook.test.tsx`. Skeletons de contenu restent optionnels |
| **Jargon solveur brut** (`HARD`/`PREFERRED`/`BONUS`/`LOCK` affichés) | 🟡 | **🟢** | Badges anglais + dropdown tronqué « PREFERRI ». Fix : libellés FR (Obligatoire/Préféré/Bonus/Verrouillé). Lié à l'i18n diagnostics ci-dessus |
| **Réservations en localStorage jamais revalidées** | 🟡 | **🟡** | Copie l'horaire au moment T ; si le créneau bouge/est supprimé → postée en LOCK HARD sur un horaire mort → solveur INFEASIBLE sans explication. Fix : référencer le slot + revalider au lancement (`useLaunchGeneration`) |
| **Étape Équipes = 1 seul écran de N lignes** (saisie 1 par 1, pas d'import) | 🟡 | **🟡** | 49 équipes tapées à la main. L'endpoint import Excel **existe déjà** (§8) — le brancher au wizard. + corriger le bug React **duplicate keys** de cet écran |
| **Deux classements concurrents non expliqués** : Rang (S/A/B/C/D priorité) vs Niveau de jeu (division FFBB) | ✅ | **🟢** | **Livré** : micro-copy dans l'aide de l'étape Équipes (« le niveau décrit la compétition, pas la priorité de placement ») + regroupement par rang S/A/B/C/D (PR #106) |
| **Piège nouveau gymnase** : après ajout d'un 2ᵉ gymnase, sélection reste sur le 1ᵉʳ → créneaux posés sur le mauvais | 🟢 | **🟢** | **✅ Livré** : le gymnase créé est auto-sélectionné (`VenuesStep`, `onSuccess`) → les créneaux tombent au bon endroit |
| **Polarité des jours invisible** : cocher un jour l'*interdit* (sens visible seulement après enregistrement) | 🟢 | **🟢** | Fix : label « éviter ces jours » |
| **Pas d'indicateur « étape terminée »** dans le rail (navigation libre pour club existant) | 🟢 | **🟢** | Fix : coches d'état par étape |
| **Focus non géré** après ajout (pas de retour au champ) ; **pas de dimanche** dans la grille alors que les contraintes l'autorisent ; colonne coach « a » / « Sans coach » (donnée à trancher) | 🟢 | **🟢** | **Focus ✅ Livré** (retour au champ nom après ajout équipe/gymnase/coach). **Dimanche : tranché — abandonné** (≈95 % des clubs amateurs ne s'entraînent pas le dimanche ; une grille 7 colonnes ne se justifie pas pour les 5 %). À rouvrir seulement si un vrai club pilote le demande. **Reste** : colonne coach « a » |

**Top 3 (2026-07-04) — statut au 2026-07-08** : (1) feedback d'erreur global ✅, (2) confirmation des suppressions ✅, (3) micro-copy Rang/Niveau ✅ ; **reste** les libellés FR des badges de contraintes (`HARD`/`PREFERRED`/`BONUS`/`LOCK` toujours affichés bruts — cf. ligne « jargon solveur » ci-dessus, lié à l'i18n diagnostics §10).

---

## Top des zones à réfléchir en priorité (non priorisées ici — juste les plus structurantes/non-suivies)

> **Chapitre 1 (Contraintes & solveur) : les 13 points d'origine sont clos** (série ENGINE, 2026-07-03) — tranchés + le repos après jour de match livré. (3 lignes ajoutées depuis, hors série : plage horaire coach, trajets/passerelles V2, typage engine — voir fin du tableau §1.) En prime, la série a **rendu effectives** des contraintes qui étaient silencieusement ignorées par le solveur (indispo coach par jour, FACILITY_CAPACITY, LOCK, allowedDays), corrigé les faux positifs de diagnostics, sorti le solve de l'event loop, ajouté un gate perf et PREFERRED TIME. Voir les PRs #26–#31.

1. ~~**Objectif mou « repos après jour de match »**~~ — **livré** (E-feat).
2. **Modèle temporel & périodes d'exception** (templates→occurrences, vacances, plans secondaires) — grosse feature produit.
3. **Bridage plan Découverte** — verrou de conversion, business-critique, rien de fait.
4. **Transition de saison** — nécessaire dès la 2e saison d'un club.

---

## Backlog priorisé — effort × impact (cap commercialisation mi-2027)

> Vue de pilotage (ex-`backlog-priorise.md`, absorbé le 2026-07-11). **Impact** 🔴 bloque la vente /
> l'intégrité · 🟠 fort levier · 🟡 valeur ciblée · ⚪ polish/dette. **Effort** S ≤ 1 PR · M 2-3 PR ·
> L lot phasé · XL recherche + gros lot. Un item livré **quitte** ces tableaux (trace : ligne de la
> carte §1-§10 mise à jour + entrée §Livrés) ; les ids sont **stables, jamais réutilisés** (un trou =
> un livré, pas un oubli).

### P0 — Bloquants GA & intégrité (à solder AVANT de vendre)

Trois impasses GA restantes (P0-1 RGPD livré le 2026-07-11 — cf. §Livrés et `docs/security/rgpd.md`).

| # | Sujet | Impact | Effort | Pourquoi maintenant | Dépend de |
|---|-------|:---:|:---:|---|---|
| P0-2 | **Config prod** — profil prod distinct, secrets managés, `APP_ENV=prod`/`DEBUG=0` durci, healthchecks, limites RAM. | 🔴 | M | Aucune config prod → pas déployable proprement. | — |
| P0-3 | **Backups PostgreSQL** — `pg_dump` planifié + restauration testée. | 🔴 | S | Zéro backup = perte totale sur incident. | P0-2 |
| P0-4 | **Observabilité** — Sentry + logs structurés sans PII + métriques. | 🔴 | M | Zéro visibilité prod. | P0-2 |

### P1 — Enablers à fort levier

| # | Sujet | Impact | Effort | Débloque | Note |
|---|-------|:---:|:---:|---|---|
| P1-1 | **Rôles non-admin + modèle de permissions** (`ClubUser.role` hardcodé `admin`) | 🟠 | L | self-service coach · collecte vacances (P2-1) · salle convivialité · comptes coach | `isManagementRole` = amorce ; voters à câbler partout |
| P1-2 | **Console superadmin — lot socle SA0** (backend ✅, frontend React ⬜) | 🟠 | L | crons vacances/purge · refresh FFBB manuel · métriques · reconcile stuck · impersonation lecture | backend auth/MFA/audit livré ; reste écran login + shell `/admin` |
| P1-3 | **Bridage freemium Découverte** | 🟠 | M | monétisation | doc'd, 0 code ; anti-abus par identité hors v1 |

### P2 — Différenciateurs & complétion

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P2-1 | **Plan de vacances éditable + collecte coach** | 🟠 | L | **le différenciateur commercial** ; collecte = lien tokenisé sans login. Gagne à ce que P1-1 existe, pas bloqué. Première marche UI actée : modale « Demandes des coachs » (vide) commune à la période — [`types-de-planning.md`](../courantes/types-de-planning.md) E5 |
| P2-5 | **Découpage hebdomadaire des overlays + écarts types-de-planning (E1-E4)** | 🟠 | L | Modèle validé 2026-07-12 ([`types-de-planning.md`](../courantes/types-de-planning.md)) : la **semaine** est l'unité hors socle. E1 : overlay = semaines auto englobant l'indispo + notification des suivants ; reprise = **choix des semaines** dans les vacances (N cochées ensemble = identiques). E2 : lever l'exclusion été (`isAdaptableHoliday`). E3 : défaut reprise = **Fanion + importantes** (aujourd'hui Fanion seul). E4 : séances/équipe ajustables dans le flux overlay (moteur OK, UI manquante). E6 : noms par défaut conformes (socle `Planning de la saison 20XX-20XX`, indispo `Ajustement {GYMNASE} du…au…`, reprise `Planning de vacances de {nom} du…au…`). À cadrer lot par lot, validation besoin avant chaque PR |
| P2-6 | **Pattern « Plan » (reconstruction ADR-0002)** | 🟠 | L | Entité de premier ordre pour « le planning » ([ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)) — 4 lots A→D. **Lot A livré (2026-07-12)** : `SchedulePlan` + `Schedule.schedulePlanId`/`versionNumber`, migration RLS + backfill, provisioning auto, API lecture ; billing `Plan`→`SubscriptionPlan`. Additif, zéro legacy retiré. Reste B (cycle de vie), C (réglages/génération par plan), D (nettoyage + NOT NULL). **Déférés à Lot B (tracés dans l'ADR)** : nettoyage `chosenScheduleId` à la suppression de version, numérotation de version monotone (compteur stocké vs `MAX+1`) |
| P2-2 | **Boucle d'ajustement — « corriger sur place »** (glisser une équipe dans un créneau vide) | 🟠 | M | « naviguer » fait (#180) ; même primitive que la grille de réservation |
| P2-3 | **Versions D4 — « Travailler sur cette version »** + savepoint auto | 🟡 | M | moitié manquante de la décision 5 (D1-D3 livrés) |
| P2-4 | **Compte démo** | 🟡 | S/M | spec'd ([`compte-demo.md`](compte-demo.md)), 0 code |

### P3 — Complétude modules

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P3-1 | **Matchs — reste palier A** : volet joueur, `Team.preferredMatchWindow`, envelope HARD | 🟡 | M | paliers B/C plus tard |
| P3-2 | **Overlays — période `custom` générante** (aujourd'hui 422, mitigé bouton désactivé) | 🟡 | M | seul reste des overlays |
| P3-3 | **Modèle « templates → occurrences »** | 🟡 | L | débloque la cascade baseline→secondaires |
| P3-4 | **Enregistrement FFBB** (anti-squatting code club) | 🟡 | M | spec'd (#145) |
| P3-5 | **Versions — diff/comparaison · restaurer une ARCHIVED** | 🟡 | L | hors périmètre D assumé |
| P3-6 | **`solver_metrics` — persistance + partition + purge 6 mois** | 🟡 | M | calculées, pas persistées ; alimente la console superadmin |
| P3-7 | **Import équipes Excel — UI wizard** | ⚪ | S | backend existe ; l'API FFBB remplacera à terme |

### P4 — Dette & polish (avant GA, par lots opportunistes)

| # | Sujet | Impact | Effort | Réf |
|---|-------|:---:|:---:|---|
| P4-1 | **FRT-02** — erreur de query avalée → vide trompeur (pas d'`isError`/retry UI) | 🟡 | S | audit 07-10 |
| P4-2 | **ENG-17 + ENG-24** — `coachId` de sortie limité aux slotTemplates → diagnostics coach inertes ; `coach_overload` confond les unités | 🟡 | M | audit |
| P4-3 | **FE1 — 3 composants wizard > 400 lignes** (TeamsStep 552 · ConstraintsStep 498 · VenuesStep 413) — extraire éditeurs/modales en composants frères, la step reste liste + orchestration ; PR dédiée + `/code-review` | ⚪ | M | dette FE1 / UXS-03 |
| P4-4 | **FE2 — registre tu/vous incohérent** (wizard tutoie ~29/15, cockpit vouvoie) — trancher un registre app-wide + sweep | ⚪ | S | dette FE2 |
| P4-5 | **FRT-18 / SEC-08 résiduel** — messages serveur bruts en toast + `ManualEditController:154` | ⚪ | S | audit |
| P4-6 | **Bundle unique 639 KB** — pas de code-splitting | ⚪ | S | audit perf |
| P4-7 | **B4 — publish Mercure dupliqué** (`GenerateScheduleHandler`/`ExportPdfHandler::publishProgress`) — extraire un `MercureScheduleNotifier` **seulement si un 3e publisher apparaît** (defer délibéré) | ⚪ | S | dette B4 |
| P4-8 | **BCK-10** — `requireActiveAdmin()` sans `clubId` (non déterministe multi-club ; pas de fuite grâce à RLS) | ⚪ | S | audit |
| P4-9 | **Radar « jour férié » à retravailler** (quels fériés + texte d'impact) | ⚪ | S | décision produit |
| P4-10 | **#3b — désactiver « Régénérer » si rien n'a changé** | ⚪ | M | détection de changement fiable |
| P4-11 | **lint-staged installé mais jamais invoqué** (pre-commit = build+tsc, aucune config) — le câbler ou le retirer | ⚪ | S | constaté 2026-07-11 (dependabot #91) |
| P4-12 | **`*PeriodOverride` — parité miroir à durcir (les 2 jumeaux)** : (a) processor `createEntityFromInput` = check-then-insert non-atomique → POST concurrents dupliqués → 500 au lieu de 422 (guard front supprime le trigger réaliste) ; (b) `#[ApiFilter(SearchFilter)]` inerte (provider custom lit les params à la main — ne sert que le snapshot OpenAPI) ; (c) provider ré-implémente `provideCollection` au lieu du hook `applyRequestFilters` de la base. ~~(d) `PeriodConstraints` : override `isActive=true` rendait la case cochable→create→422~~ **✅ résolu** (toggle = upsert-or-delete-to-default, `useUpdatePeriodConstraintOverride`, PR reprise 2026-07-12). (e) la règle de défaut reprise (CLUB/COACH/TEAM gardées, FACILITY droppée) est **dupliquée** PHP `activePermanentForReprise` + TS `defaultKept` sans source partagée (comme le contrat engine, sync manuelle) — une édition unilatérale ferait diverger checklist et payload ; test de parité ou schéma partagé à envisager. Toucher `TeamPeriodOverride` **et** `ConstraintPeriodOverride` ensemble pour (a/b/c) | ⚪ | S | code-review PR #211 (2026-07-12) |
| P4-15 | **`PeriodConstraints` — fidélité checklist vs payload (affichage seul, plan toujours correct)** : le front ne réplique pas toute la logique backend d'`buildForOverlay`. (a) une contrainte **CLUB+targetTag** dont toutes les équipes taguées sont en pause : le backend droppe ses lignes expansées, le front la montre cochée (le front ne résout pas tag→équipes — backend-only) ; (b) sous **erreur de fetch teamOverrides**, l'applicabilité TEAM est inconnue → lock conservateur des toggles TEAM (retire une capacité que `main` avait en closure) — l'inverse (ne pas locker) affiche un strike faux : tradeoff inhérent d'un edge de panne transitoire. Backend post-filtre **autoritaire** dans tous les cas → planning correct. Relève de la dette query-error UX **P4-1** ; à traiter avec une gestion d'erreur systématique des queries wizard | ⚪ | S | code-review PR #212 (2026-07-12) |
| P4-14 | **`expandClosedVenues` gated closure-only** — une contrainte datée FACILITY `config.type=venue_closed` attachée à une période **holiday/reprise** ne serait PAS expansée en `forbiddenVenueId` (l'engine ignore `venue_closed` brut → NO-OP silencieux). Inatteignable via l'UI aujourd'hui (`venue_closed` n'est créé que par le `ClosureForm` du cockpit sur une période closure) ; à couvrir si la reprise gagne un jour un raccourci « gym fermé ». Étendre l'expansion aux deux types ou verrouiller l'invariant | ⚪ | S | code-review PR #212 (2026-07-12) |
| P4-13 | **Validation pré-solve des overlays incomplète** — `ValidateConstraintsController` en mode période ne valide que les contraintes **datées** ; or `buildForOverlay` **hérite les permanentes** (closure depuis B3+F2, reprise depuis ce lot). Une permanente HARD héritée insatisfaisable → `valid=true` au récap puis INFEASIBLE au solve (statut `failed`, diagnostics posteriori) au lieu d'un diagnostic amont. Faire valider au controller **le même jeu** que shippe `buildForOverlay` (permanentes filtrées par défaut/override + datées). Advisory (le solve reste correct), pré-existant closure | ⚪ | S | code-review PR #212 (2026-07-12) |

### Parking (idées gardées, non cadrées)

- **Reverse-engineering des contraintes** (idée club #92) — fort attrait, effort XL, aucun cadrage.
- **Réservation salle de convivialité** (V2 « club hub ») — triviale en soi, bloquée sur P1-1.

### Ordre d'attaque conseillé

1. **P0 d'abord** — sans RGPD + prod + backups + observabilité, rien n'est vendable.
2. **P1-1 (rôles)** — le verrou qui débloque le plus de valeur aval.
3. **P1-2 (superadmin SA0)** — solde la colonne « manuel aujourd'hui ».
4. Puis **P2** selon l'appétit commercial, **P3/P4** par lots opportunistes.

---

## Dette technique — état vivant

> Ex-`docs/technical-debt.md` (absorbé le 2026-07-11). Règle d'origine conservée : **un item de
> dette n'existe qu'avec une preuve** (`fichier:ligne`). Les dettes actionnables vivent comme
> lignes **P4-x** ci-dessus (même pipeline que tout le reste) ; cette section ne garde que les
> **keeps délibérés** (décisions « ne pas corriger » qu'il faut pouvoir retrouver) et le solde.

| Item | Décision | Preuve / raison |
|------|----------|-----------------|
| **FE3 — ambre codé en dur dans `MonthCalendar.tsx`** (pas le token `--warning`) | 🟩 **keep délibéré** | `--warning` clair = 2.9:1 sur fond (A11Y-06) → l'utiliser sur le label vacances **échouerait WCAG AA** ; `amber-700` passe. Migrer = régression a11y contre un résidu de cohérence |
| **B4 — publish Mercure dupliqué** | 🟩 defer (ligne P4-7) | 2 handlers, payloads distincts — extraire au 3e publisher |
| **DP1 — contacts FFBB sur `club`** | ✅ soldé avec P0-1 (2026-07-11) | base légale intérêt légitime actée, survivent à la purge (annuaire adverse), export inclus — `docs/security/rgpd.md` §2 |
| **Dette `TeamTagService::syncTeamTags`** (efface les tags custom à chaque édition) | 🟧 à corriger avant de fiabiliser les tags custom | ligne `team_tags` §9 ; bloque le report des tags custom entre saisons |

**Soldé** (détail dans l'historique git de `docs/technical-debt.md`, supprimé le 2026-07-11) :
B1 (Rector 8.4) · B2 (PHPUnit unifié) · B3 (TenantCacheIsolationTest réel) · B6 (attributs PHPUnit 11) ·
B7 (tag LOISIR fixture) · E1-E6 (aliases morts, helpers dédupliqués, ADR-0001 single-pass, doc timeout,
TODOs PREFERRED TIME) — tous résolus le 2026-07-01.

---

## Livrés — traces datées (depuis la fusion du 2026-07-11)

> Quand un item du backlog est livré : sa ligne P*x-y* est **supprimée** des tableaux ci-dessus,
> la ligne correspondante de la carte (§1-§10) passe ✅ avec pointeur, et une ligne s'ajoute ici
> (date · id · sujet · où c'est documenté). C'est la trace que `documentation-update` maintient.

| Date | Id | Sujet | Documenté dans |
|------|----|-------|----------------|
| 2026-07-11 | P0-5 | Ids de créneau par-schedule (vol de créneau inter-version) | [`planning-versions.md`](planning-versions.md) §D3quater |
| 2026-07-11 | P0-1 | **RGPD socle** (5 PRs #199-#203) : effacement (anonymisation + purge club différée 30 j, identité FFBB épargnée, win-back), portabilité (exports JSON compte/club), rétention auto (inactifs 24 mois avec préavis 1 mois, saisons N-1 au cron), audit trail append-only (12 mois), consentement au register + page confidentialité (placeholders juridiques) | [`../../docs/security/rgpd.md`](../../docs/security/rgpd.md) (registre des traitements) |

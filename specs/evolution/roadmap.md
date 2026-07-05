# Roadmap — évolutions extraites des specs initiales

> **Règle du dossier `specs/evolution/`** : ce fichier est l'**index unique** — toute évolution, gap ou idée future y laisse une trace (une ligne de tableau au minimum). Un fichier à côté n'existe que pour **préciser un besoin** trop gros pour une ligne, et doit être **référencé depuis cette roadmap**. Quand un sujet est livré ou tranché, sa ligne est mise à jour ici et le fichier de détail devenu sans objet est supprimé (l'historique vit dans git).
> **Fichiers de détail actifs** : [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md) (§2 — modèle temporel, cockpit, périodes) · [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md) (§2 — plan de vacances éditable + collecte des demandes coach) · [`transition-de-saison.md`](transition-de-saison.md) (§3 — bascule saison N→N+1) · [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md) (§6 — bridage du plan gratuit).
> **But** : base de réflexion écrite pour les prochaines features. Extraction des capacités décrites dans `specs/initiales/` (ClubScheduler v3 + Spécification des contraintes v2), confrontées à l'état **livré**.
> **Statut** : ✅ livré · 🟡 partiel · ⬜ à faire. Les réf `FF#n` et `BG G#n` sont les identifiants **historiques** des anciens fichiers `features-futures.md` / `backend-gaps.md`, absorbés dans cette roadmap le 2026-07-05 (fichiers supprimés) — conservés pour la traçabilité.
> **Effort** (annoté sur les items actionnables, pour arbitrer sans re-poser la question) : **🟢 léger** (≈ ≤2 j, ciblé, peu de risque) · **🟡 moyen** (quelques jours, plusieurs fichiers/zones) · **🔴 lourd** (structurant / semaine+ / nouvelle archi ou dépendance de zone). L'effort estime le **coût de mise en œuvre**, pas la valeur produit.
> **Sources** : `initiales/ClubScheduler_v3.md` (réf `v3 §x`), `initiales/ClubScheduler_Specification_des_contraintes_v2.md` (réf `contraintes-v2`). Ces specs sont **figées** — ne pas les modifier.

Ce n'est pas un backlog priorisé : c'est la **carte** de ce que la vision d'origine contient et de ce qui reste à faire. On priorisera au moment de traiter chaque sujet.

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
| Déterminisme : `score_formula_version`, `constraint_version` exposés | ✅ | v3 §4.5 | Livré (PR #11) : `score_formula_version` = `SCORE_FORMULA_VERSION` (`T24_LEVEL_2_FIXED_WEIGHTS_V5` depuis E-feat) + `constraint_version` = version du contrat (`CONTRACT_VERSION`) dans la sortie. ⚠️ Écart spec : livré comme **version de contrat**, pas un SHA1 du payload comme évoqué en v3 §4.5 |
| `COACH_FORBIDDEN_TIME_RANGE` (plage horaire interdite coach, au-delà du jour entier) | ⬜ | contraintes-v2 | COACH_AVAILABILITY ne gère que les **jours** (`unavailableDays`), pas les plages horaires par jour. Config + application engine · **🟡** — même grille que le questionnaire coach (§2, collecte dispos) si celui-ci retient jours×plages |
| Matrice **temps de trajet** entre salles + **passerelles** équipes (U15→U18) — `venue_travel_times`, `team_links`, `category_passway_rules` | ⬜ | v3 §3.4, §4.1 · FF#5 | Tables absentes ; contraintes engine en stub (`travel_feasibility`, `required_bridge`). Lié au bridage « travel off » (§6) · **🔴** (modèle + solveur) — V2 |
| **Typage cœur engine** — dataclass `Assignment` remplaçant `AssignmentLike = Any` + ~50 alias de champs dans `constraints.py` | 🟡 | audit ENG-05 | Dette (pas une feature) : `parse_v2_constraints` déjà typé (`ParsedConstraints`) ; reste la couche assignment, protégée entre-temps par `tests/semantic/`. À reprendre avec une extension de couverture goldens · **🟡** |

---

## 2. Modèle temporel & périodes d'exception

Grosse zone quasi entièrement à faire — l'appli ne gère aujourd'hui qu'un plan de base hebdomadaire.

> **⭐ Approche tranchée — voir [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md).** Cette
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
| **Plans secondaires / alternatifs** (vacances, coupure, mutualisation) | 🟡 | v3 §8.1 · FF#2 | **♻ = overlays de période bornés** (cockpit §6). **Livré palier B (PR-4/5)** : `Schedule.calendarEntryId` = overlay (jamais baseline), généré via wizard mode période (structure R/O, contraintes de période actives), suppression centralisée `OverlayManager`, reopen destructeur du baseline (409 + confirm). closure + holiday seulement |
| **Overlay `periodType=cutoff`** (coupure = pas d'entraînement) | ⬜ | v3 §8.1 · FF#2 | **Différé palier B.** Ex. : « la semaine de coupure de Noël, aucun entraînement » → remplaçante totale = **fenêtre vide**, aucun overlay à générer (le calendrier re-projette rien). À implémenter : affichage « coupure » sans passer par le wizard/génération · **🟢** |
| **Overlay `periodType=mutualisation`** (`team_ids[]` fusionnés) | ⬜ | v3 §3.6 · FF#2 | **Différé palier C.** Ex. : « pendant la coupure de janvier, SM1 + SM2 s'entraînent ensemble le mercredi 20h sur un seul créneau » → nécessite la fusion `team_ids[]` côté solveur (créneau partagé). Gros morceau moteur + UI · **🟡** |
| **DayDialog « Créer une période… »** (période générique `custom`) | ⬜ | — | **Différé palier B/C.** Ex. : « poser une période custom du 10 au 20 mars, ni fermeture ni vacances » → `periodType=custom` non générant aujourd'hui → 422 à la création d'overlay = impasse UX. Bouton **désactivé** avec tooltip ; à activer quand `custom` devient générant · **🟢** |
| **Plan de vacances éditable + collecte des demandes coach** (structure de période éditable · lien coach sans login · écran demandes) | ⬜ | v3 §8.2 · FF#2 | **⭐ Besoin spécifié — voir [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md).** Le terrain **corrige** la lecture initiale « dispos coach → contrainte dure » : c'est une **négociation** (le coach émet un **souhait** de volume — garde/rien/réduit ; le gestionnaire **arbitre et tranche**, le lien n'écrit jamais de contrainte). **Structure de période éditable** (créneaux salle + séances/activation par équipe) via override `calendarEntryId` en copie-sur-édition ; **mutualisation gratuite** (réservation sur créneau à capacité 2, pas d'engine) ; collecte = lien tokenisé (patron reset-password, **date limite** gestionnaire) + **écran demandes avec case « traité »** (pas d'auto-génération). **Zéro changement engine.** **Phasé** : P1 structure éditable (le gestionnaire fait tout à la main) → P2 collecte coach (le différenciateur). Gymnases dispo-seulement-vacances = différé (patron extensible à `Venue`) · **🟡** |
| Mutualisation de créneau (SM1+SM2 même créneau) | ✅ | v3 §3.6 · FF#2 | **Tranché : via réservation, pas d'engine** — salle divisible (`canSplit`) + capacité 2 sur le créneau + réservation des 2 équipes → le solveur place les deux (couvre le partiel : 1 des 2 créneaux partagé, l'autre solo). Le `periodType=mutualisation` dédié + la fusion `team_ids[]` moteur sont **abandonnés** (inutiles). Détail : [`plan-vacances-collecte-coach.md`](plan-vacances-collecte-coach.md) §3 |
| `school_holiday_periods` (API Éducation Nationale, zones A/B/C) | ✅ | v3 §3.3 | **Livré (PR-2 cockpit palier A)** : table globale `school_holiday_period` (hors RLS), seed idempotent `app:school-holidays:seed` (JSON versionné, pas d'API runtime), `GET /api/school-holidays` filtré par zone. Zone dérivée du code FFBB (`SchoolZoneResolver` — 3 lettres ligue + 4 chiffres département) au register + backfill ; DOM/TOM → zone manuelle |
| **MAJ automatique des vacances via l'API calendrier scolaire** (1×/saison) | ⬜ | [API data.gouv](https://www.data.gouv.fr/dataservices/api-calendrier-scolaire) | Aujourd'hui : re-run manuel de `app:school-holidays:seed` après édition du JSON versionné. **Cible** : enrichir `school_holiday_period` automatiquement 1×/saison depuis l'**API officielle du ministère de l'Éducation nationale** (source fiable, accès ouvert — dataset « Le calendrier scolaire », ODS `data.education.gouv.fr/api/explore/v2.0` : zones, académies, dates début/fin, population élèves/enseignants). Forme : commande `app:school-holidays:import` (filtre population=Élèves, upsert idempotent comme le seed) déclenchée par cron annuel ou bouton superadmin ; le JSON versionné reste le fallback hors-ligne. L'UI superadmin d'édition manuelle (upload/CRUD, table globale hors tenant) devient secondaire — rattachée à la future console superadmin · **🟡** |
| **Calendriers vacances DOM/TOM** (hors zones A/B/C métropole) | ⬜ | — | Chaque DOM (Guadeloupe, Martinique, Guyane, Réunion, Mayotte) et chaque TOM (Nouvelle-Calédonie, Polynésie, Wallis, St-Pierre-et-Miquelon…) a **son propre calendrier scolaire**, non dérivable de la zone A/B/C. Aujourd'hui : `SchoolZoneResolver` renvoie null (≥ dép. 96) → zone/vacances **à saisir à la main**. **À faire** : modèle « territoire » (au-delà de zone A/B/C) + saisie manuelle **ou import API** par territoire, alimentant `school_holiday_period` — l'API calendrier scolaire ci-dessus expose les académies au-delà des zones métropole (couverture DOM/TOM exacte à vérifier à l'implémentation ; le reste = saisie manuelle). Rattaché à la console superadmin ci-dessus · **🟡** |
| **Vue calendrier annuel** (menu) — voir tous les plannings prévus sur l'année pour repérer les soucis + **vacances scolaires en cours** | ⬜ | — | **♻ = le cockpit lui-même** (cockpit §5) : calendrier mois entier, jour courant entouré, couche d'exceptions + vacances de la zone. **Livrable tôt (palier A)** en mode projection · **🟡** |
| **Régénération partielle guidée** (`PartialRegenService` — salle fermée / coach indispo → slots affectés identifiés + regen ciblée) | ⬜ | v3 §6.2, §14.2 · FF#1 | **♻ Partiellement couvert par les overlays** : une période (fermeture/vacances) génère un overlay borné (palier B livré) sans toucher le plan de base ; reste la regen **ciblée** du plan de base lui-même (hors période) — à requalifier quand un besoin réel se présente · **🔴** |

---

## 3. Onboarding & saisons

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Wizard initial de saisie | ✅ | v3 §9.1 | Livré (6 étapes, reconstruit) — dépasse le draft 4 étapes |
| Auto-save serveur du brouillon (`GET/PUT /api/clubs/{id}/draft` + `clubs.draft_data`) | ✅ | v3 §9.1 · BG G1/G2 | **Tranché : abandonné** — la persistance **par entité** (chaque salle/équipe/coach POST/PUT à la saisie, le store wizard ne tient aucune donnée) couvre déjà le besoin ; un draft-blob serait une 2e source de vérité. Ferme BG G1/G2 |
| **Mode démo** (club fictif pré-rempli, génération 30s avant saisie) | ⬜ | v3 §9.1, §12.3 | À faire (différé wizard) · **🟡** (fixture club fictif + parcours dédié) — **fort levier de vente** |
| **Transition de saison** (copie éditable des entrées N→N+1 · multi-saison simultané · bascule mi-juillet non destructive · revue guidée) | ⬜ | v3 §9.2 · FF#3 | **⭐ Besoin spécifié — voir [`transition-de-saison.md`](transition-de-saison.md).** Cadré au terrain : **copie des ENTRÉES** de N (gyms/équipes/coachs/contraintes, `parent_*_id` déjà en base), **éditable** (churn de marge attendu), puis génération fraîche ; **pas de modèle joueur** → copie de structure transparente. **Le vrai chantier = multi-saison simultané** (N-1 readonly + N + N+1 brouillon + sélecteur ; le tenant est mono-saison aujourd'hui). **Bascule mi-juillet non destructive** : « courante » dérivée du calendrier + réutilise la **gate cockpit** (`socleValidatedAt` null → force le baseline). **Phasé** : P1 transition fonctionnelle (copie + multi-saison + rétention) → P2 revue guidée + re-date événements + alertes. **🔴** |
| Capitalisation des contraintes entre saisons (copie éditable, `parent_*_id` self-FK) | ⬜ | v3 §3.1, contraintes-v2 §3 · FF#3 | **♻ = la copie des entrées** de la transition (voir [`transition-de-saison.md`](transition-de-saison.md)). Manque `parentConstraintId` (Team/Venue/Coach l'ont déjà) · **🔴** (dépend de la transition) |
| Politique de rétention (2 saisons max, saison N-1 read-only, purge RGPD, `solver_metrics` 6 mois) | ⬜ | v3 §3.2, §9.3 | **♻ Cadré dans la transition** ([`transition-de-saison.md`](transition-de-saison.md) §3) : fenêtre glissante N + N-1 readonly, **purge N-2**, N read-only à la bascule · **🟡** (commande de purge + verrou lecture-seule) — jalon RGPD |
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
| E2E Playwright (onboarding→génération→PDF) | 🟡 | v3 §13.3 · FF#13 | Auth e2e existe (mais **rouge**, cf. audit FRT-07) ; parcours complet à finir · **🟡** — commencer par **réparer `auth.spec` (2/3 rouge) 🟢** |
| Gate perf (dense_club < 180s) | ✅ | v3 §13.6 | **Livré (série ENGINE, cf. l.185)** : gate perf ajouté sur fixture dense. À re-confirmer dans une édition d'audit |

---

## 8. Imports & intégrations

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Import Excel équipes (template basket) | 🟡 | v3 §9.1, §12.3 | Backend livré (`POST /clubs/{id}/import-teams`, `FfbbExcelImporter`) ; **UI wizard différée** — l'API FFBB à venir remplacera l'import manuel · **🟢** (brancher l'UI sur l'endpoint existant) |
| Import CSV salles & coachs | ✅ | v3 §9.1 | **Tranché : abandonné** — peu de lignes (1-5 salles, ~10 coachs), pas de format standard ; la saisie manuelle suffit |
| **Fermetures de salle** (`VenueClosure` date+raison → regen partielle) | ⬜ | v3 §3.4 · BG G3 | **♻ Débloqué (cockpit §4, §9ter)** : = `CalendarEntry` `kind=period`, `periodType=closure` (additive), contrainte datée `FACILITY` + overlay borné. Plus besoin de la matérialisation J+14 amont · **🟡** (palier B) |
| Connecteurs **API municipales** (`venues.source` manual/municipal/external_api + `external_ref`) | ⬜ | v3 §1.4 · FF#20 | V2 · **🔴** |
| Import équipes **FFBB** (code club, `ffbb_team_id`) | ⬜ | v3 §1.4 · FF#19 | V2 · **🟡** (infra `ffbb_team_id`/`ffbbClubCode` déjà anticipée) — **supprime la moitié de la saisie** |
| Import **calendrier de matchs FFBB** (`competitions`, `ffbb_club_code`) | ⬜ | v3 §1.4 · FF#19 | V2 · **🔴** (dépend de la planification matchs) |
| **Planification des matchs** (entraînements + matchs) | ⬜ | v3 §1.4 · FF#21 | V2 · **🔴** |
| Dérivation fuseau/zone depuis l'adresse (API Géo + timezonedb → `school_zone`) | 🟡 | v3 §3.2 | **Zone livrée (PR-2)** : `school_zone` dérivé du `ffbbClubCode` par `SchoolZoneResolver` (dép.→zone, table fixe), **pas d'API Géo** · reste le **fuseau** (`ClubTimeService`, ligne §6.2). ⚠ heuristique FFBB→dép. best-effort, fallback zone manuelle |

---

## 9. Transverse

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Export PDF async (worker Puppeteer mono-thread) | ✅ | v3 §2.1 | Livré ; **rétention** (base/period/manual/is_pinned) ⬜ |
| **Audit trail** (`audit_logs` append-only, BRIN, purge RGPD, async) | ⬜ | v3 §3.2 · FF#6 | **🟡** (table + Doctrine listener + purge) — jalon RGPD |
| Table `solver_metrics` (perf par génération, partition mensuelle, purge 6 mois) | ⬜ | v3 §3.5 · FF#7 | **🟡** (les métriques sont déjà calculées par `SolverMetricsMapper` — reste à les persister/partitionner) |
| Diagnostics avec **suggestions actionnables** (jsonb = actions + liens entités) | 🟡 | v3 §7 | Diagnostics texte livrés ; actions cliquables ⬜ · **🟡** (jumeau de l'« alerte cliquable » §4) |
| Validation temps réel à la saisie (coach-joueur = seule erreur bloquante ; le reste = warnings) | 🟡 | v3 §11.3 | Wizard valide par étape ; **warnings de réservation livrés** (PR #14 : créneau surchargé vs capacité, quota séances/semaine, 2 séances le même jour — non bloquants, le solveur reste l'autorité) ; **warning équipe compétitive classée rang D · Bonus** (PR #35, dérivé du label du tier, non bloquant). Liste complète de warnings à compléter · **🟢** (compléter la liste au fil de l'eau) |
| Push temps réel (Mercure statut/score) | ✅ | v3 §2, §6 | Livré |
| **Identité visuelle par club** (couleur d'accent + logo) | ✅ | Livré 2026-07-02 : accent + logo + extraction 3 couleurs + écran /club (reste : stockage prod S3) → [`identite-visuelle-club.md`](../courantes/identite-visuelle-club.md) |
| `team_tags` / `team_tag_assignments` + règles facility par tag | 🟡 | contraintes-v2 | Tags système + ciblage tag livrés ; FACILITY_FORBIDDEN/PREFERRED_TEAM_TAG dédiés ⬜ · **🟡** |
| `max_days_per_week` coach (calcul dynamique + override checkbox) | 🟡 | v3 §3.4 | À vérifier · **🟢** |
| **Réservation salle de convivialité** (self-service coach : réserver une soirée dans une salle non-sportive → notif gestionnaire) | ⬜ | idée club (2026-07-05) | **V2 « club hub » — différé, gardé.** La résa elle-même est **triviale** (salle = `Venue` avec horaires, réservation = date + heure début + durée, **pas de solveur**, juste un check de conflit + notif au gestionnaire). ⚠️ **Le vrai coût n'est pas la résa** : « le coach réserve lui-même » exige des **comptes coach + modèle de rôles/permissions** (aujourd'hui `ClubUser.role` hardcodé `admin`, cf. §5 🔴). Tant que §5 n'est pas fait, ce n'est **pas** gratuit. **Question stratégique** : veut-on que l'appli devienne le *hub du club* (self-service coach) ou rester l'*outil de planning* (piloté gestionnaire) ? Adjacent au cœur (n'exploite pas le solveur — commodité type Doodle). **Déclencheur de réouverture** : construction des comptes coach/rôles (§5), ou demande d'un club pilote — la feature devient alors quasi gratuite · **🟢 la résa / 🔴 le prérequis rôles** |
| App **mobile** (React Native/Expo, consultation → exceptions V2) | ⬜ | v3 §1.4 · FF#14 | P2 · **🔴** (à ne pas faire avant clients payants — web responsive + PDF suffisent) |
| Notifications coach (email PDF → push + lien consultation sans login) | ⬜ | v3 §1.4 · FF#17 | P2 · **🟡** |
| Stats & analytics (taux de remplissage, heures-coach/semaine) | ⬜ | v3 §1.4 · FF#16 | P2 · **🟡** (peu coûteux une fois les occurrences là ; demande d'AG) |
| Dashboard super-admin (multi-clubs) | ⬜ | v3 §14.3 · FF#15 | P2 · **🔴** |
| **Multi-sport** (handball/gym/volley, modèle générique) | ⬜ | v3 §1.4 · FF#18 | V2 · **🔴** (attendre une vraie demande) |
| Doc OpenAPI de `AuthController` (`/api/register`, `/api/me`) | ✅ | BG G4 | **Livré (PR #43, BCK-F)** : `CustomRoutesOpenApiFactory` |

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
| **Menu : entrée « calendrier annuel »** | ⬜ | **♻ = l'accueil cockpit** ([`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md), §2/§5) — remplace l'écran d'accueil actuel (planning), calendrier d'exceptions + radar · **🟡** (palier A) |
| **i18n des diagnostics solveur** (fichier de traduction) | ⬜ | Alertes **en français** obligatoire. Aujourd'hui messages FR codés en dur dans l'engine + reste EN à éliminer (`soft_lock_moved`, `unused_slot`). Cible : clés + fichier de traduction (côté backend `DiagnosticMessageBuilder` ou front) plutôt que du texte en dur dans l'engine · **🟢** (éliminer 2-3 clés EN dans `DiagnosticMessageBuilder` — FR obligatoire produit) |

### Audit UX du wizard (2026-07-04)

> Audit réel (parcours navigateur BCCL 49 équipes + lecture code `frontend/src/features/wizard`). Le socle est bon (6 étapes claires, aide contextuelle, grille gymnases type calendrier, génération inline, 100 % FR, `aria-label` sérieux, faux positifs de diagnostics corrigés → 1 alerte). Les points ci-dessous ciblent la **confiance du gestionnaire non-technique**. Effort estimé à côté.

| Point | Priorité | Effort | Note |
|-------|----------|--------|------|
| **Échecs de mutation silencieux** (zéro `onError`/toast dans l'app) | 🔴 P0 | **🟢** | Un 422/500/offline n'affiche **rien**, et les champs se vident *avant* de savoir → donnée saisie perdue sans trace (vu en live : 422 muet sur Contraintes). = **FRT-01/02**. Fix : `MutationCache.onError` global + toast (sonner). **Meilleur ratio effort/valeur de tout le produit.** |
| **Suppressions immédiates sans confirmation, cascade** | 🔴 P0 | **🟢/🟡** | 1 clic poubelle = équipe / gymnase (+ créneaux) détruits, pas d'undo, delete raté silencieux. Fix : modale de confirmation, surtout cascade gymnase |
| **Aucun état de chargement** (vide ≡ en cours) | 🟡 | **🟢** | **✅ Livré** : `useStepValidation` reste neutre tant que les queries chargent (`isLoading`) → plus de flash « Ajoutez au moins une équipe » avant l'arrivée des données. Gardé par `useStepValidation.hook.test.tsx`. Skeletons de contenu restent optionnels |
| **Jargon solveur brut** (`HARD`/`PREFERRED`/`BONUS`/`LOCK` affichés) | 🟡 | **🟢** | Badges anglais + dropdown tronqué « PREFERRI ». Fix : libellés FR (Obligatoire/Préféré/Bonus/Verrouillé). Lié à l'i18n diagnostics ci-dessus |
| **Réservations en localStorage jamais revalidées** | 🟡 | **🟡** | Copie l'horaire au moment T ; si le créneau bouge/est supprimé → postée en LOCK HARD sur un horaire mort → solveur INFEASIBLE sans explication. Fix : référencer le slot + revalider au lancement (`useLaunchGeneration`) |
| **Étape Équipes = 1 seul écran de N lignes** (saisie 1 par 1, pas d'import) | 🟡 | **🟡** | 49 équipes tapées à la main. L'endpoint import Excel **existe déjà** (§8) — le brancher au wizard. + corriger le bug React **duplicate keys** de cet écran |
| **Deux classements concurrents non expliqués** : Rang (S/A/B/C/D priorité) vs Niveau de jeu (division FFBB) | 🟢 | **🟢** | Micro-copy expliquant qu'ils sont orthogonaux |
| **Piège nouveau gymnase** : après ajout d'un 2ᵉ gymnase, sélection reste sur le 1ᵉʳ → créneaux posés sur le mauvais | 🟢 | **🟢** | **✅ Livré** : le gymnase créé est auto-sélectionné (`VenuesStep`, `onSuccess`) → les créneaux tombent au bon endroit |
| **Polarité des jours invisible** : cocher un jour l'*interdit* (sens visible seulement après enregistrement) | 🟢 | **🟢** | Fix : label « éviter ces jours » |
| **Pas d'indicateur « étape terminée »** dans le rail (navigation libre pour club existant) | 🟢 | **🟢** | Fix : coches d'état par étape |
| **Focus non géré** après ajout (pas de retour au champ) ; **pas de dimanche** dans la grille alors que les contraintes l'autorisent ; colonne coach « a » / « Sans coach » (donnée à trancher) | 🟢 | **🟢** | **Focus ✅ Livré** (retour au champ nom après ajout équipe/gymnase/coach). **Dimanche : tranché — abandonné** (≈95 % des clubs amateurs ne s'entraînent pas le dimanche ; une grille 7 colonnes ne se justifie pas pour les 5 %). À rouvrir seulement si un vrai club pilote le demande. **Reste** : colonne coach « a » |

**Top 3 à faire en premier** (tous 🟢, ~1-2 j chacun, attaquent la confiance) : (1) feedback d'erreur global, (2) confirmation des suppressions, (3) libellés FR des contraintes + micro-copy Rang/Niveau.

---

## Top des zones à réfléchir en priorité (non priorisées ici — juste les plus structurantes/non-suivies)

> **Chapitre 1 (Contraintes & solveur) : les 13 points d'origine sont clos** (série ENGINE, 2026-07-03) — tranchés + le repos après jour de match livré. (3 lignes ajoutées depuis, hors série : plage horaire coach, trajets/passerelles V2, typage engine — voir fin du tableau §1.) En prime, la série a **rendu effectives** des contraintes qui étaient silencieusement ignorées par le solveur (indispo coach par jour, FACILITY_CAPACITY, LOCK, allowedDays), corrigé les faux positifs de diagnostics, sorti le solve de l'event loop, ajouté un gate perf et PREFERRED TIME. Voir les PRs #26–#31.

1. ~~**Objectif mou « repos après jour de match »**~~ — **livré** (E-feat).
2. **Modèle temporel & périodes d'exception** (templates→occurrences, vacances, plans secondaires) — grosse feature produit.
3. **Bridage plan Découverte** — verrou de conversion, business-critique, rien de fait.
4. **Transition de saison** — nécessaire dès la 2e saison d'un club.

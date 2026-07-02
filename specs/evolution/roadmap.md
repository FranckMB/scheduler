# Roadmap — évolutions extraites des specs initiales

> **But** : base de réflexion écrite pour les prochaines features. Extraction des capacités décrites dans `specs/initiales/` (ClubScheduler v3 + Spécification des contraintes v2), confrontées à l'état **livré**.
> **Statut** : ✅ livré · 🟡 partiel · ⬜ à faire. Les items déjà suivis pointent vers [`features-futures.md`](features-futures.md) (FF#) ou [`backend-gaps.md`](backend-gaps.md) (BG G#).
> **Sources** : `initiales/ClubScheduler_v3.md` (réf `v3 §x`), `initiales/ClubScheduler_Specification_des_contraintes_v2.md` (réf `contraintes-v2`). Ces specs sont **figées** — ne pas les modifier.

Ce n'est pas un backlog priorisé : c'est la **carte** de ce que la vision d'origine contient et de ce qui reste à faire. On priorisera au moment de traiter chaque sujet.

---

## 1. Contraintes & solveur (cœur métier)

Le modèle cible de contraintes (scopes, types de règles, familles, liste fermée de types) est **le plus gros bloc non livré**. Détail dédié : [`contraintes-modele-cible.md`](contraintes-modele-cible.md).

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Redesign contraintes : 5 scopes (CLUB/CATEGORY/TEAM/COACH/FACILITY) + types HARD/PREFERRED/BONUS/LOCK + 7 familles + liste fermée de types | ✅ | contraintes-v2 | **Tranché (liste fermée actée dans le code)** : scopes CLUB/TEAM/COACH/FACILITY (`ConstraintScope`), types HARD/PREFERRED/BONUS/LOCK (`ConstraintRuleType`), familles TIME/DAY/FACILITY/COACH_AVAILABILITY/FACILITY_CAPACITY (`ConstraintFamily`). Scope CATEGORY + familles ALLOCATION_PRIORITY/DISTRIBUTION **non retenus** |
| Salles **divisibles** + `max_parallel_trainings` (FACILITY_MAX_PARALLEL_TRAININGS) | ✅ | contraintes-v2 | **Tranché** : « Terrain divisible » (`Venue.canSplit`) + capacité par créneau (`VenueTrainingSlot.capacity`, forcée à 1 si non divisible — garde-fou builder S4) ; le parallélisme borné passe par la famille **`FACILITY_CAPACITY`** (`maxTeams` par créneau) plutôt qu'un type `max_parallel_trainings` dédié |
| `allow_shared_court` par équipe (jeunes partagent, seniors non) | ✅ | contraintes-v2 | **Tranché : abandonné** — non retenu au modèle (le partage de terrain passe par `canSplit` + capacité créneau, pas par une règle par équipe) |
| `CLUB_YOUNG_MAX_START_TIME` / `TEAM_MAX_START_TIME` (« jeunes pas après 19h30 ») | ✅ | contraintes-v2 | **Tranché** : couvert par la famille **TIME** (`maxStartTime`/`minStartTime`, appliqué dans `constraints.py`) ciblée via le tag `JEUNE` (`TeamTagService`). Décision : pas de type dédié 1ère classe |
| `CLUB_TRAINING_DURATION_BY_CATEGORY` (durée par défaut par catégorie) | ✅ | contraintes-v2 | **Tranché : abandonné** — la durée reste saisie par créneau, pas de défaut par catégorie |
| `CLUB_SLOT_GRANULARITY` (granularité 30 min) — **discordance** : v3 grille 15 min vs contraintes-v2 30 min | ✅ | contraintes-v2 / v3 §11.1 | **Tranché : granularité fixée à 15 min** (v3 l'emporte sur les 30 min de contraintes-v2) |
| Règle implicite : **coach principal présent à toutes les séances de son équipe** | ✅ | contraintes-v2 | Livré (S2) : seul le coach `MAIN` alimente le no-overlap dur ; l'assistant est optionnel |
| Objectif mou : **repos après jour de match** (+3, `teams.match_day`) | ⬜ | v3 §4.3 | Poids `rest` conservé (S1) comme paire future avec `matchDay` ; câblage engine à faire |
| Objectif mou : **regroupement même-coach-même-salle** (+50) | ✅ | v3 §4.3 | Livré (PR #10) sous forme de **bonus de chaînage borné** : sessions back-to-back du même coach récompensées via `CHAINING_TIER_WEIGHTS` (poids < 21 ⇒ ne déplace jamais un placement) ; optimisé en **phase 2** plafonnée à 10 s après verrouillage du placement |
| Niveau 0 — extraction des locks HARD hors solveur | ✅ | v3 §4.1 | **Tranché** : `_extract_hard_locks` (`model.py`) pré-place les slots HARD (`fixed_slots`, forcés hors solveur), `is_team_satisfied_by_hard_locks` retire les équipes déjà couvertes ; slots préservés dans la sortie quel que soit le statut |
| INFEASIBLE D1–D4 : rapport dégradé, diagnostics de conflit, suggestions texte, **relaxation partielle (D4 = P2)** | ✅ | v3 §7 | **Tranché** : D1–D3 livrés (diagnostics précis S5 — équipes/salle/jour+heure nommés, raison du non-placement, indice de sous-capacité INFEASIBLE). **D4 (relaxation auto) écartée par décision** : passe unique sans fallback silencieux (`fallback_used=False`, ADR-0001) → INFEASIBLE renvoie des diagnostics, jamais un plan aux contraintes relâchées en douce |
| Timeout **adaptatif** (60/180/600s selon complexité) | ✅ | v3 §5.1 | Livré (S3) : complexité = équipes × salles → paliers 60/180/600s, plafonné par le budget du payload (650s) |
| Déterminisme : `score_formula_version`, `constraint_version` exposés | ✅ | v3 §4.5 | Livré (PR #11) : `score_formula_version` = `SCORE_FORMULA_VERSION` (`T24_LEVEL_2_FIXED_WEIGHTS_V4`) + `constraint_version` = version du contrat (`CONTRACT_VERSION`) dans la sortie. ⚠️ Écart spec : livré comme **version de contrat**, pas un SHA1 du payload comme évoqué en v3 §4.5 |

---

## 2. Modèle temporel & périodes d'exception

Grosse zone quasi entièrement à faire — l'appli ne gère aujourd'hui qu'un plan de base hebdomadaire.

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Architecture **templates → occurrences** (dates réelles, matérialisation glissante J+14) | ⬜ | v3 §3.5, §8.1 · FF#8 | `schedule_slot_occurrences` |
| Occurrences : exceptions, annulations, déplacements, remplacements coach/salle, fusions | ⬜ | v3 §3.5 · FF#8 | |
| **Périodes d'exception** (`period_templates` + `is_cutoff`, `period_template_slots` avec `team_ids[]`, `period_assignments`, `period_coach_responses`) | ⬜ | v3 §3.6, §8 · FF#2 | Vacances scolaires, coupures, mutualisation |
| Scheduler quotidien @8h : détecte période sous 14j → assignment pending + alerte (J-7 rappel, J-3 rouge), jamais d'auto-action | ⬜ | v3 §8.2 · FF#2 | |
| **Plans secondaires / alternatifs** (vacances, coupure, mutualisation) | ⬜ | v3 §8.1 · FF#2 | |
| Collecte des besoins coach par **lien sans login** (questionnaire email → `period_coach_responses`) | ⬜ | v3 §8.2 · FF#2 | |
| Mutualisation de créneau (`team_ids[]`, ex. SM1+SM2 même créneau) | ⬜ | v3 §3.6 · FF#2 | |
| `school_holiday_periods` (API Éducation Nationale, zones A/B/C) | ⬜ | v3 §3.3 | Alimente les périodes |
| **Vue calendrier annuel** (menu) — voir tous les plannings prévus sur l'année pour repérer les soucis + **vacances scolaires en cours** | ⬜ | — | Dérive la **zone de vacances** depuis la ville/région/département du club → dates de vacances associées. Dépend de la dérivation zone depuis l'adresse (§8) + `school_holiday_periods` |

---

## 3. Onboarding & saisons

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Wizard initial de saisie | ✅ | v3 §9.1 | Livré (6 étapes, reconstruit) — dépasse le draft 4 étapes |
| Auto-save serveur du brouillon (`GET/PUT /api/clubs/{id}/draft` + `clubs.draft_data`) | ⬜ | v3 §9.1 · BG G1/G2 | Choix acté : save **par entité**, pas de draft-blob → probablement **abandonné** (à confirmer) |
| **Mode démo** (club fictif pré-rempli, génération 30s avant saisie) | ⬜ | v3 §9.1, §12.3 | À faire (différé wizard) |
| **Wizard transition de saison** (5 étapes hybride : salles diff / coachs keep-modify-archive / équipes keep-modify-dissolve / passations & priorités / récap) | ⬜ | v3 §9.2 · FF#3 | `SeasonTransitionService` |
| Capitalisation des contraintes entre saisons (copie éditable, `parent_*_id` self-FK) | ⬜ | v3 §3.1, contraintes-v2 §3 · FF#3 | |
| Politique de rétention (2 saisons max, saison N-1 read-only, purge RGPD, `solver_metrics` 6 mois) | ⬜ | v3 §3.2, §9.3 | |
| **Réinitialiser son club** (RAZ des données pour repartir de zéro) | 🟡 | — | `ResetSeasonController` (`DELETE /api/reset-season`) existe côté données saison ; manque une action « reset club » assumée + son UI (avec confirmation) |

---

## 4. Édition manuelle (boucle de travail)

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Niveaux de lock NONE/SOFT/HARD (enum, ≤30 min = SOFT silencieux) | 🟡 | v3 §3.5, §11.4 | Schéma + lock HARD/SOFT posables ; règle « ≤30min = SOFT auto » à vérifier |
| **Dialogue post-modification** (déplacement significatif → créer contrainte permanente / lock SOFT|HARD / occurrence one-time « convertir en contrainte ? ») | 🟡 | v3 §11.4 · FF#4 | Service de base existe ; manque création de contrainte permanente + `source_occurrence_id` |
| **Alerte diagnostic cliquable → focus dans la grille** | ⬜ | | Cliquer une alerte (ex. créneau vide / `unused_slot`) doit **sélectionner et afficher** le créneau concerné dans la grille du planning, comme si l'utilisateur l'avait cliqué. Implique d'**afficher les créneaux vides** (disponibilités non utilisées) dans la grille, pas seulement les séances placées |
| `ScheduleDiffService` (diff lisible entre 2 snapshots) | ⬜ | v3 §6.2 · FF#11 | |
| Routes manual-edit documentées (OpenAPI) + naming `schedule-slots` vs `schedule_slot_templates` | 🟡 | BG G5/G6 | Endpoints existent, non documentés |

---

## 5. Multi-tenant, sécurité, concurrence

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Isolation 3 couches (ClubVoter + TenantFilterListener + RLS FORCE) | ✅ | v3 §10.1 | Livré (listener priorité 7) |
| Verrou de génération (1/club, Messenger + asyncio.Lock engine) | ✅ | v3 §5.1 | `ConcurrentGenerationTest` |
| Snapshot gelé (`snapshot_data` + `snapshot_hash`) | ✅ | v3 §10.2 | |
| **Optimistic locking** (`version` + 409 sur conflit) | 🟡 | v3 §10.2 | `version` exposé ; gestion 409 à généraliser/vérifier |
| **Rate limiting** (api 200/min, generate 5/h, pdf 10/h par club) | ⬜ | v3 §10.2 | À vérifier/implémenter (register limité seulement) |
| **Deux users DB** (app_user sans DDL + migration_user) | ⬜ | v3 §10.1 | À vérifier |
| CacheInvalidationListener ciblé (Venue/Coach/Team/Schedule) | 🟡 | v3 §6.2 · FF#9 | Stub actuel |
| `ClubTimeService` (UTC ↔ fuseau club) | ⬜ | v3 §6.2 · FF#10 | |

### Rôles & membres (non couvert par les initiales — issu du hors-scope wizard)

| Évolution | Statut | Note |
|-----------|--------|------|
| **Rôles non-admin** (coach / lecteur au-delà d'`admin`) + modèle de permissions | ⬜ | Aujourd'hui `ClubUser.role` est **hardcodé `'admin'`** au register ; pas de différenciation de droits |
| **Gestion des membres** (inviter / changer rôle / désactiver) — écran admin club | ⬜ | `ClubUser` (membership) existe ; pas d'UI |
| **Approbation des demandes d'adhésion** (rejoindre un club → membre `pending`) | 🟡 | Le flux register crée un membre pending + `WaitingApprovalPage` ; **manque** l'écran admin pour approuver/refuser |
| **Modifier ses informations personnelles** (nom, email, mot de passe) | ⬜ | Nav « Profil » existe + reset mot de passe ; manque l'édition du profil (prénom/nom/email) + changement de mot de passe connecté |

---

## 6. Pricing & bridage (business-critique)

Zone entièrement à faire — aucun bridage plan aujourd'hui.

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| 4 plans tarifaires (Découverte/Petit/Club/Grand) : max_teams/venues/generations, prix en DB | ⬜ | v3 §12.1 | Table `plans` + modèle à vérifier |
| Bridage **Découverte** : 3 générations/saison (lock conversion), coach-joueur off, passations off, travel off, 1 contrainte préférée/équipe, pas de PDF, pas de transition saison | ⬜ | v3 §12.2 | Verrou de conversion clé |
| Enforcement `generation_count_season` (compteur par saison) | ⬜ | v3 §3.2 | Champ existe, pas d'enforcement |
| `billing_cycle` / `plan_expires_at` | ⬜ | v3 §3.2 | Modèle |

---

## 7. Tests

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| 4 tests bloquants CI (TenantIsolation, TenantCache, ConcurrentGeneration, ContractSchema) | ✅ | v3 §13.2 | Livrés + CI vert |
| Golden datasets (simple/medium/dense/impossible/**vacation_week**/**partial_regen**) | 🟡 | v3 §13.5 | Base présente ; vacation_week/partial_regen à vérifier |
| Invariants Hypothesis (no double-booking, coach unique, coach-joueur cohérent, HARD préservé, tier-S jamais sacrifié si D placé) | 🟡 | v3 §13.5 | Partiel |
| Tests unitaires frontend (Vitest + RTL) | 🟡 | v3 §13.3 · FF#12 | Livrés depuis (wizard/planning testés) — FF à requalifier |
| E2E Playwright (onboarding→génération→PDF) | 🟡 | v3 §13.3 · FF#13 | Auth e2e existe ; parcours complet à finir |
| Gate perf (dense_club < 180s) | ⬜ | v3 §13.6 | Critère de sortie MVP-strict |

---

## 8. Imports & intégrations

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Import Excel équipes (template basket) | 🟡 | v3 §9.1, §12.3 | Contrôleur import existe ; à recâbler dans le wizard (différé) |
| Import CSV salles & coachs | ⬜ | v3 §9.1 | |
| **Fermetures de salle** (`VenueClosure` date+raison → regen partielle) | ⬜ | v3 §3.4 · BG G3 | Différé wizard |
| Connecteurs **API municipales** (`venues.source` manual/municipal/external_api + `external_ref`) | ⬜ | v3 §1.4 · FF#20 | V2 |
| Import équipes **FFBB** (code club, `ffbb_team_id`) | ⬜ | v3 §1.4 · FF#19 | V2 |
| Import **calendrier de matchs FFBB** (`competitions`, `ffbb_club_code`) | ⬜ | v3 §1.4 · FF#19 | V2 |
| **Planification des matchs** (entraînements + matchs) | ⬜ | v3 §1.4 · FF#21 | V2 |
| Dérivation fuseau/zone depuis l'adresse (API Géo + timezonedb → `school_zone`) | ⬜ | v3 §3.2 | |

---

## 9. Transverse

| Évolution | Statut | Réf | Note |
|-----------|--------|-----|------|
| Export PDF async (worker Puppeteer mono-thread) | ✅ | v3 §2.1 | Livré ; **rétention** (base/period/manual/is_pinned) ⬜ |
| **Audit trail** (`audit_logs` append-only, BRIN, purge RGPD, async) | ⬜ | v3 §3.2 · FF#6 | |
| Table `solver_metrics` (perf par génération, partition mensuelle, purge 6 mois) | ⬜ | v3 §3.5 · FF#7 | |
| Diagnostics avec **suggestions actionnables** (jsonb = actions + liens entités) | 🟡 | v3 §7 | Diagnostics texte livrés ; actions cliquables ⬜ |
| Validation temps réel à la saisie (coach-joueur = seule erreur bloquante ; le reste = warnings) | 🟡 | v3 §11.3 | Wizard valide par étape ; couvrir la liste complète de warnings |
| Push temps réel (Mercure statut/score) | ✅ | v3 §2, §6 | Livré |
| **Identité visuelle par club** (couleur d'accent + logo) | ✅ | Livré 2026-07-02 : accent + logo + extraction 3 couleurs + écran /club (reste : stockage prod S3) → [`identite-visuelle-club.md`](identite-visuelle-club.md) |
| `team_tags` / `team_tag_assignments` + règles facility par tag | 🟡 | contraintes-v2 | Tags système + ciblage tag livrés ; FACILITY_FORBIDDEN/PREFERRED_TEAM_TAG dédiés ⬜ |
| `max_days_per_week` coach (calcul dynamique + override checkbox) | 🟡 | v3 §3.4 | À vérifier |
| App **mobile** (React Native/Expo, consultation → exceptions V2) | ⬜ | v3 §1.4 · FF#14 | P2 |
| Notifications coach (email PDF → push + lien consultation sans login) | ⬜ | v3 §1.4 · FF#17 | P2 |
| Stats & analytics (taux de remplissage, heures-coach/semaine) | ⬜ | v3 §1.4 · FF#16 | P2 |
| Dashboard super-admin (multi-clubs) | ⬜ | v3 §14.3 · FF#15 | P2 |
| **Multi-sport** (handball/gym/volley, modèle générique) | ⬜ | v3 §1.4 · FF#18 | V2 |
| Doc OpenAPI de `AuthController` (`/api/register`, `/api/me`) | ⬜ | BG G4 | |

---

## 10. UX & navigation

Polish frontend discuté, non structurant mais confort d'usage.

| Évolution | Statut | Note |
|-----------|--------|------|
| **Refonte de la navigation** — menu principal **à gauche** (Accueil, Assistant…) ; header **réduit** pour gagner de l'espace au centre ; **burger en haut à droite** pour Profil + déconnexion + thème dark/light | ⬜ | Aujourd'hui tout est à plat dans le header (trop gros) |
| **Assistant = menu avec ses étapes en sous-menu** (les 6 étapes du wizard sous l'entrée « Assistant » du menu gauche) | ⬜ | Remplace la colonne d'étapes interne du wizard |
| **Clic sur le nom / logo du club → Accueil** | ⬜ | Raccourci d'accueil |
| **« Demandes » fusionné dans « Gestion du club »** | ⬜ | « Demandes » n'est qu'un wrap du club (approbation des membres) → le mettre comme section du hub /club, pas une entrée de menr distincte |
| **Wizard : titre d'étape fixe en haut + Précédent/Suivant fixes en bas** (sticky header/footer) | ⬜ | Aujourd'hui titre et footer scrollent avec le contenu |
| **Wizard : colonne d'étapes repliable / plein écran** | ⬜ | Trop large ; « agrandir » masque la nav (à trancher). Recoupe l'idée « étapes en sous-menu » ci-dessus |
| **Menu : entrée « calendrier annuel »** | ⬜ | Voir §2 — vue annuelle des plannings + vacances |
| **i18n des diagnostics solveur** (fichier de traduction) | ⬜ | Alertes **en français** obligatoire. Aujourd'hui messages FR codés en dur dans l'engine + reste EN à éliminer (`soft_lock_moved`, `unused_slot`). Cible : clés + fichier de traduction (côté backend `DiagnosticMessageBuilder` ou front) plutôt que du texte en dur dans l'engine |

---

## Top des zones à réfléchir en priorité (non priorisées ici — juste les plus structurantes/non-suivies)

> **Chapitre 1 (Contraintes & solveur) clos** : les 13 points sont tranchés (liste fermée de contraintes, salles divisibles via `FACILITY_CAPACITY`, granularité 15 min, pré-placement HARD, INFEASIBLE sans relaxation auto…) ; **seul reste ouvert** l'objectif mou « repos après jour de match » (point 8, `teams.match_day` reçu mais non câblé dans l'objectif engine).

1. **Objectif mou « repos après jour de match »** (point 8, `teams.match_day`) — seul reliquat du chapitre 1 ; câblage engine à faire.
2. **Modèle temporel & périodes d'exception** (templates→occurrences, vacances, plans secondaires) — grosse feature produit.
3. **Bridage plan Découverte** — verrou de conversion, business-critique, rien de fait.
4. **Transition de saison** — nécessaire dès la 2e saison d'un club.

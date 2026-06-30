# ClubScheduler

Spécification technique complète --- Version 3.0

Document unique consolidé --- Prêt pour OpenCode / Cursor

| Domaine | Valeur |
|---|---|
| Produit | Générateur automatique de planning pour clubs sportifs amateurs |
| Sport MVP | Basket (FFBB) --- multi-sport en V2 |
| Club référence | 41 équipes · 8 gymnases · 10-20 coaches (top 15 clubs France) |
| Problème résolu | 1 semaine de travail manuel → génération automatique < 3 minutes |
| Stack | Symfony 7 · Python OR-Tools · React · React Native · PostgreSQL · Redis |
| Modèle commercial | SaaS B2B multi-tenant --- abonnement annuel par club |
| Version doc | 3.0 --- consolidation v2 + v2.1 (C1/C2/C3) + v2.2 (C4) |

## 1. Contexte & Objectifs

Pourquoi ce produit existe et ce qu'il résout

### 1.1 Problème métier

Les clubs sportifs amateurs gèrent leurs plannings d'entraînement manuellement. Pour un club de taille moyenne à grande (15-50 équipes), cela représente 1 semaine de travail par saison pour le gestionnaire salarié --- à répéter à chaque changement exceptionnel.

ClubScheduler automatise cette génération via OR-Tools CP-SAT en tenant compte de toutes les règles métier : disponibilités salles, indisponibilités coaches, priorités équipes, trajets entre gymnases, passerelles entre équipes, et bien d'autres.

### 1.2 Utilisateur principal

Le gestionnaire salarié du club (1-2 personnes max dans un club amateur). Travaille depuis son bureau. App mobile = consultation uniquement.

- Prépare le planning annuel en août-septembre
- Gère les exceptions tout au long de la saison
- Exporte en PDF pour diffusion aux coaches et affichage en salle
- Enrichit progressivement le modèle de contraintes au fil des saisons

### 1.3 Valeur produit

| Avant | Avec ClubScheduler |
|---|---|
| 1 semaine de travail manuel | Génération en < 3 minutes |
| Replanification = nouvelle semaine | Régénération partielle en quelques minutes |
| Conflits détectés trop tard | Détection temps réel à la saisie |
| Expertise implicite dans la tête d'une personne | Modèle de contraintes documenté et réutilisable |

### 1.4 Roadmap MVP vs V2

| Feature | MVP | V2 |
|---|---|---|
| Sport | Basket uniquement | Multi-sport (handball, gym, volley...) |
| Génération planning entraînements | ✅ | ✅ |
| Génération planning matchs FFBB | ❌ | ✅ Import calendrier FFBB |
| Import équipes depuis FFBB | ❌ | ✅ Via code club FFBB |
| Connexion API réservation gymnases mairie | ❌ | ✅ Connecteurs municipaux |
| App mobile | Consultation seule | Exceptions rapides + notifications coaches |
| Notifications coaches | Email PDF | Push mobile + lien consultation sans login |
| Stats & analytics | ❌ | ✅ Taux remplissage, heures coach/semaine |

> V2 : anticiper dès le MVP --- ffbb_team_id sur teams, venues.source enum, table competitions vide.

## 2. Stack technique

Choix technologiques et justifications

### 2.1 Architecture globale

> 2 services distincts. Symfony API (PHP) + Microservice OR-Tools (Python). Monolithe modulaire Symfony avec modules découplés (Scheduling, Clubs, Users, Export, Audit).

| Couche | Technologie | Librairies clés |
|---|---|---|
| Frontend web | React + Tailwind + Vite | FullCalendar · react-dnd · Zustand · React Query · date-fns-tz |
| Mobile | React Native + Expo | Expo Router · React Query --- consultation seule MVP |
| API Backend | Symfony 7 + API Platform | Doctrine ORM · Messenger · Mercure · Lexik JWT · Scheduler |
| Solver | Python 3.12 + FastAPI + OR-Tools CP-SAT | Pydantic v2 · mypy · Ruff · pytest · hypothesis |
| Base de données | PostgreSQL 16 | RLS activé · UUID partout · timestamptz |
| Queue / Cache | Redis 7 Alpine | Symfony Messenger · 3 pools cache |
| Infra | Docker + Scaleway VPS | GitHub Actions · Sentry · Mercure hub |
| Stockage fichiers | Scaleway Object Storage (S3) | StorageInterface abstraction --- RGPD EU |
| Export PDF | Puppeteer (Node) --- container dédié | Queue dédiée mono-thread --- 1 export à la fois |
| Timezone | timezonedb/timezonedb | ClubTimeService · date-fns-tz --- France + DOM-TOM |

### 2.2 Services Docker

| Service | Rôle | RAM limite |
|---|---|---|
| php-fpm | API Symfony (xdebug dev uniquement) | 512MB |
| nginx | Reverse proxy | 64MB |
| postgres | Base principale, RLS activé | 512MB |
| redis | Queue Messenger + 3 pools cache | 256MB |
| messenger-worker | Consomme queue async | 256MB |
| engine | FastAPI + OR-Tools --- port 8000 interne uniquement | 512MB |
| pdf-worker | Puppeteer mono-thread | 512MB |
| mercure | Push temps réel → React + RN | 128MB |
| mailpit | Catch-all emails dev | 64MB |

### 2.3 Qualité code

| Outil | Langage | Niveau |
|---|---|---|
| PHPStan | PHP | Niveau 8 --- extensions Doctrine/Symfony/APIPlatform |
| PHP-CS-Fixer | PHP | PSR-12 + règles Symfony --- correction automatique |
| Rector | PHP | PHP 8.3 + Symfony 7 |
| PHPUnit 11 | PHP | ApiTestCase + Foundry + Faker + Testcontainers |
| Ruff + mypy | Python | Strict --- Pydantic + mypy = contrat garanti |
| pytest + hypothesis | Python | Golden datasets + invariants + performance |
| GitHub Actions | CI/CD | Pipeline PR --- bloque merge si check échoue |

## 3. Schéma de données

22 tables PostgreSQL 16 avec RLS

### 3.1 Règles globales

- UUID sur tous les IDs --- obligatoire multi-tenant
- club_id + season_id sur toutes les tables métier
- timestamptz pour tous les timestamps système
- time (heure locale club) pour les heures de créneaux --- clubs.timezone comme référence
- version smallint sur entités éditables --- Doctrine optimistic locking natif
- parent_*_id nullable FK self-referencing sur teams, coaches, venues --- traçabilité inter-saisons
- RLS PostgreSQL activé avec FORCE sur toutes les tables avec club_id
- Index composite (club_id, season_id) + partial indexes (is_active=true) sur toutes les tables métier
- BRIN index sur created_at pour les tables d'historique

Champs JAMAIS exposés en écriture API : club_id · season_id · version · snapshot_* · created_at · updated_at · pdf_export_* · solver_* --- toujours injectés côté Symfony.

### 3.2 Tenant & Auth

| Table | Champs clés | Notes |
|---|---|---|
| clubs | id · name · slug · plan · billing_cycle · plan_expires_at · generation_count_season · school_zone · timezone · locale · onboarding_completed · ffbb_club_code (V2) | timezone déduite depuis adresse via API Géo + timezonedb |
| users | id · email · password · first_name · last_name | Auth globale --- un user peut appartenir à plusieurs clubs |
| club_users | club_id · user_id · role (admin/editor/viewer) · joined_at | Pivot rôle par club |
| seasons | id · club_id · name · start_date · end_date · status · export_pdf_url · transition_data (jsonb) | Max 2 saisons avec données complètes. La 3ème purge la plus ancienne |
| audit_logs | id · club_id · season_id · user_id · action · entity_type · entity_id · before (jsonb) · after (jsonb) · metadata (jsonb) · ip_address · created_at | Append-only. BRIN sur created_at. Purge : sécurité 1 an, métier saison+1 an |

### 3.3 Référentiel global

| Table | Champs clés | Notes |
|---|---|---|
| sports | id · name · slug · icon · is_active | MVP : basket uniquement. V2 : autres sports |
| sport_categories | id · sport_id · club_id (nullable=global) · name · is_custom · age_min · age_max · sort_order | Catégories fédérales (club_id null) + custom par club |
| club_sports | club_id · sport_id · is_primary | MVP : basket auto-alimenté à la création du club |
| priority_tiers | id (1-5) · label (S/A/B/C/D) · name · color · or_tools_weight · default_min_sessions | Fixe --- seeder uniquement. Jamais modifié en prod |
| plans | id · name · max_teams · max_venues · max_generations · monthly_price · annual_price · features (jsonb) | Prix modifiables sans migration |
| category_passway_rules | category_from_id · category_to_id · is_allowed | Hiérarchie globale passerelles. U15→U18 ✅, U21→U18 ❌ |
| school_holiday_periods | id · season_id · name · zone (A/B/C) · date_start · date_end · type | Alimenté depuis API Éducation Nationale. Global par zone |

### 3.4 Entités métier

| Table | Champs clés | Notes |
|---|---|---|
| venues | id · club_id · season_id · name · is_external · color · latitude · longitude · source (manual/municipal/external_api) · external_ref · is_active · version · parent_venue_id | source + external_ref anticipent V2 connecteurs mairie |
| venue_availabilities | venue_id · day_of_week (0=lun...5=sam) · start_time · end_time | day_of_week=5 = samedi matin (loisirs/baby) |
| venue_closures | venue_id · date_start · date_end · reason | Déclenche alert régénération partielle |
| venue_travel_times | venue_from_id · venue_to_id · travel_minutes · is_feasible · travel_notes · last_verified_at | Matrice statique. Alerte si last_verified_at > 1 an |
| coaches | id · club_id · season_id · first_name · last_name · email · phone · max_days_override · max_days_override_confirmed · acceptable_late_minutes · is_active · version · parent_coach_id | max_days calculé dynamiquement. Override = checkbox explicite obligatoire |
| coach_unavailabilities | coach_id · day_of_week · start_time (nullable) · end_time (nullable) | nullable = journée entière indisponible |
| coach_player_memberships | coach_id · team_id · position · is_active | Créneau joueur = indispo auto coaching |
| teams | id · club_id · season_id · sport_category_id · priority_tier_id · name · gender · sessions_per_week · min_sessions_override · match_day · forced_venue_id · is_active · version · parent_team_id · ffbb_team_id (V2) | ffbb_team_id anticipe import V2 |
| team_coaches | team_id · coach_id · role (head/assistant) · is_required | |
| team_constraints | team_id · type (fixed/forbidden/preferred) · day_of_week · start_time · end_time · venue_id · reason · created_by (manager/manual_edit/import) · source_occurrence_id | created_by trace l'origine. source_occurrence_id trace édition manuelle |
| team_links | team_id_from · team_id_to · no_overlap · priority (required/preferred/optional) | required=contrainte dure. preferred=poids -80. optional=poids -5 |

### 3.5 Planning & Modèle temporel

> Architecture template/occurrence. Templates = générés par OR-Tools (planning type). Occurrences = réalité calendaire (dates exactes, exceptions). Matérialisation sur fenêtre glissante J+14.

| Table | Champs clés | Notes |
|---|---|---|
| schedules | id · club_id · season_id · name · status (pending/queued/generating/done/partial/failed/timeout/error) · score · solver_seed · snapshot_hash · snapshot_data (jsonb) · solver_version · constraint_version · score_formula_version · solver_timeout_seconds · solver_nb_variables · solver_nb_constraints · solver_nb_conflicts · solver_wall_time_ms · pdf_export_status · pdf_export_url · version | solver_seed default=42 --- déterminisme garanti. snapshot_* = données figées au lancement |
| schedule_slot_templates | id · schedule_id · team_id · venue_id · coach_id · day_of_week · start_time · duration_minutes · lock_level (NONE/SOFT/HARD) · temporary_lock · temporary_lock_for · temporary_min_sessions_override · version · pending_constraint_suggestion | lock_level remplace is_manual bool. temporary_* pour régénération partielle |
| schedule_slot_occurrences | id · slot_template_id · period_template_slot_id · schedule_id · team_id · venue_id · coach_id · occurrence_date · start_time · duration_minutes · status (scheduled/cancelled/moved/venue_changed/coach_replaced/added/merged) · replacement_venue_id · replacement_coach_id · moved_to_date · moved_to_time · merged_team_ids (uuid[]) · override_reason · created_by | Champs dédiés par type --- pas de jsonb |
| schedule_diagnostics | id · schedule_id · type (unplaced/conflict/warning/coach_overload/soft_lock_moved) · severity · team_id · coach_id · venue_id · message · suggestions (jsonb) | Messages en langage gestionnaire. suggestions = actions avec liens directs |
| solver_metrics | id · schedule_id · club_id · status · wall_time_ms · nb_variables · nb_constraints · nb_conflicts · score · solver_version · created_at | Partitionné par mois. Purgé après 6 mois. BRIN sur created_at |

### 3.6 Périodes d'exception

| Table | Champs clés | Notes |
|---|---|---|
| period_templates | id · club_id · season_id · name · is_cutoff | is_cutoff=true = coupure totale. Autant que nécessaire |
| period_template_slots | id · period_template_id · team_ids (uuid[]) · venue_id · coach_id · day_of_week · start_time · duration_minutes | team_ids[] permet la mutualisation (SM1+SM2) |
| period_assignments | id · club_id · school_holiday_period_id · period_template_id · status (pending/confirmed/ignored) · alert_sent_at · confirmed_at · confirmed_by | null period_template_id = garder template de base |
| period_coach_responses | id · period_assignment_id · coach_id · availability (available/partially/unavailable) · notes · responded_at | Réponses via lien unique sans login (email) |

## 4. Contraintes & Hiérarchie OR-Tools

Hiérarchie formelle --- deux devs doivent implémenter identiquement

### 4.1 Niveau 0 --- Pré-traitement

Slots HARD lockés extraits du solver et placés directement dans le résultat. OR-Tools ne les voit jamais.

> Règle absolue. Un slot HARD ne peut jamais être déplacé ou modifié par OR-Tools, quelle que soit la situation.

### 4.2 Niveau 1 --- Contraintes dures (violation = solution rejetée)

Si deux contraintes de ce niveau sont incompatibles → INFEASIBLE immédiat. Diagnostic généré. Le gestionnaire doit corriger.

| # | Contrainte | Entité source | Implémentation OR-Tools |
|---|---|---|---|
| 1.1 | Une salle = une équipe à la fois | venue_availabilities | add_at_most_one() |
| 1.2 | Un coach = une équipe à la fois | coaches | add_at_most_one() |
| 1.3 | Coach-joueur non-chevauchement | coach_player_memberships | add_at_most_one() |
| 1.4 | Trajet is_feasible=false interdit | venue_travel_times | add_bool_or([a.Not(), b.Not()]) |
| 1.5 | Contrainte type=fixed respectée | team_constraints | Slot pré-placé exclu optimisation |
| 1.6 | Contrainte type=forbidden respectée | team_constraints | Variable forcée à 0 |
| 1.7 | Indisponibilité coach (plage ou journée) | coach_unavailabilities | Variable forcée à 0 |
| 1.8 | Fermeture salle | venue_closures | Variable forcée à 0 |
| 1.9 | Passerelle priority=required non-chevauchement | team_links | add_bool_or([a.Not(), b.Not()]) |
| 1.10 | min_sessions_effectif garanti | priority_tiers + teams | add(sum >= min_sessions) |
| 1.11 | Salle imposée respectée | teams.forced_venue_id | Autres salles forcées à 0 |

### 4.3 Niveau 2 --- Contraintes molles (poids fixes --- ne pas modifier sans incrémenter score_formula_version)

| # | Contrainte | Poids | Entité source |
|---|---|---|---|
| 2.1 | Session placée tier S | +10 000 | priority_tiers |
| 2.2 | Session placée tier A | +1 000 | priority_tiers |
| 2.3 | Slot SOFT locké non déplacé | +800 | schedule_slot_templates |
| 2.4 | Session placée tier B | +100 | priority_tiers |
| 2.5 | Passerelle priority=preferred non-chevauchement | +80 | team_links |
| 2.6 | Contrainte type=preferred respectée | +60 | team_constraints |
| 2.7 | Regroupement créneaux même coach même salle | +50 | coaches |
| 2.8 | Session placée tier C | +10 | priority_tiers |
| 2.9 | Coach sous max_days_per_week | +8 | calculé dynamiquement depuis venue_availabilities |
| 2.10 | Passerelle priority=optional non-chevauchement | +5 | team_links |
| 2.11 | Session placée tier D | +1 | priority_tiers |
| 2.12 | Repos lendemain jour de match respecté | +3 | teams.match_day |

> Une contrainte dure prime TOUJOURS sur une contrainte molle, quels que soient les poids.

### 4.4 Niveau 3 --- Post-traitement

| Situation | Action | Type diagnostic |
|---|---|---|
| Slot SOFT locké déplacé | Ajout schedule_diagnostics | soft_lock_moved |
| Coach dépasse max_days_per_week | Ajout schedule_diagnostics | coach_overload |
| Équipe sous sessions_per_week | Ajout schedule_diagnostics | unplaced |
| INFEASIBLE --- contraintes incompatibles | Diagnostic des deux contraintes en conflit | conflict |

### 4.5 Déterminisme

| Champ | Table | Valeur | Rôle |
|---|---|---|---|
| solver_seed | schedules | int, default=42 | Même payload + même seed = même résultat. Gestionnaire peut changer pour obtenir une variante valide. |
| score_formula_version | schedules | "1.0" | Change si un poids niveau 2 est modifié. |
| constraint_version | schedules | SHA1 payload | Détecte si les données ont changé entre deux générations. |
| solver_version | schedules | "ortools-9.8" | Version de la librairie OR-Tools. |

## 5. Moteur OR-Tools CP-SAT

Architecture microservice Python

### 5.1 Principes fondamentaux

- OR-Tools résout TOUJOURS une semaine type --- jamais la saison entière
- Une génération à la fois par club --- queue stricte Messenger (clé club_id)
- Timeout adaptatif : complexity = nb_teams × nb_venues × 5 → 30s/90s/180s
- Snapshot figé au lancement --- solver travaille sur données cohérentes
- OPTIMAL et FEASIBLE sont tous deux exploitables

### 5.2 Statuts de résolution

| Statut | Signification | Comportement Symfony |
|---|---|---|
| OPTIMAL | Meilleure solution mathématique | Import slots + push Mercure done |
| FEASIBLE | Bonne solution, timeout atteint | Import slots + mention timeout |
| PARTIAL | Solution dégradée, min_sessions non atteint | Import + diagnostics unplaced |
| TIMEOUT | Meilleure solution intermédiaire retournée | Import + warning |
| INFEASIBLE | Impossible mathématiquement | Diagnostics conflits + suggestions |
| ERROR | Crash solver | Log Sentry + push Mercure failed |

### 5.3 Contrat Pydantic --- versioning

contract_version présent dans ScheduleInputSchema ET ScheduleOutputSchema. Fichier engine/CONTRACT_VERSION = source de vérité. Symfony vérifie la compatibilité au premier appel.

| Type de changement | Version | Déploiement |
|---|---|---|
| Ajout champ optionnel | Mineure (1.0 → 1.1) | Indépendant --- rétrocompatible |
| Suppression ou renommage champ | Majeure (1.0 → 2.0) | Coordonné Symfony + Python simultanément |
| Changement type d'un champ | Majeure (1.0 → 2.0) | Coordonné |

> Ne jamais déployer engine/ sans vérifier la compatibilité avec Symfony en prod. CI bloque si versions majeures divergent.

### 5.4 Fichiers Python --- engine/

| Fichier | Rôle |
|---|---|
| app/main.py | FastAPI. POST /generate. Queue mono-thread par club via asyncio.Lock |
| app/solver/model.py | CP-SAT : variables booléennes, contraintes niveaux 1 et 2, fonction objectif |
| app/solver/constraints.py | Fonctions par type de contrainte |
| app/solver/objective.py | Fonction objectif avec poids tiers + bonus/pénalités |
| app/solver/result_builder.py | Solution CP-SAT → JSON + reconstruction explanations post-solve |
| app/schemas/input_schema.py | Pydantic v2 --- validation stricte input Symfony |
| app/schemas/output_schema.py | Pydantic v2 --- validation stricte output vers Symfony |

## 6. Flux de génération

Asynchrone --- Messenger + Redis + Mercure

| Étape | Acteur | Action |
|---|---|---|
| 1 | React | POST /api/schedules/{id}/generate |
| 2 | Symfony API | Crée GenerateScheduleMessage → Redis queue (clé club_id) |
| 3 | Symfony API | status = queued. Push Mercure {status: queued} |
| 4 | messenger-worker | Dépile le message (1 seul par club à la fois) |
| 5 | GenerateScheduleHandler | status = generating. Snapshot figé. Push Mercure generating |
| 6 | ScheduleConstraintBuilder | Construit JSON payload Pydantic (cache Redis 4h) |
| 7 | GenerateScheduleHandler | POST http://engine:8000/generate |
| 8 | FastAPI engine | Pydantic valide → OR-Tools résout → Pydantic valide output |
| 9 | GenerateScheduleHandler | Résultat reçu. INFEASIBLE → diagnostics. Sinon → import |
| 10 | ScheduleResultImporter | Supprime slots NONE. Préserve SOFT/HARD. Importe nouveaux |
| 11 | GenerateScheduleHandler | status = done/partial/failed. Score. Métriques |
| 12 | Mercure | Push {status, score, unplaced, warnings} → React |

### 6.2 Services Symfony clés

| Service | Responsabilité unique |
|---|---|
| GenerateScheduleMessage | DTO : scheduleId · clubId · timeoutSeconds · isPartialRegen · affectedVenueIds · weekStart |
| GenerateScheduleHandler | Orchestrateur --- coordonne services, statuts, push Mercure. Zéro logique métier propre |
| ScheduleConstraintBuilder | FICHIER LE PLUS CRITIQUE. Sérialise entités → JSON Pydantic. Cache Redis 4h. Tests unitaires exhaustifs obligatoires |
| ScheduleResultImporter | Hydrate ScheduleSlots. Respecte lock_levels |
| PartialRegenService | Identifie slots affectés, applique/retire locks temporaires et min_sessions temporaires |
| ManualEditService | Dialogue post-édition (contrainte/lock/ponctuel). Crée TeamConstraints si choix permanent |
| SeasonTransitionService | Transition de saison 5 étapes. Duplication, archivage, purge |
| ClubTimeService | Conversions UTC ↔ timezone locale club |
| TenantFilterListener | Injecte club_id Doctrine Filter + SET LOCAL PostgreSQL app.club_id |
| CacheInvalidationListener | Invalide cache Redis club sur postUpdate/postPersist/postRemove |
| AuditListener | Écrit AuditEvents en base via Messenger async. Jamais en synchrone |
| ScheduleDiffService | Diff lisible entre deux snapshots de slot_templates |

## 7. Gestion INFEASIBLE

4 décisions validées

| Décision | Situation | Comportement |
|---|---|---|
| D1 | FEASIBLE dégradé (95% des cas) | Afficher planning + rapport équipes non satisfaites |
| D2 | INFEASIBLE total | Diagnostic conflits bloquants + suggestions + liens vers entités à corriger |
| D3 | Suggestions | Textuelles uniquement MVP. Lien direct. Pas d'auto-correction |
| D4 | Planning partiel relaxation | NON en MVP. Uniquement diagnostic texte. P2 |

> Messages schedule_diagnostics rédigés en LANGAGE GESTIONNAIRE avant implémentation. Préparer fichier templates exhaustif par type de conflit.

| Type diagnostic | Severity | Exemple message gestionnaire |
|---|---|---|
| unplaced | warning | "U13 M3 n'a pu être placée qu'1 fois sur 2 : Coach Martin occupé sur tous les créneaux libres de Salle B le mardi et jeudi soir." |
| conflict | error | "SM1 impossible à placer : Coach Dupont est indisponible tous les jours où le Gymnase A est ouvert." |
| coach_overload | warning | "Coach Dupont sera présent 5 jours cette semaine (maximum recommandé : 4)." |
| soft_lock_moved | info | "Le créneau U18 M1 Jeudi 19h a été déplacé au Mercredi 20h car le Gymnase B était indisponible." |

## 8. Modèle temporel

Templates récurrents + occurrences réelles + périodes d'exception

### 8.1 Architecture

| Niveau | Table | Rôle |
|---|---|---|
| Template de base | schedule_slot_templates | Planning type OR-Tools. Se répète chaque semaine |
| Template de période | period_template_slots | Planning alternatif pour vacances/coupure/mutualisation |
| Occurrence | schedule_slot_occurrences | Réalité calendaire à date précise. Fenêtre glissante J+14 |

### 8.2 Flux périodes d'exception

- Symfony Scheduler tourne chaque jour à 8h
- Détecte school_holiday_periods commençant dans les 14 jours
- Crée period_assignment status=pending + alerte gestionnaire
- Options : appliquer period_template · coupure totale · garder base · créer nouveau template
- Si pas de réponse à J-7 : rappel. J-3 : alerte rouge dashboard
- JAMAIS d'action automatique sans confirmation gestionnaire

Collecte besoins coaches : questionnaire envoyé par email, lien unique sans login, réponses dans period_coach_responses, tableau temps réel pour le gestionnaire.

## 9. Onboarding & Saisons

Wizard guidé + transition annuelle

### 9.1 Wizard initial --- obligatoire

Obligatoire à la première connexion. clubs.onboarding_completed = false redirige vers le wizard. Sauvegarde automatique dans clubs.transition_data (jsonb).

| Étape | Contenu | Import |
|---|---|---|
| 1 --- Club | Nom · ville · adresse (→ timezone + school_zone auto) · sport (Basket MVP) | Non |
| 2 --- Salles | Grille disponibilité (tranches 15min, lun-sam) · fermetures · matrice trajets GPS | CSV salles |
| 3 --- Coaches | Indisponibilités récurrentes · coach-joueur | CSV coaches |
| 4 --- Équipes | Import Excel recommandé (template basket pré-rempli) · saisie manuelle | Excel équipes |
| 5 --- Priorités | Tier list drag & drop S/A/B/C/D · min_sessions_override | Non |
| 6 --- Résumé | Récapitulatif · Générer · Mode démo accessible | Non |

> Mode démo : club basket fictif pré-rempli. Génération en 30s pour démontrer la valeur avant saisie des vraies données.

### 9.2 Wizard transition de saison --- hybride 5 étapes

Pré-rempli depuis la saison précédente. Une équipe U18 reste U18 --- c'est l'effectif qui change, pas l'équipe.

| Étape | Contenu |
|---|---|
| 1 --- Salles | Diff disponibilités --- ajouts ✅ · réduits ⚠️ · supprimés ❌ |
| 2 --- Coaches | Conserver / Modifier / Archiver + nouveaux |
| 3 --- Équipes | Conserver / Modifier / Dissoudre + nouvelles |
| 4 --- Passerelles & priorités | Révision team_links + tier list |
| 5 --- Résumé | Tableau récapitulatif des changements |

### 9.3 Rétention données

| Type | Durée |
|---|---|
| Saison active | Indéfinie |
| Saison précédente | 1 saison complète --- read-only |
| Saisons antérieures | Données purgées --- PDF conservé |
| PDF planning de base | Toute la saison |
| PDF planning de période | 30 jours après fin période |
| PDF export manuel | 7 jours (is_pinned = indéfini) |
| audit_logs sécurité | 1 an (RGPD) |
| audit_logs métier | Saison + 1 an |
| solver_metrics | 6 mois |

## 10. Sécurité multi-tenant

3 couches --- Symfony + Doctrine + PostgreSQL RLS

### 10.1 Architecture

| Couche | Mécanisme | Protège contre |
|---|---|---|
| 1 --- HTTP | ClubVoter (Symfony Security) | Accès non autorisé via API |
| 2 --- ORM | TenantFilterListener (Doctrine Filter) | Oubli de filtre dans repository custom |
| 3 --- DB | PostgreSQL RLS (FORCE) | Requête native, SQL direct, bug Doctrine |

- Deux users DB : app_user (pas de DDL) + migration_user (migrations uniquement)
- ALTER TABLE ... ENABLE ROW LEVEL SECURITY + FORCE sur toutes tables avec club_id
- Policy : USING (club_id = current_setting('app.club_id', true)::uuid)
- TenantFilterListener injecte SET LOCAL app.club_id en plus du filtre Doctrine
- SET LOCAL --- limité à la transaction courante, compatible pool de connexions

TenantIsolationTest OBLIGATOIRE en CI (3 niveaux : API + Doctrine + SQL brut). Bloque le merge.

### 10.2 Concurrence & Optimistic locking

- version smallint sur teams · coaches · venues · schedules · schedule_slot_templates
- OptimisticLockException → 409 Conflict → React : "Rechargez avant de sauvegarder"
- Snapshot figé au lancement (snapshot_data jsonb + snapshot_hash SHA1)
- Rate limiting : api_global 200/min · schedule_generate 5/h · pdf_export 10/h --- par club_id

## 11. UX --- Saisie des contraintes

Interface gestionnaire

### 11.1 Grille disponibilité salles

- Grille cliquable : jours (lun-sam) × tranches 15 minutes (7h-23h)
- Fermetures exceptionnelles séparées → déclenche flow régénération partielle guidée

### 11.2 Contraintes équipes --- 3 types

| Type | Couleur | Signification | OR-Tools |
|---|---|---|---|
| fixed | Bleu | Créneau imposé (SM1 mardi 20h30 Gymnase A) | Exclu du solver, placé directement |
| forbidden | Rouge | Jour/horaire impossible (U13 jamais mercredi matin) | Variable forcée à 0 |
| preferred | Orange | Préférence positive salle ou horaire | Bonus +60 dans objectif |

> Une contrainte n'est pas forcément négative. Elle peut exprimer un souhait positif (meilleur matériel, habitude des joueurs).

### 11.3 Validation temps réel

| Situation | Type | Bloquant ? |
|---|---|---|
| Coach-joueur chevauchement direct | Erreur | OUI --- seul cas bloquant |
| Salle imposée + coach indispo sur toutes ses plages | Avertissement | Non |
| Équipe avec 0 créneau possible | Avertissement | Non |
| Trajet infaisable entre salles d'un coach | Avertissement | Non |
| sessions_per_week > créneaux disponibles | Avertissement | Non |
| Coach dépassant max_days_per_week | Avertissement | Non |
| Équipes compétition le samedi matin | Avertissement | Non |

### 11.4 Édition manuelle --- dialogue post-modification

Tout déplacement significatif (changement de jour, heure > 30min, changement de salle) déclenche un dialogue 3 choix :

- Créer contrainte permanente → TeamConstraint avec created_by=manual_edit + source_occurrence_id tracé
- Verrouiller --- SOFT (OR-Tools évite si possible) ou HARD (jamais touché)
- Juste ponctuel / pour voir → occurrence modifiée. Bandeau "Convertir en contrainte ?" affiché après

Changement ≤ 30 min → SOFT lock silencieux. Changement jour ou salle → dialogue obligatoire.

## 12. Pricing & Fonctionnalités

Modèle commercial SaaS

### 12.1 Plans tarifaires

| Plan | Équipes | Salles | Générations | Mensuel | Annuel |
|---|---|---|---|---|---|
| Découverte (gratuit) | 6 | 2 | 3 max/saison | 0€ | 0€ |
| Petit Club | 12 | 3 | Illimitées | ~25€ | ~199€ |
| Club | 22 | 5 | Illimitées | ~59€ | ~490€ |
| Grand Club | ∞ | ∞ | Illimitées | ~119€ | ~990€ |

> Prix à ~75% des valeurs cibles en lancement. Montants dans la table plans --- modifiables sans déploiement.

### 12.2 Features bridées en plan Découverte

- 3 générations maximum par saison --- vrai verrou de conversion
- Coach-joueur désactivé · passerelles désactivées · trajets désactivés
- Contraintes preferred : 1 seule par équipe
- Pas d'export PDF · pas de wizard transition de saison

### 12.3 Priorisation fonctionnalités

| Priorité | Features |
|---|---|
| MVP strict | Wizard 4 étapes · import Excel · OR-Tools solver · 5 tiers · visualisation planning · export PDF · multi-tenant RLS · rapport diagnostics · coach-joueur · mode démo |
| P1.5 | Gestion saisons · périodes exception · régénération partielle · édition manuelle · matrice trajets · passerelles · audit trail · solver_metrics |
| P2 | App mobile · notifications coaches · E2E Playwright · stats analytics |
| V2 | Multi-sport · import FFBB · planning matchs · connecteurs mairie |

## 13. Stratégie de tests

5 zones critiques --- 4 tests bloquants CI

### 13.1 Zones critiques

| Zone | Risque si non testé | Priorité |
|---|---|---|
| Multi-tenant + RLS | Fuite de données entre clubs | 🔴 Bloquant prod |
| Cache Redis multi-tenant | Planning d'un club affiché à un autre silencieusement | 🔴 Bloquant prod |
| Concurrence génération | 2 générations corrompent le planning sans erreur visible | 🔴 Bloquant prod |
| Cohérence schema Symfony ↔ Python | Désynchronisation contrat Pydantic → crash silencieux | 🔴 Bloquant prod |
| Solver OR-Tools | Planning valide techniquement mais faux métier | 🔴 Bloquant prod |

### 13.2 Les 4 tests bloquants CI

> Ces 4 tests bloquent le merge sur chaque PR. Sans eux le projet ne part pas en production.

#### Test 1 --- TenantIsolationTest (3 niveaux)

| Niveau | Ce qui est testé | Méthode |
|---|---|---|
| API (HTTP) | User club A → GET /api/teams/{id club B} → 404 jamais 200 | WebTestCase Symfony |
| Repository Doctrine | TeamRepository::find() avec club_id B depuis contexte club A → null | Kernel test + TenantFilterListener actif |
| SQL brut (PDO direct) | SELECT avec club_id B + app.club_id = A → 0 lignes (RLS bloque) | DBAL executeQuery direct |

#### Test 2 --- TenantCacheIsolationTest

| Scénario | Résultat attendu |
|---|---|
| Club A génère planning → cache alimenté | Cache club A existe |
| Club B lit le planning → cache B vide | Cache B non alimenté par A |
| Club A modifie un coach → CacheInvalidationListener | Cache A purgé, cache B intact |
| Club B modifie une venue → invalidation | Cache B purgé, cache A intact |

#### Test 3 --- ConcurrentGenerationTest

| Scénario | Résultat attendu |
|---|---|
| Club A lance génération → status=generating | Job dans queue Redis |
| Club A relance immédiatement | status=queued --- pas de 2ème job actif |
| Deux clubs différents simultanément | Deux jobs indépendants --- pas d'interférence |
| Worker crash pendant génération | status=failed --- autre club non impacté |
| Même message dispatché 2 fois (retry) | Idempotence --- résultat identique, pas de doublon |

#### Test 4 --- ContractSchemaTest

Vérifie que le JSON produit par ScheduleConstraintBuilder valide exactement le ScheduleInputSchema Pydantic. Test cross-stack Symfony → Python.

| Scénario | Résultat attendu |
|---|---|
| ScheduleConstraintBuilder::build() sur club 41 éq. 8 salles | JSON valide par Pydantic sans erreur |
| contract_version dans JSON = CONTRACT_VERSION engine/ | Versions synchronisées |
| Modification DTO Symfony sans update Pydantic | Test échoue --- désynchronisation détectée |
| Modification Pydantic sans update Symfony | Test échoue --- version majeure différente |

> Nécessite Testcontainers ou docker-compose CI éphémère pour lancer l'engine Python pendant les tests.

### 13.3 Stack de tests par couche

| Couche | Outils | Types | Priorité |
|---|---|---|---|
| Symfony | PHPUnit 11 · WebTestCase · Foundry · Testcontainers | Unitaires services · Fonctionnels API · Intégration RLS · Cache · Async | 🔴 MVP strict |
| Python solver | pytest · hypothesis · seed · snapshot JSON | Golden datasets · Invariants · Stabilité score · Performance | 🔴 MVP strict |
| Cross-stack | Testcontainers (PG + Redis + engine) | 4 tests bloquants CI | 🔴 MVP strict |
| Frontend React | React Testing Library · MSW | Composants critiques | 🟠 P1.5 |
| E2E | Playwright | Onboarding → génération → export PDF | 🟠 P1.5 |

### 13.4 Tests unitaires services Symfony critiques

| Service | Cas critiques à tester |
|---|---|
| ScheduleConstraintBuilder | DB → JSON Pydantic · max_days_per_week · lock_level · snapshot hash · coach-joueur inclus · HARD lockés exclus |
| PartialRegenService | Identification slots affectés · locks temporaires · min_sessions par tier · retrait post-génération |
| ManualEditService | Dialogue 3 choix · création TeamConstraint avec created_by=manual_edit · source_occurrence_id tracé |
| ScheduleResultImporter | Préservation SOFT/HARD · suppression NONE · hydratation occurrences |
| TenantFilterListener | club_id Doctrine Filter · SET LOCAL exécuté · reset entre requêtes |
| CacheInvalidationListener | Invalidation planning.cache sur modification coach · isolation entre clubs |

### 13.5 Golden datasets solver

| Dataset | Contenu | Assertions |
|---|---|---|
| simple_club | 5 équipes · 2 salles · contraintes basiques | status=OPTIMAL · toutes équipes placées |
| medium_club | 20 équipes · 4 salles · coaches multi-équipes | status=OPTIMAL ou FEASIBLE · invariants OK |
| dense_club | 41 équipes · 8 salles (club référence) | status=FEASIBLE · wall_time < 180s · score ≥ baseline × 0.95 |
| impossible | Contraintes dures incompatibles | status=INFEASIBLE · diagnostics non vides |
| vacation_week | Template période · mutualisations SM1+SM2 | status=FEASIBLE · tier S/A min_sessions respectés |
| partial_regen | Gymnase fermé · locks temporaires · dégradation tiers | HARD slots inchangés · tier D peut avoir 0 session |

| Propriété hypothesis | Invariant |
|---|---|
| Jamais double booking salle | Toute paire slots même salle même jour : pas de chevauchement horaire |
| Jamais coach sur 2 lieux simultanément | Tous les slots d'un coach : pas de chevauchement |
| Coach-joueur cohérent | Créneau joueur jamais en overlap avec créneaux coaching |
| Slots HARD préservés | Chaque slot HARD apparaît dans le résultat inchangé |
| Tier S jamais sacrifié si tier D placé | Si équipe S sans toutes ses sessions → aucune équipe D n'en a |

### 13.6 Pipeline CI

| Étape | Outils | Durée | Bloquant merge ? |
|---|---|---|---|
| 1. Lint & style | PHP-CS-Fixer · Ruff | ~10s | Oui |
| 2. Analyse statique | PHPStan niveau 8 · mypy | ~45s | Oui |
| 3. 4 tests bloquants CI | PHPUnit + Testcontainers (PG+Redis+engine) | ~90s | Oui |
| 4. Tests unitaires services | PHPUnit | ~30s | Oui |
| 5. Tests solver Python | pytest sans dense_club | ~60s | Oui |
| 6. Tests fonctionnels API | PHPUnit WebTestCase | ~90s | Oui |
| 7. Performance solver | pytest dense_club < 180s | ~3min | Main uniquement |
| 8. Build Docker prod | docker build | ~3min | Main uniquement |

## 14. MVP strict --- Implémentation

Critère de sortie clair · 18 tables · 4 phases

### 14.1 Critère de sortie

> Un gestionnaire saisit les données de son club (41 équipes, 8 gymnases), génère un planning valide en < 3 minutes, le consulte dans l'interface, l'exporte en PDF. C'est tout. Rien d'autre n'est nécessaire pour valider la valeur core du produit.

### 14.2 Tables MVP strict --- 18 tables uniquement

| Groupe | Tables |
|---|---|
| Tenant & Auth | clubs · users · club_users · seasons |
| Référentiel global | sports · sport_categories · priority_tiers · plans |
| Entités métier | venues · venue_availabilities · venue_closures · coaches · coach_unavailabilities · coach_player_memberships · teams · team_coaches · team_constraints |
| Planning | schedules · schedule_slot_templates · schedule_diagnostics |

> Les tables venue_travel_times · team_links · category_passway_rules · period_* · audit_logs · solver_metrics · schedule_slot_occurrences sont en P1.5. Migrations préparées en MVP mais pas activées.

### 14.3 Découpage MVP / P1.5 / P2

| Feature | MVP | P1.5 | P2 |
|---|---|---|---|
| Entités core + contraintes base | ✅ | | |
| Coach-joueur | ✅ | | |
| OR-Tools solver + 5 tiers + hiérarchie C1 | ✅ | | |
| Wizard onboarding 4 étapes + import Excel | ✅ | | |
| Tier list drag & drop | ✅ | | |
| Visualisation planning semaine | ✅ | | |
| Export PDF simple async | ✅ | | |
| Multi-tenant RLS + 4 tests bloquants CI | ✅ | | |
| Rapport post-génération (diagnostics lisibles) | ✅ | | |
| Mode démo club basket fictif | ✅ | | |
| Gestion saisons + wizard transition hybride | | ✅ | |
| Périodes exception + alertes J-14 | | ✅ | |
| Régénération partielle guidée | | ✅ | |
| Édition manuelle + dialogue post-édition | | ✅ | |
| Matrice trajets + passerelles | | ✅ | |
| Audit trail complet | | ✅ | |
| Super-admin dashboard | | | ✅ |
| App mobile React Native | | | ✅ |
| E2E Playwright | | | ✅ |

> Ne jamais ajouter de feature P1.5 avant que le critère de sortie MVP strict soit atteint et validé sur données réelles du club de référence.

### 14.4 Séquence d'implémentation --- 4 phases

| Phase | Contenu | Critère de sortie |
|---|---|---|
| Phase 1 Socle | Docker Compose tous services · PriorityTierFixtures + SportFixtures (basket) · RLS PostgreSQL (2 users, policies) · TenantFilterListener · 4 tests bloquants CI configurés | make test passe. TenantIsolationTest vert. Isolation multi-tenant vérifiée. |
| Phase 2 Entités | 18 tables Doctrine dans l'ordre : Club → User → ClubUser → Season → Sport → SportCategory → PriorityTier → Plan → Venue → VenueAvailability → VenueClosure → Coach → CoachUnavailability → CoachPlayerMembership → Team → TeamCoach → TeamConstraint → Schedule → ScheduleSlotTemplate → ScheduleDiagnostic · Tous index définis | doctrine:schema:validate passe. ContractSchemaTest vert. |
| Phase 3 Solver | Pydantic schemas (contract_version="1.0") · FastAPI POST /generate · OR-Tools model + contraintes niveau 1 + objectif niveau 2 · result_builder.py avec explanations · 6 golden datasets + tests invariants + CONTRACT_VERSION CI | Dense club génère en < 3 min. Tous pytest passent. ConcurrentGenerationTest vert. |
| Phase 4 Produit | ScheduleConstraintBuilder + tests unitaires exhaustifs · GenerateScheduleHandler · API Platform 18 resources (DTOs + groups) · Wizard 4 étapes React · Tier list drag&drop · Visualisation planning · Export PDF · Rapport diagnostics | Critère de sortie MVP strict atteint sur données réelles. |

## 15. Prompt de démarrage OpenCode v3.0

Document unique --- copier-coller directement

Tu es un développeur senior Symfony 7 / Python 3.12 / React.

Tu implémentes ClubScheduler v3.0 selon ce document de référence.

CONTEXTE MÉTIER :

Générateur automatique de planning pour clubs sportifs amateurs.

Problème : 1 semaine de travail manuel → < 3 min automatisé.

Club référence : 41 équipes · 8 gymnases · 10-20 coaches (basket).

Utilisateur : gestionnaire salarié travaillant depuis son bureau.

MVP STRICT --- critère de sortie :

Gestionnaire saisit données → génère planning valide < 3 min →

consulte dans interface → exporte PDF. 18 tables. Pas de P1.5 avant.

CONTRAINTES TECHNIQUES CRITIQUES :

- Multi-tenant : club_id + season_id sur toutes tables métier, UUID
- RLS PostgreSQL FORCE sur toutes tables avec club_id
- 4 tests bloquants CI : TenantIsolation · TenantCacheIsolation · ConcurrentGeneration · ContractSchema --- bloquent le merge
- OR-Tools : résout 1 semaine type, jamais la saison entière
- 1 génération à la fois par club (queue Messenger clé club_id)
- Snapshot figé au lancement (snapshot_data jsonb + snapshot_hash)
- solver_seed default=42 --- même payload+seed = même résultat
- lock_level enum NONE/SOFT/HARD remplace is_manual bool partout
- contract_version dans input ET output Pydantic. Fichier ENGINE/CONTRACT_VERSION vérifié en CI.
- Hiérarchie contraintes : niveau 0 (HARD pré-traités) → niveau 1 (11 dures) → niveau 2 (12 molles poids fixes) → niveau 3 (post-traitement diagnostics)
- Poids niveau 2 fixes : S=10000 A=1000 SOFT=800 B=100 pref_link=80 preferred=60 grouping=50 C=10 max_days=8 opt_link=5 D=1 rest=3
- PHPStan niveau 8 · PHP-CS-Fixer · pytest · hypothesis
- jsonb uniquement : suggestions · snapshot_data · transition_data · settings · features plans
- Timestamps système = timestamptz. Heures créneaux = time locale
- API Platform : DTOs entités sensibles, groups simples
- club_id · season_id · version · snapshot_* jamais du client
- V2 anticipé : ffbb_team_id sur teams, venues.source enum

COMMENCE PAR : Phase 1 socle (section 14.4).

Une phase à la fois. Critère de sortie validé avant phase suivante.

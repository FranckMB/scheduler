# Glossaire ClubScheduler — termes métier & clés de payload

> **Un concept = un mot.** Glossaire transverse (les termes traversent les zones — le payload est
> le contrat). Référencé depuis `CLAUDE.md` §Pointers ; vocabulaire contraintes exhaustif :
> `engine/docs/constraint-vocabulary.md`.

## Métier (FFBB / club)

| Terme | Définition |
|-------|------------|
| **Club** | Racine tenant. Un club = un espace de données isolé (RLS + `TenantFilter`). |
| **Saison** | Cadre annuel d'un club (pivot calendaire **15 juillet**). Une saison archivée est en lecture seule (409 à l'écriture). |
| **Équipe** (`Team`) | Unité planifiée : catégorie d'âge (U9…U21, senior), genre, niveau, `sessionsPerWeek`. |
| **Gymnase** (`Venue`) — *jamais « salle »* | Lieu d'entraînement, porte des créneaux (`trainingSlots`). Divisible via `canSplit`/capacité. |
| **Créneau** (`slot`) — *jamais « slot » dans l'UI* | Fenêtre `jour + heure début + durée` d'un gymnase. Id engine : `"jour:HH:MM"` (jour 1=lundi…7=dimanche). |
| **Coach** — *jamais « entraîneur » dans l'UI* | Encadrant d'équipes. Taxonomie : **salarié** (`isEmployee`), **coach-joueur** (joue aussi — `CoachPlayerMembership`), **bénévole**. Rôle `ASSISTANT` = optionnel, ne bloque jamais un placement. |
| **Contrainte** | Règle de placement saisie (famille × ruleType × config) ou implicite (no-overlap, capacité…). |
| **Rang / tier** (`PriorityTier`) | Priorité S/A/B/C/D d'une équipe, poids objectif exponentiel (S=10000…D=1). |
| **Tag système** (`TeamTag`) | Étiquette auto-assignée (21 tags : U9…U21, JEUNE, ADULTE, EMB, FEMININE…) groupée par **axe** GENRE/NIVEAU/ÂGE. Cible de groupe des contraintes (`targetTag`). |
| **Socle** | Données minimales validées (équipes+gymnases+créneaux) requises avant d'activer les modules ; `socleValidatedAt` sur la saison. |
| **FFBB / FBI / ARA** | Fédération / son outil de gestion (import matchs `externalRef`) / code d'affiliation club. |

## Cycle de vie planning

| Terme | Définition |
|-------|------------|
| **Schedule** | Un « run » de planning. Statuts : `DRAFT → PENDING → GENERATING → COMPLETED \| FAILED`, puis `VALIDATED` (verrouille), `ARCHIVED` (versions). |
| **Planning principal** | **Un fait, pas un choix** : le premier planning validé de la saison. Sans lui, tout est verrouillé. |
| **Baseline** | Photo de référence d'un planning validé (comparaisons d'écart). |
| **Version** (D1) | Schedule archivé conservé (V1/V2…) ; « Charger cette version » restaure sa structure pour régénérer. |
| **Overlay** | Planning **secondaire borné** à une période du cockpit (`calendarEntryId` non-null) — vacances, fermeture. Ne remplace pas le plan de saison. |
| **Cockpit** | Vue temporelle de la saison : périodes (`CalendarEntry` PERIOD/EVENT), overlays, matchs. |
| **Génération** | Pipeline async : controller → Messenger(Redis) → handler (lock + snapshot figé) → engine CP-SAT → import → Mercure. |
| **Snapshot figé** | Photo des données au moment du dispatch — le solve est **rejouable**, insensible aux éditions concurrentes. |
| **Réservation** (`Reservation`) | Épingle durable équipe→créneau (HARD), envoyée à l'engine en `slotTemplates`. ≠ `ScheduleSlotTemplate` (résultat de solve). |
| **Diagnostic** | Explication structurée d'un échec/compromis solveur (`ScheduleDiagnostic`, ex. `day_constraint_conflict`, `venue_minimum_unreachable`). |

## Payload backend↔engine (contrat `CONTRACT_VERSION`, actuel 2.1)

Clés racine : `version` · `clubId` · `seasonId` · `scheduleName` · `solverSeed` (déterminisme) ·
`solverTimeoutSeconds` (**plafond**, jamais le budget réel — paliers adaptatifs 60/180/600 s) ·
`venues` · `teams` · `coaches` · `constraints` · `slotTemplates` · `priorityTiers`.

| Clé | Sens |
|-----|------|
| `venues[].trainingSlots[]` | Créneaux ouverts du gymnase (jour/heure/durée/capacité). |
| `teams[].sessionsPerWeek` | Cible de séances (soft — ENG-18, pas un plancher dur). |
| `teams[].tags` | Tags système matérialisés à la génération (`ScheduleConstraintBuilder`). |
| `coaches[].isEmployee` | Salarié (distribution équitable dédiée). |
| `constraints[]` | `{scope, scopeTargetId, family, ruleType, config}` — familles TIME/DAY/FACILITY/COACH_AVAILABILITY/FACILITY_CAPACITY. **Toute clé de `config` absente de `engine/docs/constraint-vocabulary.md` est ignorée sans erreur.** |
| `ruleType` | `HARD`/`LOCK` = dur (jamais violé) · `PREFERRED`/`BONUS` = soft (objectif). |
| `config.targetTag` | Cible de groupe — le backend **éclate** en N contraintes TEAM avant l'envoi. |
| `slotTemplates[]` | Épingles HARD (réservations, verrous manuels). |
| `priorityTiers[].orToolsWeight` | Poids objectif du rang (S=10000…D=1). |
| Sortie : `status` | `completed` \| `failed` (INFEASIBLE → `failed` + diagnostics ; **pas de fallback par relaxation**). |
| Sortie : `score` | Valeur objectif (stable au re-run même seed ; l'*assignment* peut varier en multi-worker). |

## Infra & sécurité

| Terme | Définition |
|-------|------------|
| **Tenant** | Le club courant, résolu **côté serveur depuis le JWT** (le frontend n'envoie PAS `X-Club-Id` ; header spoofé → 403). |
| **RLS** | Row-Level Security PostgreSQL, policies `FORCE` sur `club_id`, clé = GUC `app.club_id`. Runtime `app_user` (restreint) / migrations-ops `clubscheduler` (connexion `admin`, **bypasse RLS**). CLI sans GUC → 0 ligne (attendu). |
| **GUC** | Variable de session PostgreSQL (`app.club_id`) posée par `TenantConnectionContext` (workers : depuis le message). |
| **season_filter** | Filtre Doctrine intra-club : scope saison (X-Season-Id validé, sinon saison calendaire courante). |
| **Mercure** | Hub SSE ; topic `club:{clubId}:schedule:{scheduleId}` ; publication **best-effort** (le front polle en secours). |
| **ClubGenerationLock** | Verrou Redis (`SETEX NX` + token) : une génération à la fois par club. |
| **phase1** | Groupe PHPUnit bloquant (tests structurants tenant/RLS/contrat/vie du planning). |

## Wizard & frontend

| Terme | Définition |
|-------|------------|
| **Wizard** | Saisie guidée 6 étapes : équipes → gymnases+créneaux → coachs → contraintes → récap → génération. |
| **Work-loop planning** | Boucle d'ajustement post-génération : éditer/verrouiller/régénérer/valider. |
| **Onglet Réserver** | Crée des `Reservation` (pins durables) — pas des contraintes. |
| **Mode « uniquement » vs « au moins »** | `allowedDays` (whitelist dure) ≠ `forcedDays` (≥1 séance ce jour) — piège ENG-16. |

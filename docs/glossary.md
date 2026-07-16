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
| **Socle** | Le calendrier de la saison en vigueur : la version que **pointe** le plan `SEASON`. Les modules (matchs, plans secondaires) l'exigent. Se lit sur le pointeur — il n'y a pas de jalon qui le dise. |
| **FFBB / FBI / ARA** | Fédération / son outil de gestion (import matchs `externalRef`) / code d'affiliation club. |

## Cycle de vie planning

> Vocabulaire du pattern « Plan » ([ADR-0002](architecture/adr-0002-pattern-plan.md))
> — **il fait foi pour parler du produit**, et depuis la bascule du 2026-07-16 il décrit
> aussi le code. Termes **bannis** : *baseline*, *planningName*, statuts *VALIDATED*/
> *ARCHIVED* — ils n'existent plus nulle part.
>
> `CalendarEntry.overlayScheduleId` (pointeur inverse d'une période) et `liveContext`
> (la ★) survivent : le premier jusqu'au lot C, la seconde **par décision** (inv. 17).

| Terme | Définition |
|-------|------------|
| **Plan** (`SchedulePlan`) | LE planning nommé : type (`SEASON`/`CLOSURE`/`HOLIDAY`) + période + nom + **pointeur**. C'est l'objet que le gestionnaire manipule. |
| **Version** (`Schedule`) | Une résolution du solveur d'un plan : « V3 ». Jamais nommée par l'humain. Cycle : `DRAFT → PENDING → GENERATING → COMPLETED \| FAILED`. |
| **Version choisie** | Celle que **pointe** le plan (`chosenScheduleId`) = « validée ». **Valider = pointer**, et **les autres versions sont supprimées**. « Validé » n'est pas un statut : ça se dérive du pointeur, et de rien d'autre (`Schedule.isChosen` le dit par version). |
| **Espace de travail** | Plan au **pointeur null** : on génère/compare des versions, on choisira. Rouvrir y ramène. **Aucun pointage automatique** — seul le gestionnaire pointe. |
| **★ / photo chargée** | La version dont la photo de structure est chargée dans le wizard (`liveContextScheduleId`). **Ce n'est PAS le pointeur du plan** : elle suit chaque génération COMPLETED du socle. C'est l'auto-*pointeur* qui est mort, pas la ★ (inv. 17). |
| **Plan secondaire** | Plan `CLOSURE`/`HOLIDAY` borné à une période du cockpit — vacances, fermeture. Exige que le plan `SEASON` soit **pointé**. Ne remplace pas le plan de saison. |
| **Déblocage cockpit** | Le plan `SEASON` possède **≥1 version terminée** (`COMPLETED`/`FAILED` — le solveur a rendu sa réponse) — exposé par `/api/me.seasonPlan.hasFinishedVersion`. **Indépendant du pointeur** : avoir généré une fois suffit, donc rouvrir ne re-verrouille jamais le cockpit. |
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

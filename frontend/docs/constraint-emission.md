# Émission des contraintes (frontend) + alignement 3 couches

> **But** : (1) lister ce que le **wizard émet** réellement, et (2) mettre les **3 couches côte à côte**
> (frontend → backend → engine) pour repérer les **scissions** et les **angles morts** — les cas où
> « ce que le front veut » n'est **pas** écrit par le backend ou **pas** compris par l'engine.
>
> Compléments : `engine/docs/constraint-vocabulary.md` (tout ce que l'engine comprend) ·
> `backend/docs/constraint-coverage.md` (besoins gestionnaire couverts) ·
> `docs/architecture/constraint-matrix.md` (verrou de test de l'offre wizard↔engine).

## 1. Ce que le wizard émet (`ConstraintsStep.tsx` → `POST/PUT /api/constraints`)

| Écran / mode | `config` émise | ruleType | Exemple BCCL |
|---|---|---|---|
| **TIME** « Pas avant / Pas après » | `minStartTime` et/ou `maxStartTime` | sélecteur (défaut PREFERRED) | EMB max 17h30 |
| **TIME** « Fini avant » | `maxEndTime` (fin = début + durée) | **HARD** (épinglé) | U15 fini avant 20h30 |
| **DAY** « à éviter » | `forbiddenDays` | sélecteur | SM2 évite vendredi |
| **DAY** « uniquement » | `allowedDays` (whitelist) | **HARD** (épinglé) | Vétérans vendredi uniquement |
| **FACILITY** « préfère » | `preferredVenueId` | sélecteur | Matéo préféré Régionales |
| **FACILITY** « évite » | `forbiddenVenueId` | sélecteur | Vétérans interdits |
| **FACILITY** « impose » | `forcedVenueId` | **HARD** (épinglé) | SM4 → Jean Vilar |
| **FACILITY** « au moins N » | `minAtVenueId` + `minAtVenueCount` (défaut 1) | **HARD** (épinglé) | au moins 1 séance à Armand |
| **COACH_AVAILABILITY** « indisponible » | `coachId` + `unavailableDays` (+ `fromTime`/`untilTime` optionnels, Lot C) | **HARD** (épinglé) | Lionel indispo vendredi ; indispo mardi à partir de 20:00 |
| **COACH_AVAILABILITY** « disponible uniquement » | `coachId` + `availableDays` (whitelist) (+ `fromTime`/`untilTime` optionnels) | **HARD** (épinglé) | coach dispo seulement le mardi de 20:00 à 22:00 |
| **Cible** | `targetTag` si groupe (sinon `scope`/`scopeTargetId`) | — | groupe FEMININE / REGIONAL |
| **Onglet « Réserver »** | *pas une contrainte* → `ScheduleSlotTemplate` lock **HARD** | — | épingle 1 séance sur un créneau |
| **Écran Gymnases** (hors onglet contraintes) | `FACILITY_CAPACITY` `maxTeams` (`canSplit`) | — | ADN divisible |
| **Classement équipes** (hors onglet) | `PRIORITY_TIER` `orToolsWeight` | — | rangs S/A/B/C/D |

## 2. Table d'alignement 3 couches

Colonnes : le **front** l'émet-il ? · le **backend** le transmet/transforme-t-il ? · l'**engine** l'honore-t-il ?

| Clé / notion | Frontend | Backend | Engine | Verdict |
|---|---|---|---|---|
| `minStartTime` / `maxStartTime` | ✅ TIME | passe | ✅ fenêtre dure / bonus soft | ✅ **aligné** |
| `forbiddenDays` | ✅ « à éviter » | passe | ✅ dur / soft | ✅ **aligné** |
| `allowedDays` | ✅ « uniquement » | passe | ✅ whitelist (interdit le complément) | ✅ **aligné** *(depuis ENG-16)* |
| `preferredVenueId` | ✅ « préfère » | HARD→forcé + exclusivité tag | ✅ +60 soft / forcé | ✅ **aligné** |
| `forbiddenVenueId` | ✅ « évite » | passe | ✅ interdit / −60 soft | ✅ **aligné** |
| `forcedVenueId` | ✅ « impose » | + exclusivité tag | ✅ salle forcée | ✅ **aligné** |
| `unavailableDays` | ✅ coach « indisponible » | passe | ✅ union, dur | ✅ **aligné** |
| `availableDays` (coach « disponible **uniquement** ») | ✅ coach *(depuis ALIGN)* | passe | ✅ whitelist (intersection) | ✅ **aligné** |
| `maxTeams` | ✅ (écran Gymnases) | passe | ✅ cap capacité | ✅ **aligné** |
| `venue_closed` (période) | ✅ (cockpit) | → `forbiddenVenueId`/équipe | ✅ | ✅ **aligné** *(via expansion backend)* |
| `targetTag` (groupe) | ✅ | → N contraintes TEAM | ✅ (par équipe) | ✅ **aligné** |
| `orToolsWeight` (tier) | ✅ (classement) | passe | ✅ poids objectif | ✅ **aligné** |
| **`forcedDays`** (« au moins une séance tel jour ») | ❌ non émis | — | ✅ compris | 🟠 **scission A** : engine sait, le front n'expose pas |
| **`preferredDays`** | ❌ non émis | — | ✅ (objectif) | 🟠 **scission A** (racine d'ENG-10) |
| **`maxEndTime`** (« Fini avant X h ») | ✅ « Fini avant » | passe | ✅ fenêtre dure (fin ≤ borne) | ✅ **aligné** *(ALIGN-04)* |
| **`minAtVenueId`** + `minAtVenueCount` (« au moins N à ») | ✅ « au moins N » | passe (validation fail-fast) | ✅ plancher dur, fail-soft si inatteignable | ✅ **aligné** *(ALIGN-05)* |
| **`spacing`** (espacer les jours) | *implicite* (aucune saisie) | — | ✅ malus soft jours consécutifs | ✅ **aligné** *(ALIGN-06, règle implicite)* |
| **`max_consecutive_days`** (« pas 3 jours d'affilée », **dur**) | ❌ | ❌ | ❌ (seul le `spacing` soft existe) | 🔴 **angle mort triple** |

## 3. Synthèse — scissions & angles morts

- **Aligné** : tout ce que le wizard émet est écrit par le backend et honoré par l'engine. Les scissions historiques « déclaré ≠ effectif » (ENG-10/11/12/13 offre↔engine, **ENG-16** forcedDays↔allowedDays) sont **corrigées** et verrouillées par `constraint_matrix.py`.
- **🟠 Scission A — l'engine sait, le front n'émet pas** : `forcedDays` (au moins une séance tel jour), `preferredDays`. *(`availableDays` — coach « disponible uniquement » — a été **exposé/aligné**.)* **Feature possible** : exposer les modes restants (petit ajout UI + cellule de matrice).
- **✅ Angles morts résorbés (2026-07-08)** : `maxEndTime` (**ALIGN-04**, mode « Fini avant »), **minimum de séances par gymnase** `minAtVenueId` (**ALIGN-05**, mode « au moins N »), **espacement** `spacing` (**ALIGN-06**, règle implicite soft) sont désormais câblés sur les 3 couches et verrouillés (matrice engine + offre wizard). Reste **🔴 `max_consecutive_days`** (écart **dur** « pas 3 d'affilée ») — le soft `spacing` ne le garantit pas.

> **Où le vérifier automatiquement** : `constraint_matrix.py` + son test figent l'offre wizard↔engine (colonnes Frontend↔Engine, cellules **offertes**). La couche **backend** (targetTag→N, venue_closed→forbidden, HARD preferred→forcé) et les **angles morts** ci-dessus ne sont **pas** couverts par ce test — c'est le rôle de l'**axe « alignement contraintes » de l'audit** (`/audit`) de les contre-vérifier bout-en-bout à chaque édition.

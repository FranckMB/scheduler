# Vocabulaire des contraintes — ce que l'engine comprend

> **But** : lister **exhaustivement** tout le vocabulaire (familles + clés de `config`) que le
> solveur CP-SAT (`engine/app/solver`) sait **parser et appliquer**. Source de vérité côté engine.
> Chaque entrée donne le **mécanisme** (dur/soft), le **ruleType** qui l'active, et un **exemple BCCL**.
>
> Une contrainte arrive sous la forme `{ scope, scopeTargetId, family, ruleType, config }`.
> Le backend (`ScheduleConstraintBuilder`) sérialise, l'engine (`parse_v2_constraints`,
> `add_time_window_constraints`, `objective.py`) lit. **Toute clé absente de ce document n'est PAS
> comprise** (elle est ignorée sans erreur).

## Portée & type de règle (communs à toutes les familles)

| Champ | Valeurs | Effet |
|---|---|---|
| `scope` | `CLUB` · `TEAM` · `COACH` · `FACILITY` | cible de la règle |
| `scopeTargetId` | uuid | l'équipe / coach / gymnase visé (null si CLUB) |
| `config.targetTag` | tag système (`JEUNE`, `SENIOR`, `EMB`, `U9`…`U21`, `FEMININE`, `MASCULINE`, `REGIONAL`, `DEPARTEMENTAL`, `LOISIR_ADULTE`…) | **CLUB + targetTag** → le backend **éclate** en N contraintes `TEAM` (une par équipe du tag). Une règle sans cible qui atteindrait l'engine → **warning** (`constraint_not_honored`) |
| `ruleType` | `HARD` · `LOCK` · `PREFERRED` · `BONUS` | `HARD`/`LOCK` = **dur** (jamais violé ; sur-contraint → équipe non placée + diagnostic). `PREFERRED` = **soft** (oriente l'objectif, ne bloque jamais). `BONUS` = normalisé en `PREFERRED`. |

---

## Famille TIME — heures de début

| Clé | Sens | Dur (HARD/LOCK) | Soft (PREFERRED) |
|---|---|---|---|
| `minStartTime` (`"HH:MM"`) | ne pas **commencer avant** | fenêtre dure (créneaux plus tôt interdits) | bonus objectif (préfère plus tard) |
| `maxStartTime` (`"HH:MM"`) | ne pas **commencer après** | fenêtre dure | bonus objectif (préfère plus tôt) |

⚠ **Pas de `maxEndTime`** : « finir avant 20h30 » se modélise en **début max** (ex. début ≤ 19h00 pour une séance de 90 min).

**Exemples BCCL**
- `EMB (U9/U11) - Début au premier créneau (max 17h30)` → `{ family:"TIME", ruleType:"HARD", config:{ maxStartTime:"17:30", targetTag:"EMB" } }`
- `Adultes - Début minimum 18h50` → `{ TIME, HARD, { minStartTime:"18:50", targetTag:"SENIOR" } }`
- `U13 - Début préféré avant 19h00` → `{ TIME, PREFERRED, { maxStartTime:"19:00", targetTag:"U13" } }` (soft)

---

## Famille DAY — jours de la semaine (1 = lundi … 7 = dimanche)

| Clé | Sens | Mécanisme |
|---|---|---|
| `forbiddenDays` (`[int]`) | **éviter** ces jours | `HARD` → jours interdits (dur) · `PREFERRED` → malus soft « éviter ces jours » |
| `allowedDays` (`[int]`) | **uniquement** ces jours (whitelist) | l'engine **interdit tout jour hors liste**. Toujours dur. (liste vide = « non configuré », aucune restriction) |
| `forcedDays` (`[int]`) | **au moins une** séance ces jours-là | pose `somme(vars de ces jours) ≥ 1`. **N'interdit PAS** les autres jours. **Engine-only** (le wizard émet `allowedDays` pour « uniquement », cf. audit ENG-16) |
| `preferredDays` (`[int]`) | préférer ces jours | bonus objectif. **Engine-only** (jamais émis par le wizard) |

> **Piège** : `allowedDays` (« uniquement ») ≠ `forcedDays` (« au moins un »). « Vétérans le vendredi
> **uniquement** » = `allowedDays:[5]` (sinon la 2ᵉ séance d'une équipe multi-séances pourrait tomber
> un autre jour). Contradiction `allowedDays ∩ forbiddenDays` couvrant tout → équipe à 0 séance +
> diagnostic `day_constraint_conflict` explicite.

**Exemples BCCL**
- `Veterans - Vendredi uniquement` → `{ DAY, HARD, { allowedDays:[5] } }`
- `U9M1 - Pas d'entraînement le mercredi` → `{ DAY, HARD, { forbiddenDays:[3] } }`
- `SM2 - Évite le vendredi` → `{ DAY, PREFERRED, { forbiddenDays:[5] } }` (soft)

---

## Famille FACILITY — gymnases

| Clé | Sens | Dur (HARD/LOCK) | Soft (PREFERRED) |
|---|---|---|---|
| `forcedVenueId` (uuid) | **imposer** ce gymnase | l'équipe ne joue QUE là (tous les autres interdits) | — |
| `preferredVenueId` (uuid) | ce gymnase | **HARD/LOCK = forcé** (comme `forcedVenueId`) | bonus objectif **+60** par séance dans ce gymnase |
| `forbiddenVenueId` (uuid) | **éviter** ce gymnase | assignation interdite (dur) | malus objectif **−60** (soft « évite ») |

- **Exclusivité groupe** : `CLUB + targetTag + (forcedVenueId ou preferredVenueId HARD)` → le backend force le tag ET **interdit le gymnase hors tag** → gymnase **réservé** au groupe.
- **Fermeture datée** (`config.type = "venue_closed"`, période cockpit) → le backend l'**étend** en `forbiddenVenueId` HARD par équipe sur la fenêtre.

**Exemples BCCL**
- `SM4 - Jean Vilar obligatoire` → `{ FACILITY, HARD, scope:"TEAM", scopeTargetId:<SM4>, config:{ forcedVenueId:<Jean Vilar> } }`
- `Camus - Réservé Loisir 1 exclusivement` → `{ FACILITY, HARD, TEAM:<Loisir 1>, { forcedVenueId:<Camus> } }`
- `Jean Vilar - Pas équipes féminines` → `{ FACILITY, HARD, CLUB, { forbiddenVenueId:<Jean Vilar>, targetTag:"FEMININE" } }`
- `Matéo - Préféré équipes régionales` → `{ FACILITY, PREFERRED, CLUB, { preferredVenueId:<Matéo>, targetTag:"REGIONAL" } }` (soft, +60)

---

## Famille COACH_AVAILABILITY — disponibilité coach (toujours dure)

| Clé | Sens | Mécanisme |
|---|---|---|
| `coachId` (uuid) | le coach visé | — |
| `unavailableDays` (`[int]`) | **indisponible** ces jours | jours interdits pour toute équipe du coach. **UNION** si plusieurs contraintes sur le même coach |
| `availableDays` (`[int]`) | **disponible uniquement** ces jours | whitelist. **INTERSECTION** si plusieurs contraintes |

> Une dispo coach reçue en non-HARD est **appliquée dur quand même** + diagnostic INFO (une personne
> ne peut pas être à deux endroits).

**Exemple BCCL**
- `Lionel - Indisponible le vendredi` → `{ COACH_AVAILABILITY, HARD, scope:"COACH", scopeTargetId:<Lionel>, config:{ coachId:<Lionel>, unavailableDays:[5] } }`

---

## Famille FACILITY_CAPACITY — capacité d'un gymnase

| Clé | Sens | Mécanisme |
|---|---|---|
| `venueId` (uuid) | le gymnase | **clé stricte** (`scopeTargetId` ne convient pas ici) |
| `maxTeams` (int) | nb max d'équipes **simultanées** par créneau | appliqué en `min(capacité du créneau, maxTeams)` — **ne peut que resserrer**, jamais élargir |

**Exemple BCCL** — un gymnase divisible (ex. ADN, 3 terrains) est saisi côté **écran Gymnases** (`canSplit`), pas dans l'onglet contraintes ; le backend émet le `FACILITY_CAPACITY`.

---

## `type: "PRIORITY_TIER"` — poids de priorité (rang S/A/B/C/D)

Envoyé par le backend depuis les `PriorityTier`. `metadata.id` + `metadata.defaultMinSessions` +
`orToolsWeight` (S=10000 · A=1000 · B=100 · C=10 · D=1). Le poids exponentiel garantit qu'un rang
supérieur l'emporte dans l'objectif. Le **minimum de séances** du rang est une **cible soft**
(bonus objectif), pas un plancher dur (audit ENG-18).

---

## Règles implicites (toujours appliquées, sans config)

| Règle | Effet |
|---|---|
| `VENUE_AT_MOST_ONE` / capacité | jamais 2 équipes sur le même créneau d'un gymnase non divisible |
| `TEAM_NO_OVERLAP` | une équipe jamais 2 séances en même temps |
| `COACH_NO_OVERLAP` | un coach jamais sur 2 séances simultanées |
| `COACH_PLAYER_NO_OVERLAP` | un coach qui **joue** aussi n'est jamais convoqué à 2 séances simultanées (ex. Mathis coach U13M2 + joueur U21M1) |
| `MIN_SESSIONS` | chaque équipe vise son nombre de séances/semaine (**cible soft**, cf. ENG-18) |
| jour de repos après match | bonus soft (`add_match_day_rest_bonus`) : préfère laisser le lendemain d'un match libre |

## Ce que l'engine NE comprend PAS (à ce jour)

- **`maxEndTime`** (heure de fin) — approximé via `maxStartTime`.
- **« au moins N séances dans tel gymnase »** — aucun équivalent de `forcedDays` pour les gymnases (voir `backend/docs/constraint-coverage.md`).
- **`max_consecutive_days`** / « pas 3 jours d'affilée » / « espacer les séances d'un jour » — non modélisé (seul le repos post-match est un bonus soft).

**Verrous** : `engine/tests/semantic/constraint_matrix.py` (matrice UI↔engine) + `docs/architecture/constraint-matrix.md` (jumeau humain de l'**offre du wizard**). Ce document-ci couvre le vocabulaire **engine complet**, y compris ce que le wizard n'émet pas encore.

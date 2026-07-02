# Modèle de contraintes cible (extrait de la Spécification des contraintes v2)

> **Cœur métier.** Base de réflexion pour aligner le modèle de contraintes livré sur la vision de `specs/initiales/ClubScheduler_Specification_des_contraintes_v2.md` (figée). Statut : ✅ livré · 🟡 partiel · ⬜ à faire. Ce doc décrit la **cible** et l'écart, pas une implémentation à lancer telle quelle — on tranchera au moment de traiter le sujet.

Rappel de l'implémentation actuelle : une entité `Constraint` unifiée (scope + famille + `ruleType` + `config` JSON), résolue par `ScheduleConstraintBuilder` (dont `resolveTagToTeamIds` pour cibler un groupe via `config.targetTag`), puis appliquée par l'engine (Level 1 dur / Level 2 mou).

---

## 1. Les 4 axes du modèle

### 1.1 Scopes — qui est concerné (cible : 5)
`CLUB` · `CATEGORY` · `TEAM` · `COACH` · `FACILITY`.

- ✅ CLUB, TEAM, COACH, FACILITY.
- ⬜ **CATEGORY** — cibler une catégorie d'âge (ex. tous les U11). Aujourd'hui contourné par le **ciblage groupe/tag** (`config.targetTag`, ex. `JEUNE`) éclaté en N contraintes équipe. À décider : garder l'approche tag, ou introduire un vrai scope CATEGORY 1ère classe.

### 1.2 Types de règles — l'intention (cible : 4) — ✅ livré
`HARD` (impérative) · `PREFERRED` (préférence molle) · `BONUS` (incitation) · `LOCK` (décision humaine, pas une contrainte métier — verrou de placement).

Point clé de la spec : **LOCK n'est pas une contrainte métier** mais une décision humaine dans la boucle de travail. Cohérent avec `ScheduleSlotTemplate.lockLevel` livré.

### 1.3 Familles — la mécanique (cible : 7)
`TIME` · `DAY` · `FACILITY` · `COACH_AVAILABILITY` · `FACILITY_CAPACITY` · `ALLOCATION_PRIORITY` · `DISTRIBUTION`.

- ✅ TIME, DAY, FACILITY, COACH_AVAILABILITY.
- 🟡 **FACILITY_CAPACITY** — l'onglet UI a été retiré (redondant avec la capacité 1/2 par créneau) ; le **concept** (max entraînements parallèles) reste cible via `FACILITY_MAX_PARALLEL_TRAININGS` (voir §3).
- ⬜ **ALLOCATION_PRIORITY** — priorité d'allocation des ressources (au-delà des tiers S/A/B/C/D ?). À clarifier.
- ⬜ **DISTRIBUTION** — répartition (étalement sur la semaine, équilibrage). À clarifier.

### 1.4 `config` (JSON par famille)
Déjà en place. La cible ajoute des clés pour les nouveaux types (§2).

---

## 2. Liste fermée des types de contraintes MVP (contraintes-v2)

La spec impose une **liste fermée** (pas de type inventé hors liste). Mapping cible → livré :

| Type | Statut | Note |
|------|--------|------|
| `TEAM_MAX_START_TIME` | 🟡 | Faisable via TIME « pas après » sur une équipe ; pas de type dédié |
| `TEAM_PREFERRED_DAY` | ✅ | Famille DAY |
| `TEAM_FORBIDDEN_DAY` | ✅ | Famille DAY (`forbiddenDays`) |
| `TEAM_PREFERRED_FACILITY` | ✅ | Famille FACILITY (préfère) |
| `TEAM_FORBIDDEN_FACILITY` | ✅ | Famille FACILITY (évite) |
| `COACH_FORBIDDEN_DAY` | ✅ | COACH_AVAILABILITY (`unavailableDays`) |
| `COACH_FORBIDDEN_TIME_RANGE` | ⬜ | Plage horaire interdite coach (au-delà du jour) |
| `COACH_MAX_PRESENCE_DAYS` | 🟡 | `max_days_per_week` coach — calcul + override à vérifier |
| `COACH_NO_OVERLAP` | ✅ | Implicite (Level 1) |
| `FACILITY_FORBIDDEN_TEAM_TAG` | ⬜ | Interdire un tag d'équipe dans une salle |
| `FACILITY_PREFERRED_TEAM_TAG` | ⬜ | Préférer un tag d'équipe dans une salle |
| `FACILITY_MAX_PARALLEL_TRAININGS` | 🟡 | Capacité 1/2 par créneau livrée ; `max_parallel > 2` + contrainte dédiée ⬜ (voir §3) |
| `CLUB_YOUNG_MAX_START_TIME` | 🟡 | « jeunes pas après 19h30 » — faisable via tag `JEUNE` + TIME ; pas de type club dédié |
| `CLUB_SLOT_GRANULARITY` | ⬜ | Granularité fixe (30 min recommandé). **Discordance** avec la grille 15 min de v3 §11.1 → à trancher |
| `CLUB_TRAINING_DURATION_BY_CATEGORY` | ⬜ | Durée d'entraînement par défaut par catégorie, réglée au niveau club |

---

## 3. Modèle des salles (divisible / partage)

La spec remplace le modèle « salle = booléen » par une capacité de parallélisme :

- 🟡 **`divisible` + `max_parallel_trainings`** (gymnase = 1, gymnase divisible = 2). Livré : case « Terrain divisible » (`Venue.canSplit`) + capacité 1/2 posée **par créneau**. ⬜ Manque : `max_parallel_trainings` paramétrable ( > 2), et une contrainte `FACILITY_MAX_PARALLEL_TRAININGS` de 1ère classe (plutôt que la capacité au niveau créneau).
- ⬜ **`allow_shared_court` par équipe** — chaque équipe accepte/refuse de partager le terrain (jeunes = oui, seniors = non). Non modélisé. Interagit avec la divisibilité : une salle divisible ne peut mutualiser deux équipes que si les deux acceptent le partage.

À réfléchir : où vit la capacité — au **créneau** (actuel), à la **salle** (`max_parallel_trainings`), ou aux deux ? La spec penche « salle », l'implémentation actuelle « créneau ».

---

## 4. Contraintes implicites HARD (contraintes-v2)

Règles toujours vraies, pas à ressaisir par l'utilisateur :

- ✅ Coach / équipe pas en double sur un même créneau.
- ✅ Capacité de salle respectée.
- ✅ Séances dans les créneaux municipaux (disponibilités salle).
- ✅ Locks HARD respectés.
- ⬜ **Coach principal présent à toutes les séances de son équipe** — à vérifier/garantir dans le solveur.

---

## 5. Capitalisation entre saisons

- ⬜ Contraintes **copiées** vers la nouvelle saison (copies éditables), historiques conservées.
- ⬜ Traçabilité par self-FK `parent_*_id` (contrainte, équipe, etc.).
- Lié à la [transition de saison](roadmap.md#3-onboarding--saisons) (FF#3).

---

## 6. Questions ouvertes à trancher

1. **Scope CATEGORY** 1ère classe, ou on garde le ciblage par **tag** (déjà livré) comme mécanisme unique de groupe ?
2. **Granularité** : 30 min (contraintes-v2) vs 15 min (v3 §11.1) — laquelle fait foi ?
3. **Capacité salle** : au créneau (actuel) vs `max_parallel_trainings` au niveau salle (spec) ?
4. Familles **ALLOCATION_PRIORITY** / **DISTRIBUTION** : quel périmètre réel au-delà des tiers de priorité et de l'étalement déjà gérés ?
5. Faut-il matérialiser la **liste fermée** de types côté backend (validation stricte), ou rester sur le modèle générique famille+config actuel ?

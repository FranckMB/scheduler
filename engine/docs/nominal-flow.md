# Flux nominal : de l'appel backend a la reponse du moteur

> Ce document decrit le chemin complet d'une requete de generation d'emploi du temps, du moment ou le backend construit le payload jusqu'a la notification en temps reel du frontend. Destine aux developpeurs travaillant sur l'integration backend/engine.

---

## 1. Le backend construit le payload (format v2.0)

Quand un utilisateur clique sur "Generer l'emploi du temps" dans le frontend, le backend assemble un objet JSON conforme au schema `ScheduleInputSchema` (version 2.0). Voici la structure complete, avec des explications inline.

```json
{
  "version": "2.0",
  "clubId": "550e8400-e29b-41d4-a716-446655440000",
  "seasonId": "660e8400-e29b-41d4-a716-446655440001",

  "venues": [
    {
      "id": "v1",
      "name": "Gymnase A",
      "isActive": true,
      "availability": [
        {"dayOfWeek": 1, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 2, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 3, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 4, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 5, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 6, "startTime": "08:00", "endTime": "12:00"}
      ]
    },
    {
      "id": "v2",
      "name": "Gymnase B",
      "isActive": true,
      "availability": [
        {"dayOfWeek": 2, "startTime": "18:00", "endTime": "21:00"},
        {"dayOfWeek": 4, "startTime": "18:00", "endTime": "21:00"}
      ]
    }
  ],

  "teams": [
    {
      "id": "t-sm1",
      "name": "SM1",
      "priorityTierId": 1,
      "sessionsPerWeek": 3,
      "tags": ["REGIONAL", "SENIOR", "MASCULINE"],
      "level": "REGIONAL",
      "gender": "M",
      "isActive": true
    },
    {
      "id": "t-u15f1",
      "name": "U15F1",
      "priorityTierId": 3,
      "sessionsPerWeek": 2,
      "tags": ["JEUNE", "FEMININE", "DEPARTEMENTAL"],
      "level": "DEPARTEMENTAL",
      "gender": "F",
      "isActive": true
    }
  ],

  "coaches": [
    {
      "id": "c-maxime",
      "firstName": "Maxime",
      "lastName": "Dupont",
      "isActive": true
    },
    {
      "id": "c-sophie",
      "firstName": "Sophie",
      "lastName": "Martin",
      "isActive": true
    }
  ],

  "constraints": [
    {
      "id": "ctr-1",
      "scope": "TEAM",
      "scopeTargetId": "t-sm1",
      "family": "TIME",
      "ruleType": "HARD",
      "name": "Pas apres 20h",
      "config": {"maxStartTime": "20:00"},
      "isActive": true
    },
    {
      "id": "ctr-2",
      "scope": "TEAM",
      "scopeTargetId": "t-u15f1",
      "family": "DAY",
      "ruleType": "PREFERRED",
      "name": "Preferer le mardi",
      "config": {"preferredDays": [2]},
      "isActive": true
    },
    {
      "id": "team-coach:t-sm1",
      "teamId": "t-sm1",
      "type": "TEAM_COACH",
      "severity": "HARD",
      "value": "c-maxime",
      "metadata": {"coachId": "c-maxime", "role": "MAIN"}
    }
  ],

  "slotTemplates": [
    {
      "id": "st-1",
      "teamId": "t-sm1",
      "venueId": "v1",
      "coachId": "c-maxime",
      "dayOfWeek": 1,
      "startTime": "19:00",
      "durationMinutes": 90,
      "lockLevel": "HARD"
    }
  ],

  "priorityTiers": [
    {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 3},
    {"id": 2, "label": "A", "orToolsWeight": 1000, "defaultMinSessions": 3},
    {"id": 3, "label": "B", "orToolsWeight": 100, "defaultMinSessions": 2},
    {"id": 4, "label": "C", "orToolsWeight": 10, "defaultMinSessions": 2},
    {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 1}
  ]
}
```

### Explications par section

- **`version`** : doit valoir exactement `"2.0"`. Le moteur refuse les payloads d'anciennes versions.
- **`clubId` / `seasonId`** : identifiants du club et de la saison en cours. Le moteur ne les utilise pas pour le calcul, mais les inclut dans les logs et les diagnostics.
- **`venues`** : liste des salles. Chaque salle a des fenetres de disponibilite (`availability`). Le champ `venueAvailabilities` n'existe plus dans le format v2.0. Dans les versions precedentes, les disponibilites etaient stockees dans une cle separee au niveau du payload. Elles sont maintenant imbriquees directement dans chaque salle, ce qui est plus logique et evite les erreurs de coherence.
- **`teams`** : liste des equipes. Le `priorityTierId` fait reference a l'identifiant dans `priorityTiers`.
- **`coaches`** : liste des entraineurs. Leur disponibilite propre est exprimee via des contraintes `COACH_AVAILABILITY`.
- **`constraints`** : deux formats coexistent :
  - **Format unifie v2** (`scope`, `family`, `ruleType`, `config`) : le nouveau format, plus flexible. Exemple : une contrainte `TIME` avec `maxStartTime: "20:00"`.
  - **Format legacy type** (`teamId`, `type`, `severity`, `value`) : conserve pour la retrocompatibilite, notamment pour les liens equipe-entraineur (`TEAM_COACH`). Exemple : `team-coach:t-sm1` lie le SM1 a Maxime Dupont comme entraineur principal.
- **`slotTemplates`** : creneaux pre-existants. Un creneau `HARD` est fige. Un creneau `SOFT` est une suggestion. Un creneau `NONE` n'a pas lieu d'etre dans cette liste (il serait genere par le moteur).
- **`priorityTiers`** : definition des niveaux de priorite. Le poids `orToolsWeight` est ce que le solveur OR-Tools maximise.

### Resolution des tags

Les contraintes `CLUB` avec `targetTag` ont deja ete "explosees" par le backend. Cela signifie que si le club a une regle "pas d'entrainement apres 20h pour les jeunes", le backend a deja cree N contraintes `TEAM` individuelles (une par equipe taggee `JEUNE`). Le moteur ne voit donc jamais de contraintes `CLUB` avec `targetTag`. Cette simplification reduit la complexite cote moteur.

---

## 2. Le moteur recoit et valide

Le backend envoie le payload au moteur via une requete HTTP POST sur `http://engine:8000/generate`.

### Validation Pydantic v2

FastAPI valide automatiquement le JSON contre le schema `ScheduleInputSchema`. Si un champ manque, si un type est incorrect (par exemple `sessionsPerWeek: "trois"` au lieu de `3`), ou si une valeur d'enum est invalide (par exemple `lockLevel: "FORT"` au lieu de `HARD`), FastAPI retourne immediatement une erreur `422 Unprocessable Entity` avec le detail des champs en erreur.

### Verrou asyncio par club

Avant de lancer le solveur, le moteur acquiert un verrou asyncio specifique au `clubId`. Cela empeche deux generations simultanees pour le meme club. Si un second utilisateur demande une generation pendant que la premiere est en cours, le moteur retourne `503 Service Unavailable` avec le message "Club already generating, retry later".

### Verification de version

Le moteur verifie que `version == "2.0"`. Si ce n'est pas le cas, il retourne une erreur indiquant la version attendue et la version recue.

---

## 3. Pipeline du solveur (4 etapes)

### Etape 1 — `build_model()`

Le moteur genere les **variables de decision booleennes** :

```
x[team_id, venue_id, day_of_week, slot_start]
```

Chaque variable signifie : "l'equipe T s'entraine-t-elle a la salle V le jour D a l'heure S ?" (1 = oui, 0 = non).

Les creneaux disponibles sont derives des fenetres `venue.availability`, avec une granularite de **15 minutes**. Si le Gymnase A est ouvert de 08h00 a 22h00, les slots possibles sont 08h00, 08h15, 08h30, ..., 21h45. La duree de la seance (par defaut 90 minutes) determine quel slot de debut est valide. Un slot a 21h45 serait invalide car la seance finirait a 23h15, hors des horaires d'ouverture.

Les creneaux `HARD`-verrouilles sont **pre-placees** : la variable correspondante est fixee a 1 et retiree de l'espace d'optimisation. Le solveur ne cherchera pas a les deplacer.

### Etape 2 — `add_level_1_hard_constraints()`

Ces contraintes doivent etre satisfaites pour que la solution soit **faisable**. Si l'une d'elles ne peut pas l'etre, le solveur retournera `INFEASIBLE`.

1. **VENUE_AT_MOST_ONE** : pour chaque salle, jour et slot, la somme des variables est inferieure ou egale a 1. Deux equipes ne peuvent pas partager le Gymnase A le lundi a 19h00.
2. **COACH_NO_OVERLAP** : pour chaque entraineur, jour et slot, la somme des equipes qu'il entraine est inferieure ou egale a 1. Maxime Dupont ne peut pas diriger deux equipes en meme temps.
3. **COACH_PLAYER_NO_OVERLAP** : pour chaque entraineur-joueur, la somme de ses seances d'entrainement et de ses seances de joueur est inferieure ou egale a 1.
4. **TEAM_NO_OVERLAP** : pour chaque equipe, jour et slot, la somme est inferieure ou egale a 1. Le SM1 ne peut pas avoir deux seances a 19h00.
5. **FIXED_SLOTS** : pour chaque creneau `HARD`, la variable vaut exactement 1.
6. **FORBIDDEN_ASSIGNMENTS** : pour chaque contrainte `HARD` de type interdiction, la variable vaut 0. Exemple : si le SM1 a une contrainte "pas le vendredi", toutes les variables `x[t-sm1, *, 5, *]` valent 0.
7. **COACH_UNAVAILABILITY** : pour chaque contrainte `COACH_AVAILABILITY`, les variables correspondantes valent 0.
8. **VENUE_CLOSURES** : pour chaque contrainte `FACILITY_CAPACITY`, les variables de la salle fermee valent 0.
9. **MIN_SESSIONS** : pour chaque equipe, la somme totale de ses variables est superieure ou egale a `min_sessions` (derive de `sessionsPerWeek` ou de `defaultMinSessions` du tier).
10. **FORCED_VENUES** : si une equipe a une contrainte `FACILITY` `HARD` l'obligeant a une salle specifique, toutes les variables `x[team, autre_salle, *, *]` valent 0.

### Etape 3 — `add_level_2_objective()`

Le solveur maximise la fonction objectif suivante :

```
maximiser  Σ weight(tier) × x[team, venue, day, slot]
         + bonus pour preservation des verrous SOFT
         + bonus pour slots preferes (contraintes DAY PREFERRED)
         - penalite pour surcharge d'entraineur
         + ...
```

Les poids des tiers sont fixes :

| Tier | Poids |
|------|-------|
| S | 10 000 |
| A | 1 000 |
| B | 100 |
| C | 10 |
| D | 1 |

Ainsi, placer une seance du SM1 (S) rapporte 10 000 points. Placer une seance de l'U15F1 (B) rapporte 100 points. Si une seule place est disponible au Gymnase A le lundi a 19h00, le solveur la donnera au SM1.

Les bonus et penalites sont des termes secondaires. Ils ne changent pas l'ordre de grandeur du score, mais affinent la solution. Par exemple, preserver un verrou `SOFT` rapporte un petit bonus. Placer une equipe sur son jour prefere rapporte un bonus. Surcharger un entraineur (plus de seances que son seuil) applique une penalite.

### Etape 4 — `CpSolver.Solve()`

Le solveur OR-Tools CP-SAT est lance avec un temps maximum de **10 secondes** (`max_time_in_seconds=10`).

Trois resultats possibles :

- `OPTIMAL` : la meilleure solution possible a ete trouvee. Le score est le maximum theorique.
- `FEASIBLE` : une solution valide a ete trouvee, mais pas necessairement la meilleure (le temps de 10 secondes a ete atteint). Le score est bon, mais peut-etre pas optimal.
- `INFEASIBLE` : aucune solution ne satisfait toutes les contraintes `HARD`. L'instance est impossible. Le moteur retournera `status: failed`.

---

## 4. Le moteur retourne `ScheduleOutputSchema`

Une fois le solveur termine, le moteur construit la reponse JSON :

```json
{
  "status": "completed",
  "score": 117679,
  "slots": [
    {
      "id": "slot-1",
      "teamId": "t-sm1",
      "venueId": "v1",
      "coachId": "c-maxime",
      "dayOfWeek": 1,
      "startTime": "19:00",
      "durationMinutes": 90,
      "lockLevel": "NONE"
    },
    {
      "id": "slot-2",
      "teamId": "t-u15f1",
      "venueId": "v2",
      "coachId": "c-sophie",
      "dayOfWeek": 2,
      "startTime": "18:00",
      "durationMinutes": 90,
      "lockLevel": "NONE"
    }
  ],
  "unplaced": [],
  "diagnostics": [
    {
      "type": "soft_lock_moved",
      "severity": "MEDIUM",
      "message": "Le creneau SOFT du SM1 (lundi 19h) a ete deplace vers mardi 19h pour optimiser le score global",
      "teamId": "t-sm1",
      "slotId": "st-1"
    }
  ],
  "metrics": {
    "solver_version": "9.11.0",
    "nb_variables": 5432,
    "nb_constraints": 12847,
    "wall_time_ms": 8240
  }
}
```

### Champs de la reponse

- **`status`** : `completed` (succes) ou `failed` (echec, voir `solver-errors.md`)
- **`score`** : score total de la solution. 0 signifie que rien n'a ete place
- **`slots`** : liste des creneaux assignes. Chaque creneau a un `lockLevel` (`NONE` pour les nouveaux, `HARD`/`SOFT` pour les pre-existants preserves)
- **`unplaced`** : liste des `teamId` pour lesquels aucune seance n'a ete placee
- **`diagnostics`** : alertes detaillees (voir `solver-errors.md` pour le catalogue complet)
- **`metrics`** : metriques techniques du solveur. `nb_variables` et `nb_constraints` donnent une idee de la taille du probleme. `wall_time_ms` indique le temps reel de resolution

---

## 5. Le backend traite la reponse

### Import des creneaux

Pour chaque element de `slots[]`, le backend cree ou met a jour une entite `ScheduleSlotTemplate` en base de donnees. Les creneaux existants avec `lockLevel=HARD` sont conserves. Les nouveaux creneaux sont crees avec `lockLevel=NONE`.

### Import des diagnostics

Pour chaque element de `diagnostics[]`, le backend cree une entite `ScheduleDiagnostic`. Ces diagnostics sont affiches dans le frontend pour aider l'utilisateur a comprendre les choix du moteur.

### Mise a jour du statut

Le backend met a jour le statut du `Schedule` :

- `status = COMPLETED` si `engine.status == "completed"`
- `status = FAILED` si `engine.status == "failed"`

### Notification Mercure SSE

Le backend publie un evenement sur le hub Mercure (`clubscheduler-mercure`) sur le topic :

```
club:{clubId}:schedule:{scheduleId}
```

Le frontend ecoute ce topic via `EventSource`. Des que l'evenement arrive, le frontend rafraichit l'affichage de l'emploi du temps. L'utilisateur voit le resultat apparaitre en temps reel, sans avoir a recharger la page.

---

## Resume du flux en 5 etapes

1. **Backend** : construit le payload v2.0 a partir des entites du club (equipes, salles, entraineurs, contraintes)
2. **Moteur** : valide le payload, acquiert le verrou club, verifie la version
3. **Solveur** : construit le modele, ajoute les contraintes HARD, definit l'objectif, resout en 10 secondes max
4. **Moteur** : retourne `ScheduleOutputSchema` avec creneaux, diagnostics, metriques
5. **Backend** : importe les resultats en base, met a jour le statut, notifie le frontend via Mercure

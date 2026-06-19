# Documentation métier du système de contraintes

> ClubScheduler — Symfony 7 + API Platform. Contexte : BCCL (Basket Club de la Côte du Languedoc).

---

## 1. Introduction — Qu'est-ce qu'une contrainte ?

Une **contrainte** est une règle métier qui façonne le planning d'entraînement du club. Elle dit au solveur ce qui est autorisé, ce qui est interdit, et ce qui serait préférable.

On distingue deux catégories :

- **Règles implicites** : appliquées automatiquement par le moteur, sans intervention humaine. Par exemple, un entraîneur ne peut pas être sur deux terrains en même temps, ou une salle ne peut accueillir qu'une seule équipe par créneau. Ces règles sont codées en dur dans l'engine Python et ne sont pas configurables.
- **Contraintes utilisateur** : créées explicitement par l'administrateur du club via l'interface d'administration ou l'API. C'est ce document qui les décrit.

Prenons un exemple concret au BCCL : l'équipe première masculine (SM1) s'entraîne le mardi et jeudi soir. Cette préférence n'est pas une règle universelle du basket, c'est une décision du club. C'est donc une contrainte utilisateur de type `DAY` + `PREFERRED`.

---

## 2. Anatomie d'une contrainte

L'entité `Constraint` possède quatre dimensions clés qui déterminent son comportement.

### 2.1 Scope — À qui s'applique-t-elle ?

Le champ `scope` (enum `ConstraintScope`) définit la cible de la contrainte.

| Valeur | Cible | Exemple BCCL |
|--------|-------|--------------|
| `CLUB` | Toutes les équipes du club (filtrables par tag via `targetTag`) | "Toutes les équipes jeunes finissent avant 19h30" |
| `TEAM` | Une équipe spécifique (via `scopeTargetId` = UUID de l'équipe) | "SM3 ne s'entraîne que le mercredi" |
| `COACH` | Un entraîneur spécifique (via `scopeTargetId` = UUID du coach) | "Enzo n'est pas disponible le vendredi" |
| `FACILITY` | Une salle spécifique (via `scopeTargetId` = UUID du lieu) | "Le gymnase ADN est fermé le lundi" |

### 2.2 Family — Quel type de règle ?

Le champ `family` (enum `ConstraintFamily`) définit la famille de la contrainte. Chaque famille attend des clés spécifiques dans le champ JSON `config`.

#### `TIME` — Fenêtre horaire

Restreint les horaires de début d'entraînement.

| Clé `config` | Type | Description | Exemple |
|--------------|------|-------------|---------|
| `maxStartTime` | string (HH:MM) | Heure max de début | `"19:30"` |
| `minStartTime` | string (HH:MM) | Heure min de début | `"20:00"` |

> Exemple : `{maxStartTime: "19:30"}` signifie "l'entraînement doit commencer au plus tard à 19h30". Si la séance dure 1h30, elle finira donc à 21h00 au plus tard.

#### `DAY` — Préférence de jour

Définit les jours autorisés ou préférés pour l'entraînement.

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `preferredDays` | int[] (1-7) | Jours préférés |
| `forbiddenDays` | int[] (1-7) | Jours interdits |

Numérotation des jours : `1=Lundi`, `2=Mardi`, `3=Mercredi`, `4=Jeudi`, `5=Vendredi`, `6=Samedi`, `7=Dimanche`.

> Exemple : `{preferredDays: [3]}` force l'entraînement le mercredi uniquement. `{forbiddenDays: [6, 7]}` interdit le week-end.

#### `FACILITY` — Affectation de salle

Oriente ou bloque l'utilisation d'une salle spécifique.

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `preferredVenueId` | UUID | Salle préférée |
| `forbiddenVenueId` | UUID | Salle interdite |

Pour les contraintes de type `FACILITY` avec un scope `FACILITY` (fermetures de salle) :

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `dateStart` | string (YYYY-MM-DD) | Début de fermeture |
| `dateEnd` | string (YYYY-MM-DD) | Fin de fermeture |
| `closedDay` | int (1-7) | Jour de fermeture récurrent |
| `onlyDay` | int (1-7) | Jour d'ouverture unique |

> Exemple : `{forbiddenVenueId: "uuid-jean-vilar"}` empêche toute équipe concernée d'aller au gymnase Jean Vilar.

#### `COACH_AVAILABILITY` — Indisponibilité d'entraîneur

Déclare qu'un entraîneur n'est pas disponible certains jours.

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `unavailableDays` | int[] (1-7) | Jours où le coach est indisponible |

> Exemple : `{unavailableDays: [5]}` signifie que l'entraîneur n'est jamais disponible le vendredi.

#### `FACILITY_CAPACITY` — Capacité de salle

Réservé pour un usage futur. Permettra de limiter le nombre d'équipes simultanées dans une salle en fonction de son nombre de terrains.

### 2.3 Rule Type — Quelle sévérité ?

Le champ `ruleType` (enum `ConstraintRuleType`) définit comment le solveur traite la contrainte.

| Valeur | Comportement | Analogie |
|--------|--------------|----------|
| `HARD` | Doit être respectée. Si elle est violée, le planning est infaisable. | "C'est non négociable." |
| `PREFERRED` | Devrait être respectée. Une violation est pénalisée dans le score, mais autorisée. | "C'est préférable, mais on peut déroger si nécessaire." |
| `BONUS` | Récompense si respectée. Aucune pénalité si violée. | "C'est un plus, pas une obligation." |
| `LOCK` | Figé. Le créneau est verrouillé, le solveur ne peut pas le déplacer. | "Ne touchez pas à ce créneau." |

### 2.4 Tag targeting (pour le scope `CLUB`)

Quand le scope est `CLUB`, la contrainte s'applique par défaut à **toutes** les équipes du club. Pour cibler un sous-ensemble, on utilise la clé `config.targetTag`.

Une contrainte `CLUB` avec `config.targetTag = "JEUNE"` s'applique uniquement aux équipes portant le tag `JEUNE`. Ces tags sont générés automatiquement par `TeamTagService` à partir des caractéristiques de l'équipe (âge, genre, niveau).

**Tags système disponibles :**

| Catégorie | Tags |
|-----------|------|
| Âge | `JEUNE`, `SENIOR`, `EMB` |
| Catégorie jeunes | `U9`, `U11`, `U13`, `U15`, `U18`, `U21` |
| Genre | `FEMININE`, `MASCULINE`, `MIXTE` |
| Niveau | `ELITE`, `REGIONAL`, `NATIONAL`, `DEPARTEMENTAL`, `LOISIR_ADULTE`, `LOISIR_JEUNE`, `HONNEUR`, `PROMOTION`, `PRE_REGION` |

> Exemple : `targetTag: "U11"` cible toutes les équipes U11 du club (garçons et filles confondus). Pour cibler uniquement les U11 filles, on combinerait avec `targetTag: "FEMININE"` (deux contraintes distinctes, ou logique future d'intersection de tags).

---

## 3. Exemples concrets BCCL

Voici huit contraintes réelles ou plausibles pour le BCCL, présentées sous forme de tableau récapitulatif.

| Nom | Scope | Family | Type | Config | Effet |
|-----|-------|--------|------|--------|-------|
| Jeunes — fin à 19h30 | `CLUB` | `TIME` | `HARD` | `{maxStartTime:"19:30", targetTag:"JEUNE"}` | Toutes les équipes jeunes terminent avant 19h30 |
| U11/U13 — début à 17h30 | `CLUB` | `TIME` | `PREFERRED` | `{minStartTime:"17:30", targetTag:"U11"}` | Les U11 commencent de préférence après 17h30 |
| Jean Vilar — sans filles | `CLUB` | `FACILITY` | `HARD` | `{forbiddenVenueId:"uuid-jean-vilar", targetTag:"FEMININE"}` | Aucune équipe féminine ne peut aller à Jean Vilar |
| Matéo — préféré régional | `CLUB` | `FACILITY` | `PREFERRED` | `{preferredVenueId:"uuid-mateo", targetTag:"REGIONAL"}` | Les équipes régionales vont de préférence à Matéo |
| SM3 — mercredi seulement | `TEAM` | `DAY` | `HARD` | `{preferredDays:[3]}` | SM3 ne s'entraîne que le mercredi |
| SM3 — à partir de 20h | `TEAM` | `TIME` | `HARD` | `{minStartTime:"20:00"}` | SM3 commence au plus tôt à 20h |
| ADN — fermé le lundi | `FACILITY` | `FACILITY` | `HARD` | `{closedDay:1}` | Le gymnase ADN est fermé le lundi |
| Coach Enzo — indisponible vendredi | `COACH` | `COACH_AVAILABILITY` | `HARD` | `{unavailableDays:[5]}` | Enzo n'est disponible aucun vendredi |

### 3.1 Exemple détaillé : "Jeunes — fin à 19h30"

Cette contrainte utilise le scope `CLUB` combiné au tag `JEUNE`. Dans la base de données, elle est stockée comme une seule ligne dans la table `Constraint` :

```json
{
  "scope": "CLUB",
  "family": "TIME",
  "ruleType": "HARD",
  "config": {
    "maxStartTime": "19:30",
    "targetTag": "JEUNE"
  }
}
```

Mais avant d'envoyer le payload au moteur de calcul, le backend résout ce tag. `ScheduleConstraintBuilder` interroge les tables `TeamTag` et `TeamTagAssignment` pour trouver toutes les équipes taguées `JEUNE` dans la saison active (par exemple : U9M, U9F, U11M, U11F, U13M1, U13M2, U13F, U15M, U15F). Pour chacune de ces équipes, il génère une contrainte individuelle de scope `TEAM`.

Le moteur ne voit jamais `targetTag`. Il reçoit huit contraintes `TEAM` distinctes, chacune avec `maxStartTime: "19:30"`. Cette résolution garantit que le solveur travaille avec des entités concrètes, pas avec des abstractions de tag.

### 3.2 Exemple détaillé : "SM3 — mercredi seulement"

Cette contrainte cible directement l'équipe SM3 (Senior Masculin 3) via le scope `TEAM`. Son `scopeTargetId` pointe vers l'UUID de l'entité `Team` SM3.

```json
{
  "scope": "TEAM",
  "scopeTargetId": "uuid-sm3",
  "family": "DAY",
  "ruleType": "HARD",
  "config": {
    "preferredDays": [3]
  }
}
```

Pour le solveur, cette contrainte `HARD` signifie que seuls les créneaux du mercredi sont disponibles pour SM3. Les créneaux mardi, jeudi, vendredi, etc. sont invisibles pour cette équipe. Si le mercredi est déjà saturé par d'autres équipes, le solveur peut être contraint de déclarer le planning infaisable, ou de violer d'autres contraintes `HARD` (ce qui produit un diagnostic de conflit).

C'est une contrainte très forte. Dans la pratique, le BCCL pourrait la déclarer `PREFERRED` plutôt que `HARD` pour laisser une marge de manoeuvre au solveur en cas de saturation des salles le mercredi.

### 3.3 Exemple détaillé : "ADN — fermé le lundi"

Cette contrainte est particulière car elle porte à la fois sur le scope `FACILITY` et la family `FACILITY`. Elle est attachée au lieu lui-même, pas à une équipe.

```json
{
  "scope": "FACILITY",
  "scopeTargetId": "uuid-adn",
  "family": "FACILITY",
  "ruleType": "HARD",
  "config": {
    "closedDay": 1
  }
}
```

Ici, le gymnase ADN (Albert Dubois-Nauriac) déclare sa propre indisponibilité. Le solveur sait que ce lieu ne peut pas accueillir d'entraînement le lundi, quelle que soit l'équipe. C'est l'équivalent d'une fermeture administrative récurrente.

Contrairement aux contraintes `TEAM` ou `COACH`, cette contrainte réduit l'offre de créneaux disponibles pour **toutes** les équipes. Si ADN est la seule salle disponible le lundi soir, alors aucune équipe ne pourra s'entraîner ce soir-là.

---

## 4. Résolution des tags

Le mécanisme de résolution des tags est un passage obligé entre la saisie utilisateur (abstraite) et le calcul du solveur (concret).

### 4.1 Le processus en 4 étapes

**Étape 1 — Création par l'administrateur**

L'administrateur du BCCL crée une contrainte `CLUB` avec `targetTag = "JEUNE"`. Il n'a pas besoin de lister manuellement les 8 équipes jeunes. Le tag fait le travail.

**Étape 2 — Génération automatique des tags**

`TeamTagService` s'exécute régulièrement (ou à la création/modification d'une équipe) pour maintenir les tags à jour. Il inspecte chaque équipe et lui attribue des tags système :

- Une équipe U13F se voit attribuer `JEUNE`, `U13`, `FEMININE`.
- Une équipe SM1 se voit attribuer `SENIOR`, `MASCULINE` (plus un tag niveau si défini).

Ces assignations sont stockées dans `TeamTagAssignment`, liant `Team` + `TeamTag` + `Season`.

**Étape 3 — Résolution au moment de la génération**

Quand `GenerateScheduleHandler` déclenche la construction du payload, `ScheduleConstraintBuilder` parcourt toutes les contraintes `CLUB` avec un `targetTag`. Pour chacune :

1. Il lit la valeur de `targetTag` (ex: `"JEUNE"`).
2. Il cherche le `TeamTag` correspondant pour le club.
3. Il récupère toutes les `TeamTagAssignment` actives pour ce tag et la saison en cours.
4. Pour chaque équipe trouvée, il crée une contrainte `TEAM` individuelle avec les mêmes `family`, `ruleType` et `config` (sans le `targetTag`).

**Étape 4 — Payload envoyé au moteur**

Le JSON POSTé vers `http://engine:8000/generate` contient uniquement des contraintes résolues. Le champ `constraints[]` ne contient plus de `targetTag`, seulement des `scope: "TEAM"` + `scopeTargetId` concrets.

### 4.2 Pourquoi cette résolution ?

Le solveur CP-SAT (OR-Tools) raisonne sur des variables binaires du type "l'équipe E s'entraîne le jour D à l'heure H dans la salle S". Il a besoin de savoir, pour chaque équipe concrète, quelles sont ses restrictions. Les tags sont une commodité pour l'utilisateur humain, pas pour un solveur mathématique.

---

## 5. Contraintes implicites vs contraintes utilisateur

| | Implicites | Utilisateur |
|--|-----------|------------|
| **Gérées par** | Le moteur Python, automatiquement | L'administrateur du club via l'API ou l'interface |
| **Configurables** | Non | Oui (CRUD complet) |
| **Stockage** | Code de l'engine | Table `Constraint` en base de données |
| **Exemples** | Un entraîneur = une équipe à la fois. Une salle = une équipe à la fois. | Les jeunes doivent finir avant 19h30. SM3 préfère le mercredi. |
| **Visibilité API** | Endpoint `/implicit-constraints` (lecture seule) | Endpoint `/api/constraints` (CRUD complet) |
| **Impact sur le score** | Toujours `HARD` (violations rendent le planning invalide) | Variable (`HARD`, `PREFERRED`, `BONUS`, `LOCK`) |

Les contraintes implicites sont les fondations du système. Sans elles, le solveur pourrait placer le coach Enzo sur deux terrains simultanément, ou assigner SM1 et SF3 dans la même salle à la même heure. Les contraintes utilisateur viennent affiner ce comportement de base pour refléter les réalités du BCCL : horaires des bus scolaires, disponibilités des salles municipales, préférences des entraîneurs bénévoles.

---

## 6. Référence rapide des combinaisons valides

Toutes les combinaisons scope + family ne sont pas logiques. Voici les combinaisons supportées :

| Scope | Family | Valide | Exemple |
|-------|--------|--------|---------|
| `CLUB` | `TIME` | Oui | Toutes les équipes jeunes avant 19h30 |
| `CLUB` | `DAY` | Oui | Toutes les équipes seniors préfèrent le mardi |
| `CLUB` | `FACILITY` | Oui | Aucune équipe féminine à Jean Vilar |
| `CLUB` | `COACH_AVAILABILITY` | Non | Pas de sens (pas de coach cible) |
| `CLUB` | `FACILITY_CAPACITY` | Non | Pas de sens (pas de salle cible) |
| `TEAM` | `TIME` | Oui | SM3 après 20h |
| `TEAM` | `DAY` | Oui | SF3 uniquement le mardi |
| `TEAM` | `FACILITY` | Oui | SM1 préfère Matéo |
| `TEAM` | `COACH_AVAILABILITY` | Non | Pas de sens (c'est le coach qui est indisponible, pas l'équipe) |
| `COACH` | `TIME` | Non | Pas de sens (c'est l'équipe qui a un horaire, pas le coach seul) |
| `COACH` | `DAY` | Non | Pas de sens |
| `COACH` | `FACILITY` | Non | Pas de sens |
| `COACH` | `COACH_AVAILABILITY` | Oui | Enzo indisponible vendredi |
| `FACILITY` | `TIME` | Non | Pas de sens |
| `FACILITY` | `DAY` | Non | Pas de sens |
| `FACILITY` | `FACILITY` | Oui | ADN fermé le lundi |
| `FACILITY` | `FACILITY_CAPACITY` | Oui | Limite de terrains au gymnase Matéo |

`ConstraintValidationService` rejette les combinaisons non valides au moment de la création ou de la modification d'une contrainte.

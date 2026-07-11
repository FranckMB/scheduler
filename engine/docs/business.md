# Documentation metier du moteur de generation

> Ce document explique le domaine de la planification sportive et ce que le moteur `engine` resout. Destine aux nouveaux developpeurs rejoignant le projet ClubScheduler.

---

## Contexte

Un club sportif, par exemple un club de basket comme le **BCCL** (Bourges Centre Cher Ligue), gere entre 10 et 40 equipes. Chaque equipe doit s'entrainer plusieurs fois par semaine. Le probleme : il faut assigner chaque seance a un creneau horaire, une salle (gymnase), et idealement un entraineur. Les ressources sont limitees, les contraintes sont nombreuses, et faire cela a la main prend des heures. Le moteur resout ce probleme d'optimisation combinatoire automatiquement.

---

## Concepts cles

### Equipe (`Team`)

Une equipe represente un groupe d'age et de genre. Exemples concrets au BCCL :

- `SM1` : Seniors Masculins 1, equipe premiere
- `SF1` : Seniors Feminines 1
- `U15F` : U15 Feminines
- `U13M2` : U13 Masculins 2

Chaque equipe possede :

- `sessionsPerWeek` : nombre de seances souhaitees par semaine (ex. 3 pour le SM1, 2 pour l'U13M2)
- `priorityTier` : niveau de priorite (S, A, B, C, D). Le SM1 est generalement en S, une equipe loisir en D
- `tags` : etiquettes comme `JEUNE`, `FEMININE`, `REGIONAL`, `SENIOR`, `MASCULINE`. Servent a cibler les contraintes

### Salle (`Venue`)

Une salle est un gymnase ou un terrain. Exemples :

- `Gymnase A` : disponible du lundi au samedi, 08h00-22h00
- `Gymnase B` : disponible uniquement mardi et jeudi, 18h00-21h00
- `Salle des Fetes` : disponible vendredi soir uniquement

Chaque salle declare des **creneaux d'entrainement** (`trainingSlots`), et chaque creneau porte une **capacite** : le nombre d'equipes qu'il peut accueillir simultanement (le backend la derive du gymnase : `canSplit ? capacity : 1`). Un gymnase **non divisible** (capacite 1) ne peut accueillir qu'une seule equipe a la fois : si le SM1 occupe le Gymnase A le lundi a 19h00, aucune autre equipe ne peut y etre placee au meme moment. Un gymnase **divisible** (ex. 3 terrains) accueille plusieurs equipes en parallele — le moteur contraint la somme des equipes placees sur le creneau a `min(capacite, maxTeams)`.

### Entraineur (`Coach`)

Un adulte qui dirige les seances. Exemple : **Maxime Dupont** est l'entraineur principal du SM1. Un entraineur ne peut diriger qu'une seule equipe a la fois. Un cas particulier existe : l'**entraineur-joueur** (`coach-player`), qui est entraineur d'une equipe et joueur dans une autre. Il ne peut pas etre a deux endroits simultanement.

Un entraineur peut etre **principal (`MAIN`)** ou **assistant (`ASSISTANT`)** d'une equipe. Le principal est **indispensable** : une equipe ne s'entraine jamais sans lui, il est donc considere present a **chacune** de ses seances (contrainte dure de non-chevauchement). L'assistant est **optionnel** : il ne bloque pas le placement — l'equipe peut etre planifiee meme si l'assistant est occupe ailleurs.

### Contrainte (`Constraint`)

Une regle metier qui faconne l'emploi du temps. Chaque contrainte a :

- **Portee (`scope`)** :
  - `CLUB` : s'applique a toutes les equipes (ou a celles filtreees par tag)
  - `TEAM` : s'applique a une equipe specifique (ex. le SM1)
  - `COACH` : s'applique a un entraineur specifique (ex. Maxime Dupont ne peut pas le mercredi)
  - `FACILITY` : s'applique a une salle specifique (ex. Gymnase A ferme pendant les vacances)

- **Famille (`family`)** :
  - `TIME` : heure minimale ou maximale de debut (ex. "pas avant 18h00", "pas apres 20h00")
  - `DAY` : jours preferes ou interdits (ex. "pas le vendredi", "preferer le mardi")
  - `FACILITY` : assignation de salle (ex. "le SM1 doit etre au Gymnase A")
  - `COACH_AVAILABILITY` : indisponibilite d'un entraineur (ex. "Maxime Dupont indisponible le mercredi")
  - `FACILITY_CAPACITY` : fermeture temporaire d'une salle

- **Type de regle (`ruleType`)** :
  - `HARD` : doit absolument etre respectee. Si ce n'est pas possible, le solveur declare l'instance infaisable
  - `PREFERRED` : souhaitable, mais pas obligatoire. Penalisee si non respectee
  - `BONUS` : recompensee si respectee (ex. bonus pour placer une equipe sur son jour prefere)
  - `LOCK` : fige un creneau. Toujours applique **en dur** par le moteur — le ruleType `LOCK` n'a pas de variantes SOFT/HARD. Ne pas confondre avec le `lockLevel` des `slotTemplates` (valeurs `NONE`/`SOFT`/`HARD`), qui est un autre mecanisme (voir plus bas)

- **Ciblage par tag** : une contrainte `CLUB` avec `targetTag=JEUNE` s'applique automatiquement a toutes les equipes portant le tag `JEUNE`. Cela evite de creer 15 contraintes identiques pour les 15 equipes jeunes.

### Contraintes implicites

Ces regles sont toujours actives, meme si l'utilisateur ne les configure pas :

| Contrainte | Description |
|------------|-------------|
| `VENUE_AT_MOST_ONE` | Une salle = une equipe a la fois. Deux equipes ne peuvent pas partager le meme gymnase au meme moment |
| `COACH_NO_OVERLAP` | Un entraineur **principal** = une equipe a la fois. Maxime Dupont ne peut pas diriger le SM1 et l'U15M1 en meme temps. Seul le coach `MAIN` est concerne (l'assistant est optionnel et ne bloque pas) |
| `COACH_PLAYER_NO_OVERLAP` | Un entraineur-joueur ne peut pas etre a deux endroits simultanement. S'il entraine le SM1 a 19h00, il ne peut pas jouer avec les Seniors 2 a la meme heure |
| `TEAM_NO_OVERLAP` | Une equipe ne peut pas avoir deux seances en meme temps. Le SM1 ne peut pas s'entrainer a 19h00 au Gymnase A et a 19h00 au Gymnase B simultanement |
| `MIN_SESSIONS` | Chaque equipe **vise** son nombre de seances : c'est une **cible soft** (bonus dans l'objectif, audit ENG-18), jamais une garantie dure — le plancher dur est 0 en production. Si le SM1 demande 3 seances, le moteur est fortement incite a les placer, mais peut en placer moins quand les ressources manquent |

### Creneau (`ScheduleSlotTemplate`)

Un creneau est le resultat d'une seance assignee : equipe + salle + entraineur + jour + heure. Exemple :

```
SM1 + Gymnase A + Maxime Dupont + Lundi + 19h00-20h30
```

Chaque creneau a un niveau de verrouillage (`lockLevel`) :

- `NONE` : libre, le moteur peut le deplacer
- `SOFT` : purement indicatif — le moteur l'**ignore au moment du solve** (aucun bonus de preservation dans l'objectif). Si le creneau ressort ailleurs, un diagnostic `soft_lock_moved` (severite `WARNING`) est emis **a posteriori** pour signaler le deplacement
- `HARD` : fige, le moteur ne peut absolument pas le deplacer

### Niveaux de priorite

Les equipes sont classees par tiers. Quand les ressources sont rares, les equipes de haut niveau sont servies en premier :

| Tier | Poids (fixe) | Exemple d'equipe |
|------|--------------|------------------|
| S | 10 000 | SM1, SF1 (equipes premieres) |
| A | 1 000 | U20M, U18F (formation elite) |
| B | 100 | U15M1, U15F1 (competition) |
| C | 10 | U13M2, U11F2 (loisir competitif) |
| D | 1 | Baby basket, ecole de basket (initiation) |

Le poids determine combien de points rapporte chaque seance placee. Placer une seance du SM1 (S) rapporte 10 000 points. Placer une seance d'une equipe D rapporte 1 point. Ainsi, si le Gymnase A n'a qu'un seul creneau libre le lundi a 19h00, le moteur le donnera au SM1 plutot qu'a l'ecole de basket.

Ces poids sont **fixes et codes en dur** dans le solveur (garantie de priorite stricte S ≫ A ≫ B ≫ C ≫ D). Ils ne sont **pas** parametrables par club : le champ `orToolsWeight` autrefois transmis a ete retire du payload (accepte puis ignore).

---

## Ce que le moteur produit

Le moteur retourne trois choses :

1. **Un emploi du temps optimise** : la meilleure repartition possible des seances, en maximisant le score total (somme des poids des seances placees)
2. **Des diagnostics** : alertes en **langage gestionnaire** — chaque alerte dit **qui, quand et pourquoi**. Un conflit de gymnase nomme le gymnase, les equipes concernees, le jour + la plage horaire et la capacite depassee ; une equipe non placee est nommee avec la raison (aucun creneau declare, gymnase impose sans creneau, creneaux deja pris…) ; une infaisabilite pointe la sous-capacite (seances demandees vs creneaux disponibles) quand c'est identifiable
3. **Des equipes non placees** : liste des equipes pour lesquelles aucune seance n'a pu etre assignee, avec la raison

Le moteur ne garantit pas que toutes les equipes auront toutes leurs seances. Si le club a 40 equipes et seulement 2 gymnases, certaines equipes de faible priorite risquent de rester sans creneau. C'est un choix explicite : il vaut mieux un emploi du temps partiel mais realiste, qu'un emploi du temps complet mais impossible a tenir.

Le **temps de calcul** s'adapte a la taille du probleme (equipes x gymnases) : ~60 s pour un petit club, jusqu'a 600 s pour un gros, sans jamais depasser le plafond demande. Inutile d'attendre 10 min pour un club a 8 equipes.

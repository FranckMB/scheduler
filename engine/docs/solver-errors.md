# Erreurs et diagnostics du solveur

> Ce document recense toutes les erreurs que le moteur peut produire, avec leurs causes et les actions correctives. Destine aux developpeurs et aux utilisateurs avances du club.

---

## Erreurs au niveau HTTP

Ces erreurs sont retournees directement par l'API FastAPI, avant meme que le solveur ne soit lance.

### `422 Unprocessable Entity`

**Cause** : le payload JSON ne respecte pas le schema `ScheduleInputSchema`. C'est une erreur de validation Pydantic v2.

**Exemples concrets** :
- Champ `sessionsPerWeek` manquant pour l'equipe SM1
- `sessionsPerWeek: "trois"` au lieu d'un entier
- Champ `sportCategoryId` manquant sur une equipe (requis)
- Cle inconnue dans le payload (les schemas sont `extra=forbid`)
- `version: "1.0"` alors que le moteur parle le **MAJOR 2** du contrat `2.1` (`"2.0"` comme `"2.1"` passent)

**Attention — deux pieges qui ne provoquent PAS de 422** : `lockLevel` est une **chaine libre**, pas un enum (un `"FORT"` est accepte et simplement traite comme non-`HARD`), et `dayOfWeek` est un entier **sans borne** (un `8` passe la validation).

**Que faire** : corriger le payload. Le detail de l'erreur 422 indique exactement quel champ est en cause et pourquoi.

### `409 Conflict` (sur `/implicit-constraints`)

**Cause** : les regles implicites du backend et du moteur sont desynchronisees. Le backend s'attend a ce que le moteur applique certaines contraintes implicites (ex. `VENUE_AT_MOST_ONE`), mais le moteur ne les reconnait pas.

**Que faire** : verifier que les versions du backend et du moteur sont compatibles. Le endpoint `/implicit-constraints` retourne la liste des contraintes implicites connues du moteur. Le backend la compare a sa propre liste. Si elles different, cela signifie qu'un deploiement partiel a eu lieu.

### Generations concurrentes : pas de `503`

Le moteur maintient un verrou asyncio par `clubId`, mais une seconde requete pour un club deja en cours de generation n'est **pas** rejetee : elle est **mise en attente** sur le verrou et s'execute des que la premiere se termine. Les generations d'un meme club sont donc simplement serialisees — aucune erreur HTTP n'est retournee pour ce cas.

### `500 Internal Server Error`

**Cause** : une exception non prevue s'est produite dans le moteur. Cela peut etre un bug dans le code Python, une erreur de memoire, ou un probleme avec la bibliotheque OR-Tools.

**Que faire** : consulter les logs du conteneur `engine`. L'exception complete est tracee. Signaler le bug avec le `clubId`, le `seasonId` et l'heure de l'incident.

---

## Statuts du solveur

Ces valeurs apparaissent dans le champ `status` de la reponse `ScheduleOutputSchema`.

| Statut | Signification |
|--------|---------------|
| `completed` | Le solveur a trouve une solution optimale ou faisable. L'emploi du temps est genere. |
| `failed` | Le solveur a retourne `INFEASIBLE` ou `UNKNOWN`. Aucun emploi du temps n'a pu etre genere. |
| `queued` | Etat transitoire defini par le **backend**, pas par le moteur. La demande est en file d'attente dans le bus Messenger (Redis). |
| `generating` | Etat transitoire defini par le **backend**. Le message a ete consomme par le worker et la generation est en cours. |

---

## Types de diagnostics

Les diagnostics apparaissent dans le tableau `diagnostics[]` de la reponse. Ils decrivent les problemes rencontres pendant la resolution, meme quand le statut est `completed`.

| Type | Severite | Signification | Causes courantes | Action corrective |
|------|----------|---------------|------------------|-------------------|
| `unplaced` | ERROR | Une equipe n'a recu aucune seance | Aucun creneau disponible ne correspond aux contraintes de l'equipe. Tous les slots des salles sont pris. Les contraintes HARD sont trop restrictives. | Ajouter des disponibilites de salle. Alleger les contraintes HARD (ex. lever l'interdiction du vendredi). Ajouter une nouvelle salle. |
| `soft_lock_moved` | WARNING | Un creneau verrouille en SOFT a ete deplace | Le solveur a trouve une meilleure solution globale en deplacant ce creneau. Ou bien le creneau entrait en conflit avec une autre equipe de priorite superieure. | Accepter le deplacement, ou passer le verrou en HARD si le creneau doit absolument rester a cette place. |
| `coach_overload` | WARNING | Un entraineur est assigne a plus de seances que son seuil | Plusieurs equipes partagent le meme entraineur principal. Le seuil `maxDaysOverride` de l'entraineur est trop bas. | Assigner un entraineur supplementaire a certaines equipes. Augmenter le seuil de l'entraineur. Repartir le coaching (entraineur principal + adjoint). |
| `conflict` | ERROR | Deux contraintes HARD se contredisent | Une equipe a une contrainte "pas le lundi" et une autre "obligatoirement le lundi". Une salle est fermee le jour ou une equipe y est forcee d'aller. | Revoir les contraintes en conflit. Supprimer l'une des deux regles contradictoires. |

---

## Scenarios d'infaisabilite courants

Ces situations expliquent pourquoi le statut retourne parfois `failed` (INFEASIBLE).

### 1. Fenetre horaire trop restrictive

**Probleme** : l'equipe U15F1 demande 3 seances par semaine, mais le Gymnase B (sa salle forcee) ne declare que 4 `trainingSlots` par semaine (mardi 18h00 et 19h30, jeudi 18h00 et 19h30). Les candidats du solveur sont **exactement** ces `trainingSlots` explicites — il n'y a pas de decoupage d'une fenetre horaire en departs multiples. Si le SM1 (S) et l'U20M (A) prennent 2 creneaux chacun, il n'en reste que 0 pour l'U15F1.

**Correction** : ajouter des disponibilites au Gymnase B (ouvrir le mercredi soir). Ou reduire `sessionsPerWeek` de l'U15F1 a 2. Ou lever la contrainte de salle forcee.

### 2. Goulot d'etranglement entraineur

**Probleme** : Maxime Dupont est l'entraineur principal (`MAIN`) du SM1, de l'U20M et de l'U15M1. Toutes ces equipes veulent s'entrainer le lundi et le mardi a 19h00. Maxime ne peut pas etre a trois endroits en meme temps. Si les trois equipes ont une contrainte HARD "entraineur = Maxime", le solveur ne peut pas satisfaire tout le monde.

**Correction** : assigner un second entraineur a l'une des equipes. Ou repartir les seances sur differents jours (ex. SM1 le lundi, U20M le mardi, U15M1 le mercredi). Ou definir Maxime comme entraineur adjoint (`ASSISTANT`) pour certaines equipes, ce qui le rend optionnel.

### 3. Monopole de salle

**Probleme** : le SM1 a une contrainte HARD `FORCED_VENUE` sur le Gymnase A. Le SM1 demande 3 seances. Le Gymnase A n'a que 3 creneaux disponibles par semaine (lundi, mardi, mercredi 19h00-20h30). Tous sont absorbes par le SM1. Aucune autre equipe ne peut utiliser le Gymnase A. Si le club n'a qu'une seule salle, les 15 autres equipes restent sans creneau.

**Correction** : ajouter une deuxieme salle (Gymnase B, Salle des Fetes). Ou alleger la contrainte : passer le `FORCED_VENUE` en `PREFERRED` (souhaitable mais pas obligatoire). Ou reduire les seances du SM1 a 2.

### 4. Contraintes cycliques impossibles

**Probleme** : l'equipe U13F1 a trois contraintes HARD :
- "pas le lundi" (contrainte DAY)
- "pas le mardi" (contrainte DAY)
- "pas le mercredi" (contrainte DAY)
- "pas le jeudi" (contrainte DAY)
- "pas le vendredi" (contrainte DAY)

Il ne reste que le week-end. Mais le club n'a pas de salle ouverte le samedi ou le dimanche.

**Correction** : supprimer au moins une des interdictions de jour. Ou ouvrir une salle le samedi matin.

### 5. Trop de verrous HARD

**Probleme** : l'utilisateur a verrouille en HARD 20 creneaux repartis sur toute la semaine. Ces creneaux occupent toutes les places disponibles aux heures de pointe (19h00-20h30). Il reste 5 equipes a placer, mais seuls des creneaux a 08h00 ou 21h00 sont libres. Si ces equipes ont une contrainte HARD "pas avant 18h00", le solveur ne peut pas les placer.

**Correction** : convertir certains verrous HARD en SOFT. Ou les supprimer completement pour laisser le moteur optimiser. Ou ajouter des disponibilites de salle en soiree (21h00-22h30).

---

## Interpretation du score

Le score est un nombre entier qui reflete la qualite globale de la solution.

| Score | Interpretation |
|-------|----------------|
| 0 | Le solveur a tourne mais n'a place aucune seance. L'instance est quasi-infaisable. Verifier les contraintes HARD. |
| Faible par rapport a l'attendu | Beaucoup d'equipes non placees, ou des equipes de bas tier placees au detriment des equipes de haut tier. Signe de ressources insuffisantes. |
| Eleve | Les equipes de haut tier (S, A) sont bien placees. Les ressources sont suffisantes ou bien reparties. |

### Formule du score

La version actuelle de la formule est :

```
SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V7"
```

Ce code est incremente chaque fois que les poids de l'objectif changent. Cela permet de comparer des scores entre generations ayant la meme version de formule. Ne pas comparer un score genere avec `T24_LEVEL_2_FIXED_WEIGHTS_V7` a un score genere avec une version anterieure.

### Exemple de calcul

Supposons un club avec :
- SM1 (S, 3 seances) : 3 seances placees = 3 x 10 000 = 30 000
- SF1 (S, 3 seances) : 3 seances placees = 3 x 10 000 = 30 000
- U20M (A, 3 seances) : 2 seances placees = 2 x 1 000 = 2 000
- U15F1 (B, 2 seances) : 2 seances placees = 2 x 100 = 200
- U13M2 (C, 2 seances) : 1 seance placee = 1 x 10 = 10
- Ecole de basket (D, 1 seance) : 0 seance placee = 0

Score de base = 62 210. Des bonus/penalites s'ajoutent ensuite (preservation des SOFT locks, respect des preferences, surcharge entraineur).

---

## Comment debugger un echec

1. **Verifier les contraintes HARD** : sont-elles toutes realistes ensemble ? Enlever temporairement les contraintes les plus restrictives et relancer.
2. **Verifier les verrous** : combien de creneaux sont en HARD ? Occupent-ils toutes les places aux heures de pointe ?
3. **Verifier les ressources** : nombre de salles, heures d'ouverture, nombre d'entraineurs. Suffisants pour le nombre d'equipes et de seances demandees ?
4. **Verifier les diagnostics** : le moteur retourne-t-il des diagnostics `conflict` ou `unplaced` ? Ils indiquent souvent directement le probleme.
5. **Consulter les metriques** : `nb_variables` et `nb_constraints` donnent la taille du probleme. `wall_time_ms` se compare au **budget adaptatif** du solveur — 60/180/600 s selon la taille du probleme (`n_teams × n_venues` ≤ 50 / ≤ 200 / au-dela), plafonne par `solverTimeoutSeconds` (defaut 650) : s'il est proche du budget, la solution est FEASIBLE mais peut-etre pas OPTIMAL.

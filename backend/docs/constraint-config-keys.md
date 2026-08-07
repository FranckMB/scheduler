# `config` d'une contrainte — la liste blanche (SEC-13)

> Source de vérité du code : `App\Service\ConstraintConfigValidator`.
> Cette page explique le POURQUOI ; la liste qui fait foi est dans la classe.

Le `config` était le seul champ du formulaire de contrainte sans aucune
validation. Mesuré sur l'API réelle le 2026-08-07 : `{"maxStartTme": "19:00"}`
— une lettre en moins — rendait **201**, la contrainte s'affichait « Rien après
19h · HARD · active », et le solveur plaçait la séance à **20:00**. Le
gestionnaire distribue un planning en croyant une règle appliquée ; elle n'existe
pas. Depuis SEC-13, toute clé hors liste est refusée **à l'écriture** en 422,
avec le nom de la clé et les réglages acceptés pour la famille.

## La table

| Famille | Clé | Type attendu | Lue par |
|---|---|---|---|
| **TIME** | `minStartTime` `maxStartTime` `maxEndTime` | `HH:MM` | moteur (`constraints.py`) |
| **DAY** | `preferredDays` `forbiddenDays` `forcedDays` `allowedDays` | liste d'entiers 1-7 (lundi = 1) | moteur (`constraints.py`, `objective.py`) |
| **FACILITY** | `forcedVenueId` `forbiddenVenueId` `preferredVenueId` `minAtVenueId` | UUID de gymnase | moteur (`constraints.py`) |
| **FACILITY** | `minAtVenueCount` | entier ≥ 1 | moteur |
| **FACILITY** | `type` (`venue_closed`) · `startDate` · `endDate` | constante · `AAAA-MM-JJ` | **backend seul** (`VenueClosureDays`) — une fermeture datée ferme des jours, elle ne produit aucune ligne de payload |
| **FACILITY_CAPACITY** | `venueId` · `maxTeams` | UUID · entier ≥ 1 | moteur (`main.py`) — ⚠ famille en cours de retrait (SEC-13 PR C) |
| **COACH_AVAILABILITY** | `unavailableDays` `availableDays` | liste d'entiers 1-7 | moteur (`constraints.py`) |
| **COACH_AVAILABILITY** | `fromTime` `untilTime` | `HH:MM` | moteur — bornent l'indisponibilité dans la journée |
| **toutes** | `targetTag` | libellé de groupe non vide | **backend seul** — éclaté en N contraintes par équipe, puis RETIRÉ du payload (`ScheduleConstraintBuilder`) |

## Trois règles pour maintenir cette liste

**1. Une clé entre par son LECTEUR, jamais par la donnée.** Un premier inventaire
tiré de la base a manqué `type`/`startDate`/`endDate` : aucune ligne ne les
portait ce jour-là, mais le cockpit en crée à chaque fermeture de gymnase
(`frontend/src/features/cockpit/queries.ts`). Livrer la liste déduite des données
aurait cassé ce geste au premier usage.

**2. Une clé moteur doit PROUVER qu'elle change le résultat.**
`ConstraintKeysAreHonouredByEngineTest` (job CI « Engine semantics ») construit,
pour chaque clé, un payload où elle est décisive, l'envoie au **vrai moteur**, et
exige que le résultat diffère de celui obtenu sans elle. Ajouter une clé sans son
scénario fait rougir la CI : **on ne peut pas déclarer sans prouver**.

**3. Une seule orthographe.** Le moteur acceptait des alias snake_case
(`forbidden_days`, `preferred_days`) que personne n'émettait ; ils ont été retirés
du moteur en même temps que l'API a cessé de les accepter. Deux façons d'écrire
une règle, c'est deux endroits où la chercher le jour où elle ne s'applique pas.

## Ce que la liste ne contient pas, et pourquoi

- **`coachId`** — doublon exact de `scope_target_id` (6 lignes sur 6, mesuré),
  supprimé par `Version20260807190000`. La cible d'une contrainte de
  disponibilité est le SCOPE.
- **`dateStart` / `dateEnd`** — recopiées vers un payload que le moteur ignore
  (`extra="ignore"`), lues par personne, zéro ligne en base. Autoriser une date
  sans effet, ce serait fabriquer le mensonge qu'on corrige.

## Où le contrôle s'applique

- **À l'écriture** (`POST`/`PUT /api/constraints`) → **422**, la donnée fautive
  n'entre pas. C'est la barrière principale.
- **Au pré-solve** (`/api/constraints/validate`, le récap du wizard) → le MÊME
  validateur, pour la forme. Il rattrape ce qui est entré hors API (fixtures,
  imports, SQL direct). Le reste du pré-solve — contradictions entre contraintes,
  coach doublé, capacité, gymnase fermé — n'est pas concerné : l'écriture ne peut
  pas voir ces choses-là.

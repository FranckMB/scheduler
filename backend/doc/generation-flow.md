# Documentation technique du flux de génération de planning

> ClubScheduler — Symfony 7 + API Platform + Messenger Redis + Mercure SSE. Contexte : BCCL (Basket Club de la Côte du Languedoc).

---

## 1. Vue d'ensemble du flux

La génération d'un planning au BCCL suit un pipeline asynchrone en cinq étapes. L'utilisateur clique sur "Générer", le backend délègue le travail lourd à un worker, appelle le moteur de calcul Python, importe le résultat, et notifie le frontend en temps réel.

```
Utilisateur (interface React)
    ↓ POST /api/schedules/{id}/generate
GenerateScheduleController
    ↓ dispatch(GenerateScheduleMessage)
Messenger Bus (async, transport Redis)
    ↓
GenerateScheduleHandler (conteneur messenger-worker)
    ├─ 1. Verrou Redis (ClubGenerationLock)
    ├─ 2. Validation + construction payload (ScheduleConstraintBuilder)
    ├─ 3. POST http://engine:8000/generate
    ├─ 4. Traitement réponse (ScheduleResultImporter)
    └─ 5. Notification temps réel (Mercure SSE)
         ↓
    Frontend (EventSource)
    ↓ Rafraîchissement automatique du calendrier
```

Ce flux est **non bloquant** pour l'utilisateur. La requête HTTP initiale retourne immédiatement. Tout le travail se fait en arrière-plan, dans le conteneur `messenger-worker`.

---

## 2. Étape 1 — Déclenchement

### 2.1 Action utilisateur

Dans l'interface du BCCL, l'administrateur du club ouvre le planning de la saison 2025-2026, vérifie que les équipes (SM1, SM2, SM3, SF1, SF2, SF3, U9M, U9F, U11M, U11F, U13M1, U13M2, U13F, U15M, U15F, U18M, U18F) et les salles (ADN, Jean Vilar, Matéo, Gymnase Nord) sont bien configurées, puis clique sur le bouton **"Générer le planning"**.

### 2.2 Requête HTTP

Le frontend envoie :

```http
POST /api/schedules/550e8400-e29b-41d4-a716-446655440000/generate
Authorization: Bearer <token>
```

### 2.3 Traitement par GenerateScheduleController

Le contrôleur `GenerateScheduleController` exécute trois actions atomiques :

1. **Vérification des droits** : l'utilisateur appartient-il au club propriétaire du planning ?
2. **Mise à jour du statut** : `Schedule.status` passe de `DRAFT` à `PENDING`.
3. **Dispatch du message** : un objet `GenerateScheduleMessage` est envoyé sur le bus Messenger async (transport Redis).

Le contrôleur retourne immédiatement :

```json
{
  "scheduleId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "PENDING",
  "message": "La génération du planning a été mise en file d'attente."
}
```

Code HTTP : `202 Accepted`. L'utilisateur peut fermer son navigateur, la génération continuera.

---

## 3. Étape 2 — Traitement asynchrone (GenerateScheduleHandler)

Le handler `GenerateScheduleHandler` s'exécute dans le conteneur Docker `messenger-worker`. Il consomme les messages de la file Redis `async` un par un.

### 3a — Verrou Redis (ClubGenerationLock)

Avant tout traitement, le handler tente d'acquérir un verrou Redis :

```
SET club:{clubId}:generation locked NX EX 300
```

- `NX` : uniquement si la clé n'existe pas encore.
- `EX 300` : expiration automatique après 5 minutes (safety net en cas de crash du worker).

**Si le verrou est déjà tenu** (une autre génération pour le même club est en cours), le handler :
1. Crée un `ScheduleDiagnostic` de type `engine_busy`.
2. Met à jour `Schedule.status` → `FAILED`.
3. Publie l'échec via Mercure.
4. Retourne sans appeler l'engine.

> Exemple concret : si l'administrateur du BCCL clique deux fois rapidement sur "Générer", la seconde requête échoue immédiatement avec le diagnostic `engine_busy`. Cela évite de surcharger le moteur et de corrompre les données.

### 3b — Construction du payload (ScheduleConstraintBuilder)

Le verrou acquis, `ScheduleConstraintBuilder` construit le payload JSON destiné au moteur. Voici ce qu'il fait, dans l'ordre :

1. **Salles actives** (`venues[]`) : récupère toutes les salles du club pour la saison active. Chaque salle est sérialisée avec ses fenêtres de disponibilité par défaut (lundi-samedi, 08h00-22h00) et ses éventuelles fermetures spécifiques (contraintes `FACILITY` scope `FACILITY`).

   > Exemple : ADN est fermé le lundi (contrainte `FACILITY` + `closedDay: 1`). Cette information est intégrée directement dans l'objet salle du payload.

2. **Équipes actives** (`teams[]`) : récupère toutes les équipes avec leurs tags (générés par `TeamTagService`), leur niveau, leur genre, et leur nombre de séances hebdomadaires (`sessionsPerWeek`).

   > Exemple : SM3 a `sessionsPerWeek: 2`, tags `["SENIOR", "MASCULINE"]`, niveau `HONNEUR`.

3. **Entraîneurs actifs** (`coaches[]`) : récupère tous les entraîneurs avec leurs liens d'encadrement (`TeamCoach`) et leurs indisponibilités.

   > Exemple : Enzo est lié à SM1 (MAIN) et SM2 (ASSISTANT). Il a une indisponibilité `unavailableDays: [5]` (vendredi).

4. **Contraintes utilisateur** (`constraints[]`) : récupère toutes les entités `Constraint` actives. Résout les tags `CLUB` en contraintes `TEAM` individuelles (voir [constraints.md](./constraints.md) section 4). Sérialise au format v2.

5. **Créneaux verrouillés** (`slotTemplates[]`) : récupère les `ScheduleSlotTemplate` existants avec `lockLevel = "LOCK"`. Ces créneaux sont figés, le solveur ne peut pas les déplacer.

   > Exemple : le créneau du SM1 le mardi 20h00-22h00 à Matéo est verrouillé par l'administrateur. Le solveur doit le conserver tel quel.

6. **Niveaux de priorité** (`priorityTiers[]`) : récupère les `PriorityTier` du club (S, A, B, C, D) avec leurs poids pour la fonction objectif du solveur.

7. **Métadonnées** : ajoute `version: "2.0"`, `clubId`, `seasonId`.

Le payload complet pèse généralement entre 50 et 200 Ko de JSON selon la taille du club.

### 3c — Snapshot SHA-256

Le payload construit est hashé en SHA-256. Ce hash est stocké sur l'entité `Schedule` dans le champ `inputHash`.

**À quoi ça sert ?**

- **Audit** : on sait exactement quelles données ont été envoyées au moteur pour une génération donnée.
- **Debug** : si un utilisateur dit "la génération d'hier donnait un meilleur résultat", on peut comparer les hash pour voir si les données d'entrée ont changé (nouvelle équipe, nouvelle contrainte, nouvel entraîneur).
- **Détection de changement** : une future optimisation pourrait éviter de regénérer si le hash n'a pas changé.

---

## 4. Étape 3 — Appel au moteur de calcul

### 4.1 Requête HTTP

Le handler envoie un POST synchrone (depuis le point de vue du worker) vers l'engine :

```http
POST http://engine:8000/generate
Content-Type: application/json

{
  "version": "2.0",
  "clubId": "bccl-uuid",
  "seasonId": "2025-2026-uuid",
  "venues": [
    {
      "id": "uuid-adn",
      "name": "Gymnase ADN",
      "availabilityWindows": [
        {"dayOfWeek": 1, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 2, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 3, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 4, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 5, "startTime": "08:00", "endTime": "22:00"},
        {"dayOfWeek": 6, "startTime": "08:00", "endTime": "22:00"}
      ],
      "closedDays": [1]
    }
  ],
  "teams": [
    {
      "id": "uuid-sm3",
      "name": "SM3",
      "sessionsPerWeek": 2,
      "tags": ["SENIOR", "MASCULINE", "HONNEUR"],
      "priorityTier": "C"
    }
  ],
  "coaches": [
    {
      "id": "uuid-enzo",
      "name": "Enzo Martin",
      "unavailableDays": [5],
      "teams": [
        {"teamId": "uuid-sm1", "role": "MAIN"},
        {"teamId": "uuid-sm2", "role": "ASSISTANT"}
      ]
    }
  ],
  "constraints": [
    {
      "scope": "TEAM",
      "scopeTargetId": "uuid-sm3",
      "family": "DAY",
      "ruleType": "HARD",
      "config": {"preferredDays": [3]}
    },
    {
      "scope": "TEAM",
      "scopeTargetId": "uuid-sm3",
      "family": "TIME",
      "ruleType": "HARD",
      "config": {"minStartTime": "20:00"}
    }
  ],
  "slotTemplates": [
    {
      "teamId": "uuid-sm1",
      "venueId": "uuid-mateo",
      "coachId": "uuid-enzo",
      "dayOfWeek": 2,
      "startTime": "20:00",
      "durationMinutes": 120,
      "lockLevel": "LOCK"
    }
  ],
  "priorityTiers": [
    {"tier": "S", "weight": 10000},
    {"tier": "A", "weight": 5000},
    {"tier": "B", "weight": 2000},
    {"tier": "C", "weight": 1000},
    {"tier": "D", "weight": 500}
  ]
}
```

### 4.2 Timeout et gestion d'erreur

Le timeout de l'appel HTTP est fixé à **10 secondes** (durée codée en dur dans l'engine Python, qui utilise `max_time=10s` pour le solveur CP-SAT).

| Réponse engine | Traitement backend | Diagnostic créé |
|----------------|-------------------|-----------------|
| `200 OK` + `status: "completed"` | Import des créneaux | Aucun (ou diagnostics métier) |
| `200 OK` + `status: "failed"` | Import diagnostics, statut `FAILED` | `conflict` + liste équipes non placées |
| `200 OK` + `status: "infeasible"` | Import diagnostics, statut `FAILED` | `conflict` + liste équipes non placées |
| `422 Unprocessable Entity` | Statut `FAILED` | `engine_validation_error` |
| `500 Internal Server Error` | Statut `FAILED` | `engine_error` |
| Timeout (> 10s) | Statut `FAILED` | `engine_timeout` |
| Host unreachable | Statut `FAILED` | `engine_error` |

> Exemple concret : si le BCCL ajoute une contrainte `HARD` "SM3 uniquement le mercredi après 20h" et que le mercredi soir est déjà saturé par SM1, SM2, SF1 et SF2, le solveur peut déclarer le problème infaisable. Il retourne `status: "infeasible"` avec un diagnostic listant SM3 comme équipe non placée.

---

## 5. Étape 4 — Traitement de la réponse (ScheduleResultImporter)

### 5.1 Cas : statut "completed"

Le moteur a trouvé un planning valide. `ScheduleResultImporter` exécute les opérations suivantes dans une transaction Doctrine :

1. **Suppression des anciens créneaux non verrouillés** : tous les `ScheduleSlotTemplate` existants pour ce planning, dont `lockLevel != "LOCK"`, sont supprimés. Les créneaux verrouillés sont préservés.

2. **Import des nouveaux créneaux** : pour chaque `slot` dans la réponse engine, création d'un `ScheduleSlotTemplate` :
   - `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime`, `durationMinutes`
   - `lockLevel` = `"NONE"` (les nouveaux créneaux ne sont pas verrouillés par défaut)
   - `source` = `"engine"`

   > Exemple : le moteur place SM3 le mercredi 20h00-22h00 à ADN. Un nouveau `ScheduleSlotTemplate` est créé avec ces valeurs.

3. **Import des diagnostics** : les `diagnostics[]` retournés par l'engine (avertissements sur des contraintes `PREFERRED` violées, par exemple) sont transformés en entités `ScheduleDiagnostic`.

4. **Mise à jour du planning** :
   - `Schedule.status` → `COMPLETED`
   - `Schedule.score` → valeur du score objectif (ex: `117679`)
   - `Schedule.solverMetrics` → métriques brutes du solveur (temps de résolution, nombre de itérations, etc.)

### 5.2 Cas : statut "failed" ou "infeasible"

Le moteur n'a pas pu produire de planning complet.

1. **Import des diagnostics** : les diagnostics incluent obligatoirement la liste des équipes `unplaced` (non placées) et les contraintes `HARD` en conflit.

   > Exemple : `{"type": "conflict", "severity": "ERROR", "message": "SM3 cannot be placed: no available slot matches HARD constraints DAY+TIME", "unplacedTeams": ["uuid-sm3"]}`

2. **Mise à jour du planning** :
   - `Schedule.status` → `FAILED`
   - `Schedule.score` → `null`

3. **Préservation des créneaux** : contrairement au cas `completed`, **aucun créneau existant n'est supprimé**. L'administrateur conserve son ancien planning et peut l'ajuster pour résoudre le conflit.

### 5.3 Cas : engine inaccessible

Si l'engine ne répond pas du tout (conteneur arrêté, réseau coupé), le handler attrape l'exception `TransportException` et :

1. Crée un `ScheduleDiagnostic` de type `engine_error` (ou `engine_timeout` si c'est un timeout).
2. Met à jour `Schedule.status` → `FAILED`.
3. Libère le verrou Redis.
4. Publie l'échec via Mercure.

---

## 6. Étape 5 — Notification temps réel (Mercure SSE)

Quel que soit le résultat (succès ou échec), le handler publie un événement Mercure à la fin du traitement.

### 6.1 Topic

```
club:{clubId}:schedule:{scheduleId}
```

> Exemple pour le BCCL : `club:bccl-uuid:schedule:550e8400-e29b-41d4-a716-446655440000`

### 6.2 Payload SSE

**Cas succès :**

```json
{
  "scheduleId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "COMPLETED",
  "score": 117679,
  "timestamp": "2025-09-15T14:32:11+02:00"
}
```

**Cas échec :**

```json
{
  "scheduleId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "FAILED",
  "diagnostics": [
    {"type": "engine_busy", "severity": "ERROR", "message": "Une génération est déjà en cours pour ce club."}
  ],
  "timestamp": "2025-09-15T14:32:05+02:00"
}
```

### 6.3 Comportement frontend

Le frontend maintient une connexion `EventSource` permanente sur `/.well-known/mercure?topic=club:{clubId}:schedule:{scheduleId}`.

Quand il reçoit un événement :
- Si `status === "COMPLETED"` : il recharge les créneaux via `GET /api/schedule-slot-templates?schedule=...` et rafraîchit le calendrier FullCalendar.
- Si `status === "FAILED"` : il affiche une notification d'erreur rouge avec la liste des diagnostics, et propose à l'utilisateur de consulter les détails du conflit.

L'utilisateur n'a pas besoin d'actualiser la page manuellement.

---

## 7. Cas d'erreur et diagnostic

Voici un tableau récapitulatif de tous les cas d'erreur possibles, avec leur cause, leur statut final, et le diagnostic créé.

| Cas | Cause | Statut résultant | Diagnostic | Action recommandée |
|-----|-------|-----------------|------------|-------------------|
| **Club déjà en génération** | Verrou Redis tenu par un autre worker | `FAILED` | `engine_busy` | Attendre la fin de la génération en cours, ou forcer le déverrouillage via l'admin |
| **Timeout engine (> 10s)** | Problème trop complexe pour le solveur CP-SAT | `FAILED` | `engine_timeout` | Simplifier les contraintes `HARD`, augmenter le nombre de salles, ou réduire le nombre d'équipes |
| **Payload invalide (422)** | Contraintes mal formées (ex: `minStartTime` > `maxStartTime`) | `FAILED` | `engine_validation_error` | Corriger la contrainte incriminée via `/api/constraints` |
| **Engine inaccessible** | Conteneur `engine` arrêté ou crash | `FAILED` | `engine_error` | Vérifier l'état des conteneurs Docker (`make logs SERVICE=engine`) |
| **Planning infaisable** | Contraintes `HARD` mutuellement exclusives | `FAILED` | `conflict` + liste équipes non placées | Relâcher une contrainte `HARD` en `PREFERRED`, ou ajouter des ressources (salle, coach) |
| **Partiellement résolu** | Ressources insuffisantes pour toutes les équipes | `COMPLETED` (score bas) | `unplaced` diagnostics | Accepter le planning incomplet, ou ajouter des créneaux/salles |
| **Verrou Redis expiré** | Worker crashé après 5 min | `FAILED` (si requête suivante) | `engine_busy` (pour la requête suivante) | Le verrou s'auto-expire, réessayer après 5 minutes |

> Exemple concret au BCCL : l'administrateur ajoute une contrainte `HARD` "Aucune équipe féminine à Jean Vilar" et une autre `HARD` "SF3 doit s'entraîner à Jean Vilar". Ces deux contraintes sont contradictoires. Le solveur retourne `infeasible` avec un diagnostic `conflict` indiquant que SF3 est `unplaced`. L'administrateur doit alors choisir : supprimer la contrainte sur Jean Vilar pour SF3, ou changer la règle globale sur les équipes féminines.

---

## 8. Cycle de vie du statut d'un planning

Le champ `Schedule.status` suit un cycle de vie strict à cinq états.

```
DRAFT ──► PENDING ──► GENERATING ──► COMPLETED
                          │
                          └──────────► FAILED
```

| Statut | Signification | Qui le définit |
|--------|---------------|----------------|
| `DRAFT` | Planning créé, jamais généré | API Platform (création de l'entité) |
| `PENDING` | Génération demandée, message en file d'attente Redis | `GenerateScheduleController` |
| `GENERATING` | Le worker `GenerateScheduleHandler` est en cours d'exécution | `GenerateScheduleHandler` (début du traitement) |
| `COMPLETED` | Le moteur a retourné un planning valide, les créneaux sont importés | `ScheduleResultImporter` (cas succès) |
| `FAILED` | Erreur à n'importe quelle étape (verrou, timeout, infaisabilité, etc.) | `GenerateScheduleHandler` ou `ScheduleResultImporter` (cas échec) |

### 8.1 Transitions possibles

- `DRAFT` → `PENDING` : l'utilisateur clique sur "Générer".
- `PENDING` → `GENERATING` : le worker consomme le message Redis.
- `GENERATING` → `COMPLETED` : le moteur retourne un planning valide.
- `GENERATING` → `FAILED` : n'importe quelle erreur (verrou, timeout, infaisabilité, engine down).
- `COMPLETED` → `PENDING` : l'utilisateur reclique sur "Générer" pour regénérer.
- `FAILED` → `PENDING` : l'utilisateur reclique sur "Générer" après avoir corrigé le problème.

> Note : il n'y a pas de transition directe `COMPLETED` → `DRAFT`. Une fois qu'un planning a été généré, il reste au minimum en `FAILED` s'il est réinitialisé.

### 8.2 Affichage frontend

Le frontend utilise ce statut pour afficher des indicateurs visuels :

- `DRAFT` : badge gris "Brouillon".
- `PENDING` : badge jaune "En attente" avec spinner.
- `GENERATING` : badge orange "Génération en cours..." avec barre de progression indéterminée.
- `COMPLETED` : badge vert "Planning généré" avec score affiché.
- `FAILED` : badge rouge "Échec" avec bouton "Voir les détails du conflit".

---

## 9. Référence des entités et services impliqués

| Classe | Rôle | Conteneur |
|--------|------|-----------|
| `GenerateScheduleController` | Point d'entrée HTTP, dispatch du message | `php-fpm` |
| `GenerateScheduleMessage` | DTO du message async | — |
| `GenerateScheduleHandler` | Worker qui orchestre les 5 étapes | `messenger-worker` |
| `ClubGenerationLock` | Verrou Redis par club | `messenger-worker` |
| `ScheduleConstraintBuilder` | Construction du payload engine | `messenger-worker` |
| `ScheduleResultImporter` | Import des résultats du solveur | `messenger-worker` |
| `Schedule` | Entité planning (statut, score, hash) | — |
| `ScheduleSlotTemplate` | Entité créneau (jour, heure, salle, équipe, coach) | — |
| `ScheduleDiagnostic` | Entité diagnostic (type, sévérité, message) | — |
| `MercureHub` | Publication SSE | `messenger-worker` |

---

## 10. Commandes utiles pour le debug

### Voir les messages en attente dans Redis

```bash
cd backend && make exec
# Dans le conteneur :
redis-cli LRANGE messenger_messages 0 10
```

### Forcer la consommation d'un message

```bash
cd backend && make exec
# Dans le conteneur :
php bin/console messenger:consume async --limit=1
```

### Vérifier l'état du verrou Redis

```bash
cd backend && make exec
# Dans le conteneur :
redis-cli GET club:{clubId}:generation
```

### Supprimer manuellement un verrou bloqué

```bash
cd backend && make exec
# Dans le conteneur :
redis-cli DEL club:{clubId}:generation
```

### Voir les logs du worker

```bash
make logs SERVICE=messenger-worker
```

### Voir les logs de l'engine

```bash
make logs SERVICE=engine
```

### Rejouer une génération avec le même payload

```bash
cd backend && make exec
# Dans le conteneur, extraire le hash et reconstruire le payload :
php bin/console debug:container ScheduleConstraintBuilder
# Puis appeler manuellement l'engine avec curl pour isoler un problème.
```

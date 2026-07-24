# Les 3 types de planning — référence produit

> **Rôle de ce document** : la trace durable du modèle métier des plannings, validé avec le
> fondateur le 2026-07-12. C'est LA référence à consulter avant tout travail sur la
> génération : quel type se déclenche quand, ce qu'on y manipule, quel besoin il comble,
> et où l'implémentation actuelle diverge de la cible.
>
> **Ce doc = le PRODUIT** (déclenchement, manipulation, besoin). **Le modèle TECHNIQUE**
> (entité Plan, versions, pointeur, invariants, vocabulaire) vit dans
> [`ADR-0002 — pattern « Plan »`](../../docs/architecture/adr-0002-pattern-plan.md) — un
> concept = une maison, pas de duplication.
> Mécanique temporelle : [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md)
> (CalendarEntry, cockpit) · besoin détaillé vacances :
> [`../evolution/plan-vacances-collecte-coach.md`](../evolution/plan-vacances-collecte-coach.md).

## Vue d'ensemble

| | 1. Planning principal (socle) | 2. Overlay d'ajustement | 3. Planning de reprise (vacances) |
|---|---|---|---|
| **Déclenchement** | **Automatique** : création du compte / démarrage de saison. Anticipable dès la saison N-1 (transition). | Une **indisponibilité est déclarée d'abord** ; puis **le gestionnaire décide** de s'y ajuster. Rien d'automatique dans le déclenchement. | **Manuel** : le gestionnaire **choisit les semaines** qu'il veut travailler parmi celles disponibles dans les vacances. |
| **Couverture** | **Toute la saison** | **1 semaine** (découpage auto en semaines englobant l'indispo une fois la décision prise) | **1 semaine** choisie à l'intérieur des vacances (ou N semaines identiques, voir règle) |
| **Structure** | Saisie complète (wizard) | **Verrouillée** : équipes, gymnases/créneaux, coachs non modifiables — **exception : les séances/équipe sont ajustables** (3→2, 0 = pas de créneau cette semaine) | Équipes **cochables/décochables** (défaut : **Fanion + importantes**), créneaux gym **redéfinissables** (prêts mairie), coachs lecture seule |
| **Contraintes** | Toutes (permanentes) | **C'est ce qui bouge** : héritées + datées, ajustables pour la semaine | Héritées avec défaut intelligent (suit les équipes) + propres à la période |
| **Ce que ça comble** | Le plan de base de l'année — le process **le mieux rodé** | **Réparer un souci ponctuel** (gym fermé, coach absent) sans toucher le socle | La **reprise progressive** semaine par semaine (vacances, effectif réduit) |
| **Nom par défaut** | `Planning de la saison 20XX-20XX` | `Ajustement {NOM DU GYMNASE} du {début} au {fin}` | `Planning de vacances de la Toussaint du {début} au {fin}` |

## Règle transverse : la SEMAINE est l'unité

**Tout planning qui n'est pas le socle couvre UNE semaine.** Un besoin multi-semaines =
**N plannings hebdomadaires**, jamais un seul planning multi-semaines.

- **Overlay** : une indispo à cheval (jeudi → jeudi suivant) ⇒ **2 overlays auto** (un par
  semaine englobée). On gère le premier ; l'outil **notifie qu'un second planning est
  ouvert à compléter**. Semaine 1 : jeudi–vendredi indisponibles ; semaine 2 : lundi–jeudi
  indisponibles — le vendredi de la semaine 2 **garde ses créneaux du socle**.
- **Reprise** : le gestionnaire clique les semaines possibles des vacances. **N semaines
  cochées ensemble = N semaines IDENTIQUES** (un planning répliqué). Deux semaines
  **différentes** = deux plannings (deux sélections).

## 1. Planning principal (socle)

- **Déclenchement** : automatique à la création du compte (onboarding wizard) ou au
  démarrage d'une nouvelle saison ; préparable en avance via « Préparer la saison
  suivante » (transition N→N+1).
- **Contenu** : toute la structure du club (équipes, gymnases + créneaux, coachs, liens,
  contraintes permanentes) → génération CP-SAT → plan de saison.
- **Cycle de vie (ADR-0002, livré)** : des **versions** (V1, V2… nom auto) ; **valider = le
  plan pointe** sur la version choisie et les autres sont **supprimées** ; **rouvrir =
  dépointer**, la version survit ; **pointeur null = espace de travail**. Générer ne pointe
  jamais — seul le gestionnaire choisit. Modifier le socle invalide les plans construits
  dessus (confirmation proportionnée).
- **État** : ✅ livré et rodé — c'est le flux de référence (cycle de vie basculé sur le pointeur du plan, ADR-0002, 2026-07-16).

## 2. Overlay d'ajustement (indisponibilité)

- **Séquence** : (1) l'indisponibilité est **déclarée** (« Signaler une indispo » — gym
  fermé, etc.) — **aucun plan n'existe encore** (ADR-0002 amendé 2026-07-24 : le plan naît
  du geste d'ADAPTER, la déclaration n'est qu'un fait au calendrier, le radar en lit
  l'impact par les contraintes datées) ; (2) **le gestionnaire décide** de traiter cette
  indispo (« Adapter ») — **c'est là que le plan naît** (`POST /schedule_plans`) ;
  (3) l'outil découpe **automatiquement** en autant de plannings que de **semaines
  englobées** ; (4) il gère le premier, est **notifié** des suivants à compléter.
- **Manipulation** : structure verrouillée (équipes entières, gymnases/créneaux, coachs) ;
  **exception validée** : le **nombre de séances par équipe** est ajustable — un
  gestionnaire réel passe une équipe de 3 à 2 créneaux, ou supprime les créneaux d'une
  équipe loisir pour la semaine. Les **contraintes** sont l'outil principal d'ajustement.
- **Résultat** : un calendrier secondaire borné à la semaine ; hors des jours d'indispo,
  les créneaux du socle restants **sont conservés**.
- **État** : 🟢 rodé sur les axes livrés — découpage hebdo + granularité JOUR (E1/5b),
  contraintes héritées cochables (#211), **séances/équipe ajustables dans l'UI** (champ 1–7
  + toggle = 0 séance, E4 via `TeamPeriodOverride`), **défaut = tout le club actif** (E3,
  structure verrouillée), **nom auto** `Ajustement {gymnase} du … au …` (E6). Reste la
  notification multi-semaines (cadrage à venir). Voir « Écarts » ci-dessous.

## 3. Planning de reprise (vacances)

- **Séquence** : depuis le cockpit (radar vacances ou clic sur un jour de vacances), le
  gestionnaire **choisit les semaines** à travailler parmi celles des vacances → chaque
  sélection ouvre le wizard en mode période → génération. **Chaque semaine cochée naît
  avec SON plan (c'est le geste)** ; la mère, pur ancrage, **n'a jamais de plan** — sauf
  chemin « d'un bloc » explicite, où le clic Adapter le crée. **Découper une mère au
  plan-bloc commencé (0 version) supprime ce plan et ses réglages** ; revenir au bloc =
  supprimer soi-même chaque semaine puis re-Adapter (jamais de bascule automatique —
  décision fondateur 2026-07-24).
- **Manipulation** : équipes **cochables/décochables** — défaut : **Fanion + importantes**
  (rangs S + A) pré-cochées ; séances/équipe surchargables ; **créneaux gym
  redéfinissables** (un gymnase prêté par la mairie juste pour la fenêtre) ; contraintes
  **héritées** avec défaut intelligent (club/coach gardées, équipe-en-pause et gymnase
  décochées) + contraintes propres à la période.
- **Exemples réels** :
  - **Toussaint** : 2 semaines différentes → **2 plannings**.
  - **Noël** : 1 semaine blanche (aucun planning) + 1 semaine de reprise → **1 planning**.
  - **Été** : rien pendant l'été, puis **2 semaines de reprise dégradée** → **2 plannings**.
- **Futur (documenté, pas construit)** : un bouton ouvre une **modale « Demandes des
  coachs »** (aujourd'hui vide) — la TODO-list des envies des coachs pour les vacances,
  **commune à toute la période de vacances**, que le gestionnaire **barre ou non**
  (accepte/refuse). Détail : [`plan-vacances-collecte-coach.md`](../evolution/plan-vacances-collecte-coach.md).
- **État** : 🟢 rodé sur les axes livrés — héritage contraintes + défaut intelligent (#212),
  équipes on/off + séances, créneaux prêtés, **choix des semaines** (E1), été inclus (E2),
  **défaut équipes = Fanion + importantes** (E3), **nom auto** `Planning de vacances de … du …
  au …` (E6). Reste la modale « Demandes des coachs » (E5, futur — P2-1). Voir « Écarts ».

## Écarts implémentation ↔ cible (actés 2026-07-12)

| # | Écart | Type touché | Cible |
|---|---|---|---|
| E1 | ✅ **Livré (2026-07-18, version fondateur — remplace la cible d'origine)** : adapter une période couvrant **plusieurs semaines** ouvre le **choix des semaines** (lun→dim, clampées à la saison) — chaque semaine cochée = une `CalendarEntry` **enfant** (`parentEntryId`) avec **son plan indépendant** (rail 1 entrée = 1 plan intact ; « N cochées ensemble = identiques » abandonné). Le chemin « d'un bloc » reste offert ; exclusivité bloc/semaines gardée serveur (422/409). Couverture visible (chips par semaine au radar + DayDialog). Datées héritées de la mère. **Granularité JOUR livrée (5b, 2026-07-18)** : un gymnase fermé une partie de la semaine n'est indispo QUE ses jours réellement fermés (incident ∩ fenêtre) — ses créneaux sont retirés du payload ces jours-là (`VenueClosureDays`), pas de forbid tous-jours ; conflits day-précis aussi. Zéro engine | 2 + 3 | — |
| E2 | ✅ **Livré (2026-07-18)** : exclusion été levée (`isAdaptableHoliday` supprimé), dates clampées à la saison | 3 | — |
| E3 | ✅ **Livré (2026-07-19)** : défaut équipes reprise = **Fanion + importantes** (2 premiers rangs) pré-cochés ; **fermeture = tout le club actif** (structure verrouillée, équipes loisir décochables à la main). Le seed de `PeriodTeams` est désormais conscient du `periodType` | 3 | — |
| E4 | ✅ **Livré (arrivé avec #262, tracé 2026-07-19)** : `PeriodTeams` expose l'ajustement des séances/équipe (champ 1–7) **et** le toggle actif/inactif (= 0 séance) dans le flux période — pour la **fermeture comme la reprise** (`TeamPeriodOverride`) | 2 | — |
| E5 | Modale **« Demandes des coachs »** absente | 3 | Bouton → modale vide d'abord, puis TODO-list par coach commune aux vacances (futur) |
| E6 | ✅ **Livré (2026-07-19)** : noms par défaut des plans conformes (`SchedulePlanProvisioner`, source unique serveur, ADR-0002 inv. 12) — SEASON `Planning de la saison …`, CLOSURE `Ajustement {gymnase} du … au …`, HOLIDAY `Planning de vacances de … du … au …`. Le nom du PLAN (réponse) est distinct du `CalendarEntry.title` (fait déclencheur) ; renommable | 1 + 2 + 3 | — |

> Suivi : ces écarts sont des items de backlog dans
> [`../evolution/roadmap.md`](../evolution/roadmap.md) — ils se cadrent et se livrent
> PR par PR, avec validation du besoin avant chaque lot (règle CLAUDE.md §7).

## Historique des décisions

- **2026-07-12** — **pattern « Plan » arbitré point par point (A→H)** → formalisé dans
  [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) : entité Plan (type, nom
  public, période propre, pointeur), Schedule = version, valider = pointer + supprimer
  les autres, réglages de période sur le Plan, structure partagée + photo.
- **2026-07-12** — modèle des 3 types validé avec le fondateur (cette page) : semaine =
  unité hors socle ; overlay = décision du gestionnaire après déclaration, structure
  verrouillée sauf séances ; reprise = semaines choisies, défaut Fanion + importantes,
  demandes coach en futur.
- **2026-07-11/12** — overlays spontanés / différentiels (couche diff éparse, socle
  jamais touché) : `TeamPeriodOverride`, `VenueTrainingSlot.calendarEntryId`,
  `ConstraintPeriodOverride` (#208, #210, #211, #212).

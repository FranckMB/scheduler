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
- **Cycle de vie (cible ADR-0002)** : des **versions** (V1, V2… nom auto) ; **valider = le
  plan pointe** sur la version choisie et les autres sont supprimées ; **pointeur null =
  espace de travail**. Modifier le socle invalide les plans construits dessus
  (confirmation proportionnée). *(Implémentation actuelle : statuts VALIDATED/ARCHIVED +
  baseline — à démolir/reconstruire selon l'ADR.)*
- **État** : ✅ livré et rodé — c'est le flux de référence (refonte du cycle de vie à venir, ADR-0002).

## 2. Overlay d'ajustement (indisponibilité)

- **Séquence** : (1) l'indisponibilité est **déclarée** (« Signaler une indispo » — gym
  fermé, etc.) ; (2) **le gestionnaire décide** de traiter cette indispo (« Adapter ») ;
  (3) l'outil découpe **automatiquement** en autant de plannings que de **semaines
  englobées** ; (4) il gère le premier, est **notifié** des suivants à compléter.
- **Manipulation** : structure verrouillée (équipes entières, gymnases/créneaux, coachs) ;
  **exception validée** : le **nombre de séances par équipe** est ajustable — un
  gestionnaire réel passe une équipe de 3 à 2 créneaux, ou supprime les créneaux d'une
  équipe loisir pour la semaine. Les **contraintes** sont l'outil principal d'ajustement.
- **Résultat** : un calendrier secondaire borné à la semaine ; hors des jours d'indispo,
  les créneaux du socle restants **sont conservés**.
- **État** : 🟡 partiel — le moteur overlay existe (closure : contraintes héritées
  cochables #211, expansion venue_closed, créneaux du socle) mais couvre **la fenêtre
  d'indispo entière**, sans découpage hebdo ni notification multi-semaines, et les
  séances/équipe n'y sont pas encore ajustables côté UI (le moteur les supporte —
  `TeamPeriodOverride.sessionsPerWeek`). Voir « Écarts » ci-dessous.

## 3. Planning de reprise (vacances)

- **Séquence** : depuis le cockpit (radar vacances ou clic sur un jour de vacances), le
  gestionnaire **choisit les semaines** à travailler parmi celles des vacances → chaque
  sélection ouvre le wizard en mode période → génération.
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
- **État** : 🟡 partiel — le moteur reprise existe (holiday : héritage contraintes +
  défaut intelligent #212, équipes on/off + séances, créneaux prêtés) mais « Adapter »
  couvre **la fenêtre de vacances entière** (pas le choix de semaines), le défaut équipes
  est **Fanion seul** (cible : Fanion + importantes), et **l'été est exclu**
  (`isAdaptableHoliday`). Voir « Écarts » ci-dessous.

## Écarts implémentation ↔ cible (actés 2026-07-12)

| # | Écart | Type touché | Cible |
|---|---|---|---|
| E1 | Pas de **découpage hebdomadaire** : overlay = fenêtre d'indispo entière, reprise = fenêtre de vacances entière | 2 + 3 | 1 planning par semaine ; overlay : semaines **auto** (englobantes) + notification des suivants ; reprise : semaines **choisies** (N cochées ensemble = identiques) |
| E2 | **Été exclu** de « Adapter » (`isAdaptableHoliday`) | 3 | Lever l'exclusion — l'été porte 2 semaines de reprise dégradée |
| E3 | Défaut équipes reprise = **Fanion seul** | 3 | **Fanion + importantes** (rangs S + A) pré-cochées |
| E4 | **Séances/équipe non ajustables dans l'overlay** côté UI (moteur OK) | 2 | Exposer l'ajustement 3→2 / 0 séances dans le flux overlay |
| E5 | Modale **« Demandes des coachs »** absente | 3 | Bouton → modale vide d'abord, puis TODO-list par coach commune aux vacances (futur) |
| E6 | **Nommage des plannings** — souci de conception (le nom n'avait pas de conteneur ; 1re tentative sur `Schedule.name` = échec, PR #214) | 1 + 2 + 3 | **Absorbé par [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)** : `Plan.name` = le nom public (défauts inv. 12), versions = noms auto « Vn - date » |

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

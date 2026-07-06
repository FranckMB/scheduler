# Gestion des matchs (FFBB) — besoin spécifié

> **Statut** : **besoin spécifié** (exploration terrain 2026-07-06) — **référence de `roadmap.md` §8 (FF#21)**.
> **Pas un plan** — pas de tâches, pas d'effort chiffré ; l'exécution se planifiera palier par palier (§9).
> **Nature** : ce document cadre un **module produit à part entière** (placement des matchs, radar de
> conflits, demandes de dérogation), distinct du module d'entraînement, et **challenge** le classement
> d'origine `🔴 lourd / V2` de la roadmap : le cœur n'est **pas** un solveur.
> **Rattachement roadmap** : `roadmap.md` §8 (Imports & intégrations — FF#21 « Planification des matchs »,
> FF#19 « Import calendrier de matchs FFBB »). **Vision d'origine** : `initiales/ClubScheduler_v3.md` §1.4.

---

## 1. Le problème (terrain)

La FFBB — via la **ligue** (compétitions régionales) ou le **comité** (départementales) — impose à chaque
club un calendrier de matchs par équipe. Le gestionnaire ne **place** que ses matchs **à domicile** : leur
donner une **heure + un gymnase** sur la date (journée) imposée, puis **répondre à la ligue**. Les matchs à
l'extérieur, il ne les place pas — mais ils **comptent** (une personne est prise ce jour-là).

Ce n'est **pas un problème d'optimisation** : la date est imposée, le degré de liberté est petit (heure +
salle sur une date fixée). Le gestionnaire **place à la main** ; la valeur est ailleurs :

> **Voir les conflits tôt → préparer/suivre les demandes de dérogation → négocier à l'amiable, avec du
> temps devant.**

**Ex. terrain.** SM2 placé à 16h le dimanche, mais RF3 joue à l'extérieur à 15h30. La même personne est
coach/joueur des deux → **on ne peut pas assurer les deux**. La date est imposée : on ne re-place pas
librement, on fait une **demande de dérogation** (processus ligue) et on négocie. La plus-value : on l'a vu
**des semaines avant**, on a le temps de réagir.

---

## 2. Le reframe central (challenge du `🔴` d'origine)

> **Les matchs ne sont PAS un problème solveur. C'est un module « placement daté + radar de conflits +
> workflow dérogation ».**

| | Entraînements (livré) | Matchs (ce besoin) |
|---|---|---|
| Dates | semaine type récurrente, **sans date** | **dates réelles imposées** par la FFBB |
| Placement | le **solveur CP-SAT** optimise | **le gestionnaire, à la main** (DOF = heure + salle) |
| Valeur | optimiser un objectif mou | **détecter des conflits + agir tôt** |
| Objet technique | `ScheduleSlotTemplate` (hebdo, sans date) | événement **daté** (nouvelle entité) |

Conséquence : le coût réel n'est **pas** un nouveau moteur, mais **(a)** un modèle de données matchs,
**(b)** un moteur de règles/conflits, **(c)** une grille datée par gymnase. Les dépendances lourdes (import
FFBB, matrice trajet) sont des **enrichissements progressifs**, pas des prérequis bloquants. → **Le `🔴`
monolithique se livre en paliers (§9).**

**Le solveur garde un rôle optionnel V2** : « auto-place-moi ce week-end au mieux » (K matchs, G gyms, sous
contraintes jour + personne + trajet) **est** un petit CP-SAT réutilisant le moteur. **Hors cœur** — la
valeur ressentie est le radar, pas l'auto-génération.

---

## 3. La colonne vertébrale de valeur

Le placement n'est que **l'entrée**. Trois couches :

| Couche | Rôle |
|---|---|
| **Entrée** | placement des matchs domicile (+ amicaux) sur la grille datée |
| **Moteur** | **détection de conflits** (le radar) |
| **Sortie / action** | **dérogation** : brouillon + suivi d'état + deadline ligue |

Le calendrier n'est que la **surface**. Le **moteur de conflits + le workflow dérogation** = ce qui se vend.

---

## 4. Le moteur de conflits = no-overlap PERSONNE (réutilise l'existant)

Le conflit atomique n'est **pas** « deux matchs à la même heure ». Deux matchs simultanés d'équipes qui **ne
partagent rien** ne sont **pas** un problème. Le conflit naît d'une **ressource partagée** — et sur le
terrain, cette ressource est **la dualité coach/joueur** :

> **Une personne ne peut pas coacher et jouer en même temps, ni coacher/jouer deux équipes en même temps.**

C'est **exactement** `COACH_NO_OVERLAP` + `COACH_PLAYER_NO_OVERLAP` (`backend/src/Enum/ImplicitConstraint.php`),
déjà codées pour l'entraînement, gardées par l'invariant Hypothesis « coach-joueur cohérent » (roadmap §7).

> **Un match n'est qu'un nouveau TYPE D'ÉVÉNEMENT sur la même timeline de disponibilité des personnes.**
> Le moteur de conflits matchs **réutilise** le no-overlap personne. Aucune nouvelle sémantique de conflit
> à inventer — on étend la timeline aux événements datés.

**Le seul ajout vs l'entraînement = la dimension SPATIALE.** L'entraînement est tout au club (temps pur).
Les matchs traversent des villes → le no-overlap devient **« pas de chevauchement + trajet faisable entre
deux événements consécutifs »**. Ex. : U13 domicile 14h puis SM1 extérieur à 40 min de route → **infaisable**
même sans chevauchement horaire strict. C'est là qu'entre la **matrice trajet** (§7).

> ✅ **Point de branchement identifié** : l'entité **`CoachPlayerMembership`** (`backend/src/Entity/
> CoachPlayerMembership.php` — `coachId` + `teamId` + `position`, tenant-owned) modélise déjà « cette
> personne (coach) joue dans cette équipe ». Le no-overlap personne des matchs **branche dessus** : une
> personne prise par un match de son équipe **coachée** (via `TeamCoach`) **ET** un match de son équipe
> **jouée** (via `CoachPlayerMembership`) sur des créneaux qui se chevauchent (ou trajet infaisable) = conflit.
> Le concept **et** le modèle sont déjà en base.

**Autres conflits (non-personne), à couvrir aussi :**
- **Gymnase** : deux matchs domicile sur le même terrain qui se chevauchent (le `VENUE_AT_MOST_ONE` existe).
- **Jour imposé** : un match placé un autre jour que celui imposé par la ligue (§6).
- **Officiels/bénévoles** : *hors périmètre V1* — non retenu comme ressource de conflit (le terrain a
  tranché : le conflit structurant est la dualité coach/joueur). À rouvrir si un club pilote le demande.

---

## 5. Deux besoins de données à NE PAS confondre + effet réseau

1. **La LISTE des rencontres** (qui joue qui, quelle journée, domicile/extérieur, par équipe et par phase).
   Vient de la **ligue/FFBB**, jamais des autres clubs. Sans elle, on ne sait même pas qu'une équipe reçoit.
   **Forme concrète (tranchée)** : un **export FBI par équipe** (fourni par le gestionnaire), **ajouté un à
   un** — **même format** à chaque fois → un seul parseur, appelé par équipe. Pas d'export club global à
   attendre. Dé-risque l'import (patron `FfbbExcelImporter` + format unique connu).
2. **L'HEURE + la LOCALISATION des matchs extérieurs** (le « 15h30 » et « au gymnase du Clar »). Vient : (a)
   **de la plateforme** si l'adversaire est client et a saisi, (b) **estimée** par tendance sinon, (c) FFBB.

> Le cross-club auto-remplit **l'heure et la position**, jamais **l'existence** de la rencontre.
> « Une fois le championnat saisi par tous » résout les heures/positions, **pas** la liste (toujours FFBB).

### 5bis. L'annuaire adverse = table GLOBALE, enrichie par tous les clubs

« Plus on rencontre d'adversaires, plus on connaît leur position. » Le club de Meyzieu a saisi qu'il reçoit
au gymnase du Clar → **l'app connaît la position du Clar** → quand un autre club joue contre le Clar, elle la
donne **sans travail**. Trois enrichissements, **un seul annuaire** :

1. **Localisation** adverse (pour le trajet) — **on stocke directement le gymnase précis** (tranché : plus
   simple à terme que ville-puis-affinage). Le trajet reste tolérant (« < 15 min entre gymnase A et B, on
   s'en fiche »), mais la donnée de base est le lieu exact, pas une approximation ville à raffiner ensuite.
2. **Tendances** horaires adverses (« le Clar joue le samedi soir »).
3. **Heures extérieures précises** (si l'adversaire est client et a saisi sa rencontre).

> **Architecture : annuaire adverse = table GLOBALE (hors tenant), enrichie par l'usage**, exactement comme
> `school_holidays` / `public_holidays` déjà en place (données de référence globales, seedées + enrichies).

> ⚠ **Garde-fou sécurité (critique vu l'isolation tenant du projet, cf. `docs/security/rls.md`)** : cet
> annuaire **traverse délibérément le tenant** → il ne doit contenir que du **public** (adresse/ville d'un
> gymnase FFBB, déjà publiée ; heure d'un match publiée). **Jamais** de donnée privée club (dispos internes,
> créneaux d'entraînement, contraintes). Précédent propre : les tables fériés/vacances sont déjà globales et
> ne portent aucune donnée club. On calque ce patron. **Un test d'isolation dédié devra le garder.**

### 5ter. Dynamique d'adoption (bonne nouvelle)

Valeur **solo dès le départ** (conflits domicile-vs-domicile + jour imposé + heures extérieur **estimées**)
→ valeur **réseau croissante** (heures/positions extérieures précises à mesure que des clubs rejoignent).
Chaque club gagne **seul** + bonus collectif → **pas de chicken-egg bloquant**. Mais **la liste des
rencontres reste à importer** quel que soit le réseau.

---

## 6. Contraintes & préférences (réutilisent le vocabulaire existant)

- **Jour imposé par catégorie/niveau** (« U18/U21 Région = dimanche ») = contrainte **HARD** imposée par la
  ligue. Réutilise le vocabulaire `Constraint` (family `DAY`, ruleType `HARD`) mais **appliqué au placement
  manuel** (l'UI bloque / le radar signale la violation), **pas** au solveur. Le gestionnaire « fait sa
  magie » = arbitre sous contraintes, comme il l'a fait sur l'entraînement.
  ⚠ **Tension canevas** : la grille d'entraînement a **abandonné le dimanche** (décision roadmap §10). Les
  matchs le **réintroduisent** — le module match a un **calendrier week-end-centrique** (samedi/dimanche au
  centre), distinct du canevas lun-sam de l'entraînement.
- **Fenêtre-type de match par équipe** (« SM1 samedi soir », « U13 très tôt pour libérer le coach senior »)
  = **`PREFERRED TIME` par équipe, champ 1re classe** (tranché). Sert **deux fois** : (a) suggérer/valider le
  placement domicile, (b) **estimer l'heure d'un match extérieur** non contrôlé (pour le radar personne).
  Cheap, haute valeur.

---

## 7. Le trajet = infra partagée avec l'entraînement (une pierre, deux coups)

Un adversaire = une **ville** → **temps de trajet siège club ↔ ville adverse**. C'est la même matrice trajet
que le module d'entraînement voulait déjà (FF#5, `venue_travel_times`) : elle sert **l'entraînement**
(gym→gym) **ET** les matchs (siège→ville adverse). **Un seul investissement, deux features** → priorité
relevée.

On stocke le **gymnase précis** de l'adversaire (tranché §5bis) ; le calcul de trajet reste tolérant (± 15 min
sans importance), mais pas d'approximation ville à raffiner.

---

## 8. Le workflow dérogation = mini-tracker daté (nouvelle brique)

Un conflit détecté → le gestionnaire tranche :
- **re-placer** (si domicile + marge disponible), ou
- **dérogation** : brouillon (quel match, créneau actuel, changement demandé, motif) → **envoyée** → **en
  attente** → **acceptée / refusée** → calendrier mis à jour.

État + **deadline ligue** (les dérogations ont une date limite) → alimente un **radar matchs** :
« 3 conflits · 2 dérogations à envoyer · deadline J-6 ». C'est le radar cockpit appliqué aux matchs : **voir
venir**.

> **Périmètre (challenge assumé)** : l'outil **prépare + suit** la dérogation. Il ne la **soumet pas** à la
> FFBB (pas d'intégration/API ligue ; la négociation amiable se fait par mail/téléphone, hors outil). C'est
> un **tracker + rédacteur de demande**, **pas** un connecteur ligue. Ne rien promettre de plus.

---

## 9. Modèle de données qui se dessine

**Nouvelles entités** (aucune n'existe aujourd'hui — `Team.matchDay` smallint est le seul vestige, il sert
au bonus « repos après match » du solveur et sera **superseded** par ce module) :

| Entité | Rôle |
|---|---|
| **Competition / Phase** | équipe + nom + début + fin + type (championnat / coupe / brassage / amical). **N par équipe** (une équipe peut gérer 1 à 3 championnats + coupe, fenêtres distinctes) |
| **Match / Fixture** | équipe + phase + date (journée) + **domicile\|extérieur** + adversaire + statut de placement + créneau (gym + heure, si domicile) |
| **Adversaire** | entité **globale** légère (nom club + **gymnase précis + coords**, enrichie par l'usage) → trajet + tendances |
| **Amical** | un Match sans phase FFBB, date + créneau **100 % au choix** du gestionnaire → réserve le slot gym |
| **Derogation** | match + créneau actuel + demande + motif + statut + deadline |

**Réutilisé de l'existant** (le gros de la valeur vient du réemploi) :
- le **no-overlap personne** (`COACH_NO_OVERLAP` / `COACH_PLAYER_NO_OVERLAP`) → moteur de conflits (§4) ;
- le **vocabulaire `Constraint`** (jour HARD, fenêtre PREFERRED) (§6) ;
- la **surface calendrier** dates réelles + la **projection** de la semaine type (pour détecter un conflit
  match ↔ entraînement du même jour) — cf. `accueil-cockpit-temporel.md` ;
- la **grille interactive** (« créneaux vides + clic = placer ») — **3e usage** du même primitif après la
  grille de réservation pré-génération et la boucle d'ajustement (roadmap §4) ;
- la **matrice trajet** (§7) ;
- le patron **table globale** des fériés/vacances → l'annuaire adverse (§5bis).

---

## 10. Positionnement produit : module AUTONOME, découplé de l'entraînement

Signal marché fort (terrain 2026-07-06) : un gestionnaire **peu intéressé par l'entraînement** (peu de
gymnases, tous les créneaux tiennent sans solveur) a eu **« les yeux qui brillent » pour les matchs**. → Les
matchs sont peut-être le **meilleur wedge de vente**.

> **Deux modules indépendants sur les mêmes entités club.** Un club peut vouloir la gestion des matchs sans
> le solveur d'entraînement, **et inversement**. Gating **indépendant** de la validation du socle
> d'entraînement.

⚠ **« Découplé » ≠ zéro setup (piège à éviter)** : un club matchs-only a **quand même** besoin d'équipes,
coachs, **liens coach↔équipe** (sinon pas de détection de conflit personne) et gymnases. C'est-à-dire **les
étapes structure du wizard (1-4)** ; seule la **génération d'entraînement** est sautable. « Module séparé »
= **workspace séparé sur les mêmes entités**, pas une appli à part.

**UI** : vue dédiée **« Compétition »** (calendrier week-end-centrique, bandes par phase/équipe), même
surface de dates que le cockpit dessous, mais **lentille différente**. Le cockpit montre les *exceptions à
l'entraînement* ; le calendrier compétition montre *la vie des championnats*.

---

## 11. Paliers de valeur (ordre, pas un plan)

- **Palier A — le placement + le radar solo.** Import FFBB de la liste des rencontres (+ ajout manuel).
  Modèle Competition/Phase/Match. Grille datée par gymnase (réemploi du primitif grille). Détection de
  conflits **personne** (domicile-vs-domicile, jour HARD, match ↔ entraînement projeté) sur données **du
  club**. Heures extérieures **estimées** par tendance. **→ Déjà énorme : le gestionnaire voit les soucis.**
- **Palier B — la dérogation + le trajet.** Workflow dérogation (brouillon + suivi + deadline + radar).
  Matrice trajet siège↔ville → conflits **spatiaux** (temps + trajet). Annuaire adverse global amorcé.
- **Palier C — l'effet réseau.** Auto-remplissage des heures/positions extérieures par le cross-club
  (annuaire enrichi par l'usage). (Option V2 : auto-placement CP-SAT d'un week-end.)

---

## 12. Tranché vs ouvert

**Tranché :**
- Cœur = **placement manuel + radar de conflits + dérogation**, **pas** un solveur (solveur = assist V2).
- Conflit atomique = **dualité coach/joueur** (`COACH_(PLAYER_)NO_OVERLAP` réutilisés), branché sur
  **`CoachPlayerMembership`** (déjà en base) ; officiels/bénévoles **hors périmètre V1**.
- Ajout vs entraînement = **dimension spatiale** (trajet entre événements consécutifs).
- **Import FFBB dès V1** = **export FBI par équipe**, ajouté un à un, **même format** (patron
  `FfbbExcelImporter`) **+ ajout manuel** (amicaux, manquants).
- **Annuaire adverse = table GLOBALE hors tenant, publique-seulement**, enrichie par l'usage (patron
  fériés/vacances) — **garde-fou sécu + test d'isolation dédié obligatoires**. On stocke le **gymnase
  précis** de l'adversaire (pas d'approximation ville).
- **Fenêtre-type de match par équipe = `PREFERRED TIME`, champ 1re classe** (placement + estimation extérieur).
- Deux besoins de données distincts : **liste des rencontres** (FFBB) vs **heures/positions extérieures**
  (réseau/estimées) — ne pas confondre.
- **Jour imposé par catégorie = HARD** (vocabulaire `Constraint`, appliqué au placement, pas au solveur).
- Calendrier match = **week-end-centrique** (réintroduit le dimanche, distinct du canevas entraînement).
- **Module autonome, gating découplé** de la validation du socle ; mais **réutilise la saisie structure**
  (un club matchs-only fait quand même les étapes équipes/coachs/gymnases).
- Dérogation = **tracker + rédacteur**, **pas** un connecteur ligue (aucune soumission FFBB automatique).
- Le **trajet** est une infra **partagée** avec l'entraînement (FF#5) — une pierre, deux coups.

**Ouvert (non bloquant) :**
- Plus rien de structurant. Reste un **spike format d'export FBI par équipe** (colonnes exactes) au moment
  de coder l'import du palier A — dé-risqué (format unique, patron `FfbbExcelImporter` existant). Les détails
  fins (libellés, statuts de dérogation exacts, forme du radar matchs) se tranchent à l'implémentation.

---

## 13. En une phrase

La FFBB impose les dates ; le gestionnaire ne place que ses **matchs à domicile** (heure + salle) pour
**répondre à la ligue**. La valeur n'est pas d'optimiser mais de **voir les conflits tôt** — la même
**dualité coach/joueur** que l'entraînement, plus le **trajet** — pour **préparer les dérogations** avec du
temps devant. Un **module autonome** sur les entités club existantes, une **grille datée** réutilisant le
primitif de réservation, un **annuaire adverse global** qui s'enrichit à chaque club rejoint : plus on
avance, plus le calcul va loin.

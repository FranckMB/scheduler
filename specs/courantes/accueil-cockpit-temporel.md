# Accueil « cockpit temporel » — mise au clair (préliminaire calendriers secondaires)

> **Statut** : **approche arrêtée** (décisions tranchées §9) — **référence de `roadmap.md` §2**.
> **Pas un plan** — pas de tâches, pas d'effort chiffré ; l'exécution se planifiera palier par palier (§8).
> **Nature** : ce document fixe une **idée claire et maligne d'UX + d'architecture** pour
> remplacer l'écran d'accueil, et pose la fondation des **calendriers secondaires**.
> **Rattachement roadmap** : `roadmap.md` §2 (Modèle temporel & périodes d'exception — la
> plus grosse zone 🔴). **Vision d'origine** : `initiales/ClubScheduler_v3.md` §3.5, §3.6, §8.
> **Ce doc challenge la vision d'origine** là où elle est trop lourde (voir §3).

---

## 1. Le problème

Aujourd'hui `/` = `PlanningPage` : l'accueil **est** le planning hebdomadaire. C'est le
seul modèle que l'appli connaît : **une semaine type**, répétée à l'infini. Il n'y a ni
dates réelles, ni vacances, ni « le gymnase est fermé la semaine du 4 mai », ni plan
alternatif. Le gestionnaire n'a aucun endroit pour **voir venir** et **anticiper**.

Besoin exprimé : l'accueil devient un **cockpit** à 3 zones —
1. un **bandeau** qui renvoie à la semaine type (le planning de base) ;
2. un **calendrier** (dates réelles) pour voir la vie de la saison ;
3. un **panneau radar** des prochains événements qui demandent attention (vacances dans un
   mois, planning à (re)générer, événements du club).

Et surtout : cliquer une date → **créer un événement / signaler un souci** ; cliquer un souci
→ **créer un calendrier secondaire**. C'est le **travail préliminaire des calendriers
secondaires**.

---

## 1bis. Le cycle de vie de l'application (la boucle qu'on a validée)

```
  Création de compte
        │
        ▼
   ┌──────────────┐   générer → ajuster (work-loop)
   │    WIZARD     │◀───────────────┐
   │   (guidé)     │                │  (socle pas encore bon)
   └──────┬───────┘                 │
          │  VALIDER  ──────────────┘
          ▼
   ┌───────────────────────────────────────────────────┐
   │   COCKPIT (accueil) — débloqué par la validation    │
   │   bandeau (socle) · calendrier d'exceptions · radar  │
   └──────┬───────────────────────────────┬──────────────┘
          │ clic date / « Adapter »         │ « Modifier » le socle
          ▼                                 ▼
   ┌──────────────────────┐      ┌──────────────────────────────┐
   │  WIZARD mode PÉRIODE   │      │  WIZARD mode LIBRE            │
   │  → overlay (2ndaire)   │      │  ⚠ 1er 2ndaire = fige le socle│
   │  structure verrouillée │      │  ⚠ modifier = détruit les 2nd │
   └──────────┬───────────┘      └──────────────┬───────────────┘
              │ valider                          │ valider
              ▼                                  ▼
        ┌────────────────────────────────────────────┐
        │   CONSULTATION (grille R/O — principal &     │
        │   secondaires) ──「 Accueil 」→ COCKPIT       │
        └────────────────────────────────────────────┘
```

> **La boucle est validée** : un compte → un socle (wizard) → validation → cockpit → la vie de la
> saison se joue en **exceptions / overlays**, jamais en retouchant la base. On ne quitte jamais
> les **2 familles d'écrans** (consultation / wizard).

**À faire (doc)** : ce **cycle de vie de l'application** doit être **documenté dans la doc
adéquate** — `docs/project-map.md` (section parcours) ou une doc dédiée (`docs/technique/app-
lifecycle.md`). Il **dépasse cette spec** : il structure toute l'appli (auth → onboarding →
validation → cockpit → périodes). À porter au moment de l'implémentation.

## 2. Le vrai enjeu : un glissement de modèle mental

Le cockpit n'est pas un écran de plus. Il matérialise un **changement de modèle** :

> **De** « une semaine type » **vers** « une **semaine type de base** + une **timeline
> éparse d'exceptions**, chacune portant éventuellement un **plan secondaire borné** ».

- La **semaine type** reste la **source de vérité** (le planning principal actuel), **derrière
  le bandeau** — pas redessinée sur le calendrier.
- Le **calendrier** est la **couche d'exceptions** sur des dates réelles : il montre **ce qui
  sort de l'ordinaire** (événements, indispos, périodes, vacances), **pas** les séances de base.
  Un jour vide = la base tourne normalement.
- Les **vraies séances d'une date** se **projettent à la demande** (jamais stockées) — voir §9ter.
- Les **exceptions** (événement, indispo salle, vacances) sont des **annotations rares** sur
  des dates précises.
- Un **calendrier secondaire** = un **plan adapté, borné à une période**, qui **surcharge**
  la base uniquement sur ces dates.

Tout l'écran d'accueil est la **vue timeline** de ce modèle.

---

## 2bis. Invariant : le plan principal est un socle FIGÉ

> **Le plan principal (la semaine type) est le socle.** Tous les calendriers secondaires
> (overlays) sont **construits par-dessus** : ils héritent de sa structure (équipes / salles /
> coachs) et de ses contraintes permanentes.

**Conséquence : modifier le socle invalide ce qui repose dessus.** Les overlays ont été calculés
contre le socle ; si on le change, ils ne valent plus.

**Mais le coût est PROGRESSIF — pas un gel brutal à la validation :**
- **Tant qu'aucun calendrier secondaire n'existe** (typiquement en **début de saison** — les
  contraintes coach arrivent encore le 12 septembre), on **remanie le socle librement**, sans
  friction : **rien ne dépend encore de lui**. Le figer de force serait absurde.
- **Dès que des secondaires existent**, « Modifier » devient **coûteux** : ça **supprime les N
  overlays** → **confirmation proportionnée** (« supprime N calendriers secondaires »). Zéro
  overlay = zéro confirmation ; N overlays = avertissement à la hauteur.

> **Le gel est donc DE FACTO, pas une serrure.** On ne verrouille pas la base par un état ; on
> **arrête d'y toucher parce que ça coûterait les overlays**. En routine (mars, saison lancée),
> on n'y touche plus — non pas parce que c'est interdit, mais parce que **ce n'est pas logique**
> et que le coût est réel. En septembre, on la triture sans scrupule.

**Deux avertissements symétriques matérialisent ce coût — les seuls garde-fous nécessaires :**
1. **À la création du PREMIER calendrier secondaire** → ⚠ « Ceci **fige** ton planning principal :
   à partir de maintenant, le modifier supprimera tes calendriers secondaires. Ton socle est-il
   prêt ? » — c'est le **moment de bascule** où la base devient **porteuse**. On le **rend visible**
   (sinon la bascule serait silencieuse).
2. **À la modification du socle quand des secondaires existent** → ⚠ « Ceci **supprime** les N
   calendriers secondaires (à refaire). »

Le premier **annonce** le gel au bon moment ; le second **protège** contre la perte. C'est tout —
pas d'état « verrouillé » à gérer, juste ces deux confirmations.

> **Ex.** 8 sept, **0 overlay** : le coach U15 se libère le jeudi → « Modifier » le socle, **aucune
> friction**, on régénère. 3 mars, **4 overlays** (Toussaint, Noël, 2 fermetures) : « Modifier »
> avertit « **ceci supprime 4 calendriers secondaires** » → en pratique on n'y touche plus.

C'est cohérent avec la vraie vie du club : la semaine type se **stabilise** en début de saison
(quelques itérations), puis tient toute l'année ; ce sont les **exceptions** (vacances,
fermetures, événements) qui bougent ensuite — plus la base.

**Ça réutilise le cycle de vie existant** (`planning-lifecycle-validated.md`) : **« Modifier » =
`reopen`**. La seule chose à ajouter : la réouverture **liste et supprime les overlays
dépendants** — **silencieuse s'il n'y en a pas**, avec confirmation sévère sinon. Le work-loop
« générer → ajuster → régénérer » vit surtout **avant** que des secondaires existent.

## 2ter. La validation du socle débloque le cockpit (le plancher d'abord)

> **Le plan principal est le plancher.** Tant qu'il n'est pas **validé**, le cockpit n'a **aucun
> sens** — à quoi bon des périodes, des overlays, un calendrier d'exceptions, si la base n'est pas
> bonne ? → **le reste est verrouillé.**

D'où le parcours :
- **Compte créé / socle non validé → l'accueil EST le wizard.** C'est pour ça qu'on part **direct
  dans le wizard** à l'inscription : il est la **base de connaissance et de travail**. On y reste
  jusqu'à avoir un socle qu'on **valide**.
- **Plan principal `VALIDATED` → l'accueil devient le cockpit**, et **les fonctionnalités
  temporelles se débloquent** (créer des événements, signaler des indispos, adapter des périodes,
  générer des calendriers secondaires, radar).

**La validation est le jalon qui ouvre tout le reste.** « Si le socle n'est pas bon, ça ne sert à
rien de faire le reste. » (Nuance vs l'existant : le déclencheur est la **validation** du plan,
pas la simple première génération — `onboardingCompleted` devra s'aligner sur `VALIDATED`, à
préciser à l'implémentation.)

> **Ex.** Club inscrit le 1ᵉʳ sept : il tombe **direct sur le wizard**, pas sur un cockpit vide et
> inutile. Il saisit équipes/salles/coachs, génère, ajuste, **valide** → l'accueil bascule en
> **cockpit** et le radar affiche « Vacances Toussaint dans 55 j ». Tant qu'il n'a pas validé,
> « créer un événement » ou « adapter une période » **n'existent pas** dans son UI.

## 3. La décision d'architecture maligne (et le challenge de la vision d'origine)

La vision d'origine (v3 §3.5) prévoit `schedule_slot_occurrences` : **matérialiser chaque
occurrence** de chaque créneau sur une **fenêtre glissante J+14**. C'est la brique 🔴 dont
« tout dépend » dans la roadmap.

**Je challenge ça.** Matérialiser toutes les occurrences, c'est écrire des **milliers de
lignes** quasi identiques au template (40 semaines × N créneaux), qu'il faut ensuite
**garder synchronisées** avec la base à chaque régénération. Coût énorme, valeur quasi nulle
pour les 99 % de créneaux qui ne dérogent jamais.

**Proposition — occurrences éparses (deltas), pas matérialisation :**

> On ne stocke **une occurrence que là où la réalité diverge du template** : créneau annulé,
> déplacé, salle changée, ou appartenant à une **période** (plan secondaire). Partout ailleurs,
> le calendrier **projette** le template à la volée. **La matérialisation est paresseuse, et
> pilotée par les exceptions** — pas une fenêtre J+14 qui matérialise tout d'avance.

> ✅ **Décision structurante actée : modèle delta / override.** La matérialisation J+14 de la
> vision d'origine est **abandonnée**. Le calendrier est une **projection** ; une occurrence
> n'existe en base que comme **override** d'une date qui déroge. C'est cette décision qui
> débloque toute la §2 en incréments.

Conséquence : **le cockpit + le calendrier réel + les événements sont livrables SANS
construire la grosse machinerie templates→occurrences d'abord.** La matérialisation
n'arrive que quand/où une exception l'exige. Ça transforme un monolithe 🔴 en incréments.

> **Ex.** 40 semaines × 60 créneaux = **2 400 occurrences** à matérialiser puis resynchroniser à
> chaque régénération, pour un club qui ne déroge jamais. En delta : **0 ligne** tant que rien ne
> bouge ; une fermeture de gym la semaine du 4 mai n'écrit **que** les quelques overrides de cette
> fenêtre. Le calendrier de mars, avril, juin… reste une **projection** gratuite du template.

---

### 3bis. C'est quoi une « occurrence » ? (et pourquoi je propose de s'en passer)

- Un **slot_template** = un créneau **récurrent** du planning de base : « U13M1, **mardi**
  18h-19h30, gym Barros, coach Cyril, **toutes les semaines** ». **Pas de date.** C'est ce que
  le solveur produit et ce que tu vois dans la grille hebdo aujourd'hui.
- Une **occurrence** (`schedule_slot_occurrences`) = **une instance concrète de ce créneau à
  une date réelle** : « U13M1, le **mardi 6 mai 2026**, 18h-19h30, statut = prévu ». **Une ligne
  par (créneau × date réelle).**
- **À quoi ça sert ?** À gérer les cas où **la réalité diverge du template un jour précis** :
  « le 6 mai c'est **annulé** », « le 13 mai **déplacé** à 19h », « le 20 mai **gym changé** »,
  « coach **remplacé** ». Impossible à exprimer sur un template hebdo (ça annulerait **tous** les
  mardis). Il faut un objet **par date** → l'occurrence, avec son `status`
  (scheduled/cancelled/moved/venue_changed/coach_replaced/added/merged).
- **La vision d'origine** : matérialiser **toutes** les occurrences sur une fenêtre glissante
  **J+14** (générer d'avance les 2 prochaines semaines de dates concrètes).
- **Le hic** : 99 % des occurrences sont **identiques** au template (aucune divergence) → des
  milliers de lignes redondantes à **resynchroniser** à chaque régénération de la base.
- **Ma proposition (modèle delta)** : ne **rien** matérialiser d'avance. **Projeter** le
  template sur les dates à l'affichage, et ne créer une occurrence **que quand une date déroge**.
  Une occurrence devient alors un **override** (un delta), pas une copie. → « occurrences éparses ».

## 4. Taxonomie : 3 objets, une seule entité

Pour éviter 4 tables (`period_templates`, `period_template_slots`, `period_assignments`,
`period_coach_responses`) dès le départ, on unifie autour d'une **entrée de calendrier**
(`CalendarEntry`) avec un `kind` et une **plage de dates** :

| Objet | `kind` | Impact planning | Porte un plan secondaire ? |
|---|---|---|---|
| **Événement club** | `event` | **Au choix du gestionnaire** : informatif (défaut) **ou** perturbant | Non (mais un événement perturbant peut mener à une adaptation) |
| **Indisponibilité datée** | `venue_closure` (ou coach) | Oui, sur la fenêtre : la ressource est indispo | Optionnel (déclenche une adaptation) |
| **Période** | `period` (vacances / coupure / mutualisation) | Oui, remplace la base sur la fenêtre | Oui (son calendrier secondaire) |

- **Un événement club** = un post-it daté (tournoi, AG, stage). **Informatif par défaut**
  (n'affecte pas la semaine type). Mais le gestionnaire peut le marquer **perturbant**
  (« pas d'entraînement ce jour » / la salle est prise) → il devient une mini-indisponibilité
  et alimente le radar. **C'est lui qui tranche, événement par événement.**
- **Une indisponibilité** = « gym Barros indispo la semaine du 4 mai » → une contrainte **datée**
  qui n'a de sens que sur cette fenêtre.
- **Une période** = une plage nommée avec **son propre plan** (le calendrier secondaire).

### 4bis. Vacances scolaires — dérivées du code FFBB, stockées en base

Décision : **on ne géocode rien au runtime.** Le `Club.ffbbClubCode` encode déjà le
**département** (ex. `…0069…` → 69 = Rhône). Le département → **zone scolaire** (A / B / C)
est une table fixe. Et le **calendrier des vacances est officiel, publié ~1 an à l'avance**
(open data Éducation nationale).

Donc :
1. La **zone** du club est **dérivée une fois** du `ffbbClubCode` (département → zone) et
   stockée sur le club (le champ **`school_zone`** déjà prévu roadmap §8 — mais alimenté par
   le code FFBB, **pas** par une API Géo depuis l'adresse : plus simple, la donnée est déjà là).
2. Les **périodes de vacances** vivent en base (`school_holiday_periods` : zone · type
   [Toussaint/Noël/Hiver/Printemps/Été] · début · fin · année scolaire), **seedées une fois par
   an** depuis la source officielle (commande d'import, pas d'appel réseau au runtime).
3. Le cockpit lit simplement les vacances **de la zone du club** → les affiche sur le calendrier
   et les remonte au radar (« Vacances Toussaint dans 24 j »).

**Ça simplifie la roadmap §8** : la « dérivation fuseau/zone depuis l'adresse (API Géo) » 🔴
devient une **dérivation triviale depuis le code FFBB** 🟢.

Le clic « signaler un souci » crée une `venue_closure` ; « c'est récurrent / toute une
période » la promeut en `period` avec un calendrier secondaire.

---

## 5. L'écran d'accueil (les 3 zones)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  BANDEAU · Planning principal — Validé · score 9011   [ Ouvrir ▸ ] [ Modifier… ] │
│  (Ouvrir = grille en lecture seule · Modifier = rouvre le wizard, ⚠ détruit les secondaires) │
├──────────────────────────────────────────────┬────────────────────────────┤
│  CALENDRIER (mois entier · jour courant ⭕ · navigable) │  RADAR — à traiter │
│  (montre les ÉVÉNEMENTS, pas la semaine type)  │                             │
│   L   M   M   J   V   S   D                    │  ⏳ Vacances Toussaint       │
│  ..  ..  ..  ..  ..  ..  ..                     │     dans 24 j · pas de plan  │
│  ..  ⛔  ..  ..  ..  ..  ..   ⛔ Barros fermé   │     [ Générer le plan ]     │
│  🎉  ..  ..  ..  ..  ..  🏖   🎉 AG · 🏖 vac.   │  ⛔ Gym Barros — sem. du 4    │
│  ..  ..  ⭕  ..  ..  ..  ..   (aujourd'hui)     │     plan secondaire absent   │
│                                               │     [ Adapter la semaine ]  │
│   clic sur un jour → popover :                │  🎉 AG le 12 mai            │
│     • Événement club                          │                             │
│     • Signaler une indisponibilité            │  (le socle ne se « régénère »│
│     • (Créer une période)                     │   pas — il est figé, §2bis)  │
└──────────────────────────────────────────────┴────────────────────────────┘
```

**Bandeau** = l'état du plan principal **d'un coup d'œil** : **validé** · score · N diagnostics.
- **« Ouvrir »** → l'**écran de consultation** (grille lecture seule) — **le même écran** qui
  sert aussi à consulter les calendriers secondaires (§6ter). Pas de zones d'édition ; les entités
  sont visibles, non modifiables.
- **« Modifier »** → rouvre le **wizard (mode libre)** pour changer le socle. **Libre tant qu'il
  n'y a pas encore de calendrier secondaire** (début de saison) ; sinon **destructeur** — ça
  supprime les N secondaires → **confirmation proportionnée** (cf. coût progressif §2bis). En
  routine (saison lancée), on n'y touche plus.

**Calendrier** = **la couche des événements / exceptions**, **PAS la semaine type** (elle est la
base, accessible derrière le bandeau — inutile de la redessiner). Il montre **uniquement ce qui
sort de l'ordinaire** : événements club, indispos, périodes, vacances. **Un jour vide = tout
roule comme la semaine type.** Clic sur un jour → **création rapide** (événement / indispo /
période). Le jour courant est **entouré**.

**Radar** = la **to-do du gestionnaire**, triée par urgence : ce qui approche (J-24/J-7/J-3)
et ce qui manque (plan de période non généré, planning modifié non régénéré). **Chaque item
a un CTA.** C'est la version généralisée des alertes J-14 de la vision d'origine (§8.2).

---

## 5bis. Interaction : cliquer une date (modale légère vs écran)

> **Règle : annoter = modale ; générer / travailler un plan = écran.**

- **Date vide** → petit **popover** : `[ Événement ]` `[ Indispo salle ]` `[ Période… ]`
  - **Événement / Indispo / Coupure** → **mini-formulaire dans le popover** (titre + **plage
    `Du … Jusqu'au …`** ; l'**Événement** ajoute le toggle informatif/perturbant, l'**Indispo**
    le gymnase) → enregistrer, on **reste sur le cockpit**. Le **jour cliqué n'est qu'un défaut**
    pour les deux bornes : début **et** fin sont éditables (`aujourd'hui ≤ début ≤ fin`). Geste de 2 secondes.
  - **Période…** → **navigue vers l'écran dédié** (atelier du calendrier secondaire).
- **Jour férié / vacances** → la modale affiche un **bandeau info** en tête (« Jour férié — … »
  pour un férié public ; « Vacances — … » pour des vacances scolaires). Les **vacances** portent
  en plus un **« Adapter »** directement dans la modale (même action que le radar : crée la période
  de vacances si absente puis ouvre le wizard en mode période ; « Voir le planning » si l'overlay
  existe déjà) — pas besoin de passer par le radar. **Exception : les vacances d'été** (`ete`) sont
  hors saison → **info seulement, jamais d'« Adapter »** (comme le radar, qui les exclut).
- **Date avec entrée(s)** → le popover **liste** ce qui est là ; chaque entrée porte ses actions
  (voir / éditer / supprimer). Une indispo/période porte un **« Adapter → »** qui ouvre
  l'**écran dédié**.
- **L'écran dédié « calendrier secondaire » = le wizard réutilisé en « mode période »**
  (voir §6bis). Pas un nouvel écran à apprendre : **les mêmes 6 étapes**, mais le roster/les
  gymnases restent **hérités** (non ré-éditables comme entités) — on les **surcharge pour la
  fenêtre** (équipe on/off + séances, créneaux prêtés) via un DIFF `calendarEntryId`, en plus des
  **contraintes + la génération**.
  → **Une modale serait trop à l'étroit ; et surtout, réutiliser le wizard = zéro réapprentissage.**

Le geste unique « cliquer une date » ouvre donc **le bon niveau selon le besoin** : une note
rapide (modale) ou l'atelier de génération (le wizard en mode période). Pas deux entrées à retenir.

> **Ex.** « AG le 12 mai » → clic sur le 12, popover, titre + toggle informatif, **enregistré, je
> reste sur le cockpit** (2 s). « Gym Barros fermé la semaine du 4 » que je veux résoudre → clic,
> « Adapter → » → **plein écran** = wizard mode période, structure surchargeable pour la fenêtre, contraintes ouvertes.

## 6. Le calendrier secondaire = un overlay borné, pas une alternative plein-saison

Clic sur une indispo/période → **« Adapter cette période »** → on **régénère uniquement la
fenêtre** concernée, avec l'exception appliquée (gym fermé / template vacances), produisant
un **plan secondaire** qui **surcharge la base sur ces dates seulement**.

> Un calendrier secondaire n'est **pas** un deuxième planning de saison complet. C'est un
> **delta de période** : la base partout, le secondaire là où la période l'écrase. Le
> calendrier affiche la base, et « bascule » sur le secondaire sur la fenêtre.

Ça évite l'explosion combinatoire des « plans alternatifs » et colle au geste réel :
« pour ces 2 semaines, c'est différent ».

**État intermédiaire — indispo signalée mais pas encore adaptée (palier A).** Tant qu'aucun
plan secondaire n'est généré, la base continue de « vouloir » placer les séances dans le gym
fermé. Ces séances en conflit sont **affichées en alerte** (« à replacer — salle indispo »),
et le **radar** propose **[ Adapter ]**. **Rien ne bouge tout seul** : c'est un **problème
visible non résolu**, pas une erreur silencieuse. L'adaptation (palier B) le résout en
générant l'overlay. → C'est **ça**, « une indispo sans plan secondaire » : un souci **posé et
signalé**, en attente d'être adapté.

---

## 6bis. L'atelier de calendrier secondaire = le wizard en « mode période »

Décision UX forte : générer le plan d'une période **ne réinvente aucun écran**. On **réutilise
le wizard** avec un **3ᵉ mode** (`période`, à côté de `guidé` et `libre`) et des **accès
différents** :

> **Structure éditable PAR PÉRIODE (F1) :** le mode période n'est plus « lecture seule ». Le
> roster/les gymnases restent **hérités** (non ré-éditables comme entités), mais on peut
> **surcharger la participation pour la fenêtre** — équipe **on/off** + **séances**, et **créneaux
> prêtés** (mairie) additifs — via un DIFF scopé `calendarEntryId` (le socle n'est jamais touché).
> Détail besoin : [`../evolution/plan-vacances-collecte-coach.md`](../evolution/plan-vacances-collecte-coach.md) §3+§6bis.

| Étape wizard | En mode période |
|---|---|
| Équipes | Roster **hérité** (non ré-éditable), mais **activable/désactivable** pour la période + **séances** surchargeables. **Défaut : Fanion seul** (ramp de reprise), ajustable. |
| Gymnases | Hérités (fermetures marquées **« fermé cette période »**) + **créneaux prêtés** ajoutables pour la fenêtre (additifs, scopés période) |
| Coachs | **Hérités, lecture seule** (lien équipe↔coach préservé) |
| **Contraintes** | **Active.** Pré-remplie avec **l'exception** (ex. De Barros indispo sur la fenêtre) ; le gestionnaire **ajoute les contraintes propres à la période** (« du coup U13 passe le mercredi ») et, sur une **fermeture**, peut **désactiver** certaines contraintes **permanentes** pour la fenêtre (case à décocher — DIFF `ConstraintPeriodOverride` épars, `isActive=false` ; le socle et le `isActive` propre de la contrainte ne sont **jamais** touchés ; défaut = tout actif, aucun seed) |
| Récap | Résumé de la **période** (fenêtre + exceptions + contraintes) |
| **Génération** | Génère l'**overlay** borné à la fenêtre (le calendrier secondaire) |

> **On ne re-saisit jamais la structure du club** (équipes / salles / coachs) pour une exception
> de 2 semaines. On **hérite**, et on ne touche qu'aux **contraintes** de la période. Le
> gestionnaire retrouve **exactement les mêmes écrans** → il n'est **jamais perdu**.

**Enchaînement concret** (« De Barros impossible cette semaine → nouvelle organisation ») :
1. Clic sur la date → « Signaler une indispo » → gym De Barros, fenêtre = la semaine.
2. L'indispo **crée une période** et **pré-remplit** sa contrainte (De Barros fermé sur la fenêtre).
3. « Adapter → » ouvre le **wizard en mode période** : Équipes/Salles/Coachs en lecture seule,
   **Contraintes** ouvertes (l'exception est déjà là, on affine), **Génération** → overlay.
4. L'overlay **surcharge** la base sur la fenêtre ; ailleurs, la base est intacte.

C'est le lien direct entre **« créer un événement contraignant »** et **« générer le plan de
cette période »** : le même wizard, en plus léger, focalisé sur ce qui change.

## 6ter. Cohérence : DEUX familles d'écrans, réutilisées partout

Le principe qui tient toute l'ergonomie : le gestionnaire ne rencontre que **deux types
d'écran**, quel que soit le planning (principal ou secondaire). Il navigue entre **trois
endroits** seulement.

**Écran de consultation** (grille, lecture seule)
- Le **même écran** sert à consulter le **planning principal** **ET**, à terme, **chaque
  calendrier secondaire**. Une seule UI de consultation.

**Enchaînement wizard** (les 6 étapes)
- Le **même flux** sert à l'**onboarding**, à l'**édition du socle** (mode libre) **ET** à la
  **génération/édition d'un secondaire** (mode période — §6bis). Un seul flux d'édition, avec des
  **accès selon le mode**.

**Navigation :**
```
        ┌──────────────┐   Ouvrir / clic un planning    ┌────────────────┐
        │   ACCUEIL    │ ─────────────────────────────▸ │  CONSULTATION  │
        │  (cockpit)   │ ◀───────── « Accueil » ─────── │ (grille R/O)   │
        └──────┬───────┘                                 └───────┬────────┘
               │  Modifier / Adapter / Créer période             │ Modifier
               ▼                                                  ▼
        ┌────────────────────────────────────────────────────────────────┐
        │      WIZARD  (onboarding · libre · période — mêmes 6 écrans)     │
        │      … validation → repart sur la CONSULTATION                   │
        └────────────────────────────────────────────────────────────────┘
```
- **Valider un planning** → on arrive sur la **consultation**. Pour revenir → **« Accueil »**.
- **Éditer un planning** → on entre dans l'**enchaînement wizard** (avec ses spécificités de mode).

> **Bénéfice** : le gestionnaire n'a **que 2 types d'écran à apprendre** — consulter, éditer.
> Principal ou secondaire, onboarding ou ajustement de période : **mêmes repères**. Il se
> concentre sur **son travail**, pas sur « où suis-je / comment ça marche ici ». Zéro
> désorientation.

> **Ex.** Je consulte le planning principal (grille R/O), « Accueil », je clique la période
> Toussaint → **la même grille R/O** pour son overlay. Je clique « Modifier » → **le même
> enchaînement wizard** qu'à l'inscription (en mode période). **Aucun écran neuf** dans tout le
> parcours.

## 7. Ce que ça **simplifie / remplace** dans la roadmap §2

| Roadmap §2 (aujourd'hui) | Ce que ce doc propose |
|---|---|
| `schedule_slot_occurrences` + matérialisation J+14 (🔴 « tout dépend de ça ») | **Projection** + **occurrences éparses (deltas)** — la fenêtre J+14 **n'est plus un prérequis** |
| 4 tables `period_*` | **1 entité `CalendarEntry`** (kind + plage), le reste vient après si besoin |
| Plans secondaires « alternatifs » plein-saison | **Overlays de période bornés** |
| Vue calendrier annuel (dépend de tout) | **Devient l'accueil**, livrable tôt en mode projection |
| Scheduler quotidien J-14/J-7/J-3 | **Le panneau radar** (même logique, rendue visible et actionnable) |

**Le pari** : en inversant « matérialiser d'abord » → « projeter + ne matérialiser que les
exceptions », toute la §2 devient **incrémentale** au lieu d'un mur 🔴.

---

## 8. Ce que ça donne, par paliers de valeur (ordre, pas un plan)

- **Palier A — le cockpit sans génération de période.** Accueil 3 zones. Le calendrier
  **projette** la semaine type. `CalendarEntry` (événements + indispos datées). Feed vacances
  scolaires. Clic-date = ajout rapide. Une indispo apparaît en ⛔ et **alimente le radar**
  (« à adapter »), sans encore générer quoi que ce soit. **→ Déjà énorme en valeur : le
  gestionnaire voit venir.**
- **Palier B — les calendriers secondaires.** Clic sur une indispo/période → génération
  **bornée** → plan secondaire en overlay. Occurrences éparses persistées seulement là.
- **Palier C — le différenciateur.** Collecte des dispos coach **par lien sans login** pour
  une période (questionnaire email) → alimente la génération du plan secondaire. + alertes
  automatiques (cron) qui remplissent le radar tout seul.

---

## 9. Tranché vs ouvert

**Tranché :**
- L'accueil **n'est plus** le planning — c'est le cockpit ; le planning reste **derrière le
  bandeau**.
- **Projection, pas matérialisation** : occurrences uniquement en delta d'exception.
- **1 entité `CalendarEntry`** (event / venue_closure / period) plutôt que 4 tables d'emblée.
- **Calendrier secondaire = overlay de période borné**, pas une alternative plein-saison.
- Le **radar** est la to-do actionnable (généralise les alertes J-14).
- **Calendrier = vue par mois entier, jour courant entouré** (plus lisible qu'une fenêtre
  glissante).
- **Événement club = informatif par défaut, marquable « perturbant » au choix du gestionnaire.**
- **Vacances = zone dérivée du code FFBB (département → zone), périodes stockées en base et
  seedées une fois par an** (pas d'API au runtime, pas de géocodage d'adresse).
- **Modèle delta / override acté** : pas de matérialisation J+14 ;
  `schedule_slot_occurrences` (si conservée comme table) ne stocke **que les overrides**. Le
  PDF daté / les stats se calculent par **projection + deltas** au moment du besoin.

- **Vacances = proposées comme période à adapter** (le radar dit « adapter les vacances ? »),
  **jamais auto-appliquées** — le gestionnaire déclenche.
- **Suppression** : un **plan secondaire (overlay) est supprimable** → le calendrier
  **re-projette la base** sur la fenêtre. **Seul le planning principal n'est jamais supprimable.**
- **Cliquer une date** : annotation légère = **modale/popover** ; générer/travailler un plan =
  **écran dédié** = **le wizard en mode période** (§5bis, §6bis).
- **Le calendrier affiche les événements/exceptions, pas la semaine type** (jour vide = base
  normale). La projection ne sert qu'à la demande (overlay, PDF daté) — pas au rendu du mois.
- **Période additive vs remplaçante, portée par `periodType`** : fermeture = **additive** (base
  + exception) ; coupure = **remplaçante totale** ; vacances/mutualisation = **remplaçante
  partielle**. **Validé.**
- **`CalendarEntry` à 2 `kind`** (event / period) + réutilisation de `Constraint` (FK nullable
  `calendarEntryId`) et de `Schedule` (overlay). Quasi aucune nouvelle table.
- **Plan principal = socle à COÛT PROGRESSIF (§2bis)** : **librement remaniable tant qu'aucun
  overlay n'existe** (début de saison, contraintes coach encore mouvantes) ; « Modifier » devient
  **destructeur dès qu'il y a des secondaires** (supprime les N, confirmation proportionnée).
  **Gel de facto, pas une serrure.** Grille en lecture seule ; l'édition = « Modifier » → wizard
  (= `reopen`). Le quotidien passe par les périodes/overlays.
- **La validation du socle DÉBLOQUE le cockpit (§2ter)** : avant validation, l'accueil **est** le
  wizard (le plancher d'abord) ; après `VALIDATED`, l'accueil devient le cockpit et les fonctions
  temporelles s'ouvrent. La validation = le jalon qui ouvre tout le reste.
- **DEUX familles d'écrans réutilisées partout (§6ter)** : **consultation** (grille R/O — principal
  ET secondaires) et **wizard** (onboarding · libre · période). Navigation à 3 endroits : Accueil ↔
  Consultation ↔ Wizard. Valider → consultation ; « Accueil » → cockpit ; éditer → wizard.
  **Le gestionnaire n'a que 2 types d'écran à connaître.**

**Ouvert — plus rien de bloquant.** Les détails restants (libellés exacts, statut d'une séance
en conflit non résolue, forme du questionnaire coach du palier C) se tranchent à l'implémentation.

---

## 9ter. Modèle de données : `CalendarEntry` (poussé)

En creusant, la taxonomie à 3 objets (§4) **se réduit en fait à 2 sur le plan données** — et
surtout, **on réutilise l'existant au lieu d'ajouter des tables.**

### a. Deux `kind`, pas trois

- **`event`** — un **marqueur** sur le calendrier (AG, tournoi, stage). Informatif. Peut être
  marqué **`isDisruptive`** (« pas d'entraînement ce jour »).
- **`period`** — une **fenêtre qui altère le plan** (fermeture de salle, vacances, coupure,
  mutualisation). Porte des **contraintes datées** + un **plan secondaire** (overlay).

> **« Signaler une indispo » n'est pas un 3ᵉ type : c'est un raccourci pour créer une `period`**
> dont la contrainte « gym X fermé » est pré-remplie. De même, un événement `isDisruptive`
> devient/produit une mini-période « pas d'entraînement ce jour ». Fermeture / vacances / coupure
> / mutualisation = un `periodType`, pas des entités séparées.

### b. L'entité

```
CalendarEntry
  id                uuid
  clubId, seasonId
  kind              event | period
  title             "AG du club" · "Vacances Toussaint" · "Gym Barros fermé"
  startDate, endDate            -- un jour : endDate == startDate
  -- event
  isDisruptive      bool         -- bloque les entraînements ce jour
  -- period
  periodType        closure | holiday | cutoff | mutualisation | custom
  schoolHolidayId   uuid?        -- si dérivée d'une période de vacances (zone du club)
  status            proposed | active | ignored   -- « proposed » = vacances suggérées par le radar
  overlayScheduleId uuid?        -- le plan secondaire généré pour la fenêtre
  createdBy, createdAt, updatedAt
```

### c. La réutilisation maligne (≈ zéro nouvelle table à part `CalendarEntry`)

- **Contrainte datée = la `Constraint` existante + un FK nullable `calendarEntryId`.**
  Une contrainte est soit **permanente** (`calendarEntryId = null`, le plan de base), soit
  **de période** (`calendarEntryId` renseigné). Le wizard **mode période** édite les contraintes
  de la période — **c'est l'étape Contraintes existante, filtrée sur ce FK**. Une « fermeture de
  salle » = une `Constraint` `family=FACILITY` rattachée à l'entrée. **On ne réinvente pas les
  contraintes.**
- **Overlay = le `Schedule` existant + un lien vers la `CalendarEntry` + la fenêtre.** Ses slots
  sont des `ScheduleSlotTemplate` bornés à la fenêtre. **On ne réinvente pas le planning.**
- **Pas de table `schedule_slot_occurrences` per-date au départ.** L'override se fait au grain
  **période** (la fenêtre bascule sur l'overlay). Le grain fin « juste ce mardi-là est annulé »
  existe **déjà** via `ManualEditController` (`/manual-edit/one-time` + `temporaryLock`). Une
  vraie table d'occurrences éparses ne s'ajoute **que si** le besoin fin le justifie (palier B/C).

### d. Deux lectures distinctes — ne pas les confondre

**Le calendrier cockpit ne dessine QUE les `CalendarEntry`** (events, jalons de period, vacances).
Il **ne projette pas** la semaine type ; un jour sans entrée est **vide** (la base tourne,
implicite). C'est une **couche d'exceptions**, pas une grille de séances.

**La projection ne sert qu'ailleurs** — quand on a besoin des **vraies séances d'une date**
(ouvrir l'atelier overlay d'une période, un export PDF daté, un « détail du jour »). Là, pour
une date `d` :
1. `d` dans la fenêtre d'une `period` **active** avec overlay ? → **les slots de l'overlay**.
2. Sinon → **projeter** la semaine type : les `ScheduleSlotTemplate` du plan principal dont le
   `dayOfWeek` correspond à `d`, contraintes **permanentes** (`calendarEntryId = null`).

Supprimer une `period` → son overlay + ses contraintes datées partent → l'étape 1 ne matche plus
→ la base **re-projette** naturellement (cohérent avec « overlay supprimable, principal non »).

> **La distinction** : le **cockpit** répond à « qu'est-ce qui sort de l'ordinaire ? » (les
> entrées). La **projection** répond à « qu'y a-t-il concrètement le mardi 6 mai ? » (à la
> demande, jamais matérialisée d'avance).

### e. Le seul vrai arbitrage de modèle qui reste

**Une `period` est-elle additive ou remplaçante ?**
- **Fermeture de salle** = **additive** : base + « Barros off » (on garde tout le reste).
- **Coupure (`cutoff`)** = **remplaçante totale** : rien (pas d'entraînement).
- **Vacances / mutualisation** = **remplaçante partielle** : un autre jeu de créneaux (SM1+SM2
  ensemble…).

→ `periodType` porte cette sémantique. Proposition : la génération de l'overlay part **des
contraintes permanentes** (héritées), **+** les contraintes de la période, **sauf** si
`periodType = cutoff/holiday` où l'on **repart d'un socle réduit**. **À confirmer** — c'est la
seule décision de modèle non tranchée.

## 10. En une phrase

L'accueil devient le **cockpit temporel** du club : la **semaine type** reste la base (derrière
le bandeau), le **calendrier** montre **la timeline des exceptions** (événements, indispos,
périodes, vacances) — un jour vide = tout roule — et un **radar** dit ce qui arrive. Chaque souci
daté devient, en un clic, une **période** adaptée dans **le même wizard, en mode allégé**, qui
produit un **overlay borné**. On ne matérialise jamais l'ennuyeux ; on ne crée que là où la
réalité diverge. Côté données : **`CalendarEntry` + 2 FK** sur l'existant, presque rien de neuf.

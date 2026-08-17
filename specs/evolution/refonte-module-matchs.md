# Refonte du module matchs — cadrage P2-26 (+ programme palier B/C)

> **Statut** : **cadrage / besoin spécifié** (entretien gestionnaire BCCL, 2026-08-17). **Pas un plan** —
> pas de tâches chiffrées ni d'ordre de PR figé ; l'exécution se planifiera lot par lot dans une session
> dédiée (§9).
> **Nature** : coordonne **deux chantiers qui partagent le même écran** — la **refonte UX** (roadmap **P2-26**,
> « l'amener au niveau du wizard ») **et** les **extensions palier B/C** déjà spécifiées dans
> [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md). La roadmap l'exige : *« ne pas refondre ce qui va être
> étendu sans coordonner les deux »* (P2-26).
> **Ce document ne re-spécifie PAS le palier B/C** (dérogation, matrice trajet, annuaire adverse) : il le
> **référence** et se concentre sur (a) la refonte UX et (b) les **besoins net-neufs** sortis de l'entretien
> du 2026-08-17 (rotation A/B, « gardien » à l'ouverture, réconciliation FBI).
> **Le palier A est LIVRÉ** — l'état courant du module vit dans
> [`../courantes/module-matchs.md`](../courantes/module-matchs.md) ; le présent fichier ne décrit que
> l'**ouvert**.

---

## 1. Le problème en une phrase

Le module matchs est **fonctionnellement complet** (palier A soldé) mais son écran unique est **surchargé et
non intuitif** : 8 boutons d'action à plat, 4 blocs empilés dans une colonne, 5 modales de même poids, aucune
notion d'étape ni de progression. **L'étalon nommé par le fondateur est le wizard** (structure guidée,
hiérarchie visuelle, états vides, un geste à la fois). C'est la plus-value la plus importante après le
planning.

---

## 2. Ce qui existe déjà (ne PAS re-construire — pointeurs)

> Détail canonique : [`../courantes/module-matchs.md`](../courantes/module-matchs.md). Résumé de réemploi :

- **Modèle** : `Fixture` (match daté : `homeAway`, `externalRef` = n° FBI, `status`
  UNPLACED→PLACED→SUBMITTED→VALIDATED, `placementSource`), `Competition` (poule/phase FFBB, `expectedMatchdays`,
  `ffbbPouleOpponents`), `LeagueMatchWindow` (fenêtres fédé/comité, **globale hors tenant**),
  `VenueMatchWindow` (accès mairie), `TeamMatchHabit` (habitude de match), `TeamLink` (passerelles).
- **Solveur de placement** : engine `POST /place-matches` + rail backend `PlaceMatchesController` →
  `MatchPlacementPayloadBuilder` (3 couches : kinds FIXED/TO_PLACE/AWAY, projection des entraînements
  effectifs, enveloppe ligue) → `MatchPlacementResultApplier`. Le « respect de l'image idéale » **existe déjà**
  comme mécanique.
- **Import FBI** : `FbiFixtureImporter` — analyse dry-run → mapping division→équipe → import idempotent
  (`POST /api/fixtures/import/analyze` + `/import`), garde-fou poule.
- **Radar de conflits** : `MatchConflictDetector` + `GET /api/fixtures/conflicts` — ~15 types gradués en
  sévérité 1→7 (VENUE_OVERLAP, LEAGUE_WINDOW_VIOLATION, MATCH_MATCH, MATCH_TRAINING, ACCESS_WINDOW_LOST,
  TEAM_LINK_OVERLAP, COMPETITION_INCOMPLETE…), recalculé à la volée, **rien persisté**.
- **Front** : `frontend/src/features/matches/` — `MatchesPage`, `WeekendGrid`, `TypicalWeekendGrid`,
  `PlacementPanel`, `ConflictRadar`, dialogues `ImportFbiDialog`, `FfbbEngagementsDialog`, `HabitsLinksDialog`,
  `MatchWindowsEditor`. 36 hooks react-query. **Le tabs du design system existe mais n'est pas exploité** ; le
  **stepper du wizard n'est pas extrait** (codé en dur dans `WizardLayout`).

**Périmètre engagé** (axe structurant §7.1) : une équipe portant ≥1 `Fixture` ne peut être ni supprimée ni
changer de niveau (`TeamEngagementGuard`, gardé par `EngagedTeamGuardTest`). Toute refonte doit le préserver.

---

## 3. L'entretien gestionnaire (BCCL, 2026-08-17) — son geste réel

Deux temps que l'écran actuel **écrase sur une seule page** :

### 3.1 Le SET-UP (rare, début de saison)
- Il dessine son **planning idéal sur 2 semaines (A et B)** : gymnases de match, créneaux de gymnase, **et
  donc les équipes dans les créneaux à domicile**. Cette image n'a « aucune valeur en soi » — c'est le
  **modèle de référence que le solveur devra respecter au maximum**.
- Quelques **contraintes de lieu / horaire** (règles région et comité) — celles déjà fournies dans le backend
  (→ `LeagueMatchWindow`, seed AURA).
- « Et ça clôture déjà le set-up de base. »

### 3.2 Le GESTE RÉCURRENT (chaque semaine)
1. Il va sur **FBI**, filtre **semaine par semaine** pour ne pas se tromper.
2. Il essaie de **coller au modèle** (son image idéale A/B), puis **regarde les litiges**.
3. Contrainte structurelle : il n'a la main **que sur ses matchs à domicile** ; les autres équipes reçoivent
   leur batch de données **plus tard**. **À la réception, il ne sait pas si les données sont à jour** — lui
   seul le sait.
4. Une fois les matchs domicile remplis, il doit les **re-saisir à la main dans FBI** (la partie pénible) puis
   sauvegarder.
5. Il veut être **alerté** si : (a) les données d'un match domicile **diffèrent entre FBI et l'app** (preuve
   d'une mauvaise saisie côté FBI, facile à identifier), (b) il y a des **conflits de créneaux**.
6. Il place parfois des matchs **avant** que les adversaires n'aient placé les leurs, puis découvre un conflit
   **APRÈS** (leur demande ne colle plus). → **il faut l'avertir**.
   **Idée du gestionnaire** : à chaque connexion, vérifier s'il n'a pas fait d'erreur de placement ou un
   conflit avec le modèle actuel vis-à-vis des matchs extérieurs.
7. Le **numéro de rencontre** l'aide à identifier vite un match.
8. Il veut un **rappel des échéances** de remplissage (données par la ligue et le comité) dans le cockpit.

---

## 4. Le challenge — quatre faits durs qui recadrent le besoin

1. **« Import FBI par API » — impossible aujourd'hui.** L'API FFBB expose un index `rencontres` **vide pour
   les vrais clubs** (documents de test uniquement au niveau national ; 0 hit pour un club réel) — mesuré deux
   fois, cf. [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md) et `backend/docs/ffbb-api.md`.
   **Le seul chemin d'entrée des rencontres reste l'export Excel FBI manuel** (déjà implémenté). On ne peut
   PAS supprimer le geste « télécharger le xlsx » ni la re-saisie manuelle dans FBI — ce sont des limites
   **fédérales**, pas de notre code. On peut le rendre **indolore et sûr**, pas l'éliminer. → Ne rien
   promettre d'automatique côté FBI.

2. **« Le numéro de rencontre est unique » — faux au niveau global.** Le « 26 » existe en RMU18 Brassage
   *et* en DF2 (fact mesuré F6). Il est modélisé (`Fixture.externalRef`) mais l'unicité est **composite**
   `(club, saison, équipe, externalRef)` (`Fixture.php:30`). On peut l'**afficher** comme repère, **jamais**
   s'en servir comme clé d'identification globale — il faut toujours résoudre la division/équipe d'abord.

3. **La rotation « A/B » n'est PAS modélisée.** Il existe une vue « week-end type » (`TypicalWeekendGrid`) et
   des `TeamMatchHabit` **à une seule** occurrence par (équipe, jour) (`TeamMatchHabit.php`). L'alternance
   semaine A / semaine B (ex. partage de gymnase une semaine sur deux) est un **besoin de modèle net-neuf**,
   pas de l'UX. **Décision de cette session : c'est une vraie rotation à honorer** (§8).

4. **Trois souhaits ne sont PAS construits — et deux sont déjà fléchés palier B :**
   - **Vérification à la connexion** (alerte au login) : **inexistante**. Le radar se déclenche à l'ouverture
     de `/matchs`, pas au login. → net-neuf (§7 « gardien »).
   - **Réconciliation « données domicile FBI ≠ app »** : **partielle** seulement (garde-fou poule +
     COMPETITION_INCOMPLETE + warnings de ré-import). Pas de comparaison ciblée « ta saisie FBI diffère de ce
     que tu as enregistré ». → net-neuf, borné par le fait #1 (§7).
   - **Échéances ligue/comité** (deadline/rappel cockpit) : **inexistantes** pour les matchs (le cron
     deadlines existe mais seulement pour les campagnes coach et les transitions de saison). → **palier B**
     (`gestion-matchs-ffbb.md` §8).
   - Le **workflow de dérogation/litige** reste **palier B** (`gestion-matchs-ffbb.md` §8) — non ré-ouvert ici.

---

## 5. Décisions de cadrage de cette session (2026-08-17)

- **Périmètre = large** : refonte UX (P2-26) **coordonnée avec** le programme palier B/C, **plus** les
  besoins net-neufs de l'entretien. Le tout se **livrera par lots** (§9), pas en une PR.
- **Rotation A/B = vraie capacité de modèle à honorer** par le solveur (§8) — sort de la refonte UX pure.
- **FBI reste manuel** (fait #4.1) : la réconciliation se fera par **diff au ré-import du xlsx**, pas par
  API. Le geste « exporter FBI / re-saisir FBI » demeure ; l'outil le **sécurise** (alerte d'écart), il ne le
  remplace pas.
- **Le radar existant devient le fil conducteur** du geste récurrent (« 3 litiges cette semaine → règle-les »),
  pas un pavé de plus dans une colonne pleine.
- **On réutilise** : le stepper/rail du wizard (à extraire dans `shared/`), le composant `tabs`, la grille
  temporelle, `MatchConflictDetector`, tout le rail `/place-matches`. **Aucune ré-écriture du moteur.**

---

## 6. La cible UX (l'étalon wizard) — principes

Ce que le wizard a et que le module matchs n'a pas, à transposer :

1. **Séparer les deux temps.** Un espace **SET-UP** (rare, guidé comme le wizard : gymnases de match →
   créneaux → image idéale A/B → contraintes ligue/comité) distinct de l'espace **GESTE RÉCURRENT**
   (hebdomadaire). Aujourd'hui tout est mélangé sur `MatchesPage`.
2. **Progressive disclosure / un geste à la fois.** N'afficher que ce qui sert l'étape courante. Sortir les
   actions rares (import FBI saisonnier, engagements FFBB, habitudes, accès match) de la barre plate ; les
   ranger dans le SET-UP ou derrière une entrée « Configuration ».
3. **Progression visible & « prochain trou ».** Le geste récurrent est une **boucle semaine par semaine** :
   *importer le batch → coller au modèle A/B → résoudre les litiges (radar) → poser les domiciles → marquer
   « saisi dans FBI »*. Un fil qui dit **où on en est** et **ce qu'il reste**, façon rail d'étapes.
4. **Hiérarchie des actions.** L'action centrale (placer / résoudre un litige) domine visuellement ; le reste
   est secondaire. Fin des 8 boutons équipollents.
5. **Contexte stable.** Supprimer les sauts de colonne (`PlacementPanel` qui apparaît/disparaît) et le « mode
   échange » caché qui change silencieusement le sens des clics.
6. **États vides soignés** (comme le wizard) : « aucun match cette semaine », « aucun litige — tout est
   propre », « importe ton premier fichier FBI ».
7. **Filtrer par semaine** (le geste réel du gestionnaire sur FBI), pas seulement par week-end ; afficher le
   **numéro de rencontre** comme repère (fait #2 : jamais comme clé globale).

> ⚠ **Refonte UX = ZÉRO changement de comportement moteur.** Réorganiser l'écran ne doit pas toucher le
> placement, le radar ni les statuts. C'est ce qui rend la 1re tranche (UX pure) peu risquée.

---

## 7. Le « gardien » — le cœur de l'angoisse du gestionnaire

Le sous-jacent « il ne sait pas si les données reçues sont à jour, et il découvre les conflits **après coup** »
(§3.2 pts 3 & 6) pointe une **capacité net-neuve** : un **contrôle d'état à l'ouverture**.

- **À l'ouverture du module (ou à la connexion)** : recalculer le radar et afficher un résumé
  « depuis ta dernière visite : N nouveaux conflits sur des matchs que tu avais posés » — typiquement parce
  qu'un adversaire a bougé son placement (les matchs extérieurs estimés ont changé).
- **Réconciliation FBI (bornée par le fait #4.1)** : au **ré-import** du xlsx FBI, comparer les matchs
  **domicile** du fichier avec ceux enregistrés dans l'app et **signaler tout écart** (heure, salle, date)
  — preuve d'une mauvaise saisie côté FBI. Réutilise le **diff idempotent** de `FbiFixtureImporter`
  (aujourd'hui il met à jour silencieusement les re-programmations ; il faut en faire une **alerte lisible**).
- **Fraîcheur des données** : introduire une notion de « ce batch est-il à jour ? » (le gestionnaire est le
  seul à le savoir) — a minima horodater le dernier import et le rappeler.

> Ce volet est le **différenciateur ressenti**. Il s'appuie sur le radar existant (aucune nouvelle sémantique
> de conflit) + une couche de **persistance légère** (pour comparer « avant/après visite ») que le radar
> actuel n'a pas (il est stateless).

---

## 8. Rotation A/B — le point modèle à creuser (décision : à honorer)

Le gestionnaire raisonne en **alternance bimensuelle** (semaines A et B). Décision de session : **le modèle et
le solveur doivent l'honorer**. À cadrer finement en session d'implémentation, mais les pistes :

- **Modèle** : étendre l'« habitude » d'équipe pour porter une **parité de semaine** (A/B) — soit un champ sur
  `TeamMatchHabit`, soit une entité « modèle de week-end type A/B ». À trancher au schéma.
- **Ancrage calendaire** : définir ce qui fixe le rythme A/B sur le calendrier réel (numéro de semaine ISO ?
  point d'ancrage saison ?). **Question ouverte** (§10).
- **Solveur** : le payload `/place-matches` doit transporter la parité attendue par équipe → nouveau SOFT
  (« respecte l'image A/B ») **sans** casser les golden fixtures ni le déterminisme du worker unique. Axe
  **contrat backend↔engine** (§7.1) → NR obligatoire (contract test) + smoke-solver.
- **UI** : le SET-UP doit permettre de **dessiner** les deux semaines (réemploi de `TypicalWeekendGrid` en
  version « A / B »).

> ⚠ **Axe structurant §7.1 touché** (contrainte sémantique : « une image A/B saisie doit être honorée par le
> solveur ») → test de non-régression sémantique dans la même PR le jour de l'implémentation.

---

## 9. Programme de travail (lots cadrés — ordre indicatif, pas un plan)

IDs locaux `RMM-n` pour référence dans ce fichier. La colonne **Rattachement** dit à quelle ligne roadmap /
quel palier chaque lot appartient — **ne pas créer de doublon de vérité**.

| ID | Lot | Type | Axe §7.1 → NR | Rattachement |
|---|---|---|---|---|
| **RMM-1** | **Refonte UX pure** : séparer SET-UP / geste récurrent, boucle semaine-par-semaine, hiérarchie d'actions, radar en fil conducteur, états vides, filtre par semaine, n° de rencontre affiché. **Zéro backend.** | Front | — (aucun comportement moteur touché) | **P2-26** (ce fichier = son détail) |
| **RMM-2** | Extraire le **stepper/rail du wizard** dans `shared/` + exploiter `tabs` ; mutualiser la grille temporelle. Prérequis technique de RMM-1. | Front | — | P2-26 |
| **RMM-3** | **Gardien à l'ouverture** : recalcul radar + résumé « nouveaux conflits depuis la dernière visite ». Nécessite une **persistance légère** de l'état radar (le radar est stateless aujourd'hui). | Back + Front | contrainte sémantique (conflits) → NR | **net-neuf** (à porter en ligne roadmap) |
| **RMM-4** | **Réconciliation FBI** : le ré-import xlsx **alerte** sur tout écart domicile (heure/salle/date) au lieu de mettre à jour en silence. Réutilise le diff de `FbiFixtureImporter`. | Back + Front | — | **net-neuf** (borné par fait #4.1) |
| **RMM-5** | **Rotation A/B** : modèle (parité de semaine) + payload/solveur (SOFT « respecte A/B ») + UI SET-UP deux semaines. | Model + Engine + Front | **contrat backend↔engine** + **contrainte sémantique** → NR (contract test + smoke-solver) | **net-neuf** (§8) |
| **RMM-6** | **Échéances ligue/comité** : deadlines + rappel cockpit (radar matchs « deadline J-6 »). | Back + Front | — | **palier B** — [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §8 |
| **RMM-7** | **Workflow dérogation** : brouillon + suivi d'état + deadline (tracker + rédacteur, PAS connecteur ligue). | Back + Front | — | **palier B** — `gestion-matchs-ffbb.md` §8 |
| **RMM-8** | **Matrice trajet** + conflits spatiaux (empreinte AWAY réelle). Infra partagée avec l'entraînement (FF#5). | Back + Engine | contrainte sémantique → NR | **palier B/vision** — `gestion-matchs-ffbb.md` §7 + roadmap « Matrice de temps de trajet » |
| **RMM-9** | **Annuaire adverse global** (table hors tenant, publique-seulement, enrichie par l'usage) + effet réseau (auto-remplissage heures/positions extérieures). | Back | isolation tenant → **test d'isolation dédié obligatoire** | **palier B/C** — `gestion-matchs-ffbb.md` §5bis/§11 |
| **RMM-10** | **DOC-2** : avertir avant qu'un match `SUBMITTED`/`VALIDATED` ne perde sa salle (suppression gymnase / restauration de version). | Back + Front | **périmètre engagé** → NR | **DOC-2** (roadmap) — traiter avec **P3-16** |

**Séquencement conseillé** : RMM-2 → RMM-1 (livrable rapide, faible risque, valeur immédiate) ; puis RMM-3 +
RMM-4 (le « gardien », cœur de l'angoisse) ; puis RMM-5 (A/B, plus lourd car moteur) ; le reste (RMM-6→10) au
rythme du palier B déjà spécifié. **Chaque lot est une session d'implémentation à part**, avec sa propre
validation de besoin + `/plan` (CLAUDE.md §7).

---

## 10. Ouvert — à trancher avec le gestionnaire avant de coder les lots concernés

- **A/B (RMM-5)** : qu'est-ce qui **ancre** le rythme A/B sur le calendrier réel (n° de semaine ISO ? date
  d'ancrage saison ? saisie manuelle par le gestionnaire) ? Une équipe est-elle A/B **par nature**, ou est-ce
  une propriété du **créneau de gymnase partagé** ? Faut-il gérer des exceptions (une semaine où A et B
  s'inversent) ?
- **Gardien (RMM-3)** : périmètre du « depuis la dernière visite » — au **login** global, ou à l'**ouverture
  du module** ? Quelle granularité de persistance (dernier hash de radar par saison ? horodatage de visite) ?
- **Réconciliation FBI (RMM-4)** : que fait-on d'un écart détecté — simple alerte, ou proposer d'**aligner**
  l'app sur FBI / FBI sur l'app ? (rappel fait #4.1 : on ne peut pas écrire dans FBI.)
- **Échéances (RMM-6)** : d'où viennent les dates limites (saisie manuelle du gestionnaire ? déductibles du
  catalogue ligue ?) — l'API FFBB ne les fournit pas.
- **Filtre par semaine (RMM-1)** : semaine calendaire ISO ou « journée » FFBB ? (les deux ne coïncident pas
  toujours.)

---

## 11. Coordination avec `gestion-matchs-ffbb.md` (qui possède quoi)

- **`gestion-matchs-ffbb.md`** reste la **référence de besoin** du palier B/C (dérogation, trajet, annuaire
  adverse, catalogue-ligue, empreinte-temps). Les lots **RMM-6 à RMM-9** y renvoient — **ne pas les
  re-spécifier ici**.
- **Ce fichier** possède la **refonte UX** (RMM-1/2), le **gardien** (RMM-3), la **réconciliation FBI**
  (RMM-4) et la **rotation A/B** (RMM-5) — les net-neufs de l'entretien 2026-08-17.
- **La spec courante** [`../courantes/module-matchs.md`](../courantes/module-matchs.md) reste la vérité du
  **livré** ; chaque lot livré y gradue (et **quitte** ce fichier), trace datée en
  [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md).

---

## 12. En une phrase

Le module matchs est complet mais son écran est un fourre-tout ; on le **réorganise à la façon du wizard**
(set-up guidé vs boucle hebdomadaire, radar en fil conducteur), on ajoute le **gardien** qui prévient le
gestionnaire des conflits qu'il découvre aujourd'hui trop tard, on rend **honorable la rotation A/B** qu'il
dessine à la main, et on branche progressivement le **palier B déjà spécifié** — sans jamais promettre ce que
la FFBB ne permet pas (l'import et la saisie FBI restent manuels).

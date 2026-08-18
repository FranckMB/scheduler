# La FFBB comme source, le gestionnaire comme juge — besoin tranché

> **Besoin fondateur, 2026-08-02.** Posé à la lecture des traces de [P2-19](../../docs/archive/api-ffbb-app-reconnaissance.md),
> puis **tranché point par point** au fil d'un échange de challenge. Les §1 à §4 sont des **décisions**, pas des
> propositions : ne pas les re-poser sans fait nouveau. Le §7 est ouvert.
>
> Endpoints et sorties réelles : [`api-ffbb-app-reconnaissance.md`](../../docs/archive/api-ffbb-app-reconnaissance.md) · [`api-ffbb-app-traces.md`](../../docs/archive/api-ffbb-app-traces.md)

---

## 1. Le principe

> **« Le gestionnaire est capable d'associer ou pas les infos et de valider si ça correspond à la situation
> réelle ou non. On l'accompagne, on ne décide pas pour lui. »**

Et son corollaire, qui a recadré tout ce document :

> **« Le gestionnaire connaît son métier, il le faisait déjà avant nous. »**

⚑ **Règle qui en découle, et qui a tué la moitié des idées d'un premier jet : l'outil RETIRE DU TRAVAIL, il
n'EXPLIQUE PAS LE MÉTIER.** Toute fonctionnalité dont la valeur est « informer le gestionnaire » sur son propre
domaine est à rejeter — il sait. Ce que l'API doit faire, c'est **pré-remplir** et **contrôler**.

### 1bis. La page pas vierge — l'objectif produit

> **« Il doit arriver avec une page pas vierge. C'est un gros effort, mais un travail prémâché qu'il peut
> moduler à sa guise. Il y a un sentiment de "l'appli me comprend et me connaît" qui est TRÈS PUISSANT. »**

**Périmètre : les clubs NEUFS uniquement.** Au changement de saison le problème ne se pose pas — on copie le
planning de la saison précédente, le travail est déjà prémâché. C'est la **première** arrivée qui est nue.

Trois leviers, du plus mesuré au moins cadré :

1. **Les gymnases proches, à cocher** → §6.9. **Validé 9/9 sur BCCL.** *« On ne part plus avec 0 % de gymnases
   mais une liste pré-remplie validée par le gestionnaire. »* — c'est le levier le plus mesuré des trois, et le
   seul qui soit déjà prouvé sur données réelles.
2. **Les équipes engagées, à apparier** → §3. ⚠ Indisponible avant le 20 juillet (§5) : inopérant pour un club
   qui s'inscrit en juin, utile pour celui qui arrive en cours de saison.
3. **Des contraintes de base** semées d'office → c'est **P2-16**, déjà en roadmap et non cadré. Le fondateur le
   confirme comme un besoin de ce lot. ⚠ Le piège y est déjà écrit : des contraintes HARD semées peuvent rendre
   un club atypique INFEASIBLE, et une règle que le gestionnaire n'a pas écrite doit être **visible et
   supprimable**.

⚠ **La limite du principe, à tenir** : « prémâché » ne veut pas dire « décidé ». Tout ce qui est pré-rempli
doit être **coché, pas imposé** — sinon on retombe sur le §1, qu'on vient d'établir.

### 1ter. Le principe qui englobe tout — transférer le cerveau du gestionnaire

> **« On reste dans cette démarche de rendre le cerveau et la connaissance du gestionnaire facilement
> transférables dans notre outil. »** (fondateur, 2026-08-02)

C'est la formulation générale dont « la page pas vierge » et « l'outil retire du travail » sont deux faces. Le
gestionnaire **sait déjà tout** : ses gymnases, ses équipes, ses contraintes, ses adversaires. Le produit ne
lui apprend rien — **il baisse le coût de sortir ce savoir de sa tête**. Chaque écran se juge à ça : combien
de frappes pour transférer ce qu'il sait déjà ?

C'est aussi le critère qui **disqualifie** une fonctionnalité : si elle lui apprend quelque chose, elle est
probablement inutile ; si elle lui épargne de saisir ce qu'il connaît, elle est probablement bonne.

#### La fréquence — rare, mais PAS une fois. Et l'écran doit être ré-ouvrable

Les gymnases vivent sur le **plan SEASON** — c'est bien le **calendrier de saison** qu'ils concernent, pas les
overlays de période, qui héritent d'une copie de la grille. Et `SeasonTransitionService` (`:139-145`) les
**recopie d'une saison à l'autre**, `latitude`, `longitude`, `externalRef` et `isActive` compris : **ce qu'on
remplit une fois se propage gratuitement**, sans reprise.

⚠ **Mais le parc bouge** (fondateur) :

> *« Il se peut que le club en cours de saison accède à d'autres gymnases, ou que la saison suivante on perde
> un gymnase pour en avoir un autre (travaux) ou autre. »*

**Conséquence de conception, qui n'est pas cosmétique : la sélection n'est PAS une étape d'onboarding
one-shot.** C'est un écran **ré-ouvrable**, et donc **idempotent** :

- rouvert, il doit distinguer **ce qui est déjà importé** de ce qui reste à proposer — sinon il re-présente
  25 gymnases sans mémoire de ceux qui sont déjà les miens, et le gestionnaire re-coche ou crée des doublons ;
- l'appariement se fait sur **`externalRef` = le `numero` FFBB** (§6.8), pas sur le nom — le club a renommé
  « ALEXANDRA DAVID NEEL » en « ADN », un rapprochement par libellé échouerait ;
- un gymnase **perdu pour travaux** devrait se **désactiver**, pas se supprimer.

⚑ **Et là il y a un trou, vérifié : le geste n'existe pas.** `Venue.isActive` existe en base et
`SeasonTransitionService` (`:144`) le reporte fidèlement d'une saison à l'autre — mais **`VenuesStep` n'offre
aucune désactivation**, seulement la suppression (`pendingDeleteVenue` → `useDeleteVenue`).

Donc aujourd'hui, un gymnase fermé pour travaux ne peut qu'être **supprimé** — ce qui emporte **ses créneaux et
ses réservations** (`DeleteConfirm` l'annonce, mais l'annoncer n'est pas l'éviter). Au retour du gymnase, tout
est à ressaisir.

C'est exactement le scénario que le fondateur décrit, et **le modèle sait déjà le faire** : il manque le
bouton. À couvrir avec la sélection de gymnases — les deux touchent le même écran.

> ⚠ Ne pas confondre avec `VenuePeriodOverride` (`DISABLED`), qui désactive un gymnase **pour une période**.
> Ici c'est la saison entière.

⚠ **Ne pas conclure « peu utilisé donc peu important ».** Le premier passage **est le moment de l'onboarding**
— celui qui décide si le club adopte l'outil ou repart. *« Un sentiment que l'appli me comprend, TRÈS
PUISSANT »*. Un écran rare peut porter le plus fort levier d'adoption.

**Ce que la rareté change, c'est le SÉQUENCEMENT.** Une carte, c'est de la machinerie (bibliothèque, tuiles,
attribution, CGU) pour un écran ouvert deux ou trois fois par club et par an. Raison de plus de livrer **la
liste d'abord** — elle capte l'essentiel à une fraction du coût — puis de décider de la carte **en ayant vu la
liste servir**. Ce n'est pas un doute sur la carte : c'est refuser de payer sa complexité avant d'avoir la
preuve qu'elle manque.

---

## 2. Ce que la FFBB donne vraiment — mesuré, pas supposé

| Objet | Verdict |
|---|---|
| Identité club, adresse, ligue, comité, logo du club | ✅ complet |
| Équipes **engagées** : compétition, poule, saison | ✅ complet |
| Catégorie / sexe / niveau de l'engagement | ⚠️ **7/14 seulement** sur BCCL — les 7 vides sont les **jeunes** |
| **Nom d'équipe** | ❌ **0/14**. `nomEquipe`, `nomUsuel`, `nomOfficiel`, `codeAbrege`, `nomCtc` : tous vides |
| **Numéro d'équipe** | ❌ 6/14, et **sans signification** (décision fondateur) |
| **Logo par équipe** | ❌ **N'EXISTE PAS.** L'engagement porte bien un `logo`, mais c'est **exactement le même id** que celui de l'organisme (`ad8d7110-…` sur les 14, vérifié) : **le logo du CLUB**, déjà récupéré par `FfbbLogoFetcher`. Une copie, pas une donnée neuve |
| **Couleur du club** | 🟢 **`logo.gradient_color`** — `#c9102e` pour BCCL. Voir §6.7 : personne ne l'avait vue |
| **Contact nommé** | ❌ **Absent de l'index.** Seuls `mail` et `telephone` institutionnels — et c'est heureux (donnée personnelle) |
| Calendrier des rencontres | ❌ index de **test** (31 docs nationaux) → **l'import FBI reste le chemin** |
| Créneaux, coachs, contraintes, équipes non engagées | — hors FFBB, à nous |

⚑ **Deux faits structurants.**

**(a) FFBB ⊂ app.** 14 équipes engagées connues de la FFBB, quand le club en gère bien plus (Baby, Micro,
Académies, Loisir). Aucun appariement ne peut supposer une bijection.

**(b) Il n'existe AUCUNE clé stable d'équipe côté FFBB.** Pas de nom, pas de code, pas de numéro fiable. Le
seul identifiant est l'**id d'engagement**, qui est attaché à une **compétition** — donc qui **change à chaque
phase**. C'est le fait qui gouverne le §3.

> ⚠️ Détail d'implémentation à ne pas rater : `niveau.libelle` sort en `PrÃ© rÃ©gionale` — **double encodage
> UTF-8 côté FFBB**. À normaliser à l'entrée, jamais à afficher brut.

### 2bis. Champs relevés au second balayage — à ne pas re-perdre

Un premier passage les avait manqués. Aucun n'est structurant seul ; ensemble ils évitent des requêtes et des
erreurs.

| Champ | Où | Ce que ça vaut |
|---|---|---|
| `logo.gradient_color` | organisme, engagement | **La couleur du club** → §6.7 |
| `salles.numero` + `_geo` | salles | **L'identité et la position d'un gymnase** → §6.8 |
| `engagements_codes` | organisme | La liste des compétitions du club **en une chaîne, sur l'organisme** — pour un ADVERSAIRE, ça évite une requête sur `engagements` |
| `url_competition` | organisme | `/ligues/ara/comites/0069/clubs/ara0069036` — **la hiérarchie ligue/comité en un chemin**, utile au §6.4 |
| `saison_en_cours` | organisme | Booléen — un filtre de saison existe donc bien à ce niveau |
| `cartographie.status` | organisme, salles | Vaut **`"draft"`** sur BCCL : la FFBB **n'a pas validé cette géoloc**. Raison de plus de préférer la salle (§6.8) à l'adresse du club |
| `nom_simple` | organisme | **Le champ « nom d'usage » EXISTE côté FFBB** — mais il est `null` pour BCCL. La décision §4 (pas de nom d'usage) reste bonne : la source ne le remplit pas |
| `type` + `type_association` | organisme | `Groupement` / `{code: K, libelle: Club}` — de quoi distinguer club, CTC et entente le jour où `nomCtc` servira |
| `dateAffiliation` | organisme | **`null` sur BCCL** — inutilisable, contrairement à ce qu'une première lecture supposait |
| `uniqueKey` | rencontre | `{pouleId}_{journée}_{n}` — **clé de dédoublonnage naturelle** pour le jour où l'index se remplit |
| `idOrganismeEquipe1/2.code` | rencontre | Le **code club** des deux camps → jointure directe vers `organismes` |
| `pratique` | rencontre | `5x5` — distingue le 3x3, qui a ses propres compétitions |

---

## 3. L'appariement — TRANCHÉ

**Le problème.** Une équipe du club porte **N engagements** et pas un seul :

- **DF2** : une poule sur toute l'année → 1 engagement ;
- **Brassage jeunes** : **3 poules successives** → 3 engagements ;
- **PNM** : poule de classement, **puis** poule haute ou basse → 2 engagements ;
- **plus la coupe**, en parallèle.

Chaque phase étant une **compétition distincte**, chaque phase produit un **nouvel id d'engagement sans lien
vers le précédent** (cf. §2b). BCCL le montre : 6 de ses 14 engagements sont des compétitions « Brassage »,
distinctes des championnats.

### La décision

> **On ré-apparie à chaque changement de phase. Assumé.**
> « C'est 1 clic, c'est peu coûteux, si derrière ça génère RAPIDEMENT un calendrier de match fiable. »

**On n'invente donc PAS d'identité d'équipe FFBB côté app.** Une première rédaction le proposait — c'était de
la sur-conception : le troc « 1 clic contre un calendrier fiable » est bon, et une identité inventée aurait
créé une seconde vérité à maintenir.

### Le refus — il n'y a rien à modéliser

> « Il dit "c'est pas la mienne", il n'associe pas via l'API, et c'est tout. Le gestionnaire SAIT, il saura
> reconnaître. S'il ne matche pas, c'est qu'il y a un souci côté ligue et il s'en chargera. »

**L'absence de lien EST l'état.** Pas de statut « ignoré », pas d'expiration, pas de mémoire du refus. Une
première rédaction proposait un refus persistable : rejeté, c'était de la sur-conception.

**En contrepartie, une obligation d'affichage** : partout où une donnée FFBB s'affiche, dire qu'elle **vient de
la ligue** et que la correction se fait **auprès d'elle**. Sans cette phrase, un écart ressemble à un bug de
l'app.

---

## 4. L'identité du club — TRANCHÉE

**Lecture seule, sans nom d'usage.** Nom, logo, adresse viennent de la FFBB.

> « C'est pas mon problème, le gestionnaire doit faire le nécessaire pour que le nom donné à la ligue
> corresponde au nom réel. À la limite on peut préciser que ce sont les infos qui viennent de la ligue pour
> qu'il fasse le nécessaire. »

**Décision fermée** — l'objection a été posée et écartée : BCCL s'affiche `B CHARPENNES CROIX LUIZET` alors
qu'il communique sous *Villeurbanne Sharks* (`urlSiteWeb: villeurbannesharks.fr`). Le fondateur assume : la
donnée fédérale fait foi, et l'écart est un problème à régler avec la ligue, pas à contourner dans l'outil.

⚠️ **Le « nom du contact » demandé n'est pas fournissable** : le champ n'existe pas dans l'index (déjà acté
dans [`backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md)). Seuls le mail et le téléphone
institutionnels sont disponibles.

**La ligue et le comité** se rafraîchissent aussi (`FfbbLeague` / `FfbbCommittee`). ⚠ Ce sont des tables
**GLOBALES** : un rafraîchissement déclenché par un club met à jour une ligne que **tous** les clubs lisent —
idempotence et traçabilité obligatoires, ce n'est pas une écriture tenant ordinaire.

---

## 5. Le calendrier de la valeur — quand ça sert

> « Les poules sortent après le 20 juillet, donc on aura déjà basculé à la saison suivante. »

Notre pivot de saison est au **15 juillet** (`SeasonResolver`). Donc **quand le gestionnaire construit son
planning d'entraînement en juin, les poules n'existent pas encore.**

⚑ **Conséquence de séquencement : ce n'est PAS une brique d'onboarding, c'est une brique du module matchs.**
Elle sert en août-septembre. Le « gain d'onboarding » évoqué au départ existe, mais il arrive après le wizard.

---

## 6. Comment exploiter l'API — le retournement

Un premier jet proposait des fonctionnalités d'**information**. Toutes rejetées, à raison : *« les idées que
tu proposes sont trop simplettes »*. Le gestionnaire sait quand sortent ses poules, connaît sa charge, connaît
ses difficultés.

**Le bon usage de l'API n'est donc pas de lui parler. C'est de CONTRÔLER SES DONNÉES et de PRÉ-REMPLIR ses
gestes.**

### ⚑ Le principe qui rend ça possible : deux sources de vérité qui se croisent

> **« L'API FFBB travaille de concert avec l'import FBI. Ce sont deux sources de vérité qui se croisent. »**
> (fondateur, 2026-08-02)

C'est le cœur du dispositif, et il faut le dire dans ces termes parce qu'il commande tout le §6 :

| Source | Fait autorité sur | Ne sait rien de |
|---|---|---|
| **API FFBB** (poule, engagement) | le **PÉRIMÈTRE** — quelles équipes, quelles compétitions, **quels adversaires** | les dates, les heures, les salles |
| **Export FBI** (fichier du gestionnaire) | le **CALENDRIER** — quand, où, contre qui, domicile/extérieur | si le fichier est le bon, complet, ou à jour |

**Aucune des deux ne peut se valider seule.** Un export FBI est un fichier : rien en lui ne dit qu'il concerne
la bonne équipe, la bonne phase, ni qu'il est entier. L'API, elle, ne connaît aucune date.

**Leur croisement est le seul endroit où une erreur devient visible** — et c'est gratuit, puisque les deux
existent déjà. C'est de là que viennent §6.1 (adversaire hors poule), §6.2 (journées manquantes) et §6.3
(`Division` du fichier rapprochée du nom canonique de l'API).

⚠ **Corollaire à tenir** : en cas de désaccord entre les deux, **on ne tranche pas** — on montre l'écart et on
laisse le gestionnaire décider, exactement comme au §1. Une contradiction API↔FBI est souvent un vrai problème
côté ligue, pas une donnée à réconcilier en silence.

Les trois premières briques ci-dessous appliquent ce croisement.

### 6.1 🟢 La poule comme garde-fou de l'import FBI — *la plus utile*

La poule donne **la liste exacte des clubs adverses**. À l'import :

- un adversaire **absent de la poule** → mauvais fichier, mauvaise équipe, ou mauvaise phase ;
- un export chargé sur les SM3 alors que les adversaires sont ceux de la poule des SM4 → **attrapé
  immédiatement**.

**Aujourd'hui rien ne l'attrape.** L'erreur se découvre des semaines plus tard via un conflit coach aberrant —
ou jamais. Ce n'est pas de l'information : **c'est une erreur qui ne se produit plus.**

### 6.2 🟢 Le contrôle de complétude

Poule de 12 → 22 journées attendues. Import qui en rend 9 → **le fichier est partiel**, on le dit.

⚠ La nuance qui rend l'idée valable : il connaît sa saison, mais **il ne compte pas de tête les matchs
manquants sur 14 équipes × 3 phases**. Et c'est exactement le trou de [P1-4 (5)](roadmap.md) : distinguer
« incomplet parce que la phase suivante n'est pas sortie » de « incomplet parce qu'un fichier a été raté ».

### 6.3 🟢 L'appariement pré-calculé — de 1 clic à 0

L'export FBI porte une colonne **`Division`** en texte libre ; l'API porte le **nom canonique** de la
compétition et sa poule. On rapproche les deux **une fois** ; aux phases suivantes, l'app propose
l'appariement **déjà fait** et le gestionnaire **confirme en bloc**.

14 équipes × 3 phases = 42 gestes ramenés à 3 confirmations. **Dégradation propre** : si le rapprochement
échoue, on retombe sur l'appariement manuel du §3, qui reste le contrat.

### 6.4 🟡 Le catalogue de fenêtres ligue se clé tout seul

[`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §6bis prévoit `LeagueMatchWindow`, **seedé à la main pour
AURA** — ça ne passe pas à l'échelle. Or chaque engagement porte `codeLigue`/`codeComite`, et l'organisme
porte `organisme_id_pere` (BCCL → *Comité du Rhône*). **La hiérarchie club→comité→ligue est gratuite.** Le
gestionnaire ne tape jamais son code ligue : travail retiré, zéro information.

### 6.5 🟡 L'empreinte-temps des déplacements, pré-remplie

`MatchFootprint` existe déjà ([`module-matchs.md`](../courantes/module-matchs.md)). La commune de chaque
adversaire est connue **par la poule, avant le calendrier** → l'empreinte d'un match extérieur se pré-remplit.

**Arbitrage fondateur sur la précision** : l'adresse d'un club est celle de son gymnase principal ; même s'il
change, *« si on joue contre Clermont c'est 2h de route aller, 45 min d'échauffement, 2h retour et 30 de
douche — l'ordre de grandeur d'indisponibilité est le même »*.
⚠ **Réserve retenue** : l'approximation est bonne au loin et **faible en local**, or les championnats
**départementaux** — le gros du volume — sont locaux, où 25 minutes d'écart changent un placement.

⚑ **Mais le §6.8 rend cette approximation largement inutile** : l'export FBI nomme la **salle**, et
`ffbbserver_salles` en donne les coordonnées exactes. On ne se rabat sur l'adresse du club que si la salle ne
résout pas. Dans ce cas seulement, afficher **comme estimation**, corrigeable.

### 6.6 🟡 Les rivaux — un drapeau, pas une inférence

Marquer un adversaire comme rival donne une **visibilité différente au calendrier** : *« ce soir la PNM joue
contre le Clar, il faut peut-être prévoir une action de club autour de ce match »*.

**Posé par le gestionnaire, jamais déduit.** Une objection initiale visait une déduction automatique (même
commune ? même niveau ?) — hors sujet : ce n'était pas la demande, et déduire violerait le §1.

### 6.7 🟢 La couleur du club, pré-remplie — trouvée en relisant les traces

L'objet `logo` porte **`gradient_color`** : `#c9102e` pour BCCL, le rouge du club. Elle voyage dans le payload
que `FfbbClubPopulator` récupère **déjà** à la création.

Or `Club.accentColor` existe (`Club.php:99`) et pilote tout le thème du club (`useApplyClubTheme`, garde AA
incluse) — mais **`FfbbClubPopulator` ne le renseigne pas** : le gestionnaire choisit sa couleur à la main
dans `/club`.

**Un club neuf peut donc arriver déjà à ses couleurs, sans un geste.** Coût : lire un champ qu'on télécharge
déjà. ⚠ Passer par la dérivation AA existante — une couleur fédérale n'est pas garantie lisible en thème
sombre — et rester **surchargeable** : c'est un pré-remplissage, pas une identité imposée (contrairement au
nom, §4, où le fondateur a tranché l'inverse).

*(Trouvée parce que le fondateur a pointé l'objet `logo` du §9 des traces. Je l'avais lu comme « rien de neuf,
c'est le logo du club » et j'étais passé à côté du champ d'à côté.)*

### 6.8 🟢 Résoudre les GYMNASES — la trouvaille du second balayage

`ffbbserver_salles` est **peuplé** (5 000+) et porte, par salle : `numero` (**l'identifiant FFBB de la salle**),
`libelle`, `adresse`, `commune`, et `_geo`. Testé : `VILLEURBANNE` → 17 salles du 69100, dont **GYMNASE JEAN
VILAR** — un gymnase de BCCL. **L'index couvre donc aussi NOS salles**, pas seulement celles des adversaires.

⚠ La recherche plein texte seule ne suffit pas — « GYMNASE JEAN VILAR » rend **2 999 hits**. Il faut
**désambiguïser par le code postal** ; même règle qu'au §2, on filtre sur le champ.

**Trois usages, et le premier change une décision déjà prise :**

1. **Le trajet devient réel, plus une estimation.** L'export FBI porte une colonne **`Salle`**. Nom + commune →
   la salle exacte et ses coordonnées. **Ça périme la réserve du §6.5** : plus besoin d'approximer par
   l'adresse du club (dont la géoloc est d'ailleurs en `status: "draft"` côté FFBB, donc non validée).
2. **Nos propres gymnases se pré-remplissent.** `Venue.latitude`/`longitude` et `Venue.externalRef` **existent**
   (entité + DTO) mais **le front ne les envoie jamais** — ils sont morts. Le `numero` FFBB est exactement ce
   qu'attend `externalRef`, et il donne au **gymnase de match** (§7) son identité officielle, celle qu'on
   déclare à la ligue.
3. **Le jour où `rencontres` se remplira**, la salle est **embarquée dans le document de match** avec adresse et
   coordonnées — plus aucune résolution à faire.

### 6.9 🟢🟢 « Cochez vos gymnases parmi ceux d'à côté » — **validé 9/9 sur BCCL**

> **« Il doit arriver avec une page pas vierge. C'est un gros effort, mais un travail prémâché qu'il peut
> moduler à sa guise. Si j'ai l'adresse du club, à l'onboarding il peut sélectionner parmi les gymnases pas
> loin pour dire s'il y a accès ou non. Il y a un sentiment de "l'appli me comprend et me connaît" qui est TRÈS
> PUISSANT. »** (fondateur, 2026-08-02)

**Testé, et le résultat dépasse l'intuition.** Meilisearch accepte le filtre géographique **avec la clé
search-only** :

```json
{ "indexUid": "ffbbserver_salles", "q": "", "limit": 60,
  "filter": "_geoRadius(45.78017, 4.88467, 5000)",
  "sort":   ["_geoPoint(45.78017, 4.88467):asc"] }
```

Le club porte déjà ses coordonnées (`Club.latitude/longitude`, remplies par `FfbbClubPopulator`). Autour de
BCCL : **25 salles dans 3 km, 53 dans 5 km**, triées par distance.

**Croisement avec les 9 gymnases réellement utilisés par BCCL : 9/9 retrouvés.**

| Le club dit | La FFBB dit |
|---|---|
| Armand | GYMNASE ARMAND |
| **ADN** | GYMNASE **A**LEXANDRA **D**AVID **N**EEL |
| Debarros | SALLE RAPHAEL DE BARROS |
| Annexe | SALLE ANNEXE DE BARROS |
| Jean Vilar | GYMNASE JEAN VILAR |
| Tonkin | GYMNASE MOULIN DE TONKIN |
| **JDR** | GYMNASE **J**EANNE **D**ESPARMET-**R**UELLO |
| Matéo | GYMNASE MATEO |
| Camus | SALLE ALBERT CAMUS |

⚑ **Ce tableau porte le principe entier.** Le club appelle ses salles par des sigles que **personne d'autre ne
peut deviner** — « ADN », « JDR ». L'app propose les **noms officiels géolocalisés**, le gestionnaire coche et
**renomme à sa main**. C'est exactement « prémâché, modulable à sa guise » : on ne devine pas son vocabulaire,
on lui épargne la saisie.

**Ce que ça remplit d'un coup** : le gymnase, son adresse, sa **géoloc** (donc les trajets), et son **`numero`
FFBB** dans `Venue.externalRef` — l'identité officielle qu'un gymnase de match déclare à la ligue (§7). Trois
champs aujourd'hui morts faute d'être saisis (§6.8).

#### Le rayon — mesuré sur quatre clubs, et le défaut fixe est un piège

> **« Dans une ville comme Villeurbanne, 5 km ça donne beaucoup de gymnases, mais pour les petits clubs il y a
> une limite, quitte à la modifier (3 km, 5 km, 10 km). »** (fondateur, 2026-08-02)

L'intuition est bonne. Les chiffres la précisent :

| Club | 3 km | 5 km | 10 km | 20 km |
|---|---:|---:|---:|---:|
| **BCCL** — Villeurbanne (urbain dense) | 25 | 53 | 127 | 217 |
| **Cantalienne Aurillac** — ville moyenne | 4 | 4 | 5 | 5 |
| **Naucelles Basket** — Cantal (rural) | 1 | 3 | 5 | 5 |
| **Martiel/Villefranchois** — Aveyron (rural) | **0** | **0** | 5 | 13 |

⚑ **Martiel rend ZÉRO gymnase à 3 km comme à 5 km.** Un défaut fixe montrerait donc une **liste vide** à un
club rural — sur son tout premier écran, et pour une fonctionnalité dont l'argument est « l'appli me comprend ».
C'est l'inverse de l'effet recherché.

Et à l'autre bout, BCCL à 10 km rend **127 salles** : ce n'est plus une aide, c'est un annuaire à trier.

**Proposition : un rayon qui s'élargit tout seul, avec les paliers restés manuels.**

Partir à **3 km**, et tant que le résultat est en dessous d'un seuil utile (~5 salles), passer au palier
suivant — 5, 10, 20 km — **en le disant** (« aucun gymnase à moins de 5 km, voici ceux dans 10 km »). Les
paliers restent affichés pour élargir ou resserrer à la main.

Résultat sur les quatre cas : BCCL s'arrête à 3 km avec 25 salles, Aurillac et Naucelles à 10 km avec 5,
Martiel à 10 km avec 5. **Aucun n'atterrit sur une liste vide, aucun sur 127.**

⚠ **Réserves.** Un club dont la géoloc est absente ou fausse retombe sur la saisie manuelle, sans dégradation.
Le `status: "draft"` de la cartographie FFBB (§2bis) vaut ici aussi : la position est une bonne amorce, pas une
vérité. Et surtout — **le 9/9 de BCCL est UN club** : rien ne garantit que le gymnase d'un club donné figure à
l'index. Les 0 de Martiel le rappellent. La liste pré-remplie est une **amorce**, l'ajout manuel doit rester à
portée immédiate, jamais relégué derrière la liste.

#### La forme voulue — « comme un choix de point relais »

> **« Je vois pour les gymnases une carte avec le nom des gymnases, comme quand on veut choisir un relais
> pickup. Le gymnase a donc un nom complet et un nom plus court utilisé par l'application. Le gestionnaire
> choisit quels gymnases importer, et on ne part plus avec 0 % de gymnases mais une liste pré-remplie validée
> par le gestionnaire. »** (fondateur, 2026-08-02)

**Deux noms, et le modèle n'en a qu'un.** `Venue` porte `name`, point. Il faut :

| Champ | Rôle | État |
|---|---|---|
| `name` | le **nom court**, celui du club — « ADN », « JDR » | existe |
| *(nouveau)* nom officiel | « GYMNASE ALEXANDRA DAVID NEEL » — ce qu'on montre à l'import et ce qui parle à la ligue | **à créer** |
| `externalRef` | le `numero` FFBB de la salle | existe, **jamais rempli** |
| `latitude` / `longitude` | pour la carte et les trajets | existent, **jamais remplis** |

Le nom court reste **libre et modifiable** : c'est le vocabulaire du club, l'app ne le devine pas (§6.9). Une
proposition raisonnable est de pré-remplir le court à partir de l'officiel et de le laisser réécrire — pas de
l'imposer.

#### La carte — ce qui coûte n'est pas le pin

> *« Qu'est-ce qui bloque si j'affiche la localisation sur une carte ? Si on a les coordonnées, ce n'est pas
> très complexe, c'est juste un pin. »*

**Rien ne bloque, et le pin est effectivement trivial.** Une première rédaction dramatisait — correction :

| | Coût réel |
|---|---|
| **Le marqueur** | Nul. Un point depuis lat/lng ; Leaflet fait ~40 Ko et reste optionnel |
| **Le fond de carte** | **Tout le sujet.** Sans tuiles, des pins sur fond blanc sont justes et illisibles — on ne reconnaît pas un lieu sans les rues |

Les tuiles viennent d'un hôte tiers, et la CSP (`docker/frontend/csp.conf:10`) n'autorise **aucun tiers** :
`img-src 'self' data: blob:` · `connect-src 'self' blob:`.

**C'est littéralement UNE ligne à modifier.** Ce qui pèse n'est pas l'effort, c'est que **ce serait le premier
appel navigateur vers un tiers de toute l'application** — et que chaque tuile envoie l'IP du gestionnaire à ce
tiers (→ `docs/security/rgpd.md`).

**Trois options, par ordre de cohérence avec l'existant :**

1. **Proxifier les tuiles par notre backend**, exactement comme `FfbbLogoFetcher` réhéberge déjà les logos FFBB
   au lieu de les hotlinker. Same-origin, CSP inchangée, **aucune IP ne fuit**. ⚠ Coût : bande passante, cache,
   et **les CGU du fournisseur de tuiles — beaucoup interdisent le proxy**. À vérifier avant de choisir.
2. **Autoriser un hôte de tuiles dans la CSP.** Une ligne, mais on assume le premier tiers navigateur.
3. ~~Pas de carte~~ — **écarté** : voir la décision ci-dessous.

#### ✅ La carte est VOULUE — décision fondateur

> **« Il faut une carte de France, oui, qui centre sur la géoloc de mon club et qui affiche les gymnases aux
> alentours. »** (2026-08-02)

L'écran est donc : **carte centrée sur `Club.latitude/longitude`**, un **pin par gymnase** du rayon courant
(§ ci-dessus, rayon auto-élargi), le **nom officiel** au pin, et la **case à cocher** qui importe.
La liste triée par distance et la carte sont **deux vues du même jeu** — pas une alternative.

#### La source des tuiles — candidats vérifiés le 2026-08-02

| Source | Usage commercial | Coût | Verdict |
|---|---|---|---|
| **IGN Géoplateforme** `data.geopf.fr/wmts` | ✅ autorisé | **gratuit** | 🟢 **Le candidat** |
| **OSM** `tile.openstreetmap.org` | toléré, **mais…** | gratuit | 🔴 **À écarter** |
| Commerciaux (MapTiler, Stadia…) | ✅ | payant au-delà d'un palier | 🟡 repli |
| Auto-hébergé (PMTiles / OpenMapTiles) | ✅ | stockage + génération | 🟡 le zéro-tiers absolu |

**🔴 Pourquoi OSM est écarté, et ce n'est pas une question de licence.** Leur propre politique d'usage le dit :
l'usage commercial est permis, mais *« access may be withdrawn at any point: you may no longer be able to serve
your paying customers if access is withdrawn »*. Pour un produit qu'on commercialise à mi-2027, **c'est une
dépendance qui peut être coupée sans préavis**. S'ajoutent : bulk download **strictement interdit**, proxy
« généralement déconseillé », User-Agent identifiant obligatoire (les UA génériques de bibliothèques sont
bloqués).

**🟢 Pourquoi l'IGN convient**, vérifié dans les CGU (v. 15/10/2024) : **usage commercial autorisé**, service
**gratuit** (« l'utilisation des géodonnées et géoservices est gratuite »), **aucune limite de débit sur le
WMTS** (les quotas cités portent sur les tuiles vectorielles TMS, le WFS et le téléchargement), palier de
consommation *Essentiel* à 1 To/mois, disponibilité annoncée 99,5 %. Licence par défaut **Licence Ouverte /
Etalab** → **attribution obligatoire**.

Et l'argument qui n'est pas technique : c'est un **service public français**, pour un produit **franco-français**
qui vend à des clubs FFBB. Pas de transfert vers un hébergeur tiers étranger — le volet RGPD s'allège.

⚠ **Restent à vérifier avant de coder** : (a) la **formulation exacte de l'attribution** exigée par la couche
choisie (elle dépend du producteur de la donnée, pas de la Géoplateforme) ; (b) si le **proxy backend** est
compatible avec leurs CGU — si oui, on garde `img-src 'self'` et **la CSP ne bouge pas du tout**, ce qui rend
la question du §précédent sans objet.

Sources : [politique de tuiles OSM](https://operations.osmfoundation.org/policies/tiles/) ·
[CGU cartes.gouv.fr](https://cartes.gouv.fr/cgu) ·
[API WMTS Géoplateforme](https://www.data.gouv.fr/dataservices/api-geoplateforme-diffusion-dimages-tuilees-wmts)

**Ordre de livraison suggéré** (sans rien retirer de la cible) : la **liste** d'abord — elle ne dépend d'aucune
tuile et livre déjà « il ne part plus de zéro » —, la **carte** ensuite par-dessus le même jeu de données. Les
coordonnées étant stockées dès le premier jour, la carte ne demande aucune reprise du modèle.

### ❌ Rejeté explicitement

**« Dire quelles phases sont publiées »** (`etat`, `publicationInternet`). Le gestionnaire sait quand sortent
ses poules. Rejeté par le fondateur, et c'est de ce rejet qu'est née la règle du §1.

---

## 6bis. L'onboarding express — l'ASSEMBLAGE des trois leviers (cadré 2026-08-04)

> **« Je rentre mon code FFBB et magiquement plein de choses sont déjà faites — l'onboarding le moins
> pénible possible, l'effet waouw. »** (fondateur, 2026-08-04)

Les leviers du §1bis existent un par un ; ce qui manque est leur **assemblage en séquence d'onboarding**.

⚑ **La forme, TRANCHÉE (fondateur, 2026-08-04) — pas d'écran de sélection pour les équipes** : elles se
créent AUTOMATIQUEMENT à l'arrivée, le gestionnaire atterrit sur l'étape Équipes **déjà peuplée** et une
modale annonce : *« Les équipes ont été importées automatiquement depuis la FFBB. Des erreurs ont pu se
glisser — corrigez et complétez cet écran. »* *« Le gestionnaire n'a rien à faire, il constate tout de
suite que 10 équipes sont déjà chargées. »* C'est une RÉVISION assumée du « tout pré-coché » du §1bis
pour CE levier : la création tombe dans un club VIDE (rien à écraser), la modale est l'annonce, et
l'écran d'atterrissage EST l'écran de correction — le gestionnaire reste le juge, juste après le fait.
⚠ La borne : ce comportement vaut à l'onboarding d'un club vide UNIQUEMENT — toute ré-exécution sur un
club peuplé repasse par une proposition explicite (l'idempotence du §1ter).

### Le challenge, posé et intégré

1. **Le waouw ment s'il ne dit pas ce qu'il couvre.** BCCL : 14 engagements pour ~49 équipes réelles —
   l'API ne voit que la COMPÉTITION ; loisir, baby, U7/U9 non engagés sont invisibles. L'écran doit
   l'annoncer : « N équipes trouvées — ajoutez vos équipes loisir et école de basket ». Pour un club
   100 % compétition le waouw est total.
2. **Le mur du calendrier (§5).** Un club qui s'inscrit en JUIN n'a aucun engagement à importer — le
   levier équipes ne sert qu'à partir de fin juillet. L'écran doit dégrader proprement : gymnases +
   contraintes toujours, équipes « reviendront avec les poules » (et l'écran est RÉ-OUVRABLE, §1ter —
   même exigence d'idempotence que les gymnases).
3. **Pas d'auto-création silencieuse** — la décision §1/§3 tient : la FFBB propose, le gestionnaire
   tranche. Le waouw correct = tout pré-coché + UN clic « Tout créer ».
4. **FBI n'est pas une API.** Fichier xlsx téléchargé à la main, disponible lui aussi seulement après
   la sortie des calendriers. Une première rédaction proposait de tuer P3-7 (import Excel équipes) —
   **REJETÉ par le fondateur (2026-08-04)** : *« on ne sait pas quand un club va souscrire — l'API peut
   répondre un grand nombre ou AUCUNE équipe selon la date, il faut pallier. L'API est une aide, pas la
   source de vérité. »* **P3-7 est GARDÉ** comme rail de secours de la saisie d'équipes ; FBI reste par
   ailleurs le rail des CALENDRIERS (l'index `rencontres` est vide, re-mesuré 2×).

### Ce qu'une équipe pré-créée porte — et les décisions des 2026-08-04

⚠ Aligné sur les MESURES du §2 — la première rédaction de cette section leur contredisait deux points :
il n'existe PAS de logo par équipe (l'engagement recopie le logo du CLUB, vérifié sur les 14), et les
champs structurés catégorie/sexe/niveau ne sont remplis que **7/14** (les seniors) — les 7 vides sont
les jeunes.

- **Principe re-confirmé par le fondateur (2026-08-04)** : *« l'API/l'import est une AIDE à la saisie —
  tout faire manuellement reste toujours possible. »* Et le critère d'acceptation, dans ses mots :
  *« si pour BCCL ça crée automatiquement les équipes en brassage et les équipes senior et que j'ai
  juste à renommer, c'est tout bénef et ça me suffit amplement. »*
- ⚑ **Décodage des codes de compétition pour les jeunes** (fondateur, 2026-08-04) : leurs champs
  structurés sont VIDES mais tout est dans le code — `RMU13 Brassage` = **R**égional **M**asculin
  **U13**, `RFU15` = **R**égional **F**éminin **U15**. Grammaire : `PN|R|D…` = niveau, `M|F` = sexe,
  `U9…U20|SE` = catégorie. Les brassages sont de VRAIES équipes jeunes → **pré-cochés** comme les autres.
- **Nom d'usage généré** depuis sexe+catégorie décodés (« SM? », « U13M ») — `nomEquipe` : 0/14 (§2),
  le club a son propre vocabulaire : généré, ÉDITABLE (« j'ai juste à renommer » — mêmes sigles
  ADN/JDR qu'au §6.9). ⚠ Le `numeroEquipe` (6/14, « sans signification » — décision §2) ne sert PAS au
  rang ; s'en servir pour suffixer le nom (« SM3 ») est à valider sur données réelles en cadrant la PR.
- **Pré-classement par NIVEAU** : pré-nationale > régionale > départementale → proposition de rangs
  S→D pré-remplie (le fanion se détecte tout seul) — un des gestes les plus coûteux du wizard actuel.
- **`sessionsPerWeek` par défaut = 2** (décision fondateur, 2026-08-04) — éditable comme tout le reste.
- ⚑ **PAS d'appariement à l'onboarding — TRANCHÉ (fondateur, 2026-08-04)** : *« si le gestionnaire
  casse tout en éditant à la volée les équipes, on va avoir de mauvais liens entre les deux. »* Une
  équipe fraîchement créée va être renommée, supprimée, fusionnée — un lien posé à cet instant est un
  lien fragile. L'appariement reste où il est : le dialog « Engagements FFBB » de `/matchs`, en août,
  1 clic par équipe (§3), sur des équipes STABILISÉES.

### Le découpage proposé (l'ordre est le levier)

| Lot | Contenu | Taille |
|---|---|---|
| A | **Création AUTOMATIQUE des équipes engagées à l'arrivée** (décodage brassage, noms générés, pré-classement par niveau, 2 séances) + modale d'annonce sur l'étape Équipes peuplée ; annonce du manque (loisir/baby) ; dégradation juin (« reviendront avec les poules ») | M |
| ~~B~~ | ~~Appariement d'office~~ — **abandonné** (liens fragiles sur équipes en cours d'édition ; reste au dialog `/matchs`) | — |
| C | Accent par défaut = `logo.gradient_color` (§6.7) | XS |
| D | Gymnases proches à cocher (§6.9, rayon auto-élargi) — le levier déjà validé 9/9 | M |
| E | P2-16 — contraintes de base semées — ⚑ **LIVRÉ le 2026-08-04** (tranché fondateur : tout PREFERRED ; jeunes ≤ 19h30 · baby ≤ 18h30 · EMB ≤ 19h · seniors ≥ 19h · pas le dimanche — voir état des lieux) | M |

Ce qui ne sera JAMAIS pré-rempli, dit une fois : les **coachs** (aucune personne physique dans l'index)
et les **créneaux** (donnée mairie, hors FFBB) — l'onboarding les annonce comme LES deux saisies
restantes, c'est aussi ça « le moins pénible possible » : savoir ce qui reste.

---

## 7. Les gymnases de match — un besoin neuf, avec une collision

> **« Il faut que l'on définisse nos gymnases de match : tous les gymnases d'entraînement ne sont pas des
> gymnases de match. »** (fondateur, 2026-08-02)

**Rien n'existe.** `Venue` porte `isExternal`, `canSplit`, `isActive`, `parentVenueId`, `latitude/longitude` —
**aucun marqueur d'aptitude au match**. [P1-4 (4)](roadmap.md) l'avait noté en passant (« un gymnase n'accueille
pas forcément des matchs ») sans le spécifier.

⚑ **La collision, à traiter avant de coder** : le wizard **exige aujourd'hui au moins un créneau par gymnase**
(règle affichée sous le formulaire d'ajout, cf. [`frontend-wizard.md`](../../frontend/docs/frontend-wizard.md)). Or un
gymnase **réservé aux matchs** — une salle plus grande louée le week-end, par exemple — n'a **aucun créneau
d'entraînement**. La règle actuelle le refuserait, ou forcerait le gestionnaire à inventer un créneau fictif
qui partirait ensuite au solveur.

**Trois questions, à trancher ensemble :**

1. **Un attribut ou deux listes ?** `Venue.canHostMatches` sur l'entité existante, ou un gymnase de match est-il
   une ressource d'une autre nature ? *(L'attribut paraît suffisant et évite de dupliquer adresse/géoloc.)*
2. **Que devient la validation « ≥ 1 créneau » ?** Elle doit devenir « ≥ 1 créneau **ou** gymnase de match »,
   sans quoi la saisie est bloquée.
3. **Le solveur doit-il savoir qu'un créneau est mangé par un match ?** Un gymnase mixte accueillant un match
   le samedi ne peut pas accueillir l'entraînement en même temps — c'est un axe **constraint semantics**, donc
   un test de non-régression obligatoire le jour venu.

**Ce que le §6.8 apporte ici** : un gymnase de match a une **identité officielle** côté FFBB (`salles.numero`),
et c'est elle qu'on déclare à la ligue. `Venue.externalRef` l'attend déjà. Le résoudre à la saisie donne au
passage la géoloc, qui sert le trajet.

*(Piste écartée : `capaciteSpectateur` — un niveau peut exiger une capacité minimale, mais le champ est **vide**
sur l'échantillon lu. Non exploitable.)*

---

## 8. Reste ouvert

| # | Sujet |
|---|---|
| **8.1** | **Forfait général.** Les matchs sont perdus et n'ont plus à être gérés ; surtout, l'équipe **n'a potentiellement plus besoin de ses créneaux**, réallouables. ⚠ `EngagedTeamGuard` verrouille toute équipe **ayant des matchs** — une équipe en forfait en a. **Forfait ≠ désengagement dans notre modèle** : il faudra un troisième état. Axe *périmètre engagé* → NR obligatoire. **Réel, pas prioritaire** (fondateur). |
| **8.2** | **Correspondance saison** FFBB `26-27` ↔ notre pivot du 15 juillet : posée une fois, où ? |

> ✅ **Le point juridique est FERMÉ, pas supprimé** (décision fondateur, 2026-08-02) : **on ne stocke pas
> d'annuaire.** Chaque club consulte la FFBB **pour lui-même, à la demande** — c'est de la consommation par
> locataire, pas de l'extraction de base. Le risque que soulevait ce point (droit *sui generis* du producteur
> de base de données, art. L341-1 CPI : l'extraction **substantielle** est protégée même quand chaque donnée
> est publique) **naissait du stockage des 4 635 organismes**, pas de l'usage.
>
> ⚠ **Ce que ça interdit, et qu'il faudra rappeler le jour où l'idée reviendra** : constituer une base
> d'adversaires nationale, un fichier de prospection commerciale à partir des `dateAffiliation` /
> `labellisation` / `offresPratiques`, ou tout cache global qui survivrait à la requête d'un club. Le jour où
> l'un de ces trois usages est demandé, **ce point se rouvre** et exige un avis juridique — il n'est pas
> tranché « pour toujours », il est tranché **pour l'architecture actuelle**.
>
> Non concerné : le réhébergement du logo et l'identité du club **de ce club-là**, qui existent depuis le
> lot C. Et l'annuaire d'adversaires enrichi par l'usage de [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md)
> §5bis reste possible — il naît de **ce que les clubs saisissent**, pas d'une extraction FFBB.

---

## 9. Traçabilité

| Spec / ligne | Ce que ce document y change |
|---|---|
| **P2-18** | Devient un **écran d'arbitrage** ; club en lecture seule sans nom d'usage ; **pas** de refus modélisé |
| **P1-4 (2)** | L'appariement s'ancre sur la compétition FFBB, **ré-apparié à chaque phase** (§3) |
| **P1-4 (4)** | Les **gymnases de match** sortent du parking et deviennent un besoin cadré (§7) |
| **P1-4 (5)** | Brassage/poules déjà modélisés côté FFBB ; le contrôle de complétude (§6.2) attaque le vrai trou |
| [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §6bis | `LeagueMatchWindow` devient **auto-clé** (§6.4) |
| [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §9 | `Competition / Phase` : une phase **est** une compétition FFBB, pas un sous-objet |
| [`module-matchs.md`](../courantes/module-matchs.md) | `MatchFootprint` pré-remplissable (§6.5) |
| **P4-35** | Même remède : correspondance explicite, jamais devinée |
| **Abonnement** | Bascule de saison **gatée sur le renouvellement** — ligne roadmap distincte (fuite de revenu) |

# La FFBB comme source, le gestionnaire comme juge — besoin tranché

> **Besoin fondateur, 2026-08-02.** Posé à la lecture des traces de [P2-19](api-ffbb-app-reconnaissance.md),
> puis **tranché point par point** au fil d'un échange de challenge. Les §1 à §4 sont des **décisions**, pas des
> propositions : ne pas les re-poser sans fait nouveau. Le §7 est ouvert.
>
> Endpoints et sorties réelles : [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md) · [`api-ffbb-app-traces.md`](api-ffbb-app-traces.md)

---

## 1. Le principe

> **« Le gestionnaire est capable d'associer ou pas les infos et de valider si ça correspond à la situation
> réelle ou non. On l'accompagne, on ne décide pas pour lui. »**

Et son corollaire, qui a recadré tout ce document :

> **« Le gestionnaire connaît son métier, il le faisait déjà avant nous. »**

⚑ **Règle qui en découle, et qui a tué la moitié des idées d'un premier jet : l'outil RETIRE DU TRAVAIL, il
n'EXPLIQUE PAS LE MÉTIER.** Toute fonctionnalité dont la valeur est « informer le gestionnaire » sur son propre
domaine est à rejeter — il sait. Ce que l'API doit faire, c'est **pré-remplir** et **contrôler**.

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

### ❌ Rejeté explicitement

**« Dire quelles phases sont publiées »** (`etat`, `publicationInternet`). Le gestionnaire sait quand sortent
ses poules. Rejeté par le fondateur, et c'est de ce rejet qu'est née la règle du §1.

---

## 7. Les gymnases de match — un besoin neuf, avec une collision

> **« Il faut que l'on définisse nos gymnases de match : tous les gymnases d'entraînement ne sont pas des
> gymnases de match. »** (fondateur, 2026-08-02)

**Rien n'existe.** `Venue` porte `isExternal`, `canSplit`, `isActive`, `parentVenueId`, `latitude/longitude` —
**aucun marqueur d'aptitude au match**. [P1-4 (4)](roadmap.md) l'avait noté en passant (« un gymnase n'accueille
pas forcément des matchs ») sans le spécifier.

⚑ **La collision, à traiter avant de coder** : le wizard **exige aujourd'hui au moins un créneau par gymnase**
(règle affichée sous le formulaire d'ajout, cf. [`frontend-wizard.md`](../courantes/frontend-wizard.md)). Or un
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

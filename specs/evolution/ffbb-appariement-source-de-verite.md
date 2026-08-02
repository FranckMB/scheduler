# La FFBB comme source, le gestionnaire comme juge — besoin cadré

> **Besoin fondateur, 2026-08-02**, posé à la lecture des traces de [P2-19](api-ffbb-app-reconnaissance.md).
> Ce fichier **trace le besoin** et croise les specs existantes. Il ne prescrit aucune implémentation :
> les propositions du §7 sont là pour être **contredites**, pas exécutées telles quelles.
>
> Endpoints et sorties réelles : [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md) · [`api-ffbb-app-traces.md`](api-ffbb-app-traces.md)

---

## 1. Le principe, avant tout le reste

> **« Le gestionnaire est capable d'associer ou pas les infos et de valider si ça correspond à la situation
> réelle ou non. On l'accompagne, on ne décide pas pour lui. »**

Tout ce qui suit en découle. La FFBB **propose**, l'app **présente**, le gestionnaire **tranche**. Trois
conséquences qu'on ne négociera pas :

1. **Aucun écrasement silencieux.** Une donnée FFBB qui contredit une donnée saisie se **montre** comme un
   écart à arbitrer. Jamais un `UPDATE` discret. *(P2-18 (c) le disait déjà pour le club — on l'étend à tout.)*
2. **Le refus est un état légitime, pas une erreur.** « Cette équipe FFBB ne correspond à aucune des miennes »
   doit être **persistable**, sinon on repose la question à chaque synchro et le gestionnaire finit par
   accepter n'importe quoi pour avoir la paix.
3. **L'appariement est une donnée, pas un calcul.** Même décision que P4-35 (import d'équipes) : une
   **correspondance explicite et persistée**, pas une clé naturelle devinée sur les noms.

⚠ **Le contre-exemple à garder en tête** est dans notre propre historique : l'import FFBB actuel
(`FfbbExcelImporter`) devine par clé naturelle et se trompe — c'est exactement P4-35. Ne pas refaire.

---

## 2. Ce que la FFBB sait, ce que nous savons

| Objet | La FFBB en est la source | Nous en sommes la source |
|---|---|---|
| Identité du club, logo, adresse, ligue, comité | ✅ **entièrement** | — |
| Équipes **engagées** en compétition + leur niveau | ✅ | le **nom d'usage** interne (« SM3 »), le rang, les séances/semaine |
| Compétition, poule, phase, saison | ✅ | — |
| Calendrier des rencontres | ❌ *(index de test — voir §5)* | import FBI |
| Créneaux, gymnases, coachs, contraintes | — | ✅ **entièrement** |
| Équipes **non engagées** (loisir, baby, académie) | ❌ elles n'existent pas pour la FFBB | ✅ |

⚑ **La dernière ligne est structurante.** Sur BCCL, la FFBB connaît **14 équipes engagées** quand l'app en
gère bien davantage (Baby 1/2, Micro Basket, Académies, Loisir…). Un appariement qui supposerait une
bijection casserait sur la moitié du club. **Le sens est : FFBB ⊂ app.**

---

## 3. Les objets à apparier, un par un

### 3.1 Le club — lecture seule, rafraîchi

**« Les informations de club ne sont pas en saisie manuelle mais proviennent de l'API. »**

Déjà à moitié en place : `FfbbClubPopulator` remplit à la création (lot C). Ce qui manque est le
**rafraîchissement**, c'est-à-dire P2-18 (a). La reconnaissance ne change pas ce cadrage — elle le confirme et
lui donne sa source : `ffbbserver_organismes`, déjà interrogé par `FfbbApiClient::search`.

**Conséquence produit à trancher** : si le club est en lecture seule, les champs correspondants du formulaire
`/club` deviennent **non éditables**, avec le motif affiché (« vient de la FFBB, resynchronisez pour mettre à
jour »). Sinon on garde deux vérités et on retombe sur l'écrasement silencieux.

### 3.2 La ligue et le comité — à rafraîchir aussi

`codeLigue` / `nomLigue` / `codeComite` / `nomComite` arrivent sur **chaque engagement** et sur l'organisme.
Le modèle existe (`FfbbLeague` / `FfbbCommittee`, tables partagées hors RLS, cache-first).

⚠ **Ce sont des tables GLOBALES** : un rafraîchissement déclenché par un club met à jour une ligne que **tous
les clubs** lisent. Le geste est donc légitime mais son effet dépasse le locataire — à traiter comme tel
(idempotence, traçabilité), pas comme une écriture tenant ordinaire.

### 3.3 Les équipes engagées — le gain d'onboarding

**« Pour l'onboarding la ligue connaît déjà les équipes du club, donc c'est un énorme gain de temps. »**

Mesuré : 14 engagements pour BCCL, chacun avec catégorie, sexe, niveau, numéro d'équipe, **logo**, compétition
et poule. Aujourd'hui le gestionnaire saisit tout cela à la main dans l'étape Équipes du wizard.

**Ce n'est pas un import, c'est une proposition à valider.** Le gestionnaire doit pouvoir, ligne à ligne :
apparier à une équipe existante · créer l'équipe depuis la proposition · **ignorer** (et que l'ignorance
tienne). Le nom d'usage reste le sien — la FFBB dit « Pré nationale féminine », le club dit « SF1 ».

### 3.4 L'équipe ↔ la compétition — et le fait qu'elle bouge

**« Une équipe est donc liée à une compétition, cette compétition peut changer dans le temps car il y a des
changements de poule ou autre en fonction des championnats. »**

C'est **le** point de conception, et l'API le rend observable :

- l'engagement porte `idCompetition {id, nom, code}` et `idPoule {id, nom}` — **ids FFBB stables** ;
- la compétition porte `saison {code}`, `phases[]`, `typeCompetition`, et **`poules[]` avec la liste des
  engagements de chaque poule** ;
- sur BCCL, **6 des 14 engagements sont des « Brassage »** (RMU13/15/18, RFU13/15/18) — la phase de brassage
  est une **compétition distincte** du championnat qui suivra.

⚑ **Donc la « géométrie variable » de [P1-4 (5)](roadmap.md) — brassage → poule 1 → poule 2 — n'est pas à
inventer : elle est déjà modélisée côté FFBB.** Ce que l'app doit tenir, c'est le **lien qui dure** quand la
compétition change.

**Proposition (à contredire)** : l'appariement stable est **équipe ↔ engagement**, pas équipe ↔ compétition.
L'engagement est ce que la FFBB rattache au club ; la compétition et la poule sont des **attributs datés** de
cet engagement. Quand la poule change, c'est l'attribut qui bouge, pas l'appariement — et le gestionnaire n'a
rien à re-valider.
→ Croise directement l'entité **`Competition / Phase`** esquissée en [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §9,
qui devra distinguer ces deux niveaux.

### 3.5 Les salles — piste, non tranchée

`ffbbserver_salles` porte 5 000+ salles (libellé, adresse, capacité, géoloc) mais **n'est pas indexée par
club** : 0 hit sur `ARA0069036`. Utilisable pour l'**adversaire** (le « gymnase du Clar » de
`gestion-matchs-ffbb.md` §5bis) plutôt que pour nos propres gymnases, que le gestionnaire saisit déjà.
**Aucune urgence** — noté pour ne pas le redécouvrir.

---

## 4. La saison — hypothèse fondateur, et ce que l'API en dit

**Hypothèse posée** : *« pour les rencontres c'est la saison en cours, et en fin de saison la ligue détruit
les infos. »*

Ce qu'on a mesuré est **cohérent** : les 14 engagements de BCCL résolvent tous en `saison.code = "26-27"`.
Rien de 25-26 ne traîne.

⚠ **Mais « cohérent » n'est pas « vérifié ».** Une mesure à un instant ne prouve pas une politique de purge.
Deux garde-fous à retenir dans la conception :

1. **Toujours lire la saison, ne jamais la supposer.** La jointure `idCompetition.id →
   competitions.saison.code` coûte une requête. Si l'hypothèse tombe (la FFBB conserve l'historique), un code
   qui filtre explicitement continue de marcher ; un code qui suppose « tout est courant » importe des
   fantômes.
2. **Notre saison n'est pas la leur.** Nous avons un pivot au 15 juillet (`SeasonResolver`) et un code
   `26-27` côté FFBB. La correspondance est à poser une fois, pas à chaque appel.

---

## 5. Les rencontres — ce que ça ne débloque pas

L'index `ffbbserver_rencontres` est un **index de test** (31 documents nationaux, « FFBB - CLUB SUPPORT - DTN »
contre lui-même, `joue: false`). **L'import FBI reste le chemin** — la ligne « ✅ Livré palier A PR-4 sur
FORMAT SUPPOSÉ » de [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §5 reste donc à fiabiliser sur un vrai
fichier, comme P1-4 (1) le dit.

**Mais l'appariement Division ↔ équipe change de nature.** P1-4 (2) demandait une correspondance explicite et
persistée ; elle peut désormais s'**ancrer sur l'`idCompetition` FFBB** plutôt que sur le libellé « Division »
d'un fichier Excel. Un ré-import retrouve son équipe par un id stable, pas par une chaîne de caractères.

---

## 6. Traçabilité — ce que ce besoin change, spec par spec

| Spec / ligne | Ce que ce cadrage y ajoute |
|---|---|
| **P2-18** (resynchro FFBB) | Passe de « bouton à câbler » à **« écran d'arbitrage »** : club en lecture seule, ligue/comité globaux, équipes proposées ligne à ligne avec refus persistable |
| **P1-4 (2)** (Division ↔ équipes) | L'ancrage devient l'**`idCompetition` FFBB**, pas le libellé Excel |
| **P1-4 (5)** (géométrie variable) | Brassage/poules **déjà modélisés côté FFBB** — à lire, pas à inventer |
| [`gestion-matchs-ffbb.md`](gestion-matchs-ffbb.md) §9 | L'entité `Competition / Phase` doit **séparer l'engagement (stable) de la compétition (datée)** |
| [`enregistrement-ffbb.md`](enregistrement-ffbb.md) | L'onboarding peut **proposer les équipes** dès la vérification du code club |
| **P4-35** (import d'équipes sans identité) | Même remède, même endroit : correspondance explicite persistée |
| **P3-7** | Redevient atteignable par l'UI une fois l'écran d'arbitrage posé |
| [`backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) | Catalogue à étendre quand un index de plus sera réellement appelé |

---

## 7. Force de proposition — trois briques, dans cet ordre

> À **valider ou refuser** avant tout plan. Chacune est indépendante et livrable seule.

**Brique 1 — `FfbbEngagement`, lecture + appariement.** Une entité tenant-scopée : `clubId`, `seasonId`,
`ffbbEngagementId` (l'id stable), `teamId` **nullable**, `status` ∈ `{à arbitrer, apparié, ignoré}`, plus la
photo des attributs FFBB (compétition, poule, niveau, catégorie, sexe). Le `null` + `ignoré` **est** le refus
persistable du §1.2. Alimentée par une requête filtrée sur `codeClub`.

**Brique 2 — l'écran d'arbitrage.** Une liste : à gauche ce que dit la FFBB, à droite mon équipe, au milieu
trois gestes (apparier / créer / ignorer). Réutilise `team-select` (le picker rang-trié de l'app) et
`DeleteConfirm` pour tout geste destructif. **C'est le même écran** pour l'onboarding et pour la resynchro —
seule la population initiale change.

**Brique 3 — la resynchro comme *delta*, jamais comme *écrasement*.** Rejouer la requête, comparer à la
photo, ne présenter **que ce qui a bougé** : nouvelle équipe engagée, poule changée, niveau changé, engagement
disparu. Un écran qui dit « 2 changements » emporte l'adhésion ; un écran qui redemande 14 arbitrages à chaque
fois se fait ignorer.

⚠ **Ce qu'il ne faut PAS faire, et qui sera tentant** : brancher la resynchro sur un cron. Le principe du §1
l'interdit — une synchro automatique **décide** à la place du gestionnaire. Le déclencheur reste un geste.

---

## 8. À trancher avant tout plan

1. **Le club en lecture seule : jusqu'où ?** Nom, logo, adresse ? Le gestionnaire peut-il surcharger un nom
   d'usage ? *(Le fondateur a dit « pas de saisie manuelle » — reste à dire ce que ça couvre exactement.)*
2. **L'ignorance a-t-elle une durée ?** Une équipe ignorée cette saison revient-elle à arbitrer la suivante ?
3. **Une équipe app peut-elle porter plusieurs engagements ?** Une même équipe en championnat **et** en coupe
   est plausible — la brique 1 suppose 1↔1, à confirmer.
4. **Que fait-on d'un engagement disparu** en cours de saison (forfait général) ? L'équipe reste, son
   engagement s'éteint — avec quel effet sur les matchs déjà importés ?
5. **La correspondance saison FFBB ↔ saison app** : `26-27` ↔ notre pivot 15 juillet, posée où ?

## 9. Réserves héritées de P2-19

Licéité (aucune CGU lue), quotas inconnus, et **aucun index de licenciés n'a été cherché ni interrogé**.
Un appel récurrent à la FFBB engage plus qu'une reconnaissance ponctuelle : à re-poser avant la brique 3.

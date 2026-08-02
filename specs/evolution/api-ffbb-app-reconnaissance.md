# Reconnaissance de l'API FFBB — P2-19

> **Livrable de cadrage**, pas du code. Alimente [P1-4](roadmap.md) (module matchs) et P2-18 (resynchronisation FFBB).
> Mesures faites le **2026-08-02** sur le club réel **`ARA0069036`** (B Charpennes Croix Luizet).
> Lectures seules, une requête par index. **Aucun jeton n'est reproduit ici** : ils sont publics mais rotatifs, et un secret recopié dans un dépôt y reste.

---

## 1. Verdict en trois lignes

1. **`api.ffbb.app` n'est pas une nouvelle API.** C'est **la même instance Directus** que `api.ffbb.com`, que l'app interroge déjà depuis le lot C. Son OAS ne documente que du CMS : aucune route rencontres, équipes, salles ou engagements.
2. **Le gisement réel est Meilisearch**, avec **le jeton que `FfbbApiClient` récupère déjà**. L'app n'exploite **1 index sur 6** accessibles.
3. **Les rencontres ne sont PAS récupérables.** L'index `ffbbserver_rencontres` contient **31 documents de test FFBB** au niveau national. **P1-4 reste sur l'import FBI.**

---

## 2. Conditions d'accès — l'intel est exacte, la conclusion non

| Point | Vérifié | Résultat |
|---|---|---|
| `User-Agent: okhttp/4.12.0` obligatoire | ✅ | Sans lui `GET https://api.ffbb.app/items/configuration` → **403**. Avec → **200**. |
| OAS publique sur `/server/specs/oas` | ✅ | **200**, 21 Ko. |
| Bearer via `/items/configuration` → `data.key_dh` | ⚠️ **oui, mais inutile** | La clé existe (32 car.). Elle **n'ouvre rien** : `/collections` répond **403 avec comme sans** elle. |

⚑ **`api.ffbb.app` et `api.ffbb.com` servent le même document.** Payload `/items/configuration` **identique au bit près**, `key_dh` et `key_ms` compris. Le champ `servers` de l'OAS le dit lui-même : `https://api.ffbb.com/` — « Your current Directus instance ».

⚑ **Conséquence de conception : le « vrai sujet » annoncé par la roadmap n'existe pas.** La ligne P2-19 prévenait que « le token étant obtenu dynamiquement, sa gestion (cache, rotation, échec) est un vrai sujet de conception ». Ce mécanisme **est livré depuis le lot C** : `FfbbApiClient::token()` lit `/items/configuration`, met en cache en mémoire, et **refetch une fois sur 401** (rotation). Il lit `key_ms` au lieu de `key_dh` — et c'est `key_ms` qui sert.

> ⚠️ `date_updated` du document de configuration valait **le jour même** de la mesure : les jetons **tournent**. Le refetch-sur-401 existant est donc la bonne réponse ; un cache à durée fixe (« ~12 h ») serait **moins** sûr.

---

## 3. Route par route — l'OAS Directus (14 chemins)

`GET https://api.ffbb.app/server/specs/oas` · OpenAPI 3.0.1 · *« Dynamic API Specification »*.

| Chemin | Verbes | Ce que ça vaut pour nous |
|---|---|---|
| `/items/configuration`, `/items/configuration/{id}` | GET | **Déjà utilisé.** Source du jeton Meilisearch. Une seule collection exposée. |
| `/assets/{id}` | GET | **Déjà utilisé** — `FfbbLogoFetcher` (`ASSET_BASE`). C'est par là que passent logos de clubs et d'équipes. |
| `/files`, `/files/{id}` | GET | Métadonnées des mêmes assets. Sans usage identifié. |
| `/auth/login`, `/auth/logout`, `/auth/refresh`, `/auth/password/request`, `/auth/password/reset`, `/auth/oauth`, `/auth/oauth/{provider}` | POST/GET | Authentification **Directus**, pour des comptes que nous n'avons pas. **Sans objet.** |
| `/server/info`, `/server/ping` | GET | Santé. `/server/info` répond 200 sans jeton. Éventuellement une sonde `AdminHealthService`. |

**Aucune donnée de compétition n'est exposée ici.** La prémisse « lire l'OAS et inventorier les rencontres/équipes/salles/engagements » ne peut pas être satisfaite par ce document.

---

## 4. Route par route — Meilisearch, là où vivent les données

`POST https://meilisearch-prod.ffbb.app/multi-search` · `Authorization: Bearer <key_ms>` — **le jeton déjà en place**.
La clé est **search-only** : `GET /indexes` répond 403, les index se découvrent en les interrogeant.

| Index | Total | Hits `ARA0069036` | Verdict |
|---|---:|---:|---|
| `ffbbserver_organismes` | 4 635 | 69 | **Déjà exploité** (`FfbbApiClient::search`) |
| **`ffbbserver_engagements`** | ≥ 5 000 | **283 → 14 réels** | 🟢 **LE gisement** — voir §5 |
| `ffbbserver_competitions` | 425 | 0 | 🟡 Référentiel compétitions (code, catégorie, poules, phases) |
| `ffbbserver_salles` | ≥ 5 000 | 0 | 🟡 Salles nationales — non indexées par club |
| `ffbbserver_terrains` | ≥ 5 000 | 0 | ⚪ Nature du sol, dimensions. Sans usage produit identifié |
| **`ffbbserver_rencontres`** | **31** | **0** | 🔴 **Index de TEST** — voir §6 |
| `ffbbserver_equipes`, `ffbbserver_pratiques` | — | — | ❌ `Index not found` |

> ⚠️ **Piège de lecture, mesuré ici même** : `estimatedTotalHits = 283` pour la requête `ARA0069036` sur les engagements, mais **14 documents seulement portent réellement `codeClub == "ARA0069036"`**. Les 269 autres matchent en plein texte sur d'autres champs (`competitionsUrl` contient le chemin du club, les adversaires partagent la compétition). **Toute intégration devra filtrer sur le champ, jamais se fier au score de pertinence.**

---

## 5. `ffbbserver_engagements` — ce que ça retire de saisie, fait par fait

Un document = **une équipe engagée dans une compétition**. Les 14 engagements réels de BCCL :

```
 n°     cat.  sexe     | compétition                          | poule
 —      —     —        | RFU13 Brassage                       | Poule A
 —      —     —        | RFU15 Brassage                       | Poule A
 —      —     —        | RFU18 Brassage                       | Poule A
 —      —     —        | RMU13 Brassage                       | Poule A
 —      —     —        | RMU15 Brassage                       | Poule A
 —      —     —        | RMU18 Brassage                       | Poule A
 —      —     —        | Régionale masculine U21              | Poule C
 n°3    SE    Masculin | Pré régionale masculine              | Poule B2
 n°3    SE    Féminin  | Départementale féminine seniors      | Poule A
 n°4    SE    Masculin | Départementale masculine seniors     | Poule C1
 n°1    SE    Féminin  | Pré nationale féminine               | Poule E
 —      SE    Masculin | Pré nationale masculine              | Poule D
 n°2    SE    Féminin  | Régionale féminine seniors           | Poule F
 n°2    SE    Masculin | Régionale masculine seniors          | Poule G
```

Champs utiles (28 au total) : `codeClub` · `nomEquipe` · `numeroEquipe` · `categorie {code, libelle, ordre}` · `age` · `sexe` · `niveau {code, libelle, ordre}` · **`idCompetition {id, nom, code, slug, sexe, categorie}`** · **`idPoule {id, nom}`** · `logo` + `thumbnail` · `codeComite` / `codeLigue` + leurs noms.

**Ce que ça retire au gestionnaire :**

| Aujourd'hui saisi à la main | L'API le donne | Pour quel lot |
|---|---|---|
| **Correspondance Division ↔ équipe** | `idCompetition` + `idPoule` par engagement, avec des **ids stables** | **P1-4 (2)** — c'est exactement l'appariement explicite et persisté qu'il demande, et il a un ancrage FFBB plutôt qu'une clé naturelle |
| Le **nombre et le niveau** des équipes engagées | 14 lignes, catégorie + niveau + n° d'équipe | **P2-18** — « resynchroniser les équipes » |
| Le **logo d'équipe** | `logo` / `thumbnail` par engagement | **P2-18 (b)** — « synchro à la création d'une équipe » |
| Ligue et comité de rattachement | `codeLigue` / `codeComite` + noms | Déjà partiellement couvert par `FfbbClubPopulator` |

⚠️ **La limite dure : aucun champ `saison`.** Le document d'engagement n'en porte pas (vérifié sur les 283 résultats). Impossible de distinguer un engagement 25-26 d'un 26-27 depuis cet index seul. **À lever avant de câbler quoi que ce soit** — sans quoi une resynchronisation ramènerait des équipes de saisons mortes.

---

## 6. `ffbbserver_rencontres` — la réponse qui ferme P1-4

**31 documents pour la France entière.** Les trois échantillonnés :

```
saison 26-27 | FFBB - CLUB SUPPORT - DTN  vs  FFBB - CLUB SUPPORT - DTN
             | 2026-07-25T16:45  | GYMNASE BASE DE PLEIN AIR | joue: false
             | 2026-07-28T17:45  | idem                      | joue: false
             | 2026-07-30T17:45  | idem                      | joue: false
```

Même équipe des deux côtés, `joue: false`, libellé « CLUB SUPPORT - DTN » : **c'est un index de test interne à la FFBB**, pas les calendriers.

Le schéma, lui, est riche et cohérent (36 champs : `date_rencontre`, `idOrganismeEquipe1/2`, `idEngagementEquipe1/2`, `idPoule`, `salle`, `resultatEquipe1/2`, `numeroJournee`, `officiels`…) — **il est prêt, il n'est pas rempli**.

### Conséquences, tranchées

- 🔴 **P1-4 reste un lot d'IMPORT FBI.** La mention « dépend de P2-19 pour ce qui peut être récupéré plutôt que saisi » se solde par : **rien des rencontres ne se récupère**. Le lot ne rétrécit pas.
- 🟢 **P1-4 (2) gagne quand même** : l'appariement Division ↔ équipe peut s'appuyer sur `idCompetition`/`idPoule`, au lieu d'être entièrement saisi.
- 🟡 **À re-tester avant P1-4**, pas avant : l'index pourrait se remplir à l'ouverture de la saison. Le test est d'une requête. **Ne pas construire dessus sur la foi de ce schéma.**

---

## 7. Ce que ce cadrage NE dit pas

- **La licéité de l'usage.** Endpoints publics et non authentifiés, mais aucune CGU n'a été lue. À vérifier avant d'industrialiser un appel récurrent.
- **Les quotas.** Aucun en-tête de rate-limit observé, sur un volume d'une dizaine de requêtes. Inconnu à l'échelle.
- **Les licenciés.** Aucun index de ce type n'a été cherché ni interrogé : donnée personnelle, hors périmètre produit et hors RGPD tel qu'on l'a posé.
- **La volumétrie réelle** de `salles`/`engagements`/`terrains` : `estimatedTotalHits` plafonne à 5 000, ce n'est pas un compte.

## 8. Suites concrètes

| # | Action | Pour |
|---|---|---|
| 1 | **Lever la question `saison`** sur les engagements (autre index ? filtre Meilisearch ? `idCompetition.slug` ?) | Bloquant pour P2-18 |
| 2 | Étendre `FfbbApiClient` à `ffbbserver_engagements`, **en filtrant sur `codeClub`** et jamais sur la pertinence | P2-18, P1-4 (2) |
| 3 | Garder le confinement SSRF tel quel — les deux hôtes restent en dur, aucun dérivé d'input | Sécurité |
| 4 | Re-tester `ffbbserver_rencontres` **au moment d'attaquer P1-4** | P1-4 |

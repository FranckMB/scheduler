# Complétion automatique des informations club — les deux sources FFBB, champ par champ

> **Livrable de cadrage** (demande fondateur 2026-08-04), sœur de
> [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md) (P2-19, qui couvrait engagements/
> compétitions/rencontres). Ici : **tout ce qui peut alimenter la fiche club** — l'écran « Informations
> du club » et « Contacts FFBB » (`frontend/src/features/club/ClubPage.tsx`).
> Mesures faites le **2026-08-04** sur le club réel `ARA0069036` (BCCL), une requête par index.
> **Aucun jeton reproduit ici** (publics mais rotatifs).

---

## 0. Cadrage des « deux sources » — une précision factuelle d'abord

L'hypothèse de départ (« FFBB = officielle, meilisearch = communautaire ») ne correspond pas à ce qu'on
mesure : **`meilisearch-prod.ffbb.app` est l'infrastructure de la FFBB elle-même**. Sa clé d'accès
(`key_ms`) est servie par le propre document de configuration de la fédération
(`GET https://api.ffbb.com/items/configuration`), et c'est le moteur de recherche de l'app mobile et de
`competitions.ffbb.com`. Les deux sources sont donc **officielles**, mais de natures différentes :

| Source | Nature | Ce qu'elle donne |
|---|---|---|
| `api.ffbb.com` (Directus) | CMS : configuration + assets | Le **jeton** Meilisearch, et les **logos** (`/assets/{uuid}`). Rien d'autre : `/collections` répond 403 avec chacun des jetons servis (`key_dh`, `key_directus_website` — re-testé ce jour, `/users/me` 200 mais aucune collection ouverte ; `key_directus_competitions` vaut `null`). |
| `meilisearch-prod.ffbb.app` | Moteur de recherche des données fédérales | **Toutes les données** : organismes, engagements, compétitions, salles, terrains. |

La complémentarité réelle n'est pas « officiel vs communautaire », c'est **« porte d'entrée vs
gisement »** — et les deux sont déjà dans la liste blanche SSRF de `FfbbApiClient`.

---

## 1. `ffbbserver_organismes` — le document CLUB, ligne par ligne

Hit complet pour `ARA0069036` (extrait intégral des champs, valeurs réelles) :

```json
{
  "id": "11104",
  "code": "ARA0069036",
  "nom": "B CHARPENNES CROIX LUIZET",
  "nom_simple": null,
  "type": "Groupement",
  "type_association": { "code": "K", "libelle": "Club" },
  "adresse": "5 RUE EMILE DUNIERE",
  "commune": { "libelle": "VILLEURBANNE", "codePostal": "69100", "departement": "Rhône" },
  "cartographie": { "adresse": "Rue Émile Dunière", "codePostal": "69100", "ville": "Villeurbanne",
                    "latitude": 45.78017, "longitude": 4.88467, "id": "G-11104", "...": "…" },
  "_geo": { "lat": 45.78017, "lng": 4.88467 },
  "telephone": "0643720140",
  "mail": "contact@bccl.fr",
  "urlSiteWeb": "https://www.villeurbannesharks.fr",
  "logo": { "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1", "gradient_color": "#c9102e" },
  "thumbnail": "https://api.ffbb.com/assets/ad8d7110-…?height=220&format=avif",
  "labellisation": [ "Label FFBB Citoyen MAIF 2 étoiles", "EFMB3" ],
  "offresPratiques": [ "Compétition 3x3", "Compétition 5x5", "Compétition MiniBasket", "Loisir 3x3", "Loisir 5x5" ],
  "engagements_codes": "PRM | Pré régionale masculine|PRF | …  (~100 libellés de compétitions)",
  "engagements_noms": "B CHARPENNES CROIX LUIZET",
  "organisme_id_pere": { "id": "2093", "nom": "COMITE DU RHONE…", "adresse": "3 RUE DU COLONEL CHAMBONNET",
                         "code": "0069", "nom_simple": "RHONE ET METROPOLE DE LYON", "type": "Comité",
                         "organisme_id_pere": { "id": "200000002677104", "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES…",
                                                "code": "ARA", "type": "L" },
                         "ligueCode": "200000002677104" },
  "url_competition": "/ligues/ara/comites/0069/clubs/ara0069036",
  "saison": null, "saison_en_cours": true, "dateAffiliation": null,
  "adresseClubPro": null, "communeClubPro": null, "nomClubPro": "", "entreprise?": "…"
}
```

### Lecture champ par champ

| Champ | Exploité aujourd'hui ? | Verdict pour la complétion |
|---|---|---|
| `code` | ✅ `Club.ffbbClubCode` | — |
| `nom` | ✅ `Club.name` (FFBB fait autorité) | — |
| `adresse` | ✅ `Club.address` | — |
| `commune.codePostal` / `commune.libelle` | ✅ `Club.postalCode` / `Club.city` | — |
| `commune.departement` | ❌ | 🟢 **Nouveau** : « Rhône » — utile en affichage ; PAS pour la zone de vacances (déjà dérivée du CP, plus fiable) |
| `telephone` / `mail` / `urlSiteWeb` | ✅ `contactPhone` / `contactEmail` / `website` | — |
| `logo.id` | ✅ logo réhébergé | — |
| `logo.gradient_color` | ❌ | 🟢 **Nouveau** : la couleur dominante du logo selon la FFBB (`#c9102e` pour BCCL) — candidate par défaut pour l'**accent** du club au register, avant même l'extraction de palette côté client |
| `_geo` / `cartographie.latitude/longitude` | ❌ | 🟡 GPS du club. Sans usage produit immédiat (les trajets P1-4 utilisent les salles, pas le siège) |
| `labellisation` | ❌ | 🟡 Affichage valorisant (« Label FFBB Citoyen »), zéro saisie évitée |
| `offresPratiques` | ❌ | 🟡 Peut PRÉ-COCHER l'univers du club (MiniBasket → tags BABY/EMB attendus) — spéculatif, à ne pas faire sans besoin |
| `engagements_codes` | ❌ | ⚪ Libellés bruts multi-saisons (brassages, phases 2/3, coupes) — **l'index `engagements` structuré fait mieux** (recon P2-19 §5), ne rien construire sur cette chaîne |
| `type` / `type_association` | ❌ | ⚪ « Groupement »/« Club » — sans objet |
| `organisme_id_pere` (2 niveaux) | ✅ comité + ligue | cf. §2 |
| `url_competition` | ❌ | 🟡 Lien profond vers la fiche publique du club sur competitions.ffbb.com — affichable tel quel |
| `saison`, `dateAffiliation`, `nom_simple`, `*ClubPro` | ❌ | ⚪ NULL/vides sur le terrain — rien à en tirer |

### Ce que le document ne contient PAS (vérifié sur l'intégralité des clés)

- **Aucun champ président / correspondant / secrétaire** (personne physique). La confirmation ligne par
  ligne de ce que le lot C affirmait : ces trois blocs de l'écran « Informations du club » ne seront
  **jamais** remplis par l'API — ils restent de la saisie manuelle.
- **Aucun lien club → salles.** `salle.libelle` est déclaré filtrable sur l'index, mais
  `filter: "salle.libelle EXISTS"` → **0 document** sur 4 635 : le champ n'est jamais rempli. La salle
  principale ne peut pas être déduite du document club (voir §3 pour le contournement).

### Filtrables (relevé complet, message d'erreur Meilisearch)

`_geo` · `commune.codePostal|departement|libelle` · `communeClubPro.*` · `competitionId.categorie.code` ·
`entreprise` · `handibasket` · `horsAssociation` · `labellisation` · `offresPratiques` · `omnisport` ·
**`organisme_id_pere.code|nom|type`** · `saison_en_cours` · `salle.libelle` · `type` · `type_association.code|libelle`.

⚑ **`organisme_id_pere.code` est filtrable** : la résolution du comité complet peut se faire par
`filter: "organisme_id_pere.code = ARA" AND type = Comité`-style au lieu de la recherche plein texte du
lot C — moins de faux hits. `code` lui-même ne l'est PAS (résolution des parents toujours par `q=` + tri).

---

## 2. Comité et ligue — documents complets, deux champs de plus que ce qu'on stocke

Le 2ᵉ `multi-search` (déjà en place au lot C) rend pour le comité `0069` et la ligue `ARA` des documents
de même forme que le club. Valeurs réelles :

```json
// Comité 0069
{ "nom": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL", "code": "0069",
  "adresse": "3 RUE DU COLONEL CHAMBONNET", "commune": { "libelle": "BRON", "codePostal": "69500" },
  "telephone": "0478740634", "mail": "cdrbb@basketrhone.com",
  "urlSiteWeb": "http://www.basketrhone.com", "logo": { "id": "b0be226e-…" },
  "nom_simple": "RHONE ET METROPOLE DE LYON", "url_competition": "/ligues/ara/comites/0069" }

// Ligue ARA
{ "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL", "code": "ARA",
  "adresse": "3 AVENUE COLONEL CHAMBONNET", "commune": { "libelle": "BRON", "codePostal": "69500" },
  "telephone": "0977423620", "mail": "secretariat@aurabasketball.com",
  "urlSiteWeb": "https://www.aurabasketball.com ", "logo": { "id": "4e73cd36-…" },
  "nom_simple": "AUVERGNE-RHÔNE-ALPES" }
```

| Champ | Stocké (`FfbbCommittee`/`FfbbLeague`) ? | Verdict |
|---|---|---|
| `nom`, `adresse`, CP, ville, `telephone`, `mail`, logo | ✅ | Déjà complet |
| **`urlSiteWeb`** | ❌ | 🟢 **Nouveau** : le site du comité ET de la ligue existent — le bloc UI `ContactBlock` sait déjà afficher un `website`, seul le club en passe un. Une colonne + un mapping et c'est réglé. ⚠ Trim : la ligue rend `"…com "` avec une espace finale |
| `nom_simple` | ❌ | 🟡 Version courte (« RHONE ET METROPOLE DE LYON ») — plus lisible dans un bloc étroit que la raison sociale complète |
| `url_competition` | ❌ | 🟡 Lien profond vers la fiche publique |

---

## 3. `ffbbserver_salles` — le gisement que la fiche club ne pointe pas

L'index des salles n'est **pas relié aux clubs** (ni `codeClub`, ni organisme), mais il est **filtrable
par code postal** — et ça suffit pour de la complétion. Mesure sur `commune.codePostal = 69100`
(Villeurbanne) : **17 salles**, dont, au 5 BIS de la rue du siège de BCCL :

```json
{ "id": "1000000314", "libelle": "ASTROBALLE", "adresse": "40  Avenue Marcel Cerdan",
  "adresseComplement": "", "capaciteSpectateur": "", "telephone": "0472141670", "mail": "",
  "numero": "166926604",
  "cartographie": { "codePostal": "69100", "ville": "Villeurbanne", "latitude": 45.76672, "longitude": 4.9076 },
  "commune": { "libelle": "VILLEURBANNE", "codePostal": "69100", "departement": "Rhône" },
  "type": "Salle" }
```

Les 17 libellés : ASTROBALLE · COMPLEXE SPORTIF DES BROSSES · SALLE ANNEXE DE BARROS · SALLE SAINT JEAN ·
GYMNASE JEANNE DESPARMET-RUELLO · GYMNASE ALEXANDRA DAVID NEEL · GYMNASE MOULIN DE TONKIN · GYMNASE JEAN
MOLLIER · SALLE RAPHAEL DE BARROS · SALLE ALBERT CAMUS · SALLE LEON JOUHAUX · SALLE EUGENE FOURNIERE ·
GYMNASE ARMAND · GYMNASE JEAN VILAR · SALLE DE CUSSET · SALLE DES IRIS · **GYMNASE MATEO (5 BIS RUE EMILE
DUNIERE — l'adresse du club)**.

**Ce que ça permet** :
- 🟢 **Autocomplétion « Salle principale »** de la fiche club : proposer les salles du CP du club (nom +
  adresse pré-remplis) au lieu de deux champs texte libres.
- 🟢 **Autocomplétion des GYMNASES du wizard** (étape 2) : mêmes données — nom officiel, adresse,
  téléphone, GPS. La saisie d'un gymnase deviendrait « choisir dans la liste de sa commune », le nom
  libre restant possible.
- Champs disponibles par salle : `libelle`, `libelle2`, `adresse`, `adresseComplement`,
  `capaciteSpectateur` (souvent vide), `telephone`, `mail`, `numero` (identifiant fédéral), GPS.
- Filtrables : `_geo` (recherche par rayon possible !), `commune.codePostal|departement|libelle`, `type`.

`ffbbserver_terrains` (extérieurs) : `nom`, `rue`, `largeur`/`longueur`, `natureSol` (BITUME…),
`accesLibre`, GPS. ⚪ Sans usage produit — nos créneaux vivent en salle.

---

## 4. Rappel des autres index (couverts par la recon P2-19 — ne pas dupliquer)

`ffbbserver_engagements` (équipes engagées : catégorie/niveau/n° + `idCompetition`/`idPoule`, filtre
STRICT sur `codeClub` obligatoire) et `ffbbserver_competitions` (saison, phases, poules avec la liste des
clubs) alimentent déjà l'appariement P1-4 PR F. `ffbbserver_rencontres` reste un index de test (re-vérifié
2026-08-03). Détail : [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md).

---

## 5. Synthèse — ce qui peut devenir automatique sur la fiche club

> **Livré le 2026-08-04 (lots A+B)** : lecture seule des champs FFBB + refus serveur (422) + bouton
> « Actualiser depuis la FFBB », bloc « Club » retiré des Contacts FFBB, `website` comité/ligue stocké
> et affiché. **Reste ouvert : l'autocomplétion salles (roadmap P2-20).**

| Bloc écran actuel | Aujourd'hui | Après complétion |
|---|---|---|
| Identité (code, ligue, zone) | lecture seule | inchangé |
| Comité | champ texte ÉDITABLE | 🟢 lecture seule (dérivé du code club, déjà en base) |
| Contact du club (tél/email/adresse) | ÉDITABLE | 🟢 lecture seule — l'API fait autorité (elle remplit déjà ces champs au register et au ré-import) |
| Correspondant / Président | éditable | **reste éditable** — l'API ne connaît pas les personnes physiques (vérifié §1) |
| Salle principale | 2 champs texte libres | 🟢 autocomplétion depuis `ffbbserver_salles` (CP du club) — éditable, mais proposé |
| Bloc « Club » de Contacts FFBB | duplique la fiche club | 🔴 à retirer — la section n'a de sens que pour la hiérarchie AU-DESSUS (comité, ligue) |
| Comité/Ligue de Contacts FFBB | sans site web | 🟢 + `urlSiteWeb` (nouvelle donnée, §2) |
| Accent du club | choisi à la main / palette du logo | 🟡 `logo.gradient_color` en défaut de register |

**Aucun de ces points n'exige un nouveau host ni un nouveau jeton** : tout passe par `FfbbApiClient`
tel quel (mêmes deux hosts en dur, même `key_ms`, mêmes règles SSRF).

## 6. Ce que ce cadrage ne dit pas

Identique à la recon P2-19 §7 : licéité d'un appel récurrent non vérifiée (ici tout reste du
« à la demande » ou du register, comme le lot C), quotas inconnus, licenciés hors périmètre.

# Traces brutes de la reconnaissance API FFBB — P2-19

> Annexe de [`api-ffbb-app-reconnaissance.md`](api-ffbb-app-reconnaissance.md), qui porte l'analyse.
> **Ici, la sortie réelle de l'API**, capturée le **2026-08-02** pour le club **`ARA0069036`** (B Charpennes Croix Luizet).
> Elle existe pour qu'on puisse relire chaque route sans refaire les appels, et vérifier ce que le rapport affirme.
>
> **Aucun jeton n'est reproduit.** Seuls `rematch_videos` et `compare_old_site` sont marqués `…(tronqué)` ;
> tout le reste est **verbatim**.
>
> ⚠ **Une première version tronquait aussi `gradient_color` et `cartographie`** en les jugeant « sans valeur
> d'analyse ». Les deux portaient de l'information : la **couleur du club** (`#c9102e`), et un
> **`status: "draft"`** sur la géoloc — la FFBB n'a pas validé ces coordonnées. **Ne pas trier avant d'avoir lu.**

---

## Comment ces traces ont été obtenues

```bash
# 1. le jeton de recherche — publiquement servi, rotatif, jamais recopié
curl -H 'User-Agent: okhttp/4.12.0' https://api.ffbb.app/items/configuration   # → data.key_ms

# 2. un index, une requête
curl -X POST https://meilisearch-prod.ffbb.app/multi-search \
     -H 'Authorization: Bearer <key_ms>' -H 'Content-Type: application/json' \
     -d '{"queries":[{"indexUid":"ffbbserver_engagements","q":"ARA0069036","limit":300}]}'
```

---

### 1. `GET https://api.ffbb.app/server/specs/oas` — les 14 chemins, verbatim

⚑ Le champ `servers` désigne `api.ffbb.com` : **c'est la même instance Directus**. Et aucun chemin ne porte de donnée de compétition.

```json
{
  "openapi": "3.0.1",
  "info.title": "Dynamic API Specification",
  "servers": [
    {
      "url": "https://api.ffbb.com/",
      "description": "Your current Directus instance."
    }
  ],
  "paths": {
    "/assets/{id}": [
      "GET"
    ],
    "/auth/login": [
      "POST"
    ],
    "/auth/refresh": [
      "POST"
    ],
    "/auth/logout": [
      "POST"
    ],
    "/auth/password/request": [
      "POST"
    ],
    "/auth/password/reset": [
      "POST"
    ],
    "/auth/oauth": [
      "GET"
    ],
    "/auth/oauth/{provider}": [
      "GET"
    ],
    "/server/info": [
      "GET"
    ],
    "/server/ping": [
      "GET"
    ],
    "/items/configuration": [
      "GET"
    ],
    "/items/configuration/{id}": [
      "GET"
    ],
    "/files": [
      "GET"
    ],
    "/files/{id}": [
      "GET"
    ]
  }
}
```

### 2. `GET /items/configuration` — la forme, sans les valeurs

Réponse **identique au bit près** sur `api.ffbb.app` et `api.ffbb.com`. `date_updated` valait le jour de la mesure : **les jetons tournent**.

```json
{
  "data": {
    "date_created": "2024-02-01T09:52:44.371Z",
    "date_updated": "<le jour même de la mesure>",
    "id": 1,
    "key_dh": "<32 caractères — n'ouvre RIEN, /collections répond 403 avec comme sans>",
    "key_ms": "<64 caractères — LE jeton utile, déjà récupéré par FfbbApiClient::token()>",
    "ios_version": "<x.y.z>",
    "android_version": "<x.y.z>",
    "key_directus_website": "<32 caractères>",
    "key_directus_competitions": null,
    "user_created": "<uuid>",
    "user_updated": null,
    "force_ms_reindex": false
  }
}
```

### 3. `ffbbserver_organismes`

Déjà exploité par `FfbbApiClient::search`. 69 hits pour le code club, 4 635 documents au total.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 69,
  "estimatedTotalHits_requete_vide": 4635,
  "hits pour `ARA0069036`": [
    {
      "id": "11104",
      "adresse": "5 RUE EMILE DUNIERE",
      "adresseClubPro": null,
      "code": "ARA0069036",
      "mail": "contact@bccl.fr",
      "nom": "B CHARPENNES CROIX LUIZET",
      "nomClubPro": "",
      "telephone": "0643720140",
      "type": "Groupement",
      "urlSiteWeb": "https://www.villeurbannesharks.fr",
      "nom_simple": null,
      "dateAffiliation": null,
      "saison_en_cours": true,
      "url_competition": "/ligues/ara/comites/0069/clubs/ara0069036",
      "saison": null,
      "offresPratiques": [
        "Compétition 3x3",
        "Compétition 5x5",
        "Compétition MiniBasket",
        "Loisir 3x3",
        "Loisir 5x5"
      ],
      "labellisation": [
        "Label FFBB Citoyen MAIF 2 étoiles",
        "EFMB3"
      ],
      "cartographie": {
        "adresse": "Rue Émile Dunière",
        "codePostal": "69100",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            4.88467,
            45.78017
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "G-11104",
        "latitude": 45.78017,
        "longitude": 4.88467,
        "title": "",
        "ville": "Villeurbanne",
        "status": "draft"
      },
      "commune": {
        "libelle": "VILLEURBANNE",
        "codePostal": "69100",
        "departement": "Rhône"
      },
      "communeClubPro": null,
      "organisme_id_pere": {
        "id": "2093",
        "nom": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
        "adresse": "3 RUE DU COLONEL CHAMBONNET",
        "code": "0069",
        "commune": "28422",
        "nom_simple": "RHONE ET METROPOLE DE LYON",
        "type": "Comité",
        "organisme_id_pere": {
          "id": "200000002677104",
          "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
          "code": "ARA",
          "type": "L"
        },
        "ligueCode": "200000002677104"
      },
      "type_association": {
        "code": "K",
        "libelle": "Club"
      },
      "logo": {
        "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
        "gradient_color": "#c9102e"
      },
      "_geo": {
        "lat": 45.78017,
        "lng": 4.88467
      },
      "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?height=220&format=avif",
      "engagements_noms": "B CHARPENNES CROIX LUIZET",
      "engagements_codes": "PRM | Pré régionale masculine|PRF | Pré régionale féminine|DF2 | Départementale féminine seniors - Division 2|PNM | Pré nationale masculine|RM2 | Régionale masc…"
    },
    {
      "id": "11072",
      "adresse": "14 rue Antonin Perrin",
      "adresseClubPro": null,
      "code": "ARA0069006",
      "mail": "alap.villeurbanne@wanadoo.fr",
      "nom": "AL ANTONIN PERRIN",
      "nomClubPro": "",
      "telephone": "0478032031",
      "type": "Groupement",
      "urlSiteWeb": "http://www.alap.asso.fr",
      "nom_simple": null,
      "dateAffiliation": null,
      "saison_en_cours": true,
      "url_competition": "/ligues/ara/comites/0069/clubs/ara0069006",
      "saison": null,
      "offresPratiques": [
        "Compétition 5x5",
        "Compétition MiniBasket"
      ],
      "labellisation": [],
      "cartographie": {
        "adresse": "Rue Antonin Perrin",
        "codePostal": "69100",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            4.88468,
            45.75967
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "G-11072",
        "latitude": 45.75967,
        "longitude": 4.88468,
        "title": "",
        "ville": "Villeurbanne",
        "status": "draft"
      },
      "commune": {
        "libelle": "VILLEURBANNE",
        "codePostal": "69100",
        "departement": "Rhône"
      },
      "communeClubPro": null,
      "organisme_id_pere": {
        "id": "2093",
        "nom": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
        "adresse": "3 RUE DU COLONEL CHAMBONNET",
        "code": "0069",
        "commune": "28422",
        "nom_simple": "RHONE ET METROPOLE DE LYON",
        "type": "Comité",
        "organisme_id_pere": {
          "id": "200000002677104",
          "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
          "code": "ARA",
          "type": "L"
        },
        "ligueCode": "200000002677104"
      },
      "type_association": {
        "code": "K",
        "libelle": "Club"
      },
      "logo": {
        "id": "fadde1ce-18d0-4830-a374-9071b4fe5cb7",
        "gradient_color": "#d1f0bf"
      },
      "_geo": {
        "lat": 45.75967,
        "lng": 4.88468
      },
      "thumbnail": "https://api.ffbb.com/assets/fadde1ce-18d0-4830-a374-9071b4fe5cb7?height=220&format=avif",
      "engagements_noms": "AL ANTONIN PERRIN",
      "engagements_codes": "DM2 | Départementale masculine seniors - Division 2|DMU11-3 | Départemental masculin U11 - Division 3|DMU13-3 | Départemental masculin U13 - Division 3|DMU15-2 …"
    },
    {
      "id": "11086",
      "adresse": "60 ruelle de la Cure",
      "adresseClubPro": null,
      "code": "ARA0069016",
      "mail": "beaujolaisbasket69@gmail.com",
      "nom": "BEAUJOLAIS BASKET",
      "nomClubPro": "",
      "telephone": "0474600482",
      "type": "Groupement",
      "urlSiteWeb": "http://www.beaujolais-basket.com",
      "nom_simple": null,
      "dateAffiliation": null,
      "saison_en_cours": true,
      "url_competition": "/ligues/ara/comites/0069/clubs/ara0069016",
      "saison": null,
      "offresPratiques": [
        "Compétition 5x5",
        "Compétition MiniBasket",
        "Loisir 5x5"
      ],
      "labellisation": [
        "EFMB3"
      ],
      "cartographie": {
        "adresse": "Ruelle de la Cure",
        "codePostal": "69430",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            4.61649,
            46.11797
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "G-11086",
        "latitude": 46.11797,
        "longitude": 4.61649,
        "title": "",
        "ville": "Quincié-en-Beaujolais",
        "status": "draft"
      },
      "commune": {
        "libelle": "QUINCIE-EN-BEAUJOLAIS",
        "codePostal": "69430",
        "departement": "Rhône"
      },
      "communeClubPro": null,
      "organisme_id_pere": {
        "id": "2093",
        "nom": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
        "adresse": "3 RUE DU COLONEL CHAMBONNET",
        "code": "0069",
        "commune": "28422",
        "nom_simple": "RHONE ET METROPOLE DE LYON",
        "type": "Comité",
        "organisme_id_pere": {
          "id": "200000002677104",
          "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
          "code": "ARA",
          "type": "L"
        },
        "ligueCode": "200000002677104"
      },
      "type_association": {
        "code": "K",
        "libelle": "Club"
      },
      "logo": {
        "id": "1c42b8eb-bf93-41ad-9b0b-da1b5c3ca2e4",
        "gradient_color": "#2d2b6e"
      },
      "_geo": {
        "lat": 46.11797,
        "lng": 4.61649
      },
      "thumbnail": "https://api.ffbb.com/assets/1c42b8eb-bf93-41ad-9b0b-da1b5c3ca2e4?height=220&format=avif",
      "engagements_noms": "BEAUJOLAIS BASKET",
      "engagements_codes": "NM2 | NATIONALE MASCULINE 2 |DM2 | Départementale masculine seniors - Division 2|PRF | Pré régionale féminine|PNM | Pré nationale masculine|RMU20 | Régional mas…"
    }
  ]
}
```

### 4. `ffbbserver_engagements` — 🟢 LE gisement

283 hits annoncés, **14 réels** (voir §7). Un document = une équipe engagée.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 283,
  "estimatedTotalHits_requete_vide": 5000,
  "hits pour `ARA0069036`": [
    {
      "id": "200000005335264",
      "nom": "Pré régionale masculine Poule B2",
      "nomCtc": null,
      "clubPro": false,
      "numeroEquipe": "3",
      "nomEquipe": null,
      "nomOfficiel": "",
      "nomUsuel": "",
      "codeAbrege": "",
      "niveau": {
        "ccg": 0,
        "code": "PRM",
        "id": "1010",
        "libelle": "PrÃ© rÃ©gionale",
        "ordre": 7,
        "point": 0,
        "sexe": "M",
        "categorieChampionnat": {
          "code": "SE",
          "ordre": 10,
          "libelle": "Seniors"
        }
      },
      "logo": {
        "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
        "gradient_color": "#c9102e"
      },
      "idCompetition": {
        "id": "200000002897157",
        "nom": "Pré régionale masculine",
        "code": "PRM",
        "slug": "prm",
        "sexe": "Masculin",
        "categorie": {
          "code": "SE",
          "libelle": "Seniors"
        },
        "typeCompetitionGenerique": {
          "logo": {
            "id": "42992c59-3008-43cc-aff5-8611a6d6a378",
            "gradient_color": "#04378B"
          }
        }
      },
      "idPoule": {
        "id": "200000003054327",
        "nom": "Poule B2"
      },
      "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
      "nomOrganisme": "B CHARPENNES CROIX LUIZET",
      "categorie": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      },
      "sexe": "Masculin",
      "nomClub": "B CHARPENNES CROIX LUIZET",
      "age": "SE M | SEM | Seniors M",
      "_geo": {
        "lat": 45.78017,
        "lng": 4.88467
      },
      "codeLigue": "ARA",
      "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
      "codeComite": "0069",
      "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
      "codeClub": "ARA0069036",
      "nomClubPro": "",
      "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005335264",
      "gradient_color": "#c9102e"
    },
    {
      "id": "200000005337255",
      "nom": "Départementale féminine seniors - Division 2 Poule A",
      "nomCtc": null,
      "clubPro": false,
      "numeroEquipe": "3",
      "nomEquipe": null,
      "nomOfficiel": "",
      "nomUsuel": "",
      "codeAbrege": "",
      "niveau": {
        "ccg": 0,
        "code": "SED2F",
        "id": "200000002672978",
        "libelle": "DEPARTEMENT 2",
        "ordre": 97,
        "point": 0,
        "sexe": "F",
        "categorieChampionnat": {
          "code": "SE",
          "ordre": 10,
          "libelle": "Seniors"
        }
      },
      "logo": {
        "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
        "gradient_color": "#c9102e"
      },
      "idCompetition": {
        "id": "200000002897413",
        "nom": "Départementale féminine seniors - Division 2",
        "code": "DF2",
        "slug": "df2",
        "sexe": "Féminin",
        "categorie": {
          "code": "SE",
          "libelle": "Seniors"
        },
        "typeCompetitionGenerique": {
          "logo": {
            "id": "dfcce9ee-8df7-43c9-b821-b90943b64cad",
            "gradient_color": "#EB6D88"
          }
        }
      },
      "idPoule": {
        "id": "200000003054921",
        "nom": "Poule A"
      },
      "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
      "nomOrganisme": "B CHARPENNES CROIX LUIZET",
      "categorie": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      },
      "sexe": "Féminin",
      "nomClub": "B CHARPENNES CROIX LUIZET",
      "age": "SE F | SEF | Seniors F",
      "_geo": {
        "lat": 45.78017,
        "lng": 4.88467
      },
      "codeLigue": "ARA",
      "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
      "codeComite": "0069",
      "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
      "codeClub": "ARA0069036",
      "nomClubPro": "",
      "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005337255",
      "gradient_color": "#c9102e"
    },
    {
      "id": "200000005338342",
      "nom": "Départementale masculine seniors - Division 2 Poule C1",
      "nomCtc": null,
      "clubPro": false,
      "numeroEquipe": "4",
      "nomEquipe": null,
      "nomOfficiel": "",
      "nomUsuel": "",
      "codeAbrege": "",
      "niveau": {
        "ccg": 0,
        "code": "SED3M",
        "id": "1012",
        "libelle": "DEPARTEMENT 3",
        "ordre": 14,
        "point": 0,
        "sexe": "M",
        "categorieChampionnat": {
          "code": "SE",
          "ordre": 10,
          "libelle": "Seniors"
        }
      },
      "logo": {
        "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
        "gradient_color": "#c9102e"
      },
      "idCompetition": {
        "id": "200000002897472",
        "nom": "Départementale masculine seniors - Division 2",
        "code": "DM2",
        "slug": "dm2",
        "sexe": "Masculin",
        "categorie": {
          "code": "SE",
          "libelle": "Seniors"
        },
        "typeCompetitionGenerique": {
          "logo": {
            "id": "b074c7b9-07ba-4ed1-9f2f-9c9c8e0c9be6",
            "gradient_color": "#04378B"
          }
        }
      },
      "idPoule": {
        "id": "200000003055046",
        "nom": "Poule C1"
      },
      "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
      "nomOrganisme": "B CHARPENNES CROIX LUIZET",
      "categorie": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      },
      "sexe": "Masculin",
      "nomClub": "B CHARPENNES CROIX LUIZET",
      "age": "SE M | SEM | Seniors M",
      "_geo": {
        "lat": 45.78017,
        "lng": 4.88467
      },
      "codeLigue": "ARA",
      "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
      "codeComite": "0069",
      "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
      "codeClub": "ARA0069036",
      "nomClubPro": "",
      "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005338342",
      "gradient_color": "#c9102e"
    }
  ]
}
```

### 5. `ffbbserver_rencontres` — 🔴 index de TEST

**0 hit** pour le club. Les 31 documents nationaux sont ceux ci-dessous : même équipe des deux côtés, `joue: false`.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 0,
  "estimatedTotalHits_requete_vide": 31,
  "documents de l'index (requête vide — le club n'en rend aucun)": [
    {
      "id": "200000014547524",
      "date": "2026-07-25",
      "date_rencontre": "2026-07-25T16:45:00",
      "joue": false,
      "nomEquipe1": "FFBB - CLUB SUPPORT - DTN",
      "nomEquipe2": "FFBB - CLUB SUPPORT - DTN",
      "numeroJournee": "12",
      "resultatEquipe1": null,
      "resultatEquipe2": null,
      "pratique": "5x5",
      "uniqueKey": "200000003054321_12_12",
      "url_competition": "/competitions/edf-jeunes/match/200000014547524",
      "handicap1": null,
      "handicap2": null,
      "rematch_videos": "…(tronqué)",
      "officiels": [
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Marqueur"
          },
          "officiel": {
            "nom": "ZANETTE",
            "prenom": "Audrey"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronometreur"
          },
          "officiel": {
            "nom": "DULHOSTE",
            "prenom": "Guillaume"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronométreur des tirs"
          },
          "officiel": {
            "nom": "BELLOCQ",
            "prenom": "Severine"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Aide marqueur"
          },
          "officiel": {
            "nom": "CURCULOSSE",
            "prenom": "Béatrice"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "PIERRARD",
            "prenom": "Aynrick"
          }
        },
        {
          "ordre": 2,
          "fonction": {
            "libelle": "Chronométreur des tirs"
          },
          "officiel": {
            "nom": "CLAUDIN",
            "prenom": "Aurelie"
          }
        },
        {
          "ordre": 2,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "FOUVRY",
            "prenom": "Antoine"
          }
        }
      ],
      "gsId": null,
      "competitionId": {
        "id": "200000002897154",
        "nom": "Rencontres EDF Jeunes ",
        "competition_origine_nom": "Rencontres EDF Jeunes",
        "code": "EDF Jeunes ",
        "slug": "edf-jeunes",
        "liveStat": false,
        "sexe": "Mixte",
        "typeCompetition": "Plateau",
        "pro": false,
        "logo": null,
        "categorie": {
          "code": "U20",
          "libelle": "U20",
          "ordre": 8
        },
        "typeCompetitionGenerique": null,
        "competition_origine": {
          "id": "200000002897154",
          "code": "EDF Jeunes ",
          "slug": "edf-jeunes",
          "nom": "Rencontres EDF Jeunes ",
          "typeCompetition": "PLAT",
          "categorie": {
            "ordre": 8
          },
          "typeCompetitionGenerique": null
        },
        "nomExtended": "200000002897154|Rencontres EDF Jeunes ||PLAT|Mixte|8|FÉDÉRATION FRANCAISE|FEDE|National|2"
      },
      "idOrganismeEquipe1": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idOrganismeEquipe2": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idPoule": {
        "id": "200000003054321",
        "nom": "Poule A"
      },
      "saison": {
        "code": "26-27"
      },
      "salle": {
        "id": "1001000095",
        "libelle": "GYMNASE BASE DE PLEIN AIR",
        "adresse": "2536 Av. de Bordeaux",
        "adresseComplement": "",
        "cartographie": {
          "ville": "Le Temple-sur-Lot",
          "codePostal": "47110",
          "coordonnees": {
            "type": "Point",
            "coordinates": [
              0.52083,
              44.37991
            ]
          }
        }
      },
      "idEngagementEquipe1": {
        "id": "200000005334493",
        "nomUsuel": "",
        "logo": null
      },
      "idEngagementEquipe2": {
        "id": "200000005334169",
        "nomUsuel": "",
        "logo": null
      },
      "_geo": {
        "lat": 44.37991,
        "lng": 0.52083
      },
      "date_timestamp": 1784930400000,
      "date_rencontre_timestamp": 1784990700000,
      "creation_timestamp": 1781775967000,
      "dateSaisieResultat_timestamp": null,
      "modification_timestamp": 1783351332000,
      "thumbnail": null,
      "organisateur": {
        "id": "1472",
        "code": "FEDE",
        "nom": "FÉDÉRATION FRANCAISE BASKET-BALL",
        "type": "F",
        "organisme_id_pere": null
      },
      "niveau": "National",
      "niveau_nb": "2",
      "officiels_string": "ZANETTE Audrey, DULHOSTE Guillaume, BELLOCQ Severine, CURCULOSSE Béatrice, PIERRARD Aynrick, CLAUDIN Aurelie, FOUVRY Antoine"
    },
    {
      "id": "200000014547525",
      "date": "2026-07-28",
      "date_rencontre": "2026-07-28T17:45:00",
      "joue": false,
      "nomEquipe1": "FFBB - CLUB SUPPORT - DTN",
      "nomEquipe2": "FFBB - CLUB SUPPORT - DTN",
      "numeroJournee": "13",
      "resultatEquipe1": null,
      "resultatEquipe2": null,
      "pratique": "5x5",
      "uniqueKey": "200000003054321_13_13",
      "url_competition": "/competitions/edf-jeunes/match/200000014547525",
      "handicap1": null,
      "handicap2": null,
      "rematch_videos": "…(tronqué)",
      "officiels": [
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Marqueur"
          },
          "officiel": {
            "nom": "CLAUDIN",
            "prenom": "Elodie"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronometreur"
          },
          "officiel": {
            "nom": "ZANETTE",
            "prenom": "Audrey"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronométreur des tirs"
          },
          "officiel": {
            "nom": "CURCULOSSE",
            "prenom": "Béatrice"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Aide marqueur"
          },
          "officiel": {
            "nom": "FEODOROVA",
            "prenom": "Alice"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "BELLOC",
            "prenom": "Marie-charlotte"
          }
        },
        {
          "ordre": 2,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "BAILLIET",
            "prenom": "Emmanuel"
          }
        },
        {
          "ordre": 2,
          "fonction": {
            "libelle": "Aide marqueur"
          },
          "officiel": {
            "nom": "BRETHES",
            "prenom": "Stephane"
          }
        }
      ],
      "gsId": null,
      "competitionId": {
        "id": "200000002897154",
        "nom": "Rencontres EDF Jeunes ",
        "competition_origine_nom": "Rencontres EDF Jeunes",
        "code": "EDF Jeunes ",
        "slug": "edf-jeunes",
        "liveStat": false,
        "sexe": "Mixte",
        "typeCompetition": "Plateau",
        "pro": false,
        "logo": null,
        "categorie": {
          "code": "U20",
          "libelle": "U20",
          "ordre": 8
        },
        "typeCompetitionGenerique": null,
        "competition_origine": {
          "id": "200000002897154",
          "code": "EDF Jeunes ",
          "slug": "edf-jeunes",
          "nom": "Rencontres EDF Jeunes ",
          "typeCompetition": "PLAT",
          "categorie": {
            "ordre": 8
          },
          "typeCompetitionGenerique": null
        },
        "nomExtended": "200000002897154|Rencontres EDF Jeunes ||PLAT|Mixte|8|FÉDÉRATION FRANCAISE|FEDE|National|2"
      },
      "idOrganismeEquipe1": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idOrganismeEquipe2": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idPoule": {
        "id": "200000003054321",
        "nom": "Poule A"
      },
      "saison": {
        "code": "26-27"
      },
      "salle": {
        "id": "1001000095",
        "libelle": "GYMNASE BASE DE PLEIN AIR",
        "adresse": "2536 Av. de Bordeaux",
        "adresseComplement": "",
        "cartographie": {
          "ville": "Le Temple-sur-Lot",
          "codePostal": "47110",
          "coordonnees": {
            "type": "Point",
            "coordinates": [
              0.52083,
              44.37991
            ]
          }
        }
      },
      "idEngagementEquipe1": {
        "id": "200000005334493",
        "nomUsuel": "",
        "logo": null
      },
      "idEngagementEquipe2": {
        "id": "200000005334169",
        "nomUsuel": "",
        "logo": null
      },
      "_geo": {
        "lat": 44.37991,
        "lng": 0.52083
      },
      "date_timestamp": 1785189600000,
      "date_rencontre_timestamp": 1785253500000,
      "creation_timestamp": 1781775969000,
      "dateSaisieResultat_timestamp": null,
      "modification_timestamp": 1785168213000,
      "thumbnail": null,
      "organisateur": {
        "id": "1472",
        "code": "FEDE",
        "nom": "FÉDÉRATION FRANCAISE BASKET-BALL",
        "type": "F",
        "organisme_id_pere": null
      },
      "niveau": "National",
      "niveau_nb": "2",
      "officiels_string": "CLAUDIN Elodie, ZANETTE Audrey, CURCULOSSE Béatrice, FEODOROVA Alice, BELLOC Marie-charlotte, BAILLIET Emmanuel, BRETHES Stephane"
    },
    {
      "id": "200000014547526",
      "date": "2026-07-30",
      "date_rencontre": "2026-07-30T17:45:00",
      "joue": false,
      "nomEquipe1": "FFBB - CLUB SUPPORT - DTN",
      "nomEquipe2": "FFBB - CLUB SUPPORT - DTN",
      "numeroJournee": "14",
      "resultatEquipe1": null,
      "resultatEquipe2": null,
      "pratique": "5x5",
      "uniqueKey": "200000003054321_14_14",
      "url_competition": "/competitions/edf-jeunes/match/200000014547526",
      "handicap1": null,
      "handicap2": null,
      "rematch_videos": "…(tronqué)",
      "officiels": [
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Marqueur"
          },
          "officiel": {
            "nom": "ZANETTE",
            "prenom": "Audrey"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronometreur"
          },
          "officiel": {
            "nom": "FEODOROVA",
            "prenom": "Alice"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Chronométreur des tirs"
          },
          "officiel": {
            "nom": "CAGNAC",
            "prenom": "Yannick"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Aide marqueur"
          },
          "officiel": {
            "nom": "CURCULOSSE",
            "prenom": "Béatrice"
          }
        },
        {
          "ordre": 1,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "PIERRARD",
            "prenom": "Aynrick"
          }
        },
        {
          "ordre": 2,
          "fonction": {
            "libelle": "Arbitre"
          },
          "officiel": {
            "nom": "BENOIT",
            "prenom": "Calixte"
          }
        }
      ],
      "gsId": null,
      "competitionId": {
        "id": "200000002897154",
        "nom": "Rencontres EDF Jeunes ",
        "competition_origine_nom": "Rencontres EDF Jeunes",
        "code": "EDF Jeunes ",
        "slug": "edf-jeunes",
        "liveStat": false,
        "sexe": "Mixte",
        "typeCompetition": "Plateau",
        "pro": false,
        "logo": null,
        "categorie": {
          "code": "U20",
          "libelle": "U20",
          "ordre": 8
        },
        "typeCompetitionGenerique": null,
        "competition_origine": {
          "id": "200000002897154",
          "code": "EDF Jeunes ",
          "slug": "edf-jeunes",
          "nom": "Rencontres EDF Jeunes ",
          "typeCompetition": "PLAT",
          "categorie": {
            "ordre": 8
          },
          "typeCompetitionGenerique": null
        },
        "nomExtended": "200000002897154|Rencontres EDF Jeunes ||PLAT|Mixte|8|FÉDÉRATION FRANCAISE|FEDE|National|2"
      },
      "idOrganismeEquipe1": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idOrganismeEquipe2": {
        "id": "200000002679228",
        "nom": "FFBB - CLUB SUPPORT - DTN",
        "nom_simple": null,
        "code": "IDF0075140",
        "nomClubPro": "",
        "logo": null
      },
      "idPoule": {
        "id": "200000003054321",
        "nom": "Poule A"
      },
      "saison": {
        "code": "26-27"
      },
      "salle": {
        "id": "1001000095",
        "libelle": "GYMNASE BASE DE PLEIN AIR",
        "adresse": "2536 Av. de Bordeaux",
        "adresseComplement": "",
        "cartographie": {
          "ville": "Le Temple-sur-Lot",
          "codePostal": "47110",
          "coordonnees": {
            "type": "Point",
            "coordinates": [
              0.52083,
              44.37991
            ]
          }
        }
      },
      "idEngagementEquipe1": {
        "id": "200000005334493",
        "nomUsuel": "",
        "logo": null
      },
      "idEngagementEquipe2": {
        "id": "200000005334169",
        "nomUsuel": "",
        "logo": null
      },
      "_geo": {
        "lat": 44.37991,
        "lng": 0.52083
      },
      "date_timestamp": 1785362400000,
      "date_rencontre_timestamp": 1785426300000,
      "creation_timestamp": 1781775972000,
      "dateSaisieResultat_timestamp": null,
      "modification_timestamp": 1785168229000,
      "thumbnail": null,
      "organisateur": {
        "id": "1472",
        "code": "FEDE",
        "nom": "FÉDÉRATION FRANCAISE BASKET-BALL",
        "type": "F",
        "organisme_id_pere": null
      },
      "niveau": "National",
      "niveau_nb": "2",
      "officiels_string": "ZANETTE Audrey, FEODOROVA Alice, CAGNAC Yannick, CURCULOSSE Béatrice, PIERRARD Aynrick, BENOIT Calixte"
    }
  ]
}
```

### 6. `ffbbserver_competitions`

0 hit pour le club (normal : une compétition n'appartient à personne). Référentiel de 425 entrées.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 0,
  "estimatedTotalHits_requete_vide": 425,
  "documents de l'index (requête vide — le club n'en rend aucun)": [
    {
      "id": "200000002897149",
      "code": "PRM",
      "creationEnCours": false,
      "date_created": "2026-07-24T03:01:03.897Z",
      "date_updated": "2026-08-02T03:20:57.175Z",
      "emarqueV2": null,
      "liveStat": false,
      "nom": "Pré régionale masculine",
      "publicationInternet": "Affichée",
      "sexe": "Masculin",
      "typeCompetition": "Championnat",
      "pro": false,
      "competition_origine": "200000002897149",
      "competition_origine_niveau": 1,
      "phase_code": "P1",
      "competition_origine_nom": "Pré régionale masculine",
      "etat": "A",
      "ordre": 2,
      "slug": "prm",
      "compare_old_site": "…(tronqué)",
      "toUpdate": false,
      "poules": [
        {
          "nom": "Poule A",
          "id": "200000003054316",
          "engagements": [
            {
              "id": "200000005334136",
              "nom": "FIRMINY CHAZEAU-FAYOL AL"
            },
            {
              "id": "200000005334137",
              "nom": "VILLEREST BC"
            },
            {
              "id": "200000005334138",
              "nom": "LA RICAMARIE AL"
            },
            {
              "id": "200000005334139",
              "nom": "ST JEAN BONNEFONDS AVANT GARDE BASKET"
            },
            {
              "id": "200000005334140",
              "nom": "LE CERGNE JA"
            },
            {
              "id": "200000005334141",
              "nom": "AS THIZY BOURG"
            },
            {
              "id": "200000005334142",
              "nom": "IE - CTC OUEST STEPHANOIS BASKET"
            },
            {
              "id": "200000005334143",
              "nom": "BASKET CLUB VALLEE DU JARNOSSIN"
            },
            {
              "id": "200000005334144",
              "nom": "L'HORME US"
            },
            {
              "id": "200000005334145",
              "nom": "RIORGES BC"
            },
            {
              "id": "200000005334146",
              "nom": "VEAUCHE CRAP"
            },
            {
              "id": "200000005334147",
              "nom": "NEULISE AL"
            }
          ]
        }
      ],
      "phases": [
        "200000002897149"
      ],
      "categorie": {
        "code": "SE",
        "date_created": "2023-08-23T10:30:53.374Z",
        "date_updated": "2026-08-02T03:19:00.798Z",
        "id": "1005",
        "libelle": "Seniors",
        "ordre": 10
      },
      "idCompetitionPere": null,
      "organisateur": {
        "adresse": "47-49 Rue Gutenberg",
        "adresseClubPro": null,
        "cartographie": "C-2068",
        "code": "0042",
        "commune": "36746",
        "communeClubPro": null,
        "id": "2068",
        "mail": "comite@loirebasketball.org",
        "nom": "COMITE DE LA LOIRE DE BASKET-BALL",
        "nomClubPro": "",
        "salle": null,
        "telephone": "0477595660",
        "type": "Comité",
        "type_association": null,
        "urlSiteWeb": "https://loirebasketball.org",
        "logo": "4ac73f45-7702-4aa3-8dbc-5f3bc722f3d5",
        "nom_simple": "LOIRE",
        "dateAffiliation": null,
        "saison_en_cours": true,
        "entreprise": false,
        "handibasket": false,
        "omnisport": false,
        "horsAssociation": false,
        "url_competition": "/ligues/ara/comites/0042",
        "saison": null,
        "offresPratiques": [],
        "engagements": [],
        "labellisation": [],
        "membres": [],
        "organisme_id_pere": {
          "id": "200000002677104",
          "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
          "code": "ARA"
        }
      },
      "saison": {
        "code": "26-27"
      },
      "logo": {
        "id": "42992c59-3008-43cc-aff5-8611a6d6a378",
        "gradient_color": "#04378B"
      },
      "typeCompetitionGenerique": {
        "id": "PRM",
        "logo": {
          "id": "42992c59-3008-43cc-aff5-8611a6d6a378",
          "gradient_color": "#04378B"
        }
      },
      "thumbnail": "https://api.ffbb.com/assets/42992c59-3008-43cc-aff5-8611a6d6a378?height=300&format=avif",
      "niveau": "Départemental",
      "niveau_nb": "4",
      "age": "SE M | SEM | Seniors M",
      "codeLigue": "ARA",
      "codeComite": "0042",
      "participants": "FIRMINY CHAZEAU-FAYOL AL | VILLEREST BC | LA RICAMARIE AL | ST JEAN BONNEFONDS AVANT GARDE BASKET | LE CERGNE JA | AS THIZY BOURG | IE - CTC OUEST STEPHANOIS BA…"
    },
    {
      "id": "200000002897150",
      "code": "PRF",
      "creationEnCours": false,
      "date_created": "2026-07-24T03:01:16.463Z",
      "date_updated": "2026-08-02T03:20:57.177Z",
      "emarqueV2": null,
      "liveStat": false,
      "nom": "Pré régionale féminine",
      "publicationInternet": "Affichée",
      "sexe": "Féminin",
      "typeCompetition": "Championnat",
      "pro": false,
      "competition_origine": "200000002897150",
      "competition_origine_niveau": 1,
      "phase_code": "P1",
      "competition_origine_nom": "Pré régionale féminine",
      "etat": "A",
      "ordre": 4,
      "slug": "prf",
      "compare_old_site": "…(tronqué)",
      "toUpdate": false,
      "poules": [
        {
          "nom": "Poule A",
          "id": "200000003054317",
          "engagements": [
            {
              "id": "200000005334124",
              "nom": "CREMEAUX BC"
            },
            {
              "id": "200000005334125",
              "nom": "ST ROMAIN LE PUY A"
            },
            {
              "id": "200000005334126",
              "nom": "PERREUX AIGLONS"
            },
            {
              "id": "200000005334127",
              "nom": "ASSOCIATION SAINT DENIS MARS BASKET"
            },
            {
              "id": "200000005334128",
              "nom": "ST SYMPHORIEN DE LAY AS"
            },
            {
              "id": "200000005334129",
              "nom": "ST GENEST MALIFAUX BC"
            },
            {
              "id": "200000005334130",
              "nom": "ST PAUL EN JAREZ CS BASKET"
            },
            {
              "id": "200000005334131",
              "nom": "IE - CTC BASKET VAL DE REINS - AMPLEPUIS BC"
            },
            {
              "id": "200000005334132",
              "nom": "ANDREZIEUX-BOUTHEON LOIRE SUD BASKET"
            },
            {
              "id": "200000005334133",
              "nom": "LE CERGNE JA"
            },
            {
              "id": "200000005334134",
              "nom": "COTE ROANNAISE ALLIANCE BASKET"
            },
            {
              "id": "200000005334135",
              "nom": "IE - CTC OUEST STEPHANOIS BASKET"
            }
          ]
        }
      ],
      "phases": [
        "200000002897150"
      ],
      "categorie": {
        "code": "SE",
        "date_created": "2023-08-23T10:30:53.374Z",
        "date_updated": "2026-08-02T03:19:00.798Z",
        "id": "1005",
        "libelle": "Seniors",
        "ordre": 10
      },
      "idCompetitionPere": null,
      "organisateur": {
        "adresse": "47-49 Rue Gutenberg",
        "adresseClubPro": null,
        "cartographie": "C-2068",
        "code": "0042",
        "commune": "36746",
        "communeClubPro": null,
        "id": "2068",
        "mail": "comite@loirebasketball.org",
        "nom": "COMITE DE LA LOIRE DE BASKET-BALL",
        "nomClubPro": "",
        "salle": null,
        "telephone": "0477595660",
        "type": "Comité",
        "type_association": null,
        "urlSiteWeb": "https://loirebasketball.org",
        "logo": "4ac73f45-7702-4aa3-8dbc-5f3bc722f3d5",
        "nom_simple": "LOIRE",
        "dateAffiliation": null,
        "saison_en_cours": true,
        "entreprise": false,
        "handibasket": false,
        "omnisport": false,
        "horsAssociation": false,
        "url_competition": "/ligues/ara/comites/0042",
        "saison": null,
        "offresPratiques": [],
        "engagements": [],
        "labellisation": [],
        "membres": [],
        "organisme_id_pere": {
          "id": "200000002677104",
          "nom": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
          "code": "ARA"
        }
      },
      "saison": {
        "code": "26-27"
      },
      "logo": {
        "id": "9553622b-e88f-4115-852f-6b814b7fbdb2",
        "gradient_color": "#EB6D88"
      },
      "typeCompetitionGenerique": {
        "id": "PRF",
        "logo": {
          "id": "9553622b-e88f-4115-852f-6b814b7fbdb2",
          "gradient_color": "#EB6D88"
        }
      },
      "thumbnail": "https://api.ffbb.com/assets/9553622b-e88f-4115-852f-6b814b7fbdb2?height=300&format=avif",
      "niveau": "Départemental",
      "niveau_nb": "4",
      "age": "SE F | SEF | Seniors F",
      "codeLigue": "ARA",
      "codeComite": "0042",
      "participants": "CREMEAUX BC | ST ROMAIN LE PUY A | PERREUX AIGLONS | ASSOCIATION SAINT DENIS MARS BASKET | ST SYMPHORIEN DE LAY AS | ST GENEST MALIFAUX BC | ST PAUL EN JAREZ CS…"
    },
    {
      "id": "200000002897152",
      "code": "AMIC SM",
      "creationEnCours": false,
      "date_created": "2026-06-17T13:04:02.609Z",
      "date_updated": "2026-08-02T03:20:57.178Z",
      "emarqueV2": null,
      "liveStat": false,
      "nom": "AMICAL SENIORS MASCULINS",
      "publicationInternet": "Affichée",
      "sexe": "Masculin",
      "typeCompetition": "Plateau",
      "pro": false,
      "competition_origine": "200000002897152",
      "competition_origine_niveau": 1,
      "phase_code": "P1",
      "competition_origine_nom": "AMICAL SENIORS MASCULINS",
      "etat": "A",
      "ordre": null,
      "slug": "amic-sm",
      "compare_old_site": "…(tronqué)",
      "toUpdate": false,
      "poules": [
        {
          "nom": "Poule A",
          "id": "200000003054319",
          "engagements": [
            {
              "id": "200000005334160",
              "nom": "CD 62 HORS ASSOCIATION"
            },
            {
              "id": "200000005334167",
              "nom": "BASKET CLUB LIEVINOIS"
            },
            {
              "id": "200000005348402",
              "nom": "EOBC WIMILLE WIMEREUX"
            },
            {
              "id": "200000005348403",
              "nom": "ASPTT BOULOGNE SUR MER"
            }
          ]
        }
      ],
      "phases": [
        "200000002897152"
      ],
      "categorie": {
        "code": "SE",
        "date_created": "2023-08-23T10:30:53.374Z",
        "date_updated": "2026-08-02T03:19:00.798Z",
        "id": "1005",
        "libelle": "Seniors",
        "ordre": 10
      },
      "idCompetitionPere": null,
      "organisateur": {
        "adresse": "67, Rue du Général De Gaulle",
        "adresseClubPro": null,
        "cartographie": "C-2087",
        "code": "0062",
        "commune": "25392",
        "communeClubPro": null,
        "id": "2087",
        "mail": "cd62basket@gmail.com",
        "nom": "COMITE DU PAS-DE-CALAIS  DE BASKET-BALL",
        "nomClubPro": "",
        "salle": null,
        "telephone": "0321689994",
        "type": "Comité",
        "type_association": null,
        "urlSiteWeb": "https://www.pasdecalaisbasketball.org",
        "logo": "77b3092c-0f92-426c-86c7-6d620a4b703b",
        "nom_simple": "PAS-DE-CALAIS",
        "dateAffiliation": null,
        "saison_en_cours": true,
        "entreprise": false,
        "handibasket": false,
        "omnisport": false,
        "horsAssociation": false,
        "url_competition": "/ligues/hdf/comites/0062",
        "saison": null,
        "offresPratiques": [],
        "engagements": [],
        "labellisation": [],
        "membres": [],
        "organisme_id_pere": {
          "id": "200000002677109",
          "nom": "LIGUE REGIONALE DES HAUTS-DE-FRANCE DE BASKET-BALL",
          "code": "HDF"
        }
      },
      "saison": {
        "code": "26-27"
      },
      "logo": null,
      "typeCompetitionGenerique": null,
      "thumbnail": null,
      "niveau": "Départemental",
      "niveau_nb": "4",
      "age": "SE M | SEM | Seniors M",
      "codeLigue": "HDF",
      "codeComite": "0062",
      "participants": "CD 62 HORS ASSOCIATION | BASKET CLUB LIEVINOIS | EOBC WIMILLE WIMEREUX | ASPTT BOULOGNE SUR MER"
    }
  ]
}
```

### 7. `ffbbserver_salles`

0 hit pour le club : les salles **ne sont pas indexées par club**. Jointure possible via `rencontres.salle` ou la commune.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 0,
  "estimatedTotalHits_requete_vide": 5000,
  "documents de l'index (requête vide — le club n'en rend aucun)": [
    {
      "id": "10000000013",
      "adresse": "Route de Sainte Foy",
      "adresseComplement": "",
      "capaciteSpectateur": "",
      "date_created": "2023-07-25T14:37:56.862Z",
      "date_updated": "2026-07-31T10:37:58.446Z",
      "libelle": "GYMNASE Jean ROSTAND",
      "libelle2": "",
      "mail": "",
      "numero": "032429402",
      "telephone": "",
      "cartographie": {
        "adresse": "Route de Sainte-Foy la Grande",
        "codePostal": "24700",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            0.16231,
            44.99708
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "S-10000000013",
        "latitude": 44.99708,
        "longitude": 0.16231,
        "title": "GYMNASE Jean ROSTAND",
        "ville": "Montpon-Ménestérol",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "24700",
        "date_created": "2023-07-25T14:36:49.134Z",
        "date_updated": "2026-07-31T10:37:46.631Z",
        "id": "8916",
        "libelle": "MONTPON-MENESTEROL",
        "departement": "Dordogne"
      },
      "_geo": {
        "lat": 44.99708,
        "lng": 0.16231
      },
      "thumbnail": null,
      "type": "Salle",
      "type_association": {
        "libelle": "Salle"
      }
    },
    {
      "id": "10000000014",
      "adresse": "642 avenue Georges Sand ",
      "adresseComplement": "",
      "capaciteSpectateur": "",
      "date_created": "2023-07-25T14:38:01.699Z",
      "date_updated": "2026-07-31T10:37:59.511Z",
      "libelle": "SAINT GEOURS MAREMNE",
      "libelle2": "",
      "mail": "",
      "numero": "034026101",
      "telephone": "",
      "cartographie": {
        "adresse": "Avenue George Sand",
        "codePostal": "40230",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            -1.21828,
            43.69375
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "S-10000000014",
        "latitude": 43.69375,
        "longitude": -1.21828,
        "title": "SAINT GEOURS MAREMNE",
        "ville": "Saint-Geours-de-Maremne",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "40230",
        "date_created": "2023-07-25T14:36:52.733Z",
        "date_updated": "2026-07-31T10:37:47.342Z",
        "id": "16023",
        "libelle": "SAINT-GEOURS-DE-MAREMNE",
        "departement": "Landes"
      },
      "_geo": {
        "lat": 43.69375,
        "lng": -1.21828
      },
      "thumbnail": null,
      "type": "Salle",
      "type_association": {
        "libelle": "Salle"
      }
    },
    {
      "id": "10000000015",
      "adresse": "Au bourg",
      "adresseComplement": "81 Rte du Val d'Adour",
      "capaciteSpectateur": "",
      "date_created": "2023-07-25T14:38:01.703Z",
      "date_updated": "2025-12-17T08:53:43.503Z",
      "libelle": "SALLE ST JEAN DE LIER",
      "libelle2": "",
      "mail": "",
      "numero": "034026301",
      "telephone": "0558579293",
      "cartographie": {
        "adresse": null,
        "codePostal": "40380",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            -0.87861,
            43.78918
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "S-10000000015",
        "latitude": 43.78918,
        "longitude": -0.87861,
        "title": "SALLE ST JEAN DE LIER",
        "ville": "Saint-Jean-de-Lier",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "40380",
        "date_created": "2023-07-25T14:36:52.737Z",
        "date_updated": "2025-12-17T08:52:55.669Z",
        "id": "16025",
        "libelle": "SAINT-JEAN-DE-LIER",
        "departement": "Landes"
      },
      "_geo": {
        "lat": 43.78918,
        "lng": -0.87861
      },
      "thumbnail": null,
      "type": "Salle",
      "type_association": {
        "libelle": "Salle"
      }
    }
  ]
}
```

### 8. `ffbbserver_terrains`

0 hit pour le club. Nature du sol, dimensions — aucun usage produit identifié.

```json
{
  "estimatedTotalHits_requete_ARA0069036": 0,
  "estimatedTotalHits_requete_vide": 5000,
  "documents de l'index (requête vide — le club n'en rend aucun)": [
    {
      "id": "1",
      "accesLibre": false,
      "date_created": "2023-07-27T13:38:22.762Z",
      "date_updated": "2026-07-31T10:27:52.837Z",
      "largeur": 12,
      "longueur": 20,
      "nom": "Arena",
      "numero": 1,
      "rue": "58 Lieu-dit Aréna",
      "cartographie": {
        "adresse": "Lieu-dit Aréna",
        "codePostal": "20215",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            9.46505,
            42.50032
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "T-1",
        "latitude": 42.50032,
        "longitude": 9.46505,
        "title": null,
        "ville": "Vescovato",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "20215",
        "date_created": "2023-07-25T14:37:05.202Z",
        "date_updated": "2026-07-31T10:37:46.547Z",
        "id": "7288",
        "libelle": "VESCOVATO",
        "departement": "Corse"
      },
      "natureSol": {
        "code": "SS",
        "date_created": "2023-07-27T13:55:13.533Z",
        "date_updated": "2026-08-02T03:19:00.646Z",
        "id": "200000002672899",
        "libelle": "Sol synthétique",
        "terrain": "true"
      },
      "_geo": {
        "lat": 42.50032,
        "lng": 9.46505
      },
      "thumbnail": null,
      "type": "Terrain"
    },
    {
      "id": "10",
      "accesLibre": false,
      "date_created": "2023-07-27T13:38:22.735Z",
      "date_updated": "2026-07-31T10:27:52.831Z",
      "largeur": 20,
      "longueur": 40,
      "nom": "Plateau Multisports",
      "numero": 10,
      "rue": "D109",
      "cartographie": {
        "adresse": "D109",
        "codePostal": "20230",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            9.489639,
            42.400738
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "T-10",
        "latitude": 42.400738,
        "longitude": 9.489639,
        "title": null,
        "ville": "Poggio-Mezzana",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "20230",
        "date_created": "2023-07-27T13:36:55.152Z",
        "date_updated": "2026-07-31T10:27:45.638Z",
        "id": "7219",
        "libelle": "POGGIO-MEZZANA",
        "departement": "Corse"
      },
      "natureSol": {
        "code": "BIT",
        "date_created": "2023-07-27T13:55:13.094Z",
        "date_updated": "2026-08-02T03:19:00.510Z",
        "id": "8",
        "libelle": "BITUME",
        "terrain": null
      },
      "_geo": {
        "lat": 42.400738,
        "lng": 9.489639
      },
      "thumbnail": null,
      "type": "Terrain"
    },
    {
      "id": "100",
      "accesLibre": false,
      "date_created": "2023-07-27T13:39:05.803Z",
      "date_updated": "2026-07-31T10:28:03.472Z",
      "largeur": 24,
      "longueur": 44,
      "nom": "Espace Sportif de Proximite",
      "numero": 100,
      "rue": "13 Résidence du Petit Bois",
      "cartographie": {
        "adresse": "Résidence du Petit Bois",
        "codePostal": "95480",
        "coordonnees": {
          "type": "Point",
          "coordinates": [
            2.16085,
            49.02033
          ]
        },
        "date_created": null,
        "date_updated": null,
        "id": "T-100",
        "latitude": 49.02033,
        "longitude": 2.16085,
        "title": null,
        "ville": "Pierrelaye",
        "status": "draft"
      },
      "commune": {
        "codeInsee": null,
        "codePostal": "95480",
        "date_created": "2023-07-25T14:37:03.513Z",
        "date_updated": "2026-07-31T10:37:49.177Z",
        "id": "36520",
        "libelle": "PIERRELAYE",
        "departement": "Val-d'Oise"
      },
      "natureSol": {
        "code": "BIT",
        "date_created": "2023-07-27T13:55:13.094Z",
        "date_updated": "2026-08-02T03:19:00.510Z",
        "id": "8",
        "libelle": "BITUME",
        "terrain": null
      },
      "_geo": {
        "lat": 49.02033,
        "lng": 2.16085
      },
      "thumbnail": null,
      "type": "Terrain"
    }
  ]
}
```

### 9. Les 14 engagements RÉELS de `ARA0069036`, en entier

⚠ Filtrés sur **`codeClub == "ARA0069036"`**, pas sur la pertinence : la requête plein texte rendait **283 hits**, dont 269 appartenant à d'autres clubs (`ARA0069016`, `ARA0069034`…) qui matchaient sur `competitionsUrl` ou la compétition partagée.

⚠ **Aucun champ `saison`** dans ces documents — mais **ce n'est PAS un bloquant** : la saison s'obtient par jointure `idCompetition.id` → `ffbbserver_competitions` → `saison.code` (14/14 résolus, tous `26-27`).

⚠ **Aucune clé stable d'équipe** : `nomEquipe`, `nomUsuel`, `nomOfficiel`, `codeAbrege`, `nomCtc` sont vides **14/14**, et `numeroEquipe` (6/14) n'a pas de signification métier. C'est ce qui impose de ré-apparier à chaque phase.

⚠ Le `logo` est **le même sur les 14** et **identique à celui de l'organisme** : c'est le logo du CLUB, pas de l'équipe. Son `gradient_color` porte en revanche **la couleur du club**.

```json
[
  {
    "id": "200000005335264",
    "nom": "Pré régionale masculine Poule B2",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "3",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "PRM",
      "id": "1010",
      "libelle": "PrÃ© rÃ©gionale",
      "ordre": 7,
      "point": 0,
      "sexe": "M",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897157",
      "nom": "Pré régionale masculine",
      "code": "PRM",
      "slug": "prm",
      "sexe": "Masculin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "42992c59-3008-43cc-aff5-8611a6d6a378",
          "gradient_color": "#04378B"
        }
      }
    },
    "idPoule": {
      "id": "200000003054327",
      "nom": "Poule B2"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Masculin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE M | SEM | Seniors M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005335264",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005337255",
    "nom": "Départementale féminine seniors - Division 2 Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "3",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "SED2F",
      "id": "200000002672978",
      "libelle": "DEPARTEMENT 2",
      "ordre": 97,
      "point": 0,
      "sexe": "F",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897413",
      "nom": "Départementale féminine seniors - Division 2",
      "code": "DF2",
      "slug": "df2",
      "sexe": "Féminin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "dfcce9ee-8df7-43c9-b821-b90943b64cad",
          "gradient_color": "#EB6D88"
        }
      }
    },
    "idPoule": {
      "id": "200000003054921",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Féminin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE F | SEF | Seniors F",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005337255",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005338342",
    "nom": "Départementale masculine seniors - Division 2 Poule C1",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "4",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "SED3M",
      "id": "1012",
      "libelle": "DEPARTEMENT 3",
      "ordre": 14,
      "point": 0,
      "sexe": "M",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897472",
      "nom": "Départementale masculine seniors - Division 2",
      "code": "DM2",
      "slug": "dm2",
      "sexe": "Masculin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "b074c7b9-07ba-4ed1-9f2f-9c9c8e0c9be6",
          "gradient_color": "#04378B"
        }
      }
    },
    "idPoule": {
      "id": "200000003055046",
      "nom": "Poule C1"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Masculin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE M | SEM | Seniors M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005338342",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005341546",
    "nom": "Pré nationale féminine Poule E",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "1",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "PNF",
      "id": "1018",
      "libelle": "PNF",
      "ordre": 91,
      "point": 0,
      "sexe": "F",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897651",
      "nom": "Pré nationale féminine",
      "code": "PNF",
      "slug": "pnf",
      "sexe": "Féminin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "e4f8d2f6-5806-46c8-bd0f-850a5189eceb",
          "gradient_color": "#EB6D88"
        }
      }
    },
    "idPoule": {
      "id": "200000003055510",
      "nom": "Poule E"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Féminin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE F | SEF | Seniors F",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005341546",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005341569",
    "nom": "Pré nationale masculine Poule D",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "PNM",
      "id": "1006",
      "libelle": "PNM",
      "ordre": 6,
      "point": 0,
      "sexe": "M",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897652",
      "nom": "Pré nationale masculine",
      "code": "PNM",
      "slug": "pnm",
      "sexe": "Masculin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "0b6db544-6079-4f7b-99e5-8d70e8c00d3d",
          "gradient_color": "#04378B"
        }
      }
    },
    "idPoule": {
      "id": "200000003055515",
      "nom": "Poule D"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Masculin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE M | SEM | Seniors M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005341569",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005341649",
    "nom": "Régionale féminine seniors - Division 3 Poule F",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "2",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "SER2F",
      "id": "200000002672996",
      "libelle": "REGION 2",
      "ordre": 93,
      "point": 0,
      "sexe": "F",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897655",
      "nom": "Régionale féminine seniors - Division 3",
      "code": "RF3",
      "slug": "rf3",
      "sexe": "Féminin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "c47f122f-e7b1-4f57-a7aa-e941d0868df7",
          "gradient_color": "#EB6D88"
        }
      }
    },
    "idPoule": {
      "id": "200000003055530",
      "nom": "Poule F"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Féminin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE F | SEF | Seniors F",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005341649",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005341770",
    "nom": "Régionale masculine seniors - Division 2 Poule G",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "2",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": {
      "ccg": 0,
      "code": "SER2M",
      "id": "200000002672997",
      "libelle": "REGION 2",
      "ordre": 9,
      "point": 0,
      "sexe": "M",
      "categorieChampionnat": {
        "code": "SE",
        "ordre": 10,
        "libelle": "Seniors"
      }
    },
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002897658",
      "nom": "Régionale masculine seniors - Division 2",
      "code": "RM2",
      "slug": "rm2",
      "sexe": "Masculin",
      "categorie": {
        "code": "SE",
        "libelle": "Seniors"
      },
      "typeCompetitionGenerique": {
        "logo": {
          "id": "4019820e-b099-4105-97b2-c7a9073f004d",
          "gradient_color": "#04378B"
        }
      }
    },
    "idPoule": {
      "id": "200000003055546",
      "nom": "Poule G"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "categorie": {
      "code": "SE",
      "ordre": 10,
      "libelle": "Seniors"
    },
    "sexe": "Masculin",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "SE M | SEM | Seniors M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005341770",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005346875",
    "nom": "RFU13 Brassage  Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898047",
      "nom": "RFU13 Brassage ",
      "code": "RFU13 Brassage ",
      "slug": "rfu13-brassage",
      "sexe": "Féminin",
      "categorie": {
        "code": "U13",
        "libelle": "U13"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056266",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U13 M | U13M | U13 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005346875",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005346973",
    "nom": "RFU15 Brassage Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898058",
      "nom": "RFU15 Brassage",
      "code": "RFU15 Brassage",
      "slug": "rfu15-brassage",
      "sexe": "Féminin",
      "categorie": {
        "code": "U15",
        "libelle": "U15"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056277",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U15 M | U15M | U15 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005346973",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005347005",
    "nom": "RFU18 Brassage Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898059",
      "nom": "RFU18 Brassage",
      "code": "RFU18 Brassage",
      "slug": "rfu18-brassage",
      "sexe": "Féminin",
      "categorie": {
        "code": "U18",
        "libelle": "U18"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056279",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U18 M | U18M | U18 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005347005",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005347033",
    "nom": "RMU13 Brassage Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898060",
      "nom": "RMU13 Brassage",
      "code": "RMU13 Brassage",
      "slug": "rmu13-brassage",
      "sexe": "Masculin",
      "categorie": {
        "code": "U13",
        "libelle": "U13"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056281",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U13 M | U13M | U13 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005347033",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005347065",
    "nom": "RMU15 Brassage Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898061",
      "nom": "RMU15 Brassage",
      "code": "RMU15 Brassage",
      "slug": "rmu15-brassage",
      "sexe": "Masculin",
      "categorie": {
        "code": "U15",
        "libelle": "U15"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056282",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U15 M | U15M | U15 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005347065",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005347169",
    "nom": "RMU18 Brassage Poule A",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898069",
      "nom": "RMU18 Brassage",
      "code": "RMU18 Brassage",
      "slug": "rmu18-brassage",
      "sexe": "Masculin",
      "categorie": {
        "code": "U18",
        "libelle": "U18"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056290",
      "nom": "Poule A"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U18 M | U18M | U18 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005347169",
    "gradient_color": "#c9102e"
  },
  {
    "id": "200000005347201",
    "nom": "Régionale masculine U21 Poule C",
    "nomCtc": null,
    "clubPro": false,
    "numeroEquipe": "",
    "nomEquipe": null,
    "nomOfficiel": "",
    "nomUsuel": "",
    "codeAbrege": "",
    "niveau": null,
    "logo": {
      "id": "ad8d7110-58d7-4905-9cc8-9ddd7e351be1",
      "gradient_color": "#c9102e"
    },
    "idCompetition": {
      "id": "200000002898070",
      "nom": "Régionale masculine U21",
      "code": "RMU21",
      "slug": "rmu21",
      "sexe": "Masculin",
      "categorie": {
        "code": "U21",
        "libelle": "U21"
      },
      "typeCompetitionGenerique": null
    },
    "idPoule": {
      "id": "200000003056293",
      "nom": "Poule C"
    },
    "thumbnail": "https://api.ffbb.com/assets/ad8d7110-58d7-4905-9cc8-9ddd7e351be1?width=220&format=avif",
    "nomOrganisme": "B CHARPENNES CROIX LUIZET",
    "nomClub": "B CHARPENNES CROIX LUIZET",
    "age": "U21 M | U21M | U21 M",
    "_geo": {
      "lat": 45.78017,
      "lng": 4.88467
    },
    "codeLigue": "ARA",
    "nomLigue": "LIGUE REGIONALE D'AUVERGNE-RHÔNE-ALPES DE BASKET-BALL",
    "codeComite": "0069",
    "nomComite": "COMITE DU RHONE ET METROPOLE DE LYON DE BASKET-BALL",
    "codeClub": "ARA0069036",
    "nomClubPro": "",
    "competitionsUrl": "/ligues/ara/comites/0069/clubs/ARA0069036/equipes/200000005347201",
    "gradient_color": "#c9102e"
  }
]
```


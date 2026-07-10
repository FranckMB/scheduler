# API FFBB — routes consommées (lot C : auto-alimentation club)

> Répertoire **exhaustif** des endpoints externes FFBB utilisés par le backend pour alimenter les données institutionnelles club/comité/ligue à la création d'un club. Toute route ajoutée ici doit rester dans la **liste blanche de hosts** du client (SSRF, A12). Vérifié le 2026-07-10 sur le code réel `ARA0069036` (BCCL).

## Hosts (liste blanche — aucun autre host autorisé)

| Host | Rôle |
|------|------|
| `https://api.ffbb.com` | Directus : config (token public) + service d'assets (logos) |
| `https://meilisearch-prod.ffbb.app` | Meilisearch : recherche des organismes |

> ⚠️ Ces deux hosts sont **codés en dur**. Aucune URL n'est dérivée d'un input utilisateur. Le seul paramètre variable est le **code club**, validé par format avant tout appel.

## 1. Récupérer le token public

```
GET https://api.ffbb.com/items/configuration
Headers:
  Origin: https://competitions.ffbb.com
  Referer: https://competitions.ffbb.com/
```

Réponse (extrait) :
```json
{ "data": { "key_ms": "<clé Meilisearch>", "key_dh": "<token Directus>" } }
```

- `key_ms` → Bearer pour Meilisearch (§2). **Clé publique** embarquée dans l'app FFBB (pas un secret ; ne jamais la committer en dur — la lire ici, la mettre en cache, fallback env var `FFBB_MEILISEARCH_TOKEN`).
- L'appel **échoue en 403 sans le header `Origin`** ci-dessus.

## 2. Rechercher un organisme (club / comité / ligue)

```
POST https://meilisearch-prod.ffbb.app/multi-search
Headers:
  Authorization: Bearer {key_ms}
  Content-Type: application/json
Body:
  { "queries": [ { "indexUid": "ffbbserver_organismes", "q": "ARA0069036", "limit": 3 } ] }
```

- Index : **`ffbbserver_organismes`**.
- `q` = **code club** (recherche), ou nom / code comité / code ligue pour résoudre les parents.
- Sur 401 → token périmé : re-fetch §1 puis retry une fois.

### Champs consommés du hit → mapping entités

| Champ JSON | Cible |
|------------|-------|
| `code` | `Club.ffbbClubCode` (déjà là) |
| `nom` | `Club.name` (déjà là) |
| `adresse` | `Club.address` |
| `cartographie.codePostal` / `commune.codePostal` | `Club.postalCode` |
| `cartographie.ville` / `commune.libelle` | `Club.city` |
| `telephone` | `Club.contactPhone` |
| `mail` | `Club.contactEmail` |
| `urlSiteWeb` | `Club.website` |
| `logo.id` | uuid → logo réhébergé (§3) |
| `organisme_id_pere` (`id,nom,adresse,code`) | comité → `FfbbCommittee` |
| `organisme_id_pere.organisme_id_pere` (`id,nom,code`) | ligue → `FfbbLeague` |

Champs **ignorés** : `offresPratiques`, `labellisation`, `engagements_*`, `_geo`, `type_association`, `*ClubPro`, `saison`, `dateAffiliation`.

> Le hit club ne porte que l'adresse **partielle** du comité (sans CP/ville). Le comité et la ligue **complets** (CP+ville, tél, mail, logo) se résolvent par un **2ᵉ `multi-search`** filtré sur leur `code` (`0069`, `ARA`).

## 3. Logo d'un organisme

```
GET https://api.ffbb.com/assets/{uuid}?format=webp&height=220&fit=contain
```

- `{uuid}` = `logo.id` du hit.
- **Réhébergé** chez nous (pas de hotlink) : download → validation MIME/taille → stockage via le pipeline logo existant.

## Ce que l'API NE fournit PAS

- **Président / correspondant nommé** (personne physique) : absent de l'index. Volontairement **hors scope** lot C (seul le contact institutionnel — mail secrétariat + tél — est exposé).

# Import FFBB — auto-alimentation club / ligue / comité + logos (lot C)

> **Statut** : **décidé** (discovery technique **faite** le 2026-07-10, API vérifiée sur données réelles). Prêt pour `/plan`.
> **Nature** : à la **création du club**, alimenter automatiquement — via l'**API publique FFBB** (Meilisearch) et à partir du seul **code club** — les coordonnées **institutionnelles** club / comité / ligue + les **logos**, et les afficher sur la page club.
> **Rattachement roadmap** : `roadmap.md` §8 (import FFBB, FF#19) · onboarding §3. Croise [`enregistrement-ffbb.md`](enregistrement-ffbb.md) (légitimité/anti-squatting) : **hors scope de ce lot**.
> **Réutilise l'existant** : `AuthController::createClub()` (déjà best-effort `schoolZone`/`league` depuis le code — même point d'ancrage) · lot B (`Club` + `PATCH /api/club/info`) · async Messenger (patron génération) · mécanisme de stockage du logo club.
> **Discovery technique (faite)** : le portail est un **Next.js RSC** sans API JSON de contacts exploitable ; MAIS l'app FFBB s'appuie sur un **Meilisearch public** qui répond en JSON structuré. Le scraping HTML est **abandonné** au profit de cette API. Détail des routes : [`backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md).

---

## 1. Le besoin (reformulé, validé)

Le club connaît son **code FFBB** (`ARA0069036`), saisi au register. À partir de ce seul code, on **alimente automatiquement** — sans ressaisie — les données **club** (nom, adresse, CP+ville, tél, mail, site, logo) **et** celles de son **comité** et de sa **ligue** (nom, adresse, CP+ville, tél, mail, logo), car le club échange régulièrement avec ces deux niveaux. Puis on les **affiche** sur la page club en **3 blocs institutionnels** (Club · Comité · Ligue).

**Décision produit** : on stocke **la data du JSON API** (institutionnel). Le **président/correspondant nommé** (la personne) n'est **pas** exposé par l'API → **abandonné** pour ce lot (le mail secrétariat + tél = le vrai contact opérationnel, et le président change aux élections).

## 2. Décisions produit (verrouillées)

| # | Décision |
|---|---|
| 1 | **Source = API publique FFBB (Meilisearch)**, pas de scraping. Un appel `multi-search` sur le **code club** renvoie le club **et** sa hiérarchie (comité parent, ligue grand-parent) en JSON. Détail : [`backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md). |
| 2 | **Déclencheur = auto à la CRÉATION du club** (register), best-effort **asynchrone** (message Messenger, ne bloque pas le register ; les logos = I/O). **Pas de bouton** page club. Le **rafraîchissement manuel** est **différé** (futur **SUPERADMIN**, cf. [`console-superadmin.md`](console-superadmin.md)). |
| 3 | **Une route unique** `POST /api/club/ffbb-import` (management-gated) qui **remplit tous les champs** depuis le JSON — partagée par le handler async (register) et le futur superadmin. Idempotente. |
| 4 | **Ligue & comité = tables de référence partagées, cache-first** : clés = code ligue / code comité (ou id FFBB). Présent en base → réutilisé, **aucun appel** ; absent → un appel puis stockage pour les clubs suivants. |
| 5 | **Logos réhébergés** (pas de hotlink) : download `api.ffbb.com/assets/{uuid}?format=webp` → stockage via le pipeline logo existant. Logo **club** posé **si vide** (jamais d'écrasement d'un logo uploadé sans confirmation superadmin) ; logos comité/ligue sur les tables de référence. |
| 6 | **Affichage page club** : section lecture seule **« Contacts FFBB »** = 3 blocs (Club · Comité · Ligue) — `nom · adresse · CP+ville · téléphone · mail · logo`. Non éditable (data FFBB) ; les champs éditables du lot B restent tels quels. |
| 7 | **RGPD** : données institutionnelles publiques (pas de personne physique nommée). Rien de nouveau côté données personnelles vs lot B. |

Hors périmètre : président/correspondant nommé · scraping · refresh manuel côté gestionnaire (→ superadmin) · [`enregistrement-ffbb.md`](enregistrement-ffbb.md) · import équipes (`engagements_codes` est là mais → roadmap §8).

## 3. L'API FFBB (vérifiée sur `ARA0069036` → BCCL)

- **Token public** : `GET https://api.ffbb.com/items/configuration` (header `Origin: https://competitions.ffbb.com`) → `key_ms` (clé Meilisearch) + `key_dh` (Directus). **Pas de secret** : clés publiques embarquées dans l'app FFBB.
- **Recherche** : `POST https://meilisearch-prod.ffbb.app/multi-search`, header `Authorization: Bearer {key_ms}`, index **`ffbbserver_organismes`**, `q = code club`.
- **Réponse** : le hit club porte `code, nom, adresse, mail, telephone, urlSiteWeb, cartographie.{codePostal,ville}, commune.{libelle,codePostal,departement}, logo.id, thumbnail`, **et** `organisme_id_pere` (comité : `id, nom, adresse, code`) imbriquant la ligue (`id, nom, code`). Le comité/ligue **complets** (CP+ville, tél, mail, logo) se résolvent par un 2ᵉ `multi-search` filtré sur leur `code` (`0069`, `ARA`).
- **Champs ignorés** : `offresPratiques, labellisation, engagements_*, _geo, type_association, *ClubPro, saison`.

Exemple réel consolidé : `ffbb-bccl-sample.json` (fourni en discovery).

## 4. Données à stocker

- **`FfbbLeague`** — clé unique = code ligue (`ARA`) [ou id FFBB]. Champs : `nom, adresse, codePostal, ville, telephone, mail, logoUrl, fetchedAt`.
- **`FfbbCommittee`** — clé unique = code comité (`0069`) [+ ligue de rattachement]. Mêmes champs.
- **`Club`** : les champs institutionnels club (nom déjà là ; adresse/CP/ville/tél/mail/site/logo → lot B a déjà `address`, `contactPhone`, `contactEmail`… : **on les remplit**, on ajoute ce qui manque). Référence ligue/comité par leur code (déjà en base : `league`, `committeeCode` — que l'import **backfill** aussi).
- **Scoping/tenant** : `FfbbLeague`/`FfbbCommittee` = **référence publique sans `club_id`** (comme `Club`/`User`) → **hors RLS**, lisibles par tous, écriture par le service serveur. À exclure proprement de `TenantOwnedInterfaceCompletenessTest`.

## 5. Sécurité — SSRF & robustesse (structurant)

- **Hosts en liste blanche dure** : `api.ffbb.com`, `meilisearch-prod.ffbb.app` (+ `api.ffbb.com/assets` pour les logos). **Jamais** une URL dérivée d'input. Le **code club** est validé par format (`^[A-Z]{2,4}\d{7}$` type) avant tout appel.
- **Pas de redirection vers IP interne** (résolution DNS + rejet RFC1918/loopback/link-local), **timeout court**, **taille bornée** (JSON + logo), **MIME réel** du logo (webp/png/jpeg/avif — SVG rejeté).
- **Token public mis en cache** (récupéré sur `/items/configuration`, TTL raisonnable, fallback env var) — jamais commité.
- **Route management-gated (SEC-07)** + **rate-limit** (SEC-11).
- **Best-effort** : tout échec (API down, code inconnu, parse incomplet) → **club créé quand même**, champs laissés à null/éditables ; **jamais** de register cassé. Validation avant persist d'une entrée référence (anti-donnée-partielle).

## 6. Impact & réutilisation

- **Backend** :
  - `FfbbApiClient` (token cache + `multi-search`, hosts fixes, SSRF-safe) + `FfbbLogoFetcher` (download webp, MIME/size).
  - `FfbbClubPopulator` (service) : code club → remplit `Club` + upsert `FfbbLeague`/`FfbbCommittee` (cache-first) + logos. **Idempotent**, best-effort, validé.
  - Route `POST /api/club/ffbb-import` (management-gated) → `FfbbClubPopulator`.
  - **Hook register** : `AuthController::createClub()` → après commit, **dispatch** `PopulateClubFromFfbbMessage(clubId, ffbbCode)` → handler appelle `FfbbClubPopulator`. Non-bloquant.
- **Frontend** : section lecture seule **« Contacts FFBB »** (3 blocs) sur la page club, alimentée par `/api/me` (nouveaux champs ligue/comité). **Aucun bouton d'import** (auto à la création). État vide si l'auto-population n'a pas (encore) abouti.
- **Doc routes** : [`backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) — endpoints, récup token, index, champs consommés, mapping entités.
- **Contrat** : aucun impact engine.
- **Tests (`--group phase1`)** : SSRF (hosts fixes, rejet IP interne, code invalide) ; `FfbbClubPopulator` sur **fixture JSON figée** (mapping + cache-first + best-effort) ; garde management (`ManagementRoleTest`) ; register dispatch le message.

## 7. Phasage proposé

- **C1** — `FfbbApiClient` + `FfbbClubPopulator` + tables référence + route + hook register async (backend, le gros du risque). Tests sur fixture JSON.
- **C2** — logos réhébergés (`FfbbLogoFetcher`).
- **C3** — section « Contacts FFBB » (3 blocs) sur la page club (frontend).
- **Différé** — refresh manuel côté **superadmin** (console superadmin).

## 8. Questions ouvertes (mineures, tranchables au `/plan`)

1. **Rotation du token public** : `key_ms` change-t-il ? → cache court + re-fetch sur 401 (auto-réparation).
2. **Résolution comité/ligue** : par `code` (2ᵉ multi-search filtré) vs par `id` FFBB — trancher au plan (le `code` est plus lisible, l'`id` plus stable).
3. **Async vs inline** au register : message Messenger recommandé (non-bloquant + logos I/O) ; inline best-effort possible si on veut la data immédiatement visible. Défaut : **async**.

## 9. Décidé — plus une discovery

Décisions produit + technique verrouillées (API Meilisearch, auto à la création, institutionnel, référence cache-first, logos réhébergés, SSRF). Reste 3 micro-arbitrages plan-level (§8). CGU : usage d'une **API publique** à la création (un appel/club), acté par l'utilisateur.

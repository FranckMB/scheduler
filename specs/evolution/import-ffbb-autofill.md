# Import FFBB — autofill des infos club / ligue / comité + logos (lot C)

> **Statut** : **besoin spécifié, décisions produit posées** (retours de tests manuels 2026-07-10) ; **discovery technique du parseur ouverte**. Pas encore un `/plan`.
> **Nature** : à partir du code club FFBB, **récupérer automatiquement** depuis le portail public FFBB (`competitions.ffbb.com` + assets `api.ffbb.com`) les coordonnées **club / comité / ligue** et les **logos**, pour pré-remplir la fiche club (lot B) et afficher les contacts fédéraux régulièrement utiles au gestionnaire.
> **Rattachement roadmap** : `roadmap.md` §8 (import FFBB, FF#19) · onboarding §3. Croise [`enregistrement-ffbb.md`](enregistrement-ffbb.md) (légitimité/anti-squatting) : **même mécanisme de fetch FFBB**, finalités distinctes — à mutualiser le jour de l'implémentation.
> **Réutilise l'existant** : lot B (`Club` + `PATCH /api/club/info` : comité, contacts, correspondant, président, salle) · `LeagueResolver` (préfixe `ffbbClubCode` → ligue) · `SchoolZoneResolver` · mécanisme d'upload/stockage du logo club (`ClubAppearanceController` / route logo) · rail SSRF déjà en place pour `backend → engine` (host fixe).

---

## 1. Le besoin (reformulé, validé)

Le gestionnaire échange **beaucoup** avec sa **ligue** et son **comité** : il a besoin de leurs coordonnées sous la main. Ces infos (et le logo) sont **publiques** sur le portail FFBB, dérivables du **code club** que le club connaît déjà. Plutôt que de tout ressaisir (lot B = saisie manuelle), on **récupère quasi automatiquement** :

- **Page club** (`…/clubs/{code}`) → **logo du club**.
- **Page comité** (`…/comites/{comité}`) → **coordonnées + président du comité** + **logo comité**.
- **Page ligue** (`/ligues/{ligue}`) → **coordonnées + président de la ligue** + **logo ligue**.

Puis on **stocke** ces infos et on les **expose sur la page club**, en lecture, sous forme de **3 blocs de contact sur une ligne** (Ligue · Comité · Club).

> Décision produit (validée) — **les 3 blocs = Ligue + Comité + Club**. Chaque bloc porte :
> **fonction · nom prénom · adresse · code postal + ville · téléphone · email**.
> (Ligue/Comité = le président ; Club = le correspondant du lot B.)

## 2. Décisions produit (verrouillées)

| # | Décision |
|---|---|
| 1 | **Source = scraping HTML serveur** du portail public FFBB (host **fixe**), pas d'API tierce supposée. Le HTML FFBB peut changer → **parseur best-effort** ; tout échec **retombe sur la saisie manuelle** (lot B), jamais un blocage. |
| 1 bis | **Ligue & comité = données de référence partagées, cache-first.** Une ligue (`ara`) / un comité (`0069`) est **commun à plusieurs clubs**. À l'import, on **cherche d'abord en base** (clé = slug ligue / code comité) : **présent → on réutilise, aucun fetch FFBB** ; **absent (ou périmé) → on scrape puis on stocke** pour les clubs suivants. Réduit les fetches (CGU, rate-limit, robustesse) et partage la donnée. |
| 2 | **Déclencheur** : bouton **« Importer depuis la FFBB »** — à l'**onboarding** (pré-remplissage) **et** re-lançable depuis la page club (rafraîchissement). L'import **ne perd jamais** une saisie manuelle sans confirmation (cf. §6). |
| 3 | **Identifiants dérivés du `ffbbClubCode`** : slug ligue = préfixe 3 lettres en minuscules (`ARA…` → `ara`) ; code comité = les chiffres département (`…069…` → `0069`, déjà en base lot B) ; code club = le `ffbbClubCode` complet. Aucune saisie d'URL par l'utilisateur (anti-SSRF). |
| 4 | **Logos** club / comité / ligue **importés** (avif `api.ffbb.com/assets/{uuid}`) et stockés. Le logo **club** alimente le logo existant ; comité/ligue sont de **nouveaux** visuels affichés dans les blocs. |
| 5 | **Affichage page club** : section (lecture seule) **« Contacts FFBB »** = 3 blocs sur une ligne — **Ligue · Comité · Club** — chacun `fonction / nom prénom / adresse / CP+ville / tél / email` + logo de l'entité. Données FFBB non éditables (rafraîchies par ré-import) ; le correspondant club reste éditable via lot B. |
| 6 | **RGPD** : président ligue/comité = **contacts professionnels publics** (comme lot B) ; **aucune adresse de domicile**. Rétention/purge à couvrir par le socle RGPD pré-GA. |

Hors périmètre (PR/specs dédiées) : **preuve de légitimité gestionnaire** (→ [`enregistrement-ffbb.md`](enregistrement-ffbb.md)) · import des **équipes/catégories** (→ `FfbbExcelImporter`, roadmap §8) · rafraîchissement **automatique périodique** (ce lot = à la demande) · gestion multi-clubs.

## 3. Ce qu'on récupère et d'où

| Page FFBB (host fixe) | Données | Logo |
|---|---|---|
| `/ligues/{ligue}` | Ligue : nom, contact + **président** (fonction, nom, adresse, CP, ville, tél, email) | `api.ffbb.com/assets/{uuid}` |
| `/ligues/{ligue}/comites/{comité}` | Comité : nom, contact + **président** (idem champs) | idem |
| `…/comites/{comité}/clubs/{code}` | Club : (déjà couvert lot B) — ici surtout le **logo** | idem |

> Exemples réels fournis : ligue `ara`, comité `0069` (Rhône), club « B Charpennes Croix Luizet ». Logos servis en **avif**, `?height=220&fit=contain&format=avif`.

## 4. Données à stocker (décidé : référence partagée)

**Ligue et comité = tables de référence partagées** (décision 1 bis) : ils sont communs à plusieurs clubs, donc stockés **une fois** et réutilisés.

- **`FfbbLeague`** — clé unique = slug ligue (`ara`). Champs : nom, logo, contact président (`fonction`, `nom`, `adresse`, `codePostal`, `ville`, `téléphone`, `email`), `fetchedAt`.
- **`FfbbCommittee`** — clé unique = code comité (`0069`) [+ slug ligue de rattachement]. Mêmes champs + logo.
- **`Club`** : référence la ligue/comité (par slug/code déjà en base — `league`/`committeeCode` du lot B — ou FK). Le **logo club** reste sur `Club` (mécanisme lot B / upload existant).

**Scoping/tenant** : ces tables sont de la **donnée de référence publique**, **sans `club_id`** (comme `Club`/`User`). Elles sont donc **hors RLS** et **lisibles par tous les clubs authentifiés** (info publique) ; l'écriture (populate à l'import) passe par le service serveur. À traiter comme les entités sans `club_id` (scoping dans le provider/processor, pas via `TenantFilter`) — le vérifier contre `TenantOwnedInterfaceCompletenessTest` (elles doivent être **exclues** proprement, pas oubliées).

**Champs par bloc** (miroir du correspondant lot B) : `fonction+nom`, `adresse`, `codePostal`, `ville`, `téléphone`, `email`, `logoUrl`. **Pas d'adresse de domicile** (RGPD).

> Alternative écartée pour ce lot : dénormaliser ligue/comité **sur chaque `Club`** — plus simple mais re-scrape/duplique par club, ce que la décision 1 bis interdit explicitement.

## 5. Sécurité — SSRF & robustesse du fetch (structurant)

Le fetch sortant vers un service externe est le **vecteur A12 (SSRF)** de l'audit. La spec **exige** :

- **Host fixe** : uniquement `competitions.ffbb.com` et `api.ffbb.com` (liste blanche en dur), **jamais** une URL dérivée d'input utilisateur. Les identifiants (slug ligue, code comité/club) sont **validés par format** (`^[a-z]{2,4}$`, `^\d{4}$`, `^[A-Z0-9]+$`) avant interpolation.
- **Pas de suivi de redirection** vers une IP interne / plage privée (résolution DNS + rejet RFC1918/loopback/link-local), timeout court, **taille de réponse bornée**, **MIME réel** validé pour le logo (avif/webp/png/jpeg — SVG rejeté, cf. A8/A13).
- **Rate-limit** de la route d'import (SEC-11) — un import = un fetch multiple, ne pas laisser marteler FFBB.
- **Management-gated (SEC-07)** comme toute écriture cockpit.
- **Échec gracieux** : timeout / 404 / markup illisible → **repli saisie manuelle** (lot B), message actionnable, **aucune donnée existante écrasée** silencieusement.
- **Parseur best-effort & testé sur fixtures** : figer des snapshots HTML FFBB réels en fixtures de test ; un changement de markup fait échouer un test dédié, pas la prod.

## 6. Impact & réutilisation

- **Backend** : nouveau service `FfbbPortalClient` (fetch + parse, host fixe, SSRF-safe) + `FfbbAssetFetcher` (logo, MIME/size) ; endpoint `POST /api/club/ffbb-import` (management-gated) qui **cherche d'abord `FfbbLeague`/`FfbbCommittee` en base** (clé slug/code) et **ne fetch que sur cache-miss**, puis remplit les champs (confirmation d'écrasement si une saisie manuelle diffère) ; réutilise `LeagueResolver` (slug) et le stockage logo existant.
- **Frontend** : bouton « Importer depuis la FFBB » (onboarding + page club) ; section lecture seule **« Contacts FFBB »** (3 blocs Ligue/Comité/Club) ; états chargement / échec (→ « complétez à la main »).
- **Contrat** : aucun impact engine.
- **Tests (axes structurants)** : SSRF (host fixe, rejet IP interne, MIME) en `--group phase1` ; parseur sur fixtures HTML ; garde management (`ManagementRoleTest`) ; RGPD (pas de domicile).
- **Légal/CGU** : **l'utilisateur (Franck) doit vérifier les CGU FFBB** sur l'usage automatisé des pages publiques avant implémentation — bloquant produit, hors décision technique.

## 7. Phasage proposé

- **C1** — tables de référence `FfbbLeague`/`FfbbCommittee` + endpoint import **cache-first** + `FfbbPortalClient`/`FfbbAssetFetcher` SSRF-safe + repli manuel (backend, gros du risque sécurité).
- **C2** — bouton import (onboarding + page club) + section « Contacts FFBB » (3 blocs) + logos (frontend).
- **C3** (optionnel) — action « rafraîchir » (re-scrape d'une entrée périmée).

## 8. Questions ouvertes (à trancher avant `/plan`)

1. **CGU FFBB** : l'usage automatisé du portail public est-il autorisé ? (bloquant — décision utilisateur).
2. **Robustesse parseur** : à quelle fréquence le markup FFBB change-t-il ? faut-il une API JSON `api.ffbb.com` si elle existe (discovery à mener) plutôt que scraper le HTML ?
3. **Fraîcheur du cache** : une entrée `FfbbLeague`/`FfbbCommittee` est réutilisée telle quelle — au bout de combien de temps la considère-t-on **périmée** et re-scrape-t-on ? (défaut proposé : jamais en auto ; re-scrape seulement sur action explicite « rafraîchir »).
4. **Écrasement** : à l'import, si une saisie manuelle (lot B) diffère de la valeur FFBB, on écrase / on demande / on garde le manuel ? (défaut proposé : **confirmation d'impact**, comme lot D).
5. **Logo club** : l'import écrase-t-il un logo déjà uploadé par le gestionnaire ? (défaut : ne pas écraser sans confirmation).

## 9. Ce que ce fichier n'engage pas

Aucune décision d'implémentation ni migration. But : cadrer le besoin, verrouiller les décisions produit (3 blocs, scraping+repli, SSRF, RGPD) et forcer les 5 questions ci-dessus avant tout `/plan`.

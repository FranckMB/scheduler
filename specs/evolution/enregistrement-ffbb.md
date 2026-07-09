# Refonte de l'enregistrement (vérification FFBB + approbation) — besoin à spécifier

> **Statut** : **besoin à spécifier** (discovery **ouverte** — options posées, décisions à trancher avec l'utilisateur). **Pas un plan.**
> **Nature** : refond le point d'entrée du produit — comment un gestionnaire prouve qu'il gère bien *ce* club, et comment on empêche les doublons / l'usurpation. Business + sécurité (anti-squatting de club).
> **Rattachement roadmap** : `roadmap.md` §3 (onboarding) — croise §8 (import FFBB, FF#19).
> **Réutilise l'existant** : `Club.ffbbClubCode` · membership `ClubUser` + approbation (`MembershipController`) · mailer (déjà câblé pour le reset mot de passe) · `FfbbExcelImporter` (import équipes).

---

## 1. Le besoin (reformulé)

Aujourd'hui : `POST /api/register` prend un **code ARA + nom de club**, crée le club et fait de l'inscrivant un `admin` (`AuthController.php:344`) — **aucune preuve** que la personne gère réellement ce club, **aucune protection** contre un 2ᵉ inscrivant qui recréerait ou squatterait un club existant.

On veut un enregistrement qui répond à **deux questions** :
1. **La personne est-elle un vrai gestionnaire du club ?** — via le **code club FFBB** (à vérifier) **ou** l'**import d'un document FFBB** qui renseigne l'essentiel du club. *Fait posé : tous les gestionnaires de club ont accès au portail FFBB* → on peut s'appuyer dessus comme source de légitimité.
2. **Le club existe-t-il déjà chez nous ?** — si oui, la demande **n'auto-crée rien** : un **email part au(x) gestionnaire(s) existant(s)** pour **approuver** l'ajout du nouveau demandeur (ou le refuser).

## 2. Les deux voies d'entrée (à arbitrer)

| Voie | Ce qu'elle apporte | Faiblesse |
|---|---|---|
| **V-A — Code club FFBB** | Léger, rapide ; le code alimente déjà `ffbbClubCode` (zone scolaire, ligue) | Un code club est **public/devinable** → ne prouve PAS le rôle de gestionnaire, seulement l'existence du club |
| **V-B — Import d'un document FFBB** | Un export/attestation **nominatif** (dirigeant, licence) prouve le **rôle**, et **pré-remplit** le club (équipes, catégories) | Friction (le gestionnaire doit aller chercher le doc) ; format FFBB à valider (cf. `FfbbExcelImporter`) |

→ **Piste hybride à valider** : le **code** identifie le club (existant ou nouveau) ; le **document** (ou une vérification FFBB) sert de **preuve de légitimité** pour le **premier** gestionnaire d'un club neuf ; les **suivants** passent par l'**approbation** d'un gestionnaire déjà en place (pas besoin de re-prouver via FFBB).

## 3. Le flux « club déjà existant → approbation »

- Demande sur un club déjà chez nous → statut **`pending`** (membership non active), **rien n'est exposé** au demandeur.
- **Email au(x) gestionnaire(s) existant(s)** : « X demande à gérer {club}. Approuver / Refuser » (lien signé, TTL).
- Approbation → membership `active` + rôle ; refus → demande close. Relance + expiration à définir.
- Réutilise le rail `MembershipController` (approbation déjà là) + le mailer.

## 4. Questions ouvertes (à trancher)

1. **Source de vérité FFBB** : API FFBB (existe-t-elle, accessible ?) vs **document exporté** (lequel : export équipes, attestation dirigeant nominative ?). Détermine V-A/V-B.
2. **Preuve du rôle gestionnaire** : que vérifie-t-on réellement ? (le doc est-il nominatif ? recoupe-t-on le nom de l'inscrivant ?) — sinon la « vérification » reste déclarative.
3. **Premier gestionnaire d'un club neuf** : personne pour approuver → qui valide ? (a) auto-approuvé après import doc réussi, (b) **superadmin** en fallback (cf. [`console-superadmin.md`](console-superadmin.md)), (c) simple confiance + audit.
4. **Rôles** : aujourd'hui tout membre = `admin`. La refonte introduit-elle **gestionnaire vs coach non-management** ? (croise le trou de sécurité **A5/A6** — `/api/club_users` sans gate — repéré dans la posture cyber 2026-07-09).
5. **Anti-abus** : rate-limit sur les demandes, anti-spam d'emails d'approbation, expiration/relance.
6. **RGPD** : un document FFBB nominatif = donnée personnelle → rétention/purge du justificatif après vérification.

## 5. Réutilisation & impact (esquisse, non tranché)

- **Données** : `ffbbClubCode` déjà là ; membership `pending`/`active` déjà modélisable via `ClubUser.isActive` ; un éventuel `Club.verifiedAt`/justificatif à ajouter.
- **Sécurité** : la refonte est **le bon moment** pour fermer A5/A6 (gate management sur `/api/club_users`, `role` server-controlled) — à faire dans le même chantier.
- **Import** : V-B recoupe `FfbbExcelImporter` / futur import FFBB (§8, FF#19) — mutualiser le parseur.

## 6. Ce que ce fichier n'engage pas

Aucune décision d'implémentation. But : cadrer le besoin et forcer les 6 questions ci-dessus avant tout `/plan`.

# Bridage plan Découverte (freemium) + offres par statut — besoin spécifié

> **Statut** : **besoin spécifié** (discovery close, décisions tranchées §5) — **pas un plan**.
> **Nature** : fixe le modèle du plan gratuit ET le socle d'offres par statut, business-critique.
> **Rattachement roadmap** : **P1-3**.
> ⚑ **Modèle HYBRIDE acté le 2026-08-09 (soir)** — troisième et dernière itération d'une même journée
> de cadrage, chaque renversement tracé en §3 : générations seules (2026-08-04) → cap 12 équipes
> (2026-08-09 matin, après l'étude [`etude-tailles-clubs-ffbb.md`](etude-tailles-clubs-ffbb.md) et une
> passe `business-challenger`) → **hybride** (2026-08-09 soir : périmètre complet gratuit + générations
> limitées + features off, cap d'équipes réservé aux paliers PAYANTS).
> **Réutilise l'existant** : `SubscriptionPlan` (modèle livré, aucune offre seedée) · `Club.planId`
> (⚠ `?int` face à un id guid — lien à réparer) · `Club.paidSeasonYear` + gate de bascule saison
> (P1-5, livré — la « transition off en gratuit » est DÉJÀ effective) · quotas de solve anti-abus
> par club (P4-45, indépendants du business) · patron de garde `ClubQuotaSubscriber` pour le compteur.
> **Zéro changement engine.**

---

## 1. Le but

Laisser un gestionnaire **saisir TOUT son club** (import FFBB compris) et **voir le solveur résoudre son
vrai problème** — le coût de bascule (saisir les contraintes orales) est payé AVANT tout mur, et le
planning obtenu est l'argument que le bénévole porte à son bureau. La conversion vient de ce que la
**saison vivante est payante** : plans de vacances, matchs, exports, saison suivante.

## 2. Le modèle : Découverte complète mais bornée, payants par taille

### Découverte (gratuit — l'entrée par défaut de tout compte)

- **Périmètre ILLIMITÉ en saisie** : toutes ses équipes (l'import FFBB d'onboarding importe tout),
  gymnases, coachs, contraintes. Le solveur résout le club ENTIER — le wow est maximal, et il n'existe
  **aucun cap d'équipes en gratuit**.
- **10 générations AU TOTAL** (valeur de config), **non rechargeables** — pas « par saison », pas de
  limite horaire (la soirée générer-ajuster-regénérer est la boucle normale ; l'anti-abus de débit
  reste P4-45). Compte les 3 routes de solve. Reset **superadmin seulement** (cas particuliers).
  À l'épuisement : plus de génération — **tout le reste fonctionne** (consultation, ajustement manuel).
  Pas de read-only, pas de lockout : le planning reste vivant à la main, l'envie de régénérer convertit.
- **Features OFF** (les murs de conversion) : **module matchs** OFF · **plans de période/vacances
  (overlays)** OFF · **export PDF** OFF · **transition de saison** OFF (déjà effectif via
  `paidSeasonYear`, livré). Tout le reste est ouvert.

### Offres payantes — « quand tu payes, c'est juste le cap par équipe »

- **100 % des fonctionnalités**, générations **illimitées** (P4-45 seul frein). La SEULE différence
  entre paliers = le **cap d'équipes** :

| Offre | Cap équipes (app) | Note |
|---|---|---|
| Essentiel | ≤ 20 | |
| Club | 21-30 | |
| Grand club | 31-50 | |
| Sans limite | illimité | les > 50 (≈ BCCL et au-delà) |

  Frontières = **valeurs en base** (`maxTeams`), ajustables sans redéploiement ; calées sur l'étude
  (§4bis) et le read fondateur. Aucun montant nulle part — « sur demande ».
- **Enforcement du cap payant** : refus de créer une équipe AU-DELÀ du cap de SON offre (création
  unitaire, imports), message nommant le palier supérieur. Un club prend l'offre qui correspond à la
  taille qu'il a déjà saisie (« j'ai 15 équipes → Essentiel »).
- **Attribution superadmin seul** (virement → offre + `paidSeasonYear` posés en console). Valable
  **une saison** ; à l'expiration, l'offre effective retombe sur **Découverte** (features se ferment,
  compteur de générations tel quel) — le renouvellement rouvre tout. Pas de mécanisme de read-only.

### Bêta

- **Une offre** comme les autres : tout illimité, attribuable **UNIQUEMENT par le superadmin**,
  valable **une saison** ; à l'expiration → Découverte, le club choisit.

## 3. Historique des renversements (pourquoi CE modèle)

| Modèle | Sort |
|---|---|
| **~4 générations, club complet** (2026-08-04) | Renversé le 2026-08-09 matin : un club discipliné capte la valeur annuelle en 4 générations + ajustements gratuits ; mur en cours de saison (hors fenêtre d'achat) ; punit l'itération |
| **Cap 12 équipes, générations illimitées** (2026-08-09 matin) | Renversé le 2026-08-09 soir : son risque n°1 (nommé par le `business-challenger`, jamais couvert) — un club de 25 équipes **n'investit pas la saisie** face à un mur à 12, et l'essai sur sous-ensemble n'est pas le vrai club. Le test wow-12 (concluant) reste valable : il prouvait qu'un problème à 12 équipes n'est pas trivial — a fortiori le club entier |
| **Hybride** (2026-08-09 soir, ACTÉ) | Cumule les forces : saisie complète gratuite (coût de bascule payé avant le mur, sunk cost pro-conversion), wow sur le vrai club, murs = saison vivante (10 générations totales + matchs/vacances/PDF/transition OFF), et le trou de 2026-08-04 est bouché — le socle gratuit ne donne ni les vacances, ni les matchs, ni l'export, ni la saison suivante. Les 10 générations brûlent pendant la construction du socle (août-sept) → mur dans la fenêtre d'achat |
| Limite horaire (« 1-2/h ») | Écartée : punit la soirée de travail active ; P4-45 borne déjà le débit |
| Bombe temps · cap par saison · lockout/read-only | Écartés (inchangé 2026-08-04) — le read-only devient de surcroît INUTILE : l'expiration retombe sur Découverte et la transition payante verrouille la suite |

## 4. Enforcement — petit, sur des patrons existants

1. **Compteur 10 générations** : garde sur les 3 routes de solve (patron `ClubQuotaSubscriber` P4-45),
   active si l'offre EFFECTIVE est Découverte. Nouveau champ compteur **total** (l'existant
   `generation_count_season` se remet à zéro par saison — inutilisable). Reset superadmin.
2. **Features off Découverte** : gate matchs (rail fixtures/placement) · gate création de plan de
   période (overlays) · gate export PDF. La transition de saison est déjà gatée (`paidSeasonYear`).
3. **Cap payant aux portes de création d'équipes** (création unitaire, import Excel, import FFBB,
   confirm engagements) : ne s'applique QU'AUX offres payantes (Découverte et Bêta sans cap).
4. **Offre effective calculée à la lecture** (service de droits) : `planId` null → Découverte ;
   offre payante/bêta avec `paidSeasonYear` périmé → Découverte. Pas de cron, pas d'état stocké.
   Club démo (`isDemo`) : droits pleins, toujours.

## 5. Décisions tranchées (2026-08-09 soir — remplacent toutes les précédentes)

1. **Découverte** = défaut de tout compte : périmètre illimité, **10 générations totales** non
   rechargeables (config), matchs/overlays/PDF/transition OFF, pas de lockout à l'épuisement.
2. **Payants** = 100 % des features, générations illimitées, **seul le cap d'équipes varie** :
   Essentiel ≤ 20 · Club ≤ 30 · Grand club ≤ 50 · Sans limite. Aucun montant dans l'app.
3. **Bêta = une offre** superadmin-only, une saison, illimitée.
4. **Attribution d'offre = superadmin seul** (virement) ; expiration → Découverte effective.
5. **PSP = Stripe, différé** (décision du matin, inchangée) ; prérequis SIRET avant premier encaissement.
6. Le nom du gratuit reste **« Découverte »** (conforme à toute la doc).
7. Affiliation → parking roadmap.
8. `monthlyPrice`/`annualPrice` : à retirer du modèle (aucun montant nulle part) ; `maxGenerations`/
   `maxVenues` : convention **0 = illimité**.

## 6. Dépendances & hors scope

- **Contournement « 10 générations puis on vit à la main »** : assumé — sans vacances, sans matchs,
  sans PDF et sans saison suivante, le planning gratuit est un souvenir, pas un outil de saison.
- **Multi-comptes** : sans objet (le périmètre gratuit est déjà complet).
- **Guidage des contraintes** : chantier séparé (l'enjeu d'une génération gâchée monte avec un compteur).
- **Compte démo vendeur** : complémentaire, exempt de tout gate (`isDemo`).
- Montants, Van Westendorp, Stripe/checkout, notification d'expiration : hors scope de ce doc.

## 7. Garde-fous (mis à jour)

1. ✅ **Test wow-12 (2026-08-09)** — gardé pour mémoire : un problème à 12 équipes n'est pas trivial
   (17/17 séances, 0 violation, infaisabilité réelle détectée en 3 ms). Le modèle hybride résout mieux
   encore (club entier).
2. **Question aux clubs bêta** (reformulée pour l'hybride) : « 10 générations gratuites pour construire
   ton socle, puis vacances/matchs/PDF payants — tu aurais payé à quel moment ? » — Rillieux en premier.
3. Le chiffre **10** est une valeur de config ajustable, pas une promesse produit.

## 8. Axes structurants (§7.1) & vérification

- **generation pipeline** : le compteur gate les 3 routes de solve → NR (Découverte à 10/10 → refus
  avec message ; payant/bêta → jamais de refus business ; reset superadmin rouvre ; `ClubQuotaTest`
  P4-45 ne doit pas rougir).
- **auth & memberships / périmètre** : caps payants aux portes de création + features off → NR
  (Découverte crée sa 25ᵉ équipe SANS refus ; Essentiel refuse la 21ᵉ avec message ; matchs/overlay/PDF
  refusés en Découverte, ouverts en payant et bêta ; expiration → Découverte effective).
- **Vérification** : smoke-solveur inchangé (`backend/scripts/smoke-solver.sh`, COMPLETED) — le club
  de smoke doit garder du quota ou une offre non bridée.

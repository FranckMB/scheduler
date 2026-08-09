# Bridage plan Découverte (freemium) — besoin spécifié

> **Statut** : **besoin spécifié** (discovery close, décisions tranchées §5) — **pas un plan**.
> **Nature** : fixe le modèle de bridage du plan gratuit (Découverte / freemium), business-critique — pas de SaaS sans verrou de conversion.
> **Rattachement roadmap** : **P1-3**.
> ⚑ **Modèle RETRANCHÉ le 2026-08-09** (point de cadrage fondateur, nourri par
> [`etude-tailles-clubs-ffbb.md`](etude-tailles-clubs-ffbb.md) et passé au `business-challenger`) :
> le gate « ~4 générations totales » du 2026-08-04 est **remplacé par un cap d'équipes**.
> L'historique et les raisons du renversement : §3.
> **Réutilise l'existant** : `Club.planId` · `billing_cycle`/`plan_expires_at` · le **verrou read-only serveur** des 4 chemins d'édition (patron de la version choisie, ADR-0002) · le gate de bascule `Club.paidSeasonYear` (P1-5, livré) · les quotas de solve anti-abus par club (P4-45, indépendants du business). **Zéro changement engine.**

---

## 1. Le but

Laisser un gestionnaire **voir le gain du solveur sur SES gymnases, SES coachs, SES contraintes** — assez
pour porter l'argument à son bureau — puis le convertir **au moment où le club achète** (l'avant-saison).
Le freemium montre la magie sur un périmètre réel réduit ; le **compte démo vendeur** (livré) montre le
wow gros-club ; la formule payante fait le club entier.

## 2. Le modèle : gratuit jusqu'à 12 équipes, générations illimitées

- **Cap = 12 équipes dans l'app** (fondateur, 2026-08-09). Au-delà → création d'équipe refusée avec un
  message qui donne le prix de la formule adaptée à la taille du club. Étude à l'appui : ~76 % des clubs
  français (82 % dans le Rhône) ont plus que ça en réel → **le mur touche la quasi-totalité du marché
  cible**, et il touche **pendant la saisie, donc dans la fenêtre d'achat** (l'avant-saison — le bureau
  achète avant la saison, jamais au milieu). 12 équipes sur 2-3 gymnases = de vrais conflits : le solveur
  ne paraît pas nul.
- **Générations ILLIMITÉES.** L'itération générer→ajuster→régénérer est la philosophie de la work-loop ;
  le freemium ne la punit plus. Le coût de calcul est borné par les **quotas anti-abus P4-45** (par club,
  3 routes), qui restent en place et ne sont PAS un mécanisme business.
- **Un seul axe partout** : l'équipe est le value metric (la douleur scale avec les équipes —
  `business/pricing.md`), la grille payante est par taille, le freemium est le palier 0 de la même grille.
  « Gratuit jusqu'à 12 équipes » s'explique en une phrase.
- **PDF export off** en freemium (feature de conversion, inchangé).
- **Transition de saison off** en freemium (inchangé — un essai est mono-saison).
- **Pas de limite de temps, pas de compteur, pas de read-only d'épuisement** pour le freemium ≤ 12 :
  un petit club (≈ 22 % du marché, douleur faible < 10 équipes, WTP ≈ 0 — contexte business §2) utilise
  gratuitement à vie. **Assumé** : il n'aurait pas payé, et il parle aux autres clubs — le canal
  d'acquisition EST le bouche-à-oreille.
- **Expiration du payant** : un club payé saison N qui ne renouvelle pas et dépasse 12 équipes passe en
  **read-only total** (données préservées et visibles, aucune action) — le verrou serveur ADR-0002 des
  4 chemins d'édition est réutilisé avec ce déclencheur. Verrou de conversion, pas lockout.
- **Default freemium** : tout le monde démarre en Découverte ; le choix d'offre se fait à la conversion
  (inchangé).

## 3. Pourquoi ce modèle (et pourquoi le précédent est tombé)

| Piste | Statut |
|---|---|
| **Cap ~4 générations totales, club complet** (modèle du 2026-08-04) | **RENVERSÉ le 2026-08-09.** Trois défauts mesurés depuis : (a) un club discipliné boucle son planning de saison en 2-3 générations + ajustements gratuits → **la valeur annuelle part gratis**, conversion repoussée d'un an ; (b) le mur tombe **en cours de saison** (socle + 3-4 plans de vacances > 4 solves), hors fenêtre d'achat du bureau ; (c) il punit l'itération, cœur de la work-loop. Sa crainte fondatrice (« un cap d'entité rend le solveur nul ») est **désamorcée par l'étude** : le cap est calibré à 12 (vrais conflits), et le wow gros-club vit dans le compte démo vendeur (livré) |
| **Bombe temps** (1 mois puis blocage) | Écartée (inchangé) — se bat contre le rythme saisonnier lent du club amateur |
| **Cap par saison rechargeable** | Sans objet dans le nouveau modèle (plus de compteur) |
| **Hybride équipes + générations** | Écarté 2026-08-09 — double mur = double friction, double enforcement, message illisible |
| **Lockout total** | Écarté (inchangé) — read-only préserve le désir de revenir (ne subsiste que pour le payant expiré, §2) |

## 4. Enforcement — petit, pas transversal

1. **Cap équipes** : garde aux points d'entrée qui créent des équipes — création unitaire, **import Excel**
   (P3-7 : refus au-delà du cap avec le décompte), duplication/transition (déjà off en freemium). Refus =
   message de conversion nommant la formule adaptée. Pas d'état « déjà au-dessus » en freemium pur
   (default freemium ⇒ on ne peut jamais y être) ; le seul chemin au-dessus du cap est le payant expiré → read-only (§2).
2. **Export off** : garde sur l'export PDF si plan Découverte (inchangé).
3. **Read-only payant-expiré** : réutilise le verrou serveur ADR-0002 des 4 chemins d'édition,
   déclencheur = `paidSeasonYear` périmé ET > 12 équipes.

Pas de compteur à persister, pas de reset superadmin, pas de garde sur `/generate` (les quotas P4-45 y
restent pour l'anti-abus, indépendamment du plan).

## 5. Décisions tranchées (2026-08-09, remplacent celles du 2026-08-04)

1. **Gate = cap de 12 équipes app** ; générations illimitées ; pas de limite de temps.
2. **PDF export off** en freemium.
3. **Transition de saison off** en freemium.
4. **Petits clubs (≤ 12) gratuits à vie — assumé** (WTP ≈ 0, carburant du bouche-à-oreille).
5. **Read-only** réservé au cas « payant expiré au-dessus du cap » (données préservées).
6. **Default freemium** ; choix d'offre à la conversion.
7. **PSP = Stripe** (cible 100 % France, franchise de TVA au départ → le merchant-of-record type Polar
   paierait double commission pour un service sans objet ; facture au SIRET du fondateur = confiance
   trésorier). **Intégration DIFFÉRÉE** : P1-3 se livre avec **virement + marquage superadmin**
   (`paidSeasonYear`) ; Stripe (checkout carte + SEPA + webhook) le jour où un club veut payer par carte.
   ⚠ Prérequis légal : la micro-entreprise (SIRET) doit exister avant le premier encaissement.

## 6. Dépendances & hors scope

- **Anti-abus « plusieurs clubs de 12 »** : découper son club en 2 comptes casse le planning (gymnases
  partagés entre comptes = conflits invisibles au solveur) — contournement auto-punitif, surveillé, pas bloqué.
- **Guidage des contraintes** : besoin cœur produit, chantier séparé (inchangé).
- **Compte démo vendeur** : livré — c'est lui qui porte le wow gros-club, le freemium n'a plus à le faire.
- Les offres payantes (`SubscriptionPlan` : seed des 4 offres, `billing_cycle`/`plan_expires_at`) et les
  **prix** : hors scope de ce doc — grille et montants dans `business/pricing.md`, à valider par le
  **Van Westendorp** sur les clubs bêta avant toute annonce.

## 7. Garde-fous avant implémentation (issus du `business-challenger`, 2026-08-09)

L'hypothèse la plus risquée du modèle : **« un club de 20-30 équipes investit la saisie face à un mur à
12 équipes, et le sous-ensemble suffit au wow »**. Deux tests, AVANT ou PENDANT la construction :

1. **Test wow-12 (~1 h)** : générer sur un club tronqué à 12 équipes (démo ou BCCL) et juger le rendu —
   si le solveur paraît trivial, remonter le cap (15) avant de coder le chiffre en dur → le cap vit en
   **config**, pas en constante.
2. **Question aux clubs bêta (15 min)** : « si l'app était gratuite jusqu'à 12 équipes et payante au-delà,
   tu aurais fait quoi le premier jour ? » — teste le risque « n'investit pas la saisie ».

Le chiffre 12 est une **valeur de config ajustable**, pas une promesse produit.

## 8. Axes structurants (§7.1) & vérification

- **auth & memberships / périmètre** : le cap équipes gate la création/import → NR (13ᵉ équipe refusée en
  Découverte avec le message de conversion ; import Excel > cap refusé ; club payé non bridé ; payant
  expiré > cap → 4 chemins d'édition refusés, lecture OK).
- **generation pipeline** : PAS de garde business sur `/generate` — NR inversé : un freemium ≤ 12 équipes
  génère sans limite business (les quotas P4-45 restent, `ClubQuotaTest` les garde déjà).
- **Vérification** : smoke-solveur inchangé (le freemium génère un vrai plan).

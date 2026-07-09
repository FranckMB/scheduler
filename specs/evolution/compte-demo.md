# Compte / mode démo (montrer la puissance de l'app) — besoin à spécifier

> **Statut** : **besoin à spécifier** (discovery **ouverte** — options posées, décisions à trancher). **Pas un plan.**
> **Nature** : levier de **vente**. Montrer en quelques secondes le « wow » du solveur (un vrai planning de club résolu) sans que le prospect saisisse quoi que ce soit.
> **Rattachement roadmap** : `roadmap.md` §3 (onboarding) — concrétise la ligne existante **« Mode démo »** (fort levier de vente).
> **Réutilise l'existant** : fixtures **`BasketballInit`** (déjà un club réaliste, BCCL, 40+ équipes, contraintes, réservations, couleurs) · plan **Découverte** · `ResetSeasonController` / `app:seasons:purge` · seed superadmin (reset).

---

## 1. Le besoin (reformulé)

« **Il me faut un compte de démo pour montrer aux gens la puissance de l'application.** »

Deux usages possibles — **à distinguer** car ils ne mènent pas au même design :
- **D-vendeur** : un compte que **toi** utilises en rendez-vous pour **démontrer** l'app en live (données réalistes déjà là, tout déverrouillé, reset facile entre deux démos).
- **D-prospect (self-service)** : le visiteur clique « **Essayer avec des données d'exemple** » et explore lui-même — c'est la ligne roadmap **« Mode démo »** (club fictif pré-rempli, génération avant saisie).

Ils peuvent être **le même socle** (un club démo seedé) exposé de deux façons.

## 2. Options (à arbitrer)

| Option | Description | Pour / Contre |
|---|---|---|
| **A — Club démo seedé + login partagé** | Un club « démo » (type BCCL) toujours présent, login connu, **reset périodique** (cron restore fixtures) | + simple, données riches immédiates · − état partagé (deux démos simultanées se marchent dessus), pollution si non isolé |
| **B — Sandbox éphémère par visiteur** | À l'entrée « essayer », on **provisionne un club jetable** (TTL court), purgé automatiquement | + isolé, propre · − coût (provisioning/purge), plus lourd à bâtir |
| **C — Mode démo self-service** (ligne roadmap) | Bouton « données d'exemple » → parcours démo pré-rempli, **génération 30 s avant saisie** | + montre le wow sans friction · − à cadrer avec A/B (est-ce le rendu de A ou B ?) |

## 3. Questions ouvertes (à trancher)

1. **Vendeur vs prospect** : on vise lequel en premier ? (le plus simple = **A** pour la démo vendeur ; **C** dessus pour le self-service).
2. **Données** : réutiliser le club **BCCL des fixtures** (réaliste, déjà là) ou un club fictif anonymisé dédié « Démo Basket Club » ? (BCCL contient des noms de coachs → préférer un jeu **anonymisé** pour une démo publique — RGPD).
3. **Reset entre démos** : cron de restauration des fixtures ? snapshot/restore ? read-only + « régénérer » suffisant ?
4. **Isolation** : un club **taggé démo** (flag `Club.isDemo`) pour qu'il **ne compte pas** dans les métriques réelles (cf. [`console-superadmin.md`](console-superadmin.md)) et ne se mélange pas aux vrais clubs.
5. **Périmètre déverrouillé** : la démo montre-t-elle **tout** (export PDF, matchs…) ou reste-t-elle en **Découverte** (cf. [`bridage-freemium-decouverte.md`](bridage-freemium-decouverte.md)) ? Pour vendre, plutôt **tout déverrouillé**.
6. **Accès** : login démo partagé ? lien magique sans compte ? bouton public sur la landing ?

## 4. Réutilisation & impact (esquisse, non tranché)

- **Fixtures** : `BasketballInit` fournit déjà le club réaliste — un **variant anonymisé** + flag `isDemo` couvre A/C à faible coût.
- **Reset** : `ResetSeasonController` + purge CLI existants → base d'un reset démo.
- **Bridage** : décider si la démo ignore le quota Découverte (probable, pour montrer la génération sans butoir).

## 5. Ce que ce fichier n'engage pas

Aucune décision d'implémentation. But : cadrer les deux usages (vendeur / prospect), poser A/B/C et forcer les 6 questions avant tout `/plan`. Met à jour la ligne roadmap **« Mode démo »** existante (pointeur), sans la dupliquer.

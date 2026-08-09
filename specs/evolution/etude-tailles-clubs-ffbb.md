# Étude — tailles des clubs de basket (équipes engagées), calibrage du freemium

> **Livrable de recherche**, pas du code. Alimente le **point de cadrage P1-3** (cap freemium :
> générations vs équipes — décision fondateur à prendre, [roadmap](roadmap.md)) et la grille
> tarifaire par taille de club. Mesures faites le **2026-08-09** sur l'API publique FFBB
> (Meilisearch, index `ffbbserver_organismes` — le même jeton public que `FfbbApiClient` consomme
> déjà). Zones demandées par le fondateur : **Rhône, Martinique, Guyane, AURA, Nouvelle-Aquitaine,
> Île-de-France** — l'index étant national et sous le plafond de pagination (4 513 groupements),
> l'étude porte la **France entière** et les zones en sont des découpes.

---

## 1. Verdict en cinq lignes

1. **Le club médian français engage ~14 équipes en championnat** (moyenne 13,9 ; p90 = 26 ; maximum ≈ 40).
   Le Rhône est **au-dessus** de la médiane nationale (méd. 17, p90 26, max 33).
2. **Un cap d'équipes bas exclut vite** : seuls **31 % des clubs français tiennent en ≤ 8 équipes engagées**,
   43 % en ≤ 12, 55 % en ≤ 15. Dans le Rhône c'est plus dur encore : 20 % / 29 % / 40 %.
3. **Les équipes engagées SOUS-comptent les équipes de l'app** — étalon BCCL (précisé par le fondateur,
   2026-08-09) : **21 équipes jeunes réelles U9-U21 + 7 seniors/vétérans = 28 en compétition**, l'étude en
   mesure 25 (sous-compte ~11 % : plafond jeunes + dédoublonnage), et **49 équipes vivent dans l'app** —
   l'écart (~21) c'est le **loisir et les « équipes de travail »** créées pour obtenir des créneaux :
   invisibles fédéralement, bien réelles pour le planning. Tout cap exprimé « équipes dans l'app » doit
   se lire **~2× le chiffre engagé**.
4. **La distribution est homogène entre grandes régions** (médianes 14-17) : une seule grille nationale
   suffit. Les **DOM sont plus petits** (Guyane : 11 clubs engagés, TOUS ≤ 14 équipes ; Martinique méd. 15).
5. **Féminin ≈ 35 %** des équipes (M 59 %, mixte 6 % — le mixte vit surtout en U7-U11). **Jeunes ≈ 78 %**
   des équipes engagées. **591 ententes/unions** portent des équipes comme structures propres (13 % des
   groupements) — très inégal : 93 en Nouvelle-Aquitaine (rural), 0 déclarées dans le Rhône.

## 2. Méthode (reproductible) et limites

**Source.** `POST https://meilisearch-prod.ffbb.app/multi-search`, jeton public lu sur
`GET https://api.ffbb.com/items/configuration` (le chemin exact de `backend/docs/ffbb-api.md`).
Index `ffbbserver_organismes`, filtre `type = "Groupement"` (exclut comités/ligues/fédération),
pagination `hitsPerPage` — 4 513 documents, 5 requêtes. Chaque document porte `engagements_codes` :
le cumul des compétitions où le club a aligné une équipe (saison 25-26 complète + début 26-27),
`type_association` (Club / Entente / Union / Association club professionnel), la commune et le
département, la ligue (via `url_competition`).

**Comptage.** Une « équipe » = une entrée championnat de `engagements_codes` après nettoyage :

- **exclus** : coupes, matchs amicaux (toutes graphies), brassages, plateaux mini-basket, tournois
  qualificatifs, phases 2/3 et re-engagements de 2ᵉ semestre, poules de classement, barrages, 3x3
  (discipline à part : mêmes joueurs, pas un créneau d'entraînement propre) ;
- **dédoublonnage** : par (catégorie × sexe), équipes = paires (niveau, division) distinctes,
  **plafonné à 3 pour les jeunes** — des comités re-brassent leurs divisions jeunes par cycles
  (mesuré en Val-d'Oise : la même U13F traverse « Division 9 », « Division 10 », « Division 5 » dans
  la saison), sans plafond la même équipe compterait à chaque cycle ;
- le **loisir** compte comme équipe (il occupe un créneau d'entraînement), les **vétérans** aussi.

**Limites, à garder sous les yeux :**

| # | Limite | Effet |
|---|--------|-------|
| 1 | Équipes **engagées** ≠ équipes **dans l'app** : école de basket et groupes non engagés invisibles | Sous-compte systématique — étalon BCCL : 25 engagées pour 49 dans l'app (× ~2) |
| 2 | `engagements_codes` mélange 25-26 et le début 26-27, dédoublonné par texte | Une équipe qui a changé de division entre les deux saisons peut compter double (marginal) |
| 3 | Le plafond jeunes à 3 par (catégorie × sexe) | Sous-compte les très gros programmes jeunes ; sans lui, Val-d'Oise sort des clubs à 60 « équipes » |
| 4 | ~7 % d'entrées au format de comité exotique restent partiellement classées (niveau ou sexe inconnus) | Comptées comme équipes, mal ventilées F/M ou par niveau |
| 5 | Seine-Saint-Denis et Val-d'Oise gardent des médianes 28-29 même plafonnées | Urbain dense réellement gros, mais résidu d'inflation possible : lire l'IDF comme une borne haute |
| 6 | Licéité d'un usage récurrent de l'API non vérifiée (CGU) — même réserve que la reconnaissance P2-19 | Étude ponctuelle OK ; industrialiser demanderait la vérification |

## 3. Vue par zone

| Zone | Clubs | Sans engagement | Ententes/unions | Équipes | F | M | Mixte | Jeunes | Seniors+vét. | Moy. | Méd. | p75 | p90 | Max |
|------|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| **France entière** | 4 513 | 459 | 591 | 56 346 | 19 723 | 33 223 | 2 104 | 43 457 | 12 005 | 13,9 | 14 | 21 | 26 | 40 |
| **Rhône (69)** | 112 | 1 | 0 | 1 789 | 616 | 1 155 | 0 | 1 394 | 357 | 16,1 | **17** | 23 | 26 | 33 |
| **AURA** | 555 | 29 | 41 | 7 360 | 2 880 | 4 295 | 112 | 5 602 | 1 655 | 14,0 | 14 | 20 | 25 | 33 |
| **Nouvelle-Aquitaine** | 501 | 42 | 93 | 6 576 | 2 405 | 3 723 | 264 | 5 044 | 1 426 | 14,3 | 16 | 21 | 24 | 32 |
| **Île-de-France** | 458 | 42 | 49 | 7 049 | 1 987 | 4 295 | 649 | 5 699 | 1 240 | 16,9 | 17 | 25 | 32 | 39 |
| **Martinique** | 23 | 2 | 0 | 294 | 59 | 134 | 7 | 240 | 40 | 14,0 | 15 | 17 | 20 | 21 |
| **Guyane** | 13 | 2 | 0 | 127 | 36 | 87 | 0 | 99 | 22 | 11,5 | 12 | 13 | 13 | 14 |

Moyenne/médiane/percentiles calculés sur les clubs **ayant ≥ 1 engagement**. « Sans engagement » =
structures affiliées sans équipe en championnat (école-de-basket pures, 3x3, sommeil) — des prospects
aussi, mais très petits. F+M+mixte < total : le résidu est le ~7 % non ventilé (limite 4).

## 4. La table de décision — % de clubs tenant sous un cap d'équipes ENGAGÉES

| Cap (engagées) | France | Rhône | AURA | N.-Aquitaine | IDF | Martinique | Guyane |
|---:|---:|---:|---:|---:|---:|---:|---:|
| ≤ 2 | 16 % | 12 % | — | — | — | — | 0 % |
| ≤ 4 | 22 % | 17 % | 18 % | 21 % | 17 % | 10 % | 0 % |
| ≤ 6 | 26 % | 18 % | — | — | — | — | 0 % |
| ≤ 8 | 31 % | 21 % | 28 % | 27 % | 26 % | 14 % | 0 % |
| ≤ 10 | 37 % | 23 % | 34 % | 33 % | 31 % | 24 % | 18 % |
| ≤ 12 | 43 % | 29 % | 42 % | 38 % | 37 % | 29 % | 73 % |
| ≤ 15 | 55 % | 40 % | 55 % | 49 % | 46 % | 52 % | 100 % |
| ≤ 20 | 74 % | 66 % | 77 % | 73 % | 60 % | 95 % | 100 % |
| ≤ 25 | 90 % | 88 % | — | — | — | — | 100 % |

**Lecture produit (avec le × ~2 engagées→app)** : un cap freemium de **10 équipes dans l'app**
correspond à ~5 engagées → il ne couvre entièrement que **~25 % des clubs** (18 % dans le Rhône) —
tous les autres voient la limite. Un cap à **20 dans l'app** (~10 engagées) laisse encore ~63 %
des clubs face à la limite. Autrement dit : **il existe une vraie zone de cap où le solveur reste
impressionnant (10-15 équipes app) tout en freinant la quasi-totalité des clubs réels** — l'inverse
du cap génération, contournable par n'importe quel club soigneux. La distribution donne aussi une
assiette naturelle pour un prix par palier de taille (ex. : ≤ 15 / 16-25 / 26-40 / > 40 équipes app —
à trancher au point de cadrage P1-3, pas ici.)

## 4bis. Les 4 segments de taille (découpage fondateur, validé par les données)

Découpage proposé par le fondateur de tête (« &lt; 12, 13-22, 23-30, +30 ») et passé au crible — **il tient**,
sur le compte d'équipes **engagées** :

| Segment (engagées) | France | Rhône | AURA | N.-Aquitaine | IDF | Martinique | Guyane |
|---|---:|---:|---:|---:|---:|---:|---:|
| **≤ 12** — petits | 43 % | 29 % | 42 % | 38 % | 37 % | 29 % | 73 % |
| **13-22** — moyens | 38 % | 45 % | 41 % | 45 % | 30 % | 71 % | 27 % |
| **23-30** — gros | 16 % | 24 % | 15 % | 16 % | 22 % | 0 % | 0 % |
| **> 30** — très gros | 3 % | 2 % | 1 % | 1 % | 11 % | 0 % | 0 % |

Ce qui le valide : les parts **décroissent proprement** partout, les deux premiers segments couvrent ~80 %
du marché, et le « > 30 » est une vraie queue (3 % national — un palier « très gros club », pas un segment
de masse ; seule l'IDF y pèse, 11,5 %). Deux nuances : (a) le « ≤ 12 » agrège presque la moitié des clubs —
si un micro-palier est un jour utile, la donnée le place à **≤ 6** (26 % national) ; (b) les frontières sont
en équipes ENGAGÉES — exprimées en équipes app (l'unité qu'un cap verra), elles se lisent ~× 2
(≈ ≤ 24 / 25-45 / 46-60 / > 60), à retravailler au point de cadrage avec le ratio consolidé.

## 5. Par département (ligues AURA, Nouvelle-Aquitaine, Île-de-France)

| Département | Clubs | Ententes | Équipes | Moy. | Méd. | p90 | ≤ 8 | ≤ 12 |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Ain | 47 | 6 | 718 | 17,1 | 19 | 30 | 21 % | 29 % |
| Allier | 20 | 2 | 343 | 18,1 | 20 | 27 | 21 % | 32 % |
| Ardèche | 23 | 2 | 267 | 11,6 | 11 | 23 | 39 % | 57 % |
| Cantal | 5 | 0 | 36 | 18,0 | 9 | 27 | 0 % | 50 % |
| Drôme | 26 | 2 | 356 | 13,7 | 15 | 21 | 27 % | 38 % |
| Isère | 83 | 9 | 822 | 10,5 | 10 | 18 | 36 % | 64 % |
| Loire | 104 | 2 | 1 425 | 14,4 | 14 | 23 | 20 % | 37 % |
| Haute-Loire | 18 | 0 | 230 | 14,4 | 16 | 23 | 12 % | 38 % |
| Puy-de-Dôme | 72 | 14 | 635 | 9,2 | 8 | 21 | 51 % | 59 % |
| **Rhône** | 111 | 0 | 1 788 | 16,3 | 17 | 26 | 20 % | 28 % |
| Savoie | 18 | 1 | 182 | 11,4 | 12 | 20 | 38 % | 56 % |
| Haute-Savoie | 26 | 2 | 557 | 22,3 | 25 | 29 | 8 % | 12 % |
| Charente | 24 | 8 | 279 | 12,7 | 13 | 22 | 36 % | 45 % |
| Charente-Maritime | 32 | 1 | 535 | 17,8 | 20 | 25 | 17 % | 20 % |
| Corrèze | 12 | 0 | 210 | 17,5 | 22 | 26 | 8 % | 33 % |
| Creuse | 24 | 10 | 181 | 8,6 | 8 | 19 | 57 % | 67 % |
| Dordogne | 28 | 0 | 341 | 13,6 | 16 | 22 | 28 % | 40 % |
| Gironde | 87 | 17 | 1 105 | 14,4 | 17 | 24 | 23 % | 38 % |
| Landes | 84 | 20 | 1 124 | 14,1 | 17 | 23 | 31 % | 40 % |
| Lot-et-Garonne | 49 | 7 | 637 | 13,6 | 14 | 24 | 28 % | 43 % |
| Pyrénées-Atlantiques | 60 | 11 | 903 | 16,7 | 16 | 29 | 26 % | 32 % |
| Deux-Sèvres | 24 | 0 | 370 | 15,4 | 18 | 23 | 17 % | 33 % |
| Vienne | 34 | 8 | 411 | 13,3 | 17 | 23 | 29 % | 36 % |
| Haute-Vienne | 33 | 2 | 471 | 15,2 | 16 | 21 | 13 % | 19 % |
| Paris | 59 | 2 | 622 | 12,7 | 11 | 27 | 41 % | 61 % |
| Seine-et-Marne | 72 | 12 | 1 004 | 15,2 | 17 | 26 | 29 % | 35 % |
| Yvelines | 77 | 19 | 1 168 | 16,0 | 18 | 29 | 33 % | 38 % |
| Essonne | 65 | 3 | 865 | 14,2 | 14 | 24 | 26 % | 46 % |
| Hauts-de-Seine | 39 | 1 | 758 | 21,1 | 22 | 33 | 19 % | 19 % |
| Seine-Saint-Denis | 46 | 0 | 961 | 24,6 | 28 | 34 | 10 % | 18 % |
| Val-de-Marne | 45 | 1 | 515 | 12,6 | 13 | 18 | 17 % | 39 % |
| Val-d'Oise | 46 | 4 | 1 146 | 25,5 | 29 | 35 | 11 % | 16 % |

À retenir : **l'hétérogénéité est départementale, pas régionale** — dans la même ligue AURA cohabitent
le Puy-de-Dôme (méd. 8, la moitié des clubs sous 8 équipes) et la Haute-Savoie (méd. 25). Le marché
« petits clubs » et le marché « gros clubs » existent partout, dans des proportions locales différentes.

Top Rhône (engagées) : AL Caluire-et-Cuire 33 · BC Villefranche Beaujolais 31 · Ampuis Vienne
Saint-Romain Reventin 30 · AL Meyzieu 29 · AL Gerland Mouche Lyon 28. BCCL mesuré à 25 (49 dans l'app).

## 6. Ce que l'étude ne dit PAS

- **Le nombre d'équipes réellement gérées dans un planning** (école de basket incluse) — un seul point
  d'étalonnage existe, BCCL (× 1,96). En obtenir 2-3 autres via les clubs bêta affinerait le ×2.
- **Le budget des clubs ni leur volonté de payer** — la distribution des tailles est une assiette,
  pas une courbe de demande.
- **Les licenciés** (donnée personnelle, hors périmètre — même réserve que la reconnaissance P2-19).
- **Les créneaux/gymnases par club** (l'index `ffbbserver_salles` n'est pas relié aux clubs).

## 7. Suites

| # | Action | Pour |
|---|---|---|
| 1 | **Point de cadrage P1-3** (cap générations vs équipes vs mixte + PSP) — l'étude fournit la table §4 et les segments §4bis | Fondateur, décision |
| 2 | Étalonner le ratio engagées→app sur 2-3 clubs bêta de tailles différentes | Affiner le ×2 |
| 3 | **Re-mesurer à l'automne 2026** : tous les championnats 26-27 ne sont pas encore sortis — l'index se remplit à l'ouverture de la saison, la mesure se raffermit (mêmes 5 requêtes). **Document vivant** | Fiabilité |
| 4 | Si prospection ciblée un jour : l'index donne nom, commune, mail institutionnel par club — vérifier CGU/RGPD avant tout emailing | Business |

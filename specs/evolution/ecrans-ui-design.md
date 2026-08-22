# Écrans à designer — spécification (fonds d'écran & écrans statiques par sport)

> **Statut** : cadrage design vérifié contre le code le 2026-08-16 — rien n'est encore livré, aucun
> asset n'existe. ⚠ **A1 a bougé sous le pied de ce cadrage le 2026-08-17** : le fondateur a fait
> livrer une scène pour `GenerationWaiting.tsx` directement en code (motif basketball codé en dur,
> **sans logo au centre** — l'« élément imposé » ci-dessous ne tient plus, voir la note dans A1).
> Ce cadrage reste utile si on veut un jour remplacer cette scène par des assets DÉSIGNÉS, par
> sport ; ce n'est plus le point de départ « aucune scène ». Comportement livré :
> `../../frontend/docs/frontend-spec.md` §6.2, décision de retrait du logo :
> `specs/courantes/identite-visuelle-club.md`.
> Rattachement roadmap : **P5-16** (habillage + câblage) — distinct de
> [`roadmap.md`](roadmap.md) **P5-14** qui arrête QUELS écrans système existent et sous quelle
> forme (page/bandeau/inline/toast) : P5-14 est le catalogue d'états, cette spec est l'HABILLAGE
> visuel de certains d'entre eux et le prompt prêt à donner au designer.
> **Nature** : cadrage à consommer par un designer (humain ou IA) — pas du code, pas un ADR.
> **Le comportement livré ne vit pas ici** : une fois les assets intégrés, leur câblage se documente
> dans `../../frontend/docs/frontend-spec.md` (écrans concernés) et l'item quitte la roadmap.

## Contexte technique commun (à lire avant tout design)

- **Design tokens** (`frontend/src/index.css`) : base NEUTRE dark/light + **un seul accent, remplacé
  PAR CLUB** (défaut bleu `oklch(0.55 0.16 255)`). ⚠ **Limitation n° 1 : aucun asset ne doit
  dépendre d'une couleur d'accent précise** — le club peut la changer. Palette des assets = neutres
  (gris/encre) + touches pouvant vivre à côté de n'importe quel accent.
- Fonds : light `oklch(0.99 0 0)` (quasi blanc) · dark `oklch(0.19 0.01 260)` (encre bleutée).
  **Chaque asset existe en 2 variantes (light/dark) OU joue sur la transparence pour fonctionner sur
  les deux.**
- **Gate CI contraste WCAG 1.4.3** (axe-core en e2e) : tout texte posé sur un fond doit tenir 4.5:1
  → chaque écran définit une **zone protégée** où le fond reste calme (≤ ~15 % d'opacité) ou voilée.
- **`prefers-reduced-motion`** : toute animation doit avoir un état figé propre.
- **Formats** : SVG animé par CSS de préférence (léger, net, thémable) ; WebP pour du pictural.
  **Pas de Lottie** (dépendance refusée), **pas de police externe** (autohébergé seulement).
- **Sports** : basketball actif ; football, rugby = variantes d'avance (handball, volley prévus dans
  la nomenclature). **Limitation n° 2 : le sport n'est connu qu'après connexion** → tout écran
  pré-auth est générique multi-sport. **Fallback générique OBLIGATOIRE** pour tout sport sans asset.
- **Nommage** : `{ecran}-{sport|generic}-{light|dark}.{svg|webp}` — ex.
  `generation-basketball-dark.svg`.

---

## FAMILLE A — Dans l'app (React, sport possible)

### A1. Attente de génération — LE prioritaire
- **Utilité** : le gestionnaire fixe cet écran de 30 s à 10 min pendant que le solveur calcule. Il
  doit rassurer (ça travaille), occuper l'attente, incarner le sport du club.
- **⚠ Limitation n° 3 — ENCAPSULÉ, pas un wallpaper** : composant `GenerationWaiting.tsx`
  (`frontend/src/features/planning/`) rendu DANS la zone de contenu (wizard ou page planning),
  cadre centré.
- **⚠ PÉRIMÉ depuis le 2026-08-17 — conservé pour mémoire du cadrage d'origine, PAS l'état actuel** :
  ce paragraphe décrivait « aujourd'hui » (2026-08-16) un centre imposé (le logo du club dans un
  cercle qui pulse, 96 px) sans aucune scène autour. **Depuis le 2026-08-17, une scène EST livrée**
  (motif basketball codé en dur — mini-grille 4×4 qui se remplit, ballon qui rebondit, terrain
  filigrané) et le centre imposé a changé de nature : **décision fondateur, plus AUCUN logo/initiale
  du club** — le centre porte désormais la mini-grille + le titre/phrase tournante/note, tous pilotés
  par les tokens de thème et l'accent du club (jamais un littéral). Détail :
  `../../frontend/docs/frontend-spec.md` §6.2. Si ce cadrage sert un jour à commander des assets
  DÉSIGNÉS par sport, ils remplaceraient ce motif basketball codé en dur — **pas** un logo à
  ménager une place pour.
- **Cadre de composition** : scène **800 × 500** (16:10), redimensionnée fluide entre ~560 px
  (mobile) et ~900 px de large.
- **Zone protégée** : rectangle central **340 × 440** (mini-grille + textes, plus de logo depuis le
  2026-08-17 — voir ci-dessus) — fond quasi vide à cet endroit.
- **Par sport : OUI** (basketball d'abord). Idée directrice : l'animation raconte « on place les
  créneaux » (ballon qui rebondit de case en case, terrain stylisé en filigrane, sifflet, chrono…).
- **Livrables** : `generation-{sport}-{light|dark}.svg` + `generation-generic-*.svg`. Animation en
  CSS interne au SVG (boucle 4-8 s, discrète). < 150 Ko/fichier.

### A2. 404 dans l'app (page inconnue)
- **Utilité** : URL erronée/obsolète dans l'app. Aujourd'hui : **AUCUNE route catch-all** (`main.tsx`
  ne déclare pas de `path="*"`) — l'écran est À CRÉER au câblage.
- **Cadre** : zone sous le header (56 px) → composer pour **1920 × 1020**, crop-safe mobile
  390 × 780.
- **Zone protégée** : centre 600 × 400 (titre « Page introuvable », sous-texte, bouton retour).
- **Par sport : OUI si connecté** (c'est le cas courant), fallback générique sinon. Ton : léger,
  jeu de mots sportif bienvenu (« balle perdue »).
- **Livrables** : illustration centrale **480 × 360** `notfound-{sport|generic}-{light|dark}.svg`
  (fond de page = tokens, pas d'image pleine page nécessaire). < 100 Ko.

### A3. Crash applicatif (ErrorBoundary React)
- **Utilité** : erreur JS imprévue — l'écran remplace TOUT, plein viewport, boutons
  « Réessayer / Recharger ». Doit RASSURER, rester sobre. Existant : `frontend/src/app/ErrorBoundary.tsx`.
- **⚠ Limitation n° 4** : peut survenir AVANT l'authentification → **générique uniquement** (pas de
  variante sport).
- **Cadre** : plein viewport centré. **Illustration 360 × 280 max**, calme (pas d'animation, ou
  micro-animation).
- **Livrables** : `crash-generic-{light|dark}.svg`. < 60 Ko.

### A4. Nouvelle version / hors-ligne (RouteErrorBoundary)
- **Utilité** : 2 états distincts déjà distingués par le code (`frontend/src/app/RouteErrorBoundary.tsx`)
  — (a) « l'app a été mise à jour pendant votre session » (bouton Réessayer), (b) « vous êtes hors
  ligne » (wifi de gymnase !). Même famille visuelle que A3.
- **Cadre** : zone de contenu, centré. **2 illustrations 360 × 280** : `update-generic-*.svg`
  (fusée/flèche), `offline-generic-*.svg` (wifi barré / gymnase). Génériques (peut être pré-auth).
  < 60 Ko chacune.

### A5. Fond des écrans d'authentification (login, register, mot de passe, vérif email, attente
d'approbation)
- **Utilité** : première impression ; la carte de formulaire (448 px de large, centrée) flotte sur
  ce fond.
- **⚠ Limitation n° 2 rappelée** : sport inconnu → **générique multi-sport** (silhouettes de
  plusieurs sports, motif terrain abstrait). EXCEPTION : au register, le sport se choisit à
  l'étape 1 → le fond PEUT se thémer dès la sélection (variantes sport bienvenues, optionnelles).
- **Cadre** : master **2560 × 1440**, crop-safe jusqu'à 390 × 844. **Zone protégée : rectangle
  central 520 × 640** (la carte + ses marges).
- **Livrables** : `auth-generic-{light|dark}.webp` (ou svg) obligatoires ; `auth-{sport}-*`
  optionnels. < 250 Ko.

### A6. États vides
- **Utilité** : planning jamais généré, page club sans données, listes vides — dire « rien ici, ET
  voilà quoi faire ». Existant réutilisable : composant `EmptyState`.
- **Cadre** : illustrations inline **320 × 240**, dans des cartes.
- **Par sport : OUI**. Livrables : `empty-planning-{sport|generic}-*.svg`, `empty-club-*.svg`.
  < 50 Ko chacun.

---

## FAMILLE B — Statique Caddy (HORS app — quand l'app est MORTE)

> ⚑ **LIVRÉ le 2026-08-22** — et **ce n'est PAS un geste nginx**, malgré ce que disait cette
> famille : ces pages sont servies par **Caddy** (sur la VM, hors Docker), jamais par le conteneur
> `frontend`. Le cas « nginx vivant » est couvert par les écrans SPA (famille A). Fichiers réels :
> `system-pages/503.html` et `system-pages/maintenance.html` ; câblage
> [`../../docs/ops/Caddyfile.example`](../../docs/ops/Caddyfile.example) ; trace, copie fondateur
> retenue et pièges : [`../courantes/etat-des-lieux.md`](../courantes/etat-des-lieux.md) ; règle de
> zone `.claude/rules/system-pages.md`. La description ci-dessous garde sa valeur de CONTRAINTES ;
> la copie livrée, elle, est celle du fondateur (voir chaque écran).

**⚠ Limitation n° 5, la plus dure** : ces pages sont servies par **Caddy** quand le backend (ou
tout) est tombé. Donc : **HTML autonome en UN fichier** (CSS inline, images inline en data-URI ou
SVG inline), **pas de sport** (aucune identité disponible), thème UNIQUEMENT via
`prefers-color-scheme` en CSS. Poids total < 100 Ko par page. Le seul chargement réseau toléré est
`/config.js` (même origine, servi du disque par Caddy) — sans lui, la page reste **entièrement
fonctionnelle**, sans marque.

### B1. `503.html` — panne (502/503/504) — LIVRÉ
- **Utilité** : le service est indisponible (incident), ton calme, PAS de jargon.
- **Copie fondateur retenue** : « Le gymnase est fermé, on cherche les clés. » + rassurance sur les
  données. Gestes *Réessayer maintenant* (repli `<a href="/">` sans JS) / *Prévenir le support*
  (`mailto:` construit depuis `contactEmail`, masqué si le config n'a pas chargé).
- **Cadre** : plein viewport, contenu centré 560 px max. Servie via `handle_errors` (502/503/504),
  statut **503 explicite**.

### B2. `maintenance.html` — maintenance volontaire — LIVRÉ
- **Utilité** : déploiement planifié. Message DIFFÉRENT de la panne.
- **Copie fondateur retenue** : « On refait le parquet. » Interrupteur à **fichier témoin**
  (`maintenance.on`), statut **503 + `Retry-After`** — jamais 200. (Pas de « retour à HH:MM » :
  arbitrage 503/Retry-After, un 200 tuerait les sondes.)

### (B3. 404 statique — optionnel, faible priorité : la SPA attrape quasi tout. Non fait.)

---

## FAMILLE C — Landing (`landing/`, statique, chantier séparé — hors de cette passe)

---

## Récap des limitations (les 5 dures)

1. **Accent par club** → assets neutres, jamais dépendants d'un bleu précis.
2. **Sport connu seulement connecté** → pré-auth = générique ; fallback générique obligatoire
   partout.
3. **Attente de génération = scène ENCAPSULÉE**, pas un fond plein écran — le centre porte
   désormais la mini-grille + les textes (**plus de logo imposé depuis le 2026-08-17**, voir A1).
4. **Crash/erreurs = génériques** (peuvent survenir avant auth).
5. **Pages Caddy = un fichier autonome**, pas de sport, thème par `prefers-color-scheme` seul ;
   seul `/config.js` (même origine, disque) est toléré, et son absence ne casse rien (repli sans
   marque). *(Famille B LIVRÉE le 2026-08-22 — `system-pages/`, servie par Caddy, pas nginx.)*

+ transverses : dark ET light (ou transparence bi-thème), contraste 4.5:1 dans les zones protégées,
`prefers-reduced-motion`, budgets poids, SVG animé CSS de préférence, pas de Lottie, pas de police
externe.

---

# PROMPT PRÊT À COLLER (pour un designer, humain ou IA)

```
Tu es directeur artistique pour « Amateo », un SaaS qui génère les plannings
d'entraînement de clubs sportifs amateurs (basketball actif aujourd'hui ; football et rugby
arrivent). Utilisateurs : bénévoles de clubs, sur desktop ET mobile, thème sombre et clair.

Direction artistique : moderne, chaleureux, sportif sans cliché « stock » ; illustrations
vectorielles stylisées (flat + touches de profondeur), motifs de terrain/équipement en
filigrane ; JAMAIS de photos. Contrainte de marque : la couleur d'accent est PERSONNALISÉE
PAR CLUB (bleu par défaut) — tes visuels doivent être NEUTRES (encres, gris, off-white) et
cohabiter avec n'importe quel accent. Fond clair ≈ blanc cassé, fond sombre ≈ encre bleutée
très foncée. Chaque livrable existe en variante light ET dark (ou joue la transparence pour
fonctionner sur les deux).

Contraintes techniques NON NÉGOCIABLES :
- SVG (animations en CSS interne, boucles 4-8 s discrètes) ; WebP accepté pour l'auth.
  Pas de Lottie, pas de police externe (texte = paths ou pas de texte du tout).
- Chaque écran a une ZONE PROTÉGÉE où le fond reste quasi vide (le texte de l'app s'y
  pose, contraste WCAG 4.5:1 exigé).
- Prévoir l'état FIGÉ de chaque animation (prefers-reduced-motion).
- Nommage : {ecran}-{sport|generic}-{light|dark}.svg

Livrables, par priorité :

1. ATTENTE DE GÉNÉRATION (le plus important) — scène 800×500 affichée pendant que le
   solveur calcule (30 s à 10 min). ⚠ Une scène GÉNÉRIQUE (motif basketball codé en dur,
   pas un asset désigné) existe déjà dans l'app : réserve un rectangle central de 340×440
   quasi vide — il porte une mini-grille de créneaux qui se remplit + 3 lignes de texte
   (PAS de logo). Ta scène vit AUTOUR de ce rectangle, comme la scène existante : raconte
   « les créneaux se placent » (ballon qui rebondit de case en case d'une grille, terrain
   en filigrane, chrono). Livrable attendu : une DÉCLINAISON par sport de cette même idée
   (le motif basketball existant sert de référence), pas une scène différente dans son
   principe. Variantes : basketball-light, basketball-dark, generic-light, generic-dark
   (+ football, rugby si tu peux). < 150 Ko chacune.

2. PAGE INTROUVABLE (404 in-app) — illustration centrale 480×360, ton léger, clin d'œil
   sportif (« balle perdue »), variantes basketball + generic, light + dark. < 100 Ko.

3. PANNE (50x, page statique servie quand tout est tombé) — compose une page HTML
   COMPLÈTE et AUTONOME (CSS inline, SVG inline, zéro JS, zéro requête externe, < 100 Ko
   total), thème par prefers-color-scheme, contenu centré max 560 px : illustration
   calme sport-neutre (ballon au repos, banc de touche), titre « Petite pause technique »,
   texte « Nos équipes sont dessus — réessayez dans quelques minutes. » GÉNÉRIQUE
   uniquement (aucune identité connue à ce moment-là).

4. MAINTENANCE (page statique, mêmes contraintes que la panne) — visuel de la même
   famille mais DISTINCT (outils, chrono), titre « Maintenance en cours », un champ
   d'heure de retour éditable dans le HTML.

5. FOND D'AUTHENTIFICATION — master 2560×1440 crop-safe jusqu'à 390×844, une carte de
   formulaire de 448 px flotte au centre : rectangle central 520×640 protégé. GÉNÉRIQUE
   multi-sport (le sport est inconnu avant connexion) : silhouettes/équipements de
   plusieurs sports en filigrane. Light + dark. < 250 Ko.

6. CRASH & CIE — 3 petites illustrations sobres 360×280, génériques, light + dark :
   « erreur inattendue » (rassurante), « nouvelle version disponible » (fusée/flèche),
   « hors ligne » (wifi barré dans un gymnase). < 60 Ko chacune.

7. ÉTATS VIDES — 2 illustrations 320×240 par sport (basketball + generic) : « aucun
   planning encore » (invite à générer), « club sans données » (invite à saisir).
   Light + dark. < 50 Ko.

Commence par le livrable 1 (basketball, dark d'abord — c'est le thème majoritaire),
montre-le, on itère, puis déroule le reste.
```

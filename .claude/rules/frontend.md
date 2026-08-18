---
paths:
  - "frontend/**"
---

# Frontend — conventions & pièges (chargé quand frontend/ est touché)

> **Ce fichier ne remplace pas [`frontend/AGENTS.md`](../../frontend/AGENTS.md)** (315 lignes :
> frontières, routage, état serveur/client, primitives, a11y). Il porte **seulement ce qui, non
> su, rend un test VERT à tort** — parce que ces règles-là doivent être en contexte sans que
> personne ait à penser à les chercher.

- 🔴 **Le front n'invente JAMAIS une règle métier — il AFFICHE celle que le backend a calculée.**
  Toute logique qui répond « qu'est-ce qui s'applique / que fait le solveur / ce geste est-il
  permis » n'existe **qu'une fois**. Trois régimes, un seul interdit : **(1) le backend dit** —
  supprimer la redérivation, afficher la réponse (le défaut) ; **(2) miroir déclaré** — la
  duplication est assumée (réactivité sans aller-retour réseau), **déclarée en tête de fichier**
  ET gardée par un **test de parité** (patron : `CoachDoubleBookingDetector` ⇄
  `wizard/lib/coachDoubleBooking.ts` ; côté cross-stack `PayloadCapacityMirror` +
  `CapacityMirrorParityTest`) ; **(3) redérivation silencieuse** — ❌ interdite. Signe d'alerte :
  un `switch`/chaîne de conditions sur les valeurs d'un **enum métier partagé** (`scope`,
  `ruleType`, `family`, `lockLevel`, `status`…) pour **décider d'un comportement** (pas pour
  choisir un libellé — ça, c'est de la présentation, cf. `matches/lib/diagnostic.ts`). Cas fondateur
  du **2026-08-12** : `applicableConstraints` faisait `case "CLUB": return true` alors que
  `ScheduleConstraintBuilder.php:846-870` éclate une `CLUB+targetTag` en N contraintes TEAM — le
  wrap affichait une règle sur une équipe à qui le solveur ne l'applique jamais.
  **Gardé depuis le 2026-08-12 par `FrontRederivationRegistryTest`** (CrossStack, groupe
  `contract`) : registre des miroirs déclarés (entrée sans parité → rouge) + détecteur des
  `switch` décideurs sur les enums de CONTRAINTE (module non déclaré → rouge, nommé). La largeur
  du détecteur est un CHOIX documenté (`POLICED_ENUMS`) ; le registre, lui, tient tous les miroirs.
- 🔴 **L'image tooling COPIE le code — la rebâtir AVANT tout test**, sinon la suite valide une
  version périmée et passe : `docker compose --profile tools build frontend-tooling`
  (`make -C frontend install` le fait). **Deux faux verts dans la même session le 2026-08-11.**
- 🔴 **Le service `frontend` (Nginx :8081) sert un `dist` CUIT dans son image** — pas de bind
  mount. Un `vite build` dans le conteneur tooling est jeté. Avant un e2e qui doit voir ta
  modification : `docker compose build frontend && docker compose up -d --force-recreate frontend`.
  Seul `frontend-dev` (profil `dev`, :5173) monte `./frontend` — c'est le hot-reload, pas la cible
  des e2e.
- 🔴 **Jamais `tsc --noEmit`** : le `tsconfig.json` racine est un fichier *solution*
  (`"files": []` + `references`), donc `--noEmit` voit **zéro fichier**, sort 0 sans rien vérifier,
  et la CI (`tsc -b`) échoue sur ce qu'il a sauté. `make -C frontend lint` fait `tsc -b --force` —
  le `--force` est requis (un `tsbuildinfo` périmé court-circuite le contrôle).
- 🔴 **jsdom n'a AUCUN moteur de mise en page** : `boundingBox`, `scrollHeight` et
  `getBoundingClientRect` y valent 0. Le **contraste** et le **reflow** (WCAG 1.4.10) ne se testent
  qu'en **Playwright**. Un test jsdom sur ces sujets est vert par construction — il n'atteste rien.
- **TDD obligatoire**, RED prouvé avant l'implémentation
  ([`../../frontend/docs/frontend-strategy.md`](../../frontend/docs/frontend-strategy.md) §1).
- **Passe de design `ui-ux-pro-max`** (dans un agent, bornée à l'apparence, elle ne valide rien)
  dès qu'un écran **public** est créé ou remanié — même doc, règle du 2026-08-11.
- **Muter la PROD, pas le mock** : un test qui n'exerce que son double ne garde rien.
  `readState`/`PeriodAnchor` pour react-query (« vacuité crédible » — `AGENTS.md` §readState).
- **Tout tourne dans Docker**, frontend compris : les 12 cibles de `frontend/Makefile` passent par
  `docker compose`, sans exception. Tester sur l'hôte valide une version de Node qui n'est celle de
  personne.
- ⚠ **Un e2e qui passe sans avoir rien mis à l'épreuve est un faux vert** : quand un scénario peut
  devenir vide (une modale trop courte pour déborder, une liste vide), lui donner un **témoin** qui
  ÉCHOUE en le disant — cf. `tests/e2e/modal-reachability.spec.ts`.

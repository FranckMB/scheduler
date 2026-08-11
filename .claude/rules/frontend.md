---
paths:
  - "frontend/**"
---

# Frontend — conventions & pièges (chargé quand frontend/ est touché)

> **Ce fichier ne remplace pas [`frontend/AGENTS.md`](../../frontend/AGENTS.md)** (315 lignes :
> frontières, routage, état serveur/client, primitives, a11y). Il porte **seulement ce qui, non
> su, rend un test VERT à tort** — parce que ces règles-là doivent être en contexte sans que
> personne ait à penser à les chercher.

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
  ([`specs/courantes/frontend-strategy.md`](../../specs/courantes/frontend-strategy.md) §1).
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

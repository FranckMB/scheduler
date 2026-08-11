# Journal des upgrades techniques — le pourquoi du comment

> Tenu par le skill `/dependabot` à chaque traitement de PRs de dépendances. **Public : le
> fondateur, pas l'agent** — chaque entrée explique en français ce que fait le paquet, ce que
> l'upgrade apporte, et ce qu'il a fallu adapter chez nous. But : comprendre les mises à jour,
> pas les subir. Ordre antichronologique.

## 2026-08-11 — lot Dependabot

### Groupe backend-composer — PHP-CS-Fixer, PHPStan, Rector (outils de dev, PR #504)

**C'est quoi** : les trois outils qui relisent le code PHP automatiquement. **PHP-CS-Fixer** met le
code en forme (indentation, ordre des imports…), **PHPStan** cherche les erreurs de logique sans
exécuter le programme, **Rector** modernise le code vers les tournures de PHP 8.4. Aucun des trois
ne part en production : ils tournent chez nous et dans la CI. Chacun est un verrou qui bloque une
fusion s'il n'est pas content.

**Ça apporte** : PHP-CS-Fixer 3.95.17 → **3.95.18** et PHPStan 2.2.6 → **2.2.8** sont des correctifs
d'entretien, sans effet visible — on les prend au fil de l'eau pour ne pas accumuler du retard qui
devient un jour un saut coûteux. Rector 2.5.8 → **2.5.9** apporte une règle de plus.

**Adapté chez nous** : **un fichier**, `FfbbEngagementsController`. Rector 2.5.9 y demande d'écrire
« si cette variable EST une réponse d'erreur » plutôt que « si elle n'est pas vide » — c'est
exactement la convention que le projet s'est donnée (P4-24), et elle dit plus précisément ce que le
code vérifie.

**⚠ Et une version a été volontairement REFUSÉE : Rector 2.6.** Dependabot proposait 2.6.1. Testée,
elle réécrit deux fichiers de sécurité (le cookie qui porte la connexion, l'authentification
Mercure) en remplaçant les noms courts par des chemins complets — et **PHP-CS-Fixer les remet
aussitôt en noms courts**. Les deux outils se contredisent, chacun défaisant le travail de l'autre :
comme les deux bloquent la fusion, **plus aucune modification ne pourrait passer**. Vérifié que la
faute vient bien de l'outil et pas de notre code : Rector déclare lui-même n'appliquer **aucune
règle** sur ces fichiers (`applied_rectors: []`) — c'est son moteur d'écriture qui déraille, pas une
convention nouvelle qu'il faudrait suivre. 2.6.0 a le même défaut, 2.5.9 est saine. La version est
donc bornée à la série 2.5 (`~2.5.9` : les correctifs 2.5.x continuent d'arriver, la série 2.6 est
tenue dehors) jusqu'à ce que l'outil soit réparé — suivi en **P4-80**.

### Groupe frontend-npm — 13 paquets (PR #505)

*(entrée rédigée avec le traitement de la PR)*

### ⚠ Découvert pendant le lot, sans rapport avec les dépendances : un test qui rougit au hasard

`Engine Tests` — l'un des contrôles qui bloquent les fusions — est tombé sur la PR #504, **qui ne
touche pourtant pas le moteur**. Ce n'est ni un caprice ni la faute de la mise à jour : l'un de nos
tests se trompe.

Ce test vérifie qu'un gymnase n'accueille jamais deux équipes en même temps. Il travaille sur des
situations **tirées au hasard**, et il est tombé sur celle-ci : le gestionnaire a **épinglé
lui-même** deux équipes sur le même créneau, alors que ce créneau ne peut en accueillir qu'une. Le
moteur a fait ce qu'on lui a demandé — c'est une règle assumée du produit, l'épingle prime sur tout
(« il a le droit d'épingler, il a le droit de savoir »). Le test, lui, crie à l'erreur.

Conséquence concrète : **une fusion sur deux peut se retrouver bloquée sans raison**, selon les
situations tirées au sort ce jour-là. Suivi en **P4-81**, avec le correctif identifié (le patron
existe déjà dans le même fichier pour un test voisin).

## 2026-07-29 — lot Dependabot (4 PRs : 3 mergées, 1 toujours bloquée) + passage à Node 24

> Ce lot rattrape le retard signalé par l'audit doc du même jour : le journal avait quatre lots
> d'écart. Les lots intermédiaires (2026-07-25 → 27) avaient été mergés sans passer par ici.

### Groupe github-actions ×3 — docker/setup-buildx v4, login v4, build-push v7 (CI, majeurs)
**C'est quoi** : les trois actions GitHub qui construisent et publient nos **images Docker de
production** vers ghcr.io, dans le workflow de déploiement.
**Ça apporte** : passage au runtime **Node 24** côté action (les versions 3/6 partaient sur un Node
en fin de vie) et nettoyage des options obsolètes. À prendre maintenant : ces actions finiront par
refuser de tourner sur les runners récents.
**Adapté chez nous** : **rien**. Trois vérifications faites avant de merger — (1) `setup-buildx` est
utilisé **sans aucun input** chez nous, donc les suppressions d'options annoncées ne peuvent pas
nous atteindre ; (2) les seuls inputs qu'on passe (`context`, `file`, `target`, `push`, `tags`,
`cache-from`, `cache-to`, `registry`/`username`/`password`) sont tous des options de base,
inchangées ; (3) l'exigence « Actions Runner ≥ 2.327.1 » est satisfaite puisque tous nos jobs
tournent sur `ubuntu-latest`, mis à jour par GitHub.
⚠️ **Angle mort assumé** : ces actions ne vivent que dans `deploy.yml`, qui **n'est pas exercé par
la CI**. Le changement ne sera réellement éprouvé qu'au prochain tag `v*`.

### Groupe composer backend ×4 — doctrine-bundle 3.3.1, php-cs-fixer, phpstan, rector
**C'est quoi** : la colle Symfony↔Doctrine (accès PostgreSQL), plus les trois outils qui gardent le
style et la qualité du code backend — ceux-là mêmes qui font échouer la CI quand on dérive.
**Ça apporte** : correctifs en amont pour Doctrine ; pour les trois outils, des règles plus fines et
la compatibilité avec les versions récentes de PHP. Les bumps d'outils d'analyse sont à prendre tôt :
plus on attend, plus la moisson de nouvelles remarques est grosse d'un coup.
**Adapté chez nous** : **le lockfile a dû être re-résolu.** Dependabot avait fait dériver
**14 paquets Symfony en 8.0.x** sous des bundles 7.4 — la LTS sur laquelle le projet est
délibérément resté. Le test `SymfonyStackAlignmentTest` l'a attrapé (il lit ce qui est
*réellement installé*, pas le lock). La cause est structurelle : **Dependabot résout hors de notre
conteneur**, donc sans le plugin Flex qui impose `7.4.*` à tous les paquets Symfony, y compris ceux
tirés indirectement. Correctif conforme à la règle du dépôt — `composer update` des quatre paquets
**dans le conteneur**, jamais un pin dans `composer.json` : un pin traiterait le symptôme en laissant
croire que les dépendances indirectes ne sont pas couvertes. Les quatre montées voulues sont
conservées, la dérive est à zéro. **À savoir pour la suite : tout lot composer futur peut reproduire
ce cas** — c'est le test qui protège, pas Dependabot.

### jsdom 29 → 30 (frontend, dev-only, majeur)
**C'est quoi** : le navigateur simulé dans lequel tournent les tests unitaires du frontend. Il n'y a
pas de vrai Chrome dans Vitest : jsdom joue le rôle du DOM.
**Ça apporte** : `CSS.escape()`/`CSS.supports()`, des propriétés CSS supplémentaires et un
`getComputedStyle()` plus juste. Utile pour nous : nos tests d'accessibilité s'appuient sur le style
calculé. (Rappel : jsdom n'a **toujours pas** de moteur de rendu — le contraste de couleur reste
invérifiable en test unitaire, cf. A11Y-06.)
**Adapté chez nous** : **rien dans le code**, mais ça a révélé un décalage de version de Node —
voir l'entrée suivante.

### ⛔ typescript 6.0 → 7.0 (frontend) — TOUJOURS bloquée (#223)
Re-vérifié ce jour : `typescript-eslint` plafonne encore sa dépendance à `typescript >=4.8.4 <6.1.0`,
y compris sur la dernière version publiée (**8.65.0**) et sur ses pré-versions (`8.65.1-alpha.11`).
Sous TS 7, l'analyseur casse et **tout le lint tombe** — or le lint fait partie des contrôles
obligatoires avant merge. Rien à réparer chez nous : la migration TS 7 elle-même est triviale.
C'est l'écosystème qui n'a pas suivi. **Réouvrir quand une version de `typescript-eslint` acceptera
TS 7**, et faire alors le bump des deux ensemble.

### Node 22 → 24 (CI + image Docker frontend) — décision fondateur
**C'est quoi** : la version de Node.js qui exécute l'outillage frontend (tests, compilation
TypeScript, build).
**Ça apporte** : la sortie d'une zone grise. jsdom 30 exige `^22.22.2 || ^24.15.0 || >=26` ; la CI et
Docker prenaient le **dernier** 22.x et passaient, mais l'hôte du fondateur était en **22.22.1**, un
patch en dessous. Le pire cas : CI verte, poste local hors plage, et une casse qui n'aurait pas
ressemblé à un problème de version. Node 24 est la version supportée suivante.
**Adapté chez nous** : les trois `setup-node` de la CI, le stage `tooling` de l'image frontend (les
stages de build et de prod en héritent), et l'ajout d'un champ `engines` qui n'existait pas —
désormais npm **avertit** si Node est trop vieux, au lieu de laisser le problème invisible. Le
`pdf-worker` n'est pas touché : il embarque son propre Node via l'image Puppeteer.
⚠️ **Action fondateur : installer Node 24 sur ton poste.**

## 2026-07-19 — lot Dependabot (7 PRs : 6 mergées, 1 bloquée)

### doctrine/doctrine-migrations-bundle 3.7 → 4.0 (backend, majeur)
**C'est quoi** : le bundle Symfony qui pilote les **migrations de base de données** — les scripts
versionnés qui font évoluer le schéma PostgreSQL (`make migration-diff` / `migration-migrate`).
**Ça apporte** : compatibilité avec les versions récentes de Doctrine ORM/DBAL (déjà passées en 3/4
le 11 juillet), pérennité — le bundle 3 ne recevra plus de correctifs. Rester dans le train évite
d'accumuler l'écart.
**Adapté chez nous** : **rien** — la config de migrations était déjà compatible, CI verte
(blocking-tests + PHPStan + migrations) au premier tour.

### Groupe composer backend ×7 — API Platform 4.3.17, outils
**C'est quoi** : patchs du framework HTTP/API (API Platform) et des dépendances associées.
**Ça apporte** : correctifs de bugs/sécu en amont, dans les fourchettes existantes (lockfile).
**Adapté chez nous** : rien.

### @types/node 24 → 26 · jsdom 27 → 29 · groupe frontend-npm ×4 (frontend, dev-only)
**C'est quoi** : outillage de dev/test frontend — `@types/node` (types Node pour TypeScript),
`jsdom` (DOM simulé pour les tests unitaires Vitest), + un groupe (Storybook & co, lockfile).
**Ça apporte** : types et environnement de test à jour, correctifs. Aucun impact runtime (dev-only).
**Adapté chez nous** : rien — suite Vitest verte, `tsc` + `eslint` + `vite build` OK.

### actions/setup-node 6 → 7 (CI, GitHub Actions)
**C'est quoi** : l'action GitHub qui installe Node.js dans les jobs de CI.
**Ça apporte** : compatibilité avec les runners récents ; inputs (`node-version`, `cache`) inchangés.
**Adapté chez nous** : rien (bump de référence ×3 dans `ci.yml`).

### ⛔ typescript 6.0 → 7.0 (frontend) — NON mergée, laissée ouverte (#223)
**C'est quoi** : le compilateur TypeScript.
**Pourquoi bloquée** : TS 7 supprime l'option `baseUrl` (migration triviale, faite localement),
mais surtout **`typescript-eslint` plafonne à `typescript >=4.8.4 <6.1.0`** (dernière version
publiée 8.64.0) — sous TS 7, `@typescript-eslint/typescript-estree` crashe (`Cannot read 'Cjs'`) et
**tout le lint est cassé**. Aucune version de `typescript-eslint` compatible TS 7 n'existe encore.
**À faire** : rouvrir quand `typescript-eslint` supportera TS 7 (bump coordonné TS + typescript-eslint).
Diagnostic laissé en commentaire sur la PR #223.

## 2026-07-11 — lot Dependabot complet (9 PRs)

### doctrine/doctrine-bundle 2.18 → 3.2 + DBAL 3 → 4 (backend) — le gros morceau
**C'est quoi** : la colle entre Symfony et Doctrine (la couche qui parle à PostgreSQL). DBAL = la
couche bas-niveau SQL, l'ORM = les objets métier au-dessus.
**Ça apporte** :
- **Objets lazy natifs PHP 8.4** — avant, Doctrine générait des classes « proxy » à la volée pour
  charger les entités à la demande (mécanisme LazyGhost) ; PHP 8.4 sait le faire nativement.
  Moins de magie, moins de code généré, comportement plus prévisible, et c'est le seul chemin
  supporté à partir de maintenant.
- **DBAL 4** — savepoints toujours actifs pour les transactions imbriquées (avant : option à
  activer), API plus stricte donc erreurs détectées plus tôt.
- On reste dans le train : ORM 3.6 + bundle 3 = la base des prochaines années ; retarder =
  accumuler l'écart et upgrader dans la douleur plus tard.
**Ce qu'il a fallu adapter chez nous** : purger les options de config disparues
(`use_savepoints`, `report_fields_where_declared`, `enable_lazy_ghost_objects`…), remplacer la
commande CLI supprimée `doctrine:query:sql` par `dbal:run-sql` (smoke-solver + docs), et vérifier
que RLS/GUC survivent (phase1 386 verts, smoke COMPLETED).

### Groupe composer ×10 (backend) — API Platform 4.3.x, Symfony 7.4.14, outils
**C'est quoi** : patchs de sécurité/bugs du framework HTTP/API et des analyseurs (PHPStan, CS-Fixer, Rector).
**Ça apporte** : correctifs de bugs et de sécu en amont, analyses plus précises (CS-Fixer 3.95.11
a reformaté 5 fichiers — pur style).
**Adapté chez nous** : Symfony 7.4.14 a changé la signature de `UserCheckerInterface::checkPostAuth`
(nouveau paramètre `$token`) → notre `UserChecker` (le garde « email vérifié » du login) aligné.

### vitest 3 → 4 + @vitest/ui + coverage (frontend, outil de test)
**C'est quoi** : le lanceur de tests unitaires du frontend (l'équivalent de PHPUnit côté React).
**Ça apporte** : runner plus rapide, meilleure isolation des tests, base pour les prochaines
versions des libs de test. Majeur = toute la famille (`vitest`, `@vitest/ui`, `coverage`) doit
bouger ensemble — d'où la fermeture de la PR #89 (couverte).
**Adapté chez nous** : rien — 342 tests verts tels quels.

### mypy 1.11 → 2.2 (engine, outil) & pytest-cov 5 → 7 (engine, outil)
**C'est quoi** : le vérificateur de types Python (mypy attrape les bugs avant l'exécution — il a
déjà attrapé 2 vrais bugs chez nous) et le mesureur de couverture de tests.
**Ça apporte** : mypy 2 = analyse plus stricte/rapide ; notre config `strict` passe sans un mot à
changer = bon signe sur la santé du code engine.
**Adapté chez nous** : rien.

### lucide-react · msw · typescript-eslint · vite 8.1 (frontend, mineurs)
**C'est quoi** : icônes (lucide), faux-serveur de test (msw), linter TS, bundler (vite).
**Ça apporte** : corrections de bugs, icônes en plus, vite 8.1 améliore le build.
**Adapté chez nous** : rien.

### lint-staged 16 → 17 (frontend, outil)
**C'est quoi** : lance des vérifs sur les seuls fichiers modifiés avant un commit.
**Ça apporte** : rien chez nous pour l'instant — **il est installé mais jamais branché** (le
pre-commit fait build+tsc directement). Tracé en dette P4-11 : le câbler ou le retirer.

### github-actions ×4 (CI)
**C'est quoi** : les briques des workflows GitHub (checkout du code, installation node/python,
upload d'artefacts).
**Ça apporte** : ces majeurs ne changent que le runtime interne (Node 24) — zéro impact sur nos
usages ; rester à jour évite les dépréciations forcées de GitHub.
**Adapté chez nous** : rien.

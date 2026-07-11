# Journal des upgrades techniques — le pourquoi du comment

> Tenu par le skill `/dependabot` à chaque traitement de PRs de dépendances. **Public : le
> fondateur, pas l'agent** — chaque entrée explique en français ce que fait le paquet, ce que
> l'upgrade apporte, et ce qu'il a fallu adapter chez nous. But : comprendre les mises à jour,
> pas les subir. Ordre antichronologique.

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

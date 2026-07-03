---
name: audit
description: Audit complet du projet ClubScheduler (doc, besoin, code par brique, sécurité, supply chain, infra) avec notes /100, barème fixe, registre de findings à ID stables et résultat horodaté dans specs/audit/. À invoquer via /audit pour produire une édition comparable aux précédentes.
---

# Audit ClubScheduler — protocole reproductible

Tu produis une **édition d'audit** comparable aux éditions précédentes stockées dans `specs/audit/`. La comparabilité prime sur tout : même barème, même structure, IDs de findings stables.

## Étape 0 — Préparation

1. Lis `CLAUDE.md`, puis la **dernière édition** dans `specs/audit/AUDIT-*.md` (la plus récente par date de nom de fichier). Récupère son registre de findings.
2. Note : SHA HEAD (`git rev-parse --short HEAD`), date du jour, modèle utilisé (ton ID de modèle exact).
3. Vérifie l'état de la stack : `docker compose ps`. Remplis le **tableau de couverture** (voir format de sortie) — chaque axe est `couvert` / `partiel` / `non couvert (raison)`. Un axe non couvert ne donne PAS de note.

## Étape 1 — Collecte (agents parallèles)

Lance 4 agents d'analyse en parallèle (lecture seule, aucun test destructif) :

- **Doc** : exactitude (sonder 5-6 affirmations clés de CLAUDE.md/project-map/AGENTS.md/TENANT.md contre le code), structure (duplication, doc morte), utilité pour un agent IA, tenue du cycle specs (initiales figées / courantes à jour vs PRs récentes / graduation evolution→courantes).
- **Backend** (`backend/`, statique) : architecture (classes > 300 lignes, couplage), multi-tenant (TenantFilter, RLS réel — vérifier `CREATE POLICY` dans migrations + init SQL, PAS seulement la doc), async (failure_transport, retries, idempotence, locks), Doctrine (migrations, N+1), tests (couverture des trous, pas seulement des chemins heureux — notamment entités SANS club_id), config, sécurité (exposition API Platform opération par opération, validation input, contrôles d'appartenance sur les routes custom).
- **Engine** (`engine/`, statique) : architecture, modèle CP-SAT, **vérifier que chaque contrainte parsée depuis le payload backend réel est effectivement appliquée** (suivre le format de bout en bout : frontend → ConstraintSerializer → parse engine → matching), concurrence (solve CPU-bound vs event loop), schemas/contrat, tests (les pipelines de test correspondent-ils au chemin prod `main._solve` ?), robustesse (logging, erreurs).
- **Frontend** (`frontend/`, exécuter `npx tsc --noEmit` et `vitest run`) : architecture, types (générés vs manuels, duplication), data-fetching (gestion erreurs queries ET mutations, temps réel), features principales, tests, a11y/qualité.

## Étape 2 — Checks directs (toi-même, pas les agents)

- **Supply chain** : `npm audit --omit=dev` (frontend, host), `docker compose exec -T php-fpm composer audit`, `docker compose exec -T engine pip-audit` (installer si absent).
- **Infra/Mercure** : lire la config du hub dans `docker-compose.yml` (directives `anonymous`, `cors_origins`, clés JWT), bindings de ports, limites de ressources.
- **Prod-readiness/observabilité** : Sentry câblé ? backups PostgreSQL (`pg_dump`/scripts/volumes) ? limites RAM appliquées ? healthchecks ? config prod distincte ?
- **Secrets** : `git ls-files` sur les emplacements sensibles (`backend/config/jwt/`, `.env*`) — vérifier ce qui est réellement tracké avant d'affirmer une fuite.
- **RGPD (statique)** : purge/rétention implémentées ? audit trail ? données de mineurs/coachs exposées ?
- **Perf (si stack up)** : lancer `backend/scripts/smoke-solver.sh` et relever le wall-time. Sinon marquer l'axe `non couvert`.
- **UX (si demandé et navigateur dispo)** : parcourir wizard + planning via Playwright, sinon `non couvert`.

## Étape 2 bis — Chasse aux angles morts (OBLIGATOIRE)

Chaque édition doit **chercher ce que l'édition précédente n'a pas regardé**. Liste les axes non couverts ou partiels de l'édition précédente : couvre-en au moins un de plus si l'environnement le permet (stack up → perf ; navigateur → UX). Puis pose-toi la question : « quel angle mort aucune édition n'a encore ouvert ? » (exemples de candidats : tests de charge API, migration de données entre versions, comportement offline/latence, i18n, coûts d'infra à N clubs, onboarding d'un 2e développeur, restauration après corruption). Ajoute au moins un axe candidat au tableau de couverture — même marqué `non couvert`, il devient visible et redevable.

## Étape 3 — Vérification contradictoire (OBLIGATOIRE)

Chaque finding **critique ou élevé** doit être contre-vérifié à la main avant publication : lire les fichiers/lignes cités, chercher la preuve inverse. Issues possibles : `confirmé` / `réfuté` / `non vérifié`. Un finding réfuté reste dans le rapport, barré, avec la preuve de réfutation — c'est ce qui rend l'audit digne de confiance. Ne publie JAMAIS un finding critique non vérifié sans le marquer `non vérifié`.

## Étape 4 — Notation

Barème fixe (ne pas le modifier — la comparabilité en dépend) :

| Tranche | Signification |
|---|---|
| 90–100 | Exemplaire, niveau production commerciale |
| 75–89 | Solide, prod envisageable en l'état |
| 60–74 | Bon socle, ≥1 chantier significatif avant prod sereine |
| 40–59 | Fragile, ≥1 défaut critique vérifié |
| 20–39 | Défaillant, refonte partielle |
| 0–19 | Non fonctionnel ou dangereux |

Pondérations : **Doc** = exactitude 40 / structure 20 / utilité IA 25 / cycle specs 15. **Besoin** = réalité 40 / adéquation 30 / viabilité 30. **Code par brique** = correction+sécurité 40 / architecture 25 / tests 20 / robustesse 15. Un défaut critique **confirmé** plafonne la brique à 60. Les notes sont un indicateur secondaire — la comparaison inter-éditions se fait sur le registre de findings.

La référence de notation est **l'application commercialisable**, pas le stade de développement courant. Noter contre cette barre en permanence : les notes doivent monter au fil des éditions parce que le code s'améliore, jamais parce que l'audit s'est ramolli.

## Étape 5 — Format de sortie

Écrire `specs/audit/AUDIT-<YYYY-MM-DD>-<model-id>.md` :

1. **En-tête** : date, modèle exact, SHA HEAD, tableau de couverture des axes.
2. **Synthèse des notes** (/100, uniquement les axes couverts).
3. **Registre des findings** — LE cœur de la comparaison. Table : `ID | Titre court | Zone | Gravité | Statut vérif (confirmé/réfuté/non vérifié) | Statut vs édition précédente (nouveau/ouvert/corrigé/réfuté)`. Règles d'ID : préfixe zone (`SEC-`, `ENG-`, `BCK-`, `FRT-`, `DOC-`, `DEP-`, `INF-`, `UX-`, `PERF-`, `RGPD-`) + numéro incrémental **jamais réutilisé**. Reprendre les IDs de l'édition précédente pour les findings ouverts ; marquer `corrigé` ceux qui ont disparu (avec preuve).
4. **Détail par critère** : doc, besoin, chaque brique, supply chain, infra, RGPD — forces, faiblesses avec `fichier:ligne`.
5. **Avis global + axes d'amélioration priorisés** (P0/P1/P2).
6. **Features intéressantes à développer** (ratio valeur/effort, tenant compte de l'état réel).
7. **Annexe méthodologie** : ce qui a été exécuté vs statique, findings contre-vérifiés, limites.

## Règles

- Ne **jamais commiter** le résultat — le fichier reste en working tree, l'utilisateur décide (mémo projet : jamais de merge sans go explicite).
- **Sévérité par défaut.** La barre de notation est l'application **commercialisable** (cible : mi-2027), jamais le stade de développement du moment. Ne jamais adoucir une note ou une gravité parce que « c'est encore en dev » — l'utilisateur veut voir les angles morts tôt et s'y habituer. Pas de gravité « à terme » : un backup absent est Élevé aujourd'hui, point. Le contexte dev se lit dans la trajectoire du registre (findings corrigés édition après édition), pas dans des notes gonflées.
- En cas de doute sur une gravité, prendre la plus haute. Un finding sur-coté se rétrograde à l'édition suivante ; un finding sous-coté devient un incident en prod.
- Direct et factuel. Un audit qui flatte ne sert à rien ; un audit qui affirme sans vérifier non plus.
- Exactitude quand même : la sévérité porte sur la notation, pas sur les faits. Ex. données personnelles : périmètre réel = coachs + comptes users (email/tél) ; les noms d'équipes type « U13M1 » sont génériques, pas des données personnelles. Ne pas gonfler un finding au-delà des faits pour paraître sévère.
- Si un axe coûte trop cher à couvrir cette fois, le dire dans le tableau de couverture plutôt que de noter au doigt mouillé.

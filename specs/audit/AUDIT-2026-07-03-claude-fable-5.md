# Audit ClubScheduler — édition 2026-07-03

| Méta | Valeur |
|---|---|
| Date | 2026-07-03 |
| Modèle | `claude-fable-5` (Claude Fable 5, Anthropic) |
| HEAD | `d2cfe0c` (branche `main`) |
| Méthode | 4 agents d'analyse parallèles (doc, backend, engine, frontend) + checks directs + smoke-solver chronométré + vérification contradictoire des findings critiques |
| Édition précédente | aucune (première édition — registre initial ; consolidée en fin de journée avec perf mesurée) |

## Tableau de couverture

| Axe | Couverture | Note |
|---|---|---|
| Documentation | ✅ couvert | statique + sondage code |
| Besoin produit | ✅ couvert | specs initiales + roadmap + code livré |
| Code backend | ✅ couvert | statique, aucun test exécuté |
| Code engine | ✅ couvert | statique, aucun test exécuté |
| Code frontend | ✅ couvert | statique + `tsc` (exit 0) + `vitest run` (52 verts) |
| Supply chain | ✅ couvert | npm audit, composer audit, pip-audit exécutés |
| Infra / Mercure | 🟡 partiel | docker-compose lu ; config prod non examinée (n'existe pas encore) |
| Prod-readiness / observabilité | 🟡 partiel | constat statique (Sentry, backups, limites, healthchecks) |
| RGPD | 🟡 partiel | constat statique seulement, pas d'analyse juridique |
| Performance mesurée | ✅ couvert | smoke-solver exécuté et chronométré (voir §8) |
| UX navigateur | ✅ couvert | parcours Playwright réel : login → planning → wizard → diagnostics → 1024px, captures + suite e2e exécutée (voir §9) |
| Charge API (N clubs simultanés) | ❌ non couvert | jamais ouvert — candidat d'angle mort déclaré, redevable aux prochaines éditions |
| Restauration après corruption (backup/restore réel) | ❌ non couvert | dépend d'INF-02 (aucun backup à restaurer) — à ouvrir dès que des backups existent |
| Migration de données entre versions (schéma N → N+1 avec données réelles) | ❌ non couvert | jamais ouvert — deviendra critique dès le premier club pilote |

> **Posture de notation** : la barre est **l'application commercialisable** (cible : mi-2027), pas le stade de développement du moment. Les gravités sont cotées contre cette barre, sans adoucissement « c'est encore en dev » — le but est de voir les angles morts tôt. Le contexte dev se lit dans la trajectoire du registre (findings corrigés d'une édition à l'autre), pas dans les notes.

---

## Synthèse des notes

| Critère | Note /100 |
|---|---|
| 1. Documentation (qualité + utilité IA) | **65** |
| 2. Pertinence du besoin | **85** |
| 3a. Code backend (Symfony) | **60** |
| 3b. Code engine (Python / CP-SAT) | **55** |
| 3c. Code frontend (React) | **68** |
| 4. Supply chain | **90** |
| 5. Performance solveur (mesurée) | **80** |
| 6. UX réelle (navigateur) | **62** |
| État global (pondéré) | **66** |

**Lecture rapide :** produit bien pensé, doc au-dessus de la moyenne, frontend sain, dépendances propres, **perf solveur excellente** (49 équipes → COMPLETED en 19,6 s, marge ×9 sur le critère MVP) et écrans login/wizard/planning de bon niveau — mais **zones rouges vérifiées** : sécurité API backend (CRUD Club/User ouvert, RLS documenté mais inexistant), engine (deux contraintes dures mortes en prod, event loop bloqué, **18 faux positifs de diagnostics affichés sur l'écran principal** — ENG-09), hub Mercure en abonnement anonyme, suite e2e rouge. Perf notée 80 et pas plus : un point de mesure unique sans gate CI n'est pas une garantie (PERF-01).

## Barème de notation

| Tranche | Signification |
|---|---|
| 90–100 | Exemplaire. Niveau production commerciale. |
| 75–89 | Solide. Défauts mineurs, prod envisageable en l'état. |
| 60–74 | Bon socle, ≥1 chantier significatif avant prod sereine. |
| 40–59 | Fragile. ≥1 défaut critique vérifié. |
| 20–39 | Défaillant. Refonte partielle nécessaire. |
| 0–19 | Non fonctionnel ou dangereux. |

Pondérations : **Doc** = exactitude 40 / structure 20 / utilité IA 25 / cycle specs 15 · **Besoin** = réalité 40 / adéquation 30 / viabilité 30 · **Code** = correction+sécurité 40 / architecture 25 / tests 20 / robustesse 15. Défaut critique confirmé ⇒ plafond 60 pour la brique. Les notes sont un indicateur secondaire : **la comparaison inter-éditions se fait sur le registre de findings ci-dessous.**

---

## Registre des findings

> IDs stables, jamais réutilisés. Prochaine édition : reprendre ces IDs, marquer `corrigé` (avec preuve) ou `ouvert`.

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| SEC-01 | CRUD cross-tenant sur Club (GET collection / PUT / DELETE tout club) | backend | Critique | confirmé | nouveau |
| SEC-02 | CRUD User ouvert à tout authentifié (énumération emails, delete tiers) | backend | Critique | confirmé | nouveau |
| SEC-03 | RLS PostgreSQL inexistant (helper jamais appelé, zéro policy) alors que la doc l'affirme actif ; `SET LOCAL` hors transaction = no-op | backend | Critique | confirmé | nouveau |
| SEC-04 | `POST /clubs/{id}/import-teams` sans contrôle d'appartenance au club | backend | Élevée | confirmé | nouveau |
| SEC-05 | Hub Mercure : directive `anonymous` + `cors_origins *` → abonnement à tout topic sans JWT (`docker-compose.yml:209-212`). Atténuants : bind 127.0.0.1, UUIDs non devinables, payload = statut/score | infra | Élevée | confirmé | nouveau |
| SEC-06 | ~~Clé privée JWT commitée~~ — **réfuté** : `git ls-files backend/config/jwt/` vide, clés locales non trackées. Reste : `JWT_PASSPHRASE` dupliquée dans `.env:46,67` (la 2e écrase la 1re) | backend | — | réfuté | réfuté |
| ENG-01 | `coach_unavailability` morte en prod : wizard envoie `unavailableDays:[2,4]`, engine compare au `time_key` `"2:18:00"` → jamais match. Indispo coach saisie = ignorée par le solveur | engine | Critique | confirmé | nouveau |
| ENG-02 | `venue_closures` morte (`_contains` sur clés du dict `{dateStart,dateEnd}`) ; branche inatteignable depuis l'UI aujourd'hui | engine | Élevée | confirmé | nouveau |
| ENG-03 | `solver.Solve` dans la coroutine (`main.py:313,333`), zéro executor → event loop bloqué jusqu'à 650 s, `/health` mort, sérialisation de facto globale | engine | Critique | confirmé | nouveau |
| ENG-04 | Tests golden/invariants valident un pipeline copié-collé (`_run_pipeline` ×4) divergent du chemin prod `main._solve` (min_sessions, coach, 2-phases) | engine | Élevée | non vérifié | nouveau |
| ENG-05 | Duck-typing généralisé (`AssignmentLike = Any`, ~50 alias) neutralise mypy strict — cause racine de ENG-01/02 | engine | Élevée | confirmé (indirect) | nouveau |
| ENG-06 | Zéro logging, erreurs 500 brutes, types de contraintes inconnus ignorés en silence | engine | Moyenne | non vérifié | nouveau |
| ENG-07 | Ruff quasi désactivé (pas de `[tool.ruff.lint] select`) | engine | Mineure | non vérifié | nouveau |
| ENG-08 | Encodages quadratiques (paires O(n²)) — a déjà causé un dépassement 30 s (commentaire `constraints.py:578-585`) | engine | Moyenne | non vérifié | nouveau |
| BCK-01 | `messenger.yaml` sans `failure_transport` ni retry adapté → schedule bloqué `PENDING`/`GENERATING` à jamais après épuisement des retries ; pas de watchdog | backend | Élevée | non vérifié | nouveau |
| BCK-02 | Release lock Redis non atomique (GET puis DEL, `ClubGenerationLock.php:36-40`) | backend | Moyenne | non vérifié | nouveau |
| BCK-03 | `method_exists('getClubId')` comme mécanisme de sécurité — cause racine de SEC-01/02 ; remplacer par interface explicite + deny-by-default | backend | Moyenne | confirmé | nouveau |
| BCK-04 | `GenerateScheduleHandler` 442 lignes (4 responsabilités) ; `ScheduleConstraintBuilder` à deps nullable | backend | Moyenne | non vérifié | nouveau |
| BCK-05 | Pagination sans COUNT total ; `#[ApiFilter]` morts sur TeamResource ; validation DTO clairsemée | backend | Mineure | non vérifié | nouveau |
| BCK-06 | Aucun test cross-tenant sur Club/User (les tests tenant ne couvrent que les entités à `club_id`) | backend | Élevée | confirmé (indirect) | nouveau |
| FRT-01 | Mutations sans aucun feedback d'erreur (zéro `onError`/toast) : échec de lock/move/rename/tri invisible | frontend | Élevée | non vérifié | nouveau |
| FRT-02 | Erreurs de query avalées (`PlanningPage.tsx:92-99`, défauts `[]`, `isError` non consommé) : panne réseau = « planning vide » | frontend | Élevée | non vérifié | nouveau |
| FRT-03 | `types.gen.ts` (6 879 lignes, généré OpenAPI) importé nulle part ; types API réécrits à la main, dupliqués et divergents entre `planning/api.ts` et `wizard/api.ts` | frontend | Moyenne | non vérifié | nouveau |
| FRT-04 | Pas d'abonnement Mercure (polling pur) — l'infra SSE backend est inutilisée | frontend | Moyenne | confirmé (commentaire `planning/queries.ts:10`) | nouveau |
| FRT-05 | E2E quasi nulle (3 tests auth) ; flux generate→adjust→regenerate non couvert bout en bout | frontend | Moyenne | non vérifié | nouveau |
| FRT-06 | `ValidateDialog` sans focus-trap/Escape ; `useLaunchGeneration` sans rollback ; `TeamsStep.tsx` 462 l. ; réservations localStorage sans revalidation | frontend | Mineure | non vérifié | nouveau |
| DOC-01 | `docs/project-map.md:72` : « priority 8 » — la valeur documentée ailleurs comme cause du bug de fuite cross-club (code réel : 7) | doc | Élevée | confirmé | nouveau |
| DOC-02 | `backend/AGENTS.md` / `engine/AGENTS.md` : ≥8 faits faux (« 3 controllers » vs 16, « ExportPdfHandler stub » vs implémenté, « 5 fixtures » vs 12, aliases disparus…) | doc | Élevée | non vérifié | nouveau |
| DOC-03 | CLAUDE.md + TENANT.md présentent le RLS comme actif — faux (cf. SEC-03) | doc | Élevée | confirmé | nouveau |
| DOC-04 | Inventaires `specs/courantes/` figés au 2026-06-30 (avant ~19 PRs) ; graduation evolution→courantes non tenue (VALIDATED, identité visuelle) | doc | Moyenne | non vérifié | nouveau |
| DOC-05 | Contradiction interne `testing-strategy.md` (TenantCacheIsolationTest « skipped » ET « resolved ») ; section « Phase 1 Stub » morte dans TENANT.md | doc | Mineure | non vérifié | nouveau |
| DEP-01 | pytest 8.4.2 : CVE-2025-71176, fix 9.0.3 (dépendance dev engine — impact faible) | deps | Mineure | confirmé | nouveau |
| ENG-09 | Diagnostics de capacité en **faux positifs** : 18 « erreurs » sur un planning COMPLETED fixture, chacune listant la même équipe deux fois (« SM3, SM3 », « SM1, SM1 »…). Le check post-solve (`result_builder.py:548-563`) groupe par (gymnase, jour, heure) et compte des slots dupliqués de la même équipe. Cause exacte à creuser : doublons réels dans la sortie engine ou bug du check. Tueur de confiance : premier contact du gestionnaire avec un plan réussi = 18 erreurs rouges fausses | engine | Élevée | confirmé (visuel navigateur + code) | nouveau |
| UX-01 | Toute URL inconnue (ex. `/planning`) affiche la page d'erreur **brute de React Router** : « Unexpected Application Error! 404 Not Found — Hey developer 👋 ». Aucune route catch-all ni `errorElement` custom | frontend | Moyenne | confirmé (capture) | nouveau |
| FRT-07 | La seule suite e2e (`tests/e2e/auth.spec.ts`, 3 tests) est **rouge : 2/3 échouent** — assertion `getByText(/bonjour/i)` périmée, l'UI actuelle n'affiche plus « Bonjour ». La suite e2e ne protège rien en l'état. (Les « 4 specs e2e » cités par `specs/evolution/features-futures.md` n'existent plus — corrobore DOC-04) | frontend | Moyenne | confirmé (exécuté) | nouveau |
| RGPD-01 | Rétention/purge spécifiées (saisons, audit_logs 1 an, PDF) mais **rien d'implémenté** ; pas d'audit trail ; données perso limitées aux **coachs et comptes users** (email/tél) sans politique d'effacement. Les noms d'équipes (« U13M1 ») sont génériques et ne constituent pas une donnée personnelle | transverse | Élevée | confirmé (statique) | nouveau |
| PERF-01 | Pas de gate perf automatisé (CI ni script) : la perf n'est protégée par rien — une régression solveur serait invisible. Mesure manuelle de cette édition (voir §8) : critère « < 3 min » largement tenu aujourd'hui, mais un point de mesure unique n'est pas un garde-fou | engine | Moyenne | mesuré (partiel) | nouveau |
| INF-01 | Observabilité absente : Sentry prévu par la spec (v3 §2.1) mais introuvable (composer.json, config, pyproject, compose) ; aucun monitoring/alerting | infra | Élevée | confirmé | nouveau |
| INF-02 | Aucune stratégie de backup PostgreSQL (zéro `pg_dump`/script/volume de sauvegarde dans le repo) ; restore non testé par définition | infra | Élevée | confirmé | nouveau |
| INF-03 | Limites RAM par service spécifiées (v3 §2.2) mais absentes du compose (aucun `mem_limit`/`deploy.resources`) ; healthchecks présents (10) | infra | Mineure | confirmé | nouveau |

Supply chain : `npm audit --omit=dev` → 0 vulnérabilité · `composer audit` → 0 advisory · `pip-audit` → 1 vulnérabilité (DEP-01).

---

## 1. Documentation — 65/100

### Forces
- Architecture documentaire **pensée pour les agents IA** : ordre de lecture explicite (CLAUDE.md → project-map → specs/courantes), frontières inter-zones testables, checklist de scope injectable, gotchas actionnables.
- CLAUDE.md exact sur les faits sondés (priorité 7, timeout 650 s, contract 2.0, commandes make) et court (89 lignes).
- `docs/technical-debt.md` exemplaire (preuves `fichier:ligne`, résolutions datées).
- Cycle « initiales figées / courantes = vérité / evolution = futur » sain ; roadmap réellement vivante.

### Faiblesses
- Tier 2 décroché : DOC-01, DOC-02, DOC-04, DOC-05.
- **DOC-03 : la doc affirme un RLS actif qui n'existe pas** — le plus gros mensonge documentaire du repo, dangereux pour un projet piloté par agents (du code s'appuie sur cette hypothèse, cf. commentaire `AbstractStateProvider.php:87`).
- Cause racine du drift : **duplication** (les AGENTS.md de zone re-décrivent le contenu de CLAUDE.md/project-map — et ce sont ces copies qui pourrissent) + **comptages volatils** (« 3 controllers », « 20 entities »).

### Verdict utilité IA
Oui au tier 1 (fiable, bien conçu) ; non au tier 2 (induit activement en erreur). Actions : corriger DOC-01/03 (P0), réduire les AGENTS.md de zone à pointeurs + gotchas, bannir les comptages volatils, graduer les specs livrées, re-vérifier les inventaires.

## 2. Pertinence du besoin — 85/100

### Le problème est réel et bien cadré
- 1 semaine de travail manuel/saison pour un gestionnaire salarié (15-50 équipes), à refaire à chaque exception : vrai pain point, chiffré, utilisateur cible précis.
- Club de référence (41 équipes, 8 gymnases) ancre la spec ; critère de sortie MVP mesurable et honnête.
- CP-SAT = bon outil ; hiérarchie de contraintes (HARD pré-placés / dures / molles pondérées / diagnostics) = spec de niveau professionnel.
- La boucle produit est la bonne : **générer puis ajuster** (work-loop, locks, régénération) — c'est comme ça que les gestionnaires travaillent.

### Réserves (les 15 points)
- **Le modèle hebdomadaire pur est une limite produit réelle** : sans templates→occurrences, l'appli résout la semaine type d'août, pas la vie de la saison — or la spec dit elle-même que les exceptions sont la moitié du problème.
- **Aucun bridage de plans** : le modèle SaaS n'existe pas dans le code. Tant que ce n'est pas là, c'est un outil, pas un produit.
- Marché de niche à valider terrain ; le mode démo (l'outil de validation prévu) n'est pas livré.

**Verdict : besoin OK.** Le risque n'est pas le besoin, c'est la distance MVP semaine-type → produit vendable (saison réelle + bridage).

## 3a. Code backend (Symfony) — 60/100

Plafonné à 60 par SEC-01/02/03 (barème). Hygiène seule ≈ 72.

### Forces
- **PHPStan niveau 8 sans baseline** (1 seul ignore inline) — rare.
- Architecture disciplinée : DTO in/out, providers/processors génériques, scalar-FK (pas de N+1 par construction), 174 fichiers majoritairement < 200 lignes.
- Async bien pensé au nominal : lock NX+EX avec token, snapshot figé + hash, diagnostics riches, Mercure sur tous les chemins.
- 24 migrations propres, 36 fichiers de tests à vraies assertions, phase1 sérieux.

### Failles critiques (vérifiées)
- **SEC-01/02** : `ClubResource.php:20-32` et `UserResource.php:20-26` exposent GetCollection/Get/Post/Put/Delete sans `security:` ; providers sans aucun filtre. Tout authentifié liste tous les clubs, modifie `planId` d'autrui, énumère tous les emails, supprime un compte tiers. Cause racine BCK-03 (`method_exists('getClubId')` saute silencieusement les entités sans club_id). **À corriger avant toute prod.**
- **SEC-03** : `01-rls.sql` définit un helper jamais appelé ; `03-rls-template.sql` commenté ; zéro `CREATE POLICY` ; `SET LOCAL` hors transaction = no-op — masqué en test par dama (tout en transaction). La 3e couche de défense est fictive.
- **SEC-04** : `ImportController.php:29-49` sans check membership.

### Autres
BCK-01 (messenger sans failure_transport — schedules zombies), BCK-02 (lock release non atomique), BCK-04 (handler 442 l., deps nullable), BCK-05, BCK-06 (zéro test là où sont les trous), `.env` avec `JWT_PASSPHRASE` dupliquée (SEC-06 résiduel).

## 3b. Code engine (Python / CP-SAT) — 55/100

Plafonné par ENG-01/03. Travail solveur seul ≈ 70.

### Forces
- **2 phases** intelligent : placement optimal verrouillé puis chaining borné 10 s avec warm-start (`AddHint`), plafonds de poids justifiés (`objective.py:59-77`).
- Diagnostics INFEASIBLE riches, en français gestionnaire, sans fallback silencieux (ADR-0001 respecté).
- Timeout adaptatif borné par le payload, seed déterministe sur les 2 phases.
- ~165 tests : goldens, invariants hypothesis, fixture de régression réelle.

### Failles critiques (vérifiées)
- **ENG-01** : chaîne complète vérifiée — `ConstraintsStep.tsx:182` (`unavailableDays: [2,4]`) → `ConstraintSerializer.php:91-93` (transmis brut) → `constraints.py:1534-1536` (stocké) → `_rule_matches`/`_contains` (`:1189-1214`) compare `[2,4]` au `time_key` `"2:18:00"` → toujours faux. **Le produit promet de respecter les indispos coach et ne le fait pas.** Le seul test passe par un flag `coach_unavailable=True` qui n'est pas le format réel.
- **ENG-02** : même mécanique sur `venue_closures`.
- **ENG-03** : `main.py:313,333` — solve CPU-bound dans la coroutine, zéro `run_in_executor`/`to_thread` dans `app/`. Lock par club décoratif.

### Cause racine
ENG-05 : le cœur travaille sur des dicts non typés (Pydantic jeté dès `main.py:117`), mypy strict de facto neutralisé — c'est ce qui a laissé passer ENG-01/02 sans alarme. Plus ENG-04 (tests ≠ pipeline prod), ENG-06 (zéro logging), ENG-07, ENG-08.

## 3c. Code frontend (React) — 70/100

La brique la plus saine. `tsc` strict exit 0, 52 tests verts.

### Forces
- Feature-first propre ; TanStack Query (serveur) + Zustand persist minimal (UI) — séparation exemplaire.
- Cœur planning = **lib pure testée** (`planning/lib/grid.ts`, 391 l. sans React).
- DnD sérieux : fallback clavier a11y, commit atomique du tri ; `aria-label` systématiques ; quasi-zéro `any`.

### Faiblesses
FRT-01 (mutations muettes — fix peu coûteux : `MutationCache.onError` global), FRT-02 (panne réseau = « planning vide »), FRT-03 (pipeline de types généré non branché → zéro garantie de contrat), FRT-04 (polling pur, SSE inutilisée), FRT-05 (E2E quasi nulle), FRT-06 (divers).

## 4. Supply chain — 90/100

npm 0 vuln (prod), composer 0 advisory, pip 1 vuln dev (DEP-01, pytest → 9.0.3). Deps saines, versions épinglées, pas de package louche. -10 : pas de scan automatisé en CI (dependabot/renovate absents).

## 5. Infra / Mercure — partiel (non noté)

- **SEC-05** : hub Mercure en `anonymous` + `cors_origins *` + `publish_origins *` (`docker-compose.yml:209-214`). En dev, bind 127.0.0.1 limite l'exposition ; en prod derrière nginx, tout client peut s'abonner aux événements de génération de n'importe quel club (topics UUID difficiles à deviner — atténuant, pas une protection). À corriger avant prod : JWT subscriber obligatoire + topics privés. Ironie : le frontend ne consomme même pas Mercure (FRT-04) — le hub ouvert ne sert aujourd'hui personne.
- Pas de config prod distincte dans le repo (compose unique) ; limites RAM des services définies dans la spec mais pas dans le compose.
- Pas de monitoring/Sentry câblé, pas de stratégie de backup PostgreSQL visible.

## 6. RGPD — partiel (non noté)

RGPD-01 : la spec prévoit rétention/purge (saisons N-2, audit_logs 1 an, PDF 7-30 j, solver_metrics 6 mois) — **rien n'est implémenté** (pas d'audit trail, pas de purge, pas de politique d'effacement).

Périmètre réel des données personnelles : **emails/téléphones des coachs et comptes utilisateurs** — c'est tout. Les noms d'équipes type « U13M1 » sont des dénominations génériques de catégorie et ne constituent pas une donnée personnelle (pas de noms de joueurs dans le modèle). Le sujet est donc **borné et gérable** : purge programmée + droit à l'effacement sur 2 tables, mentions légales/CGU/DPA au moment de vendre. Jalon pré-commercialisation, pas un défaut du stade de développement actuel.

## 7. Prod-readiness / observabilité — partiel (non noté)

Constat statique, tous vérifiés :

- **INF-01 — Observabilité zéro** : Sentry est dans la spec (v3 §2.1) mais absent du code (aucune trace dans `composer.json`, `config/packages/`, `pyproject.toml`, compose). Aucun monitoring, aucun alerting. Aujourd'hui, une prod qui tombe à 22h est invisible jusqu'au lendemain.
- **INF-02 — Backups inexistants** : zéro `pg_dump`, zéro script, zéro volume de sauvegarde dans le repo. Pour un SaaS où la donnée client EST le produit (des heures de saisie wizard), c'est le premier chantier infra avant le moindre client réel — avant même le monitoring.
- **INF-03 — Limites de ressources** : la spec fixe des limites RAM par service (v3 §2.2), le compose n'en applique aucune (seul le worker Messenger a son `--memory-limit=256M` applicatif). Les 10 healthchecks sont, eux, en place — bon point.
- Pas de config compose prod distincte, pas de rotation de logs, pas de stratégie de déploiement documentée.

Cohérent avec le stade dev — mais INF-02 (backups) devrait précéder le premier club pilote, pas la commercialisation.

## 8. Performance réelle — couvert (mesure du 2026-07-03)

**Mesure exécutée** : `backend/scripts/smoke-solver.sh` chronométré sur la stack locale (WSL2), club fixture BCCL — **49 équipes · 9 salles · 26 coachs**, soit PLUS que le club de référence de la spec (41 équipes · 8 gymnases).

| Métrique | Valeur |
|---|---|
| Wall-time bout-en-bout (create → generate → poll COMPLETED) | **19,6 s** |
| Statut final | COMPLETED, score 9011 |
| Polls | PENDING → GENERATING ×2 → COMPLETED |

**Verdict : le critère de sortie MVP « dense club < 3 min » est tenu avec une marge ×9** sur du matériel de dev — excellente nouvelle, le cœur du produit tient sa promesse de perf. La solve à 2 phases + timeout adaptatif font leur travail.

**Mais (PERF-01 reste ouvert)** : c'est un point de mesure unique, pas un garde-fou. Aucun gate automatisé (le `pytest dense_club < 180s` prévu en CI main par la spec §13.6 n'existe pas). Une régression de perf — nouvel encodage quadratique (cf. ENG-08, déjà mordu une fois), contrainte mal bornée — passerait inaperçue jusqu'à la plainte d'un client. À faire : gate CI sur fixture dense + relever le wall-time engine (`solver_wall_time_ms`) dans chaque édition d'audit pour tracer la tendance.

## 9. UX réelle — couvert — 62/100

Parcours Playwright exécuté (deps Chromium installées en cours d'édition) : login fixture → home → wizard → panneau diagnostics ouvert → viewport 1024px. Suite e2e du repo exécutée dans la foulée.

### Ce qui est bon (et c'est visible)
- **Login** : sobre, propre, focus clair, lien mot de passe oublié + création de compte. Rien à redire.
- **Planning (home)** : grille par gymnase lisible, colonnes jour/gymnase, blocs équipe+coach colorés à l'accent club, sélecteur de planning + Régénérer/Valider + vues Par gymnase/coach/équipe bien hiérarchisés. Le badge « Terminé · score · Planning principal » situe l'état d'un coup d'œil.
- **Wizard** : 6 étapes claires à gauche, aide contextuelle en tête d'étape (« donnez un rang : il tranche quand les créneaux manquent »), regroupement par tier S/A/B, saisie inline. Très bon niveau pour un outil métier.
- Dark theme cohérent partout, libellés 100 % français.

### Ce qui casse la confiance (findings)
- **ENG-09 — le pire constat UX de l'édition** : le panneau Diagnostics affiche **18 erreurs** sur un planning fraîchement COMPLETED… toutes en faux positifs visibles (« Le gymnase X accueille 2 équipes en même temps … : SM3, SM3 » — la même équipe citée deux fois). Premier contact du gestionnaire avec le produit qui vient de réussir = un mur d'erreurs rouges fausses. C'est exactement l'inverse de la promesse (« diagnostics en langage gestionnaire fiables »).
- **UX-01** : `/planning` (URL pourtant plausible) → page d'erreur brute React Router « Hey developer 👋 » destinée aux développeurs, affichée à l'utilisateur final. Manque une route catch-all + `errorElement`.
- **FRT-07** : la suite e2e existante est rouge (2/3, assertion « Bonjour » périmée) — le filet e2e affiché ne protège rien.
- Mineur : à 1024px la grille reste utilisable ; « Sans coach » apparaît en libellé de coach sur un bloc (U15F2) — voulu ou trou de données fixture, à trancher.

### Note 62 — justification
Le socle visuel et la structure sont bons (75+ si seuls comptaient les écrans), mais un produit dont l'écran principal ment (18 fausses erreurs) et dont les URLs inconnues parlent développeur est en dessous de la barre commercialisable. Chantiers : ENG-09, UX-01, remettre l'e2e au vert, puis re-mesurer le temps de saisie wizard réel pour 41+ équipes (non fait cette édition).

---

## Avis global

Projet **au-dessus de la moyenne des projets solo assistés par IA** : spec produit de qualité pro, discipline d'outillage réelle (PHPStan 8 sans baseline, mypy strict, CI ordonnée, 4 tests bloquants), architecture 3 zones respectée, boucle produit conforme au vrai usage métier.

Pattern transversal préoccupant : **l'écart entre le déclaré et l'effectif**. RLS documenté actif mais inexistant ; contraintes coach « supportées » mais mortes ; tests qui valident un autre pipeline que la prod ; types générés jamais branchés ; Mercure câblé côté serveur, ouvert en anonyme, et jamais consommé ; mypy strict neutralisé par `Any`. Chaque brique a sa version du problème. Les filets de sécurité vérifient parfois la fiction plutôt que la réalité.

### Axes d'amélioration priorisés

**P0 — avant toute prod (sécurité + confiance produit)**
1. Verrouiller Club/User (`security:`/voters, GetCollection restreinte aux clubs de l'utilisateur, check membership sur ImportController) + tests cross-tenant Club/User en phase1. [SEC-01/02/04, BCK-06]
2. Corriger le matching `coach_unavailability` engine + test end-to-end au format backend réel ; idem `venue_closures` ou supprimer la branche. [ENG-01/02]
3. Sortir le solve de l'event loop (`asyncio.to_thread` / `ProcessPoolExecutor`). [ENG-03]
4. Mettre la doc en conformité avec la réalité (RLS, priorité 7). [DOC-01/03]
5. Fermer l'abonnement anonyme Mercure avant toute exposition prod. [SEC-05]

**P0 bis — confiance produit (découvert au parcours navigateur)**
- Corriger les 18 faux positifs de diagnostics capacité — l'écran principal ne doit jamais mentir. [ENG-09]

**P1 — robustesse opérationnelle**
6. Activer réellement le RLS (policies + FORCE + `set_config` en transaction) — ou l'assumer absent et le retirer de la doc. [SEC-03]
7. Messenger : failure_transport + retry adapté + watchdog des schedules bloqués. [BCK-01]
8. Feedback d'erreurs frontend global. [FRT-01/02]
9. Converger les pipelines de test engine vers `main._solve`. [ENG-04]
10. Brancher `types.gen.ts` ou le supprimer ; consolider les types API. [FRT-03]
11. Backups PostgreSQL automatisés + restore testé — avant le premier club pilote. [INF-02]
12. Sentry (backend + engine) — la spec le prévoit, l'effort est faible. [INF-01]
13. Chantier RGPD : purge/rétention + audit trail (borné : coachs + users). [RGPD-01]

**P2 — dette structurante**
14. Typer le cœur engine (dataclasses depuis Pydantic, suppression des alias). [ENG-05]
15. `TenantOwnedInterface` + deny-by-default. [BCK-03]
16. Lock Redis atomique (Lua). [BCK-02]
17. Logging engine + sévérités normalisées. [ENG-06]
18. E2E Playwright wizard→génération→ajustement. [FRT-05]
19. Gate perf CI (dense club < 180 s). [PERF-01]
20. Limites RAM compose + rotation logs. [INF-03]

### Conseil de méthode
Le smoke-test actuel valide « un planning sort en COMPLETED ». Ajouter un **smoke-test sémantique** : club fixture avec une indispo coach connue → assert que le planning la respecte. C'est le test qui aurait attrapé ENG-01, et il protégera chaque future contrainte.

---

## Fonctionnalités intéressantes à développer

### Quick wins à forte valeur (semaines)
1. **Mode démo** (club fictif, génération 30 s) — outil de vente ET de validation marché ; prévu, jamais livré ; effort faible. Après les P0 sécurité.
2. **Repos après jour de match** (`teams.match_day`, +3) — seul reliquat du chapitre contraintes, données déjà transmises, câblage simple.
3. **PREFERRED TIME** — backlog existant, analogue au `add_preferred_day_bonus`.
4. **Alerte diagnostic cliquable → focus grille** — transforme le rapport en outil de travail ; pur frontend.

### Structurant produit
5. **Templates → occurrences** (dates réelles, J+14) — LA feature qui fait passer de « générateur de semaine type » à « outil de gestion de saison » ; débloque fermetures datées, vacances, plans alternatifs, annulations. À spec-er en premier.
6. **Bridage des plans + compteur de générations** — business-critique, rien n'existe ; sans verrou de conversion, pas de SaaS.
7. **Transition de saison** — indispensable dès la 2e saison d'un club réel.

### Différenciateurs
8. **Import FFBB** (équipes via code club) — supprime la moitié de la saisie ; infra déjà anticipée.
9. **Collecte dispos coach par lien sans login** — le vrai goulot des gestionnaires ; différenciant.
10. **Stats simples** (remplissage, heures/coach) — peu coûteux post-occurrences ; demande d'AG.

### À ne pas faire maintenant
App mobile (web responsive + PDF suffisent avant des clubs payants) · Multi-sport (attendre une vraie demande) · Connecteurs mairie (V2 au mieux).

---

## Annexe — méthodologie et fiabilité

- 4 agents parallèles (doc, backend, engine, frontend), lecture statique ; frontend : `tsc` + `vitest` exécutés ; supply chain : `npm audit` / `composer audit` / `pip-audit` exécutés dans les conteneurs ; Mercure + prod-readiness : lecture compose + grep Sentry/backup/limites sur l'ensemble du repo.
- **Findings contre-vérifiés à la main** : ENG-01 (chaîne front→back→engine suivie fichier par fichier), ENG-02, ENG-03, SEC-01/02 (resources + providers lus), SEC-03 (init SQL + grep migrations), SEC-05 (compose lu), SEC-06 (**réfuté** — `git ls-files` vide).
- Les findings marqués `non vérifié` proviennent des agents et n'ont pas été individuellement re-vérifiés ; les plus lourds de conséquence l'ont tous été.
- Perf : `smoke-solver.sh` exécuté et chronométré (19,6 s bout-en-bout, 49 équipes, COMPLETED score 9011) — voir §8.
- UX : parcours Playwright réel exécuté (login/planning/wizard/diagnostics/1024px, 7 captures) + suite e2e du repo lancée (1/3 vert). Nouveaux findings ENG-09, UX-01, FRT-07 — tous confirmés par exécution. Voir §9.
- Angles morts déclarés (jamais ouverts, redevables aux prochaines éditions) : charge API multi-clubs, restauration backup réelle, migration de schéma avec données réelles.

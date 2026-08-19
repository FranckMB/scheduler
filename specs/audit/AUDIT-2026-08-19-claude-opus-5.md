# Audit ClubScheduler (Amateo) — édition 2026-08-19

| Méta | Valeur |
|---|---|
| Date | 2026-08-19 |
| Modèle | `claude-opus-5[1m]` (Opus 5, contexte 1M, Anthropic) — première édition non-Fable depuis le 07-08 |
| HEAD | `08c3c38b` (`main` — l'arbre analysé était la branche de passe doc du jour, mergée en #644 pendant l'audit ; contenu identique) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, UX) + checks directs (supply chain ×3 exécutés, Mercure, secrets, prod-readiness, RGPD, cyber A1–A20) + smoke-solver EXÉCUTÉ + **onboarding e2e API EXÉCUTÉ (1re fois, nouvel axe)** + vérification contradictoire manuelle (BCK-15, DOC-31, ALIGN-08, spot-check alignement 3 clés × 3 couches) |
| Édition précédente | `AUDIT-2026-08-08-claude-fable-5.md` (HEAD `0feabea5`) — depuis : **346 commits** (suppression sûre + deletion-impact, vue par club + export PNG au choix, landing publiable via workflow, migration doc de zone + DocPlacementTest, P2-37/38/40/41 overlays/segments/indispo jour-par-jour, bien-être par période, mutualisation P2-27 PR B, previousAssignments P3-21, seed BCCL recalé, Turnstile register, rotation de fraîcheur doc) |

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + 6 sondages (5 EXACT, 1 parenthèse périmée) ; DOC-26/27/28/29/30/04 re-vérifiés un à un |
| Besoin produit | ✅ couvert | roadmap/état-des-lieux vs livré, boucle terrain BCCL |
| Code backend | ✅ couvert | statique ; RLS relu aux migrations des 6 tables nouvelles ; chaîne Deletion suivie de bout en bout |
| Code engine | ✅ couvert | statique ; sharedTrainings/previousAssignments/implicitRules suivis parse→pose→diagnostic ; déterminisme relu au code |
| Code frontend | ✅ couvert | `tsc -b --force` EXÉCUTÉ (0 erreur) + `vitest run` EXÉCUTÉ ×2 (**1623 tests / 178 fichiers**, verts au run 2 ; 4 timeouts de contention au run 1 → FRT-25) |
| Supply chain | ✅ couvert | npm `--omit=dev` + composer audit + pip-audit (62 paquets, freeze réel vérifié) : **0 vuln ×3** |
| Cybersécurité — surface d'attaque | ✅ couvert | A1–A19 re-verdictés sur preuves fraîches + **nouvelle ligne A20** (chaîne de déploiement) |
| Infra / Mercure | ✅ couvert | compose dev + prod relus (mem_limit ×10, healthchecks ×11, restart ×10, un seul port publié 127.0.0.1:8081) |
| Prod-readiness / observabilité | ✅ couvert | Sentry câblé (backend + front + engine DSN), `DatabaseBackupCommand` + volumes, `ProdSecretGuard` |
| RGPD | ✅ couvert (statique) | AccountErasure/RgpdExport/ErasedClubPurger/SeasonDataPurger présents ; purge audit_log 12 mois (connexion admin) |
| Performance solveur | ✅ couvert | `smoke-solver.sh` EXÉCUTÉ : PASS, COMPLETED score 21646, ~52 s mur (setup compris) |
| Alignement contraintes 3 couches | ✅ couvert | table d'alignement relue + spot-check indépendant (`maxEndTime`/`minAtVenueId`/`allowedDays` × front/backend/engine) |
| **Onboarding bout-en-bout (API)** | ✅ couvert — **NOUVEL AXE** | `onboarding-smoke.sh` EXÉCUTÉ : register → vérif email (Mailpit) → minimum de données → génération COMPLETED (score 9025) en **17 s** |
| UX-Cohérence | ✅ couvert | collecte statique exhaustive (primitives, tokens, terminologie) |
| UX-Simplicité / Intuitivité | 🟡 partiel | proxys statiques seuls — **pas de navigateur cette édition** (l'édition 08-08 l'avait : régression de couverture assumée, dite ici) |
| Inclusivité-a11y | 🟡 partiel | statique seul (aria/couleur/cibles/tailles) ; pas de parcours clavier réel |
| Coûts / scalabilité financière | ⬜ non couvert (pas de données réelles) | ligne permanente — aucune donnée de facturation/infra prod ; pas de chiffres fabriqués |

---

## Synthèse des notes

| Critère | 2026-08-08 | **2026-08-19** |
|---|---|---|
| 1. Documentation | 81 | **89** |
| 2. Pertinence du besoin | 91 | **92** |
| 3a. Code backend | 85 | **82** |
| 3b. Code engine | 86 | **91** |
| 3c. Code frontend | 84 | **86** |
| 4. Supply chain | 96 | **96** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 85 | **87** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 89·.10 + 92·.10 + 82·.25 + 91·.20 + 86·.15 + 96·.05 + 90·.075 + 78·.075 = **87,1** → **87**. Malus transversal 0.

⚠ Le backend **descend** (85 → 82) pendant que tout le reste monte : la tendance god-service s'aggrave nettement (`CustomRoutesOpenApiFactory` 962 → **2513 lignes**, `BcclSeeder` +321, `AuthController` 967, 38 fichiers src/ > 300 l.) et la cascade destructive team/coach reste vérifiée en structure seulement (BCK-15). Ce n'est pas un ramollissement de l'audit ailleurs : c'est le registre qui parle.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 08-08 | **08-19** | Plafond appliqué |
|---|---|---|---|
| UX-Cohérence | 82 | **84** | aucun finding ≥ Moyen ; résidus Faibles (amber cockpit UXC-14, UXC-10/12/15/16) |
| UX-Simplicité & Intuitivité | 75 | **80** | UXS-04 corrigé → l'ancien plafond 75 saute ; aucun nouveau ≥ Moyen ; ⚠ axe **partiel** (statique seul) |
| Inclusivité / a11y | 74 | **78** | A11Y-12/13/14 corrigés → l'ancien plafond 75 saute ; résidus Faibles (A11Y-15, FRT-23) |
| **Score UX général** | 74 | **78** | = le PLUS BAS des sous-axes couverts |

**Lecture rapide.** Édition de **consolidation prouvée** : sur les ~25 findings ouverts/partiels de l'édition 08-08, **19 sont corrigés ou fermés avec preuve** — et surtout, plusieurs le sont *mécaniquement* : la liste des blocking-tests est désormais gardée par un test bidirectionnel (`BlockingTestsListMatchesCiTest`), la fraîcheur des docs par `DocStampFreshnessTest`, le placement des docs par `DocPlacementTest`, le snapshot OpenAPI par `OpenApiSnapshotMatchesTheLiveContractTest`, les unions TS par `TsUnionsMatchPhpEnumsTest`. Le motif historique « déclaré ≠ effectif » a changé de camp : il ne survit **ni dans le code ni dans les contrats**, seulement dans deux endroits périphériques (le garde de fraîcheur amputé par la migration doc — DOC-31 — et un repli d'enum jumeau sur CalendarEntry — BCK-14, aujourd'hui inatteignable). Zéro finding nouveau ≥ Élevée — une première depuis le début de la série. Les deux chantiers réels : **la cascade destructive team/coach jamais exécutée en base** (BCK-15, confirme et étend P4-110) et **la tendance god-service backend** qui s'aggrave pendant que le lot Deletion prouve, dans le même dépôt, que l'inverse est possible.

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| DOC-26 | Liste blocking-tests fausse 2 sens | doc | Élevée | contre-vérifié | **corrigé + MÉCANISÉ** — diff 42/42 vide dans les deux sens, gardé par `BlockingTestsListMatchesCiTest.php` (parse le YAML ET CLAUDE.md, échoue dans les deux sens) |
| DOC-27 | Table alignement périmée post-SEC-13 | doc | Élevée | contre-vérifié | **corrigé** — `constraint-emission.md:64` (FACILITY_CAPACITY « retirée »), `constraint-vocabulary.md:102,112` (coachId retiré, famille barrée) |
| BCK-12 | Fallback silencieux enums contrainte | backend | Moyenne | contre-vérifié | **corrigé — et partiellement RÉFUTÉ avec honnêteté** : le docblock `AUD-BCK-12` (`ConstraintStateProcessor.php:303-320`) démontre que le chemin réel rejetait déjà en 422 (`Assert\Choice` sur le DTO) ; le throw explicite (`:321-331` + `unknownEnumValue()`) est posé en défense en profondeur. ⚠ règle non propagée au foyer jumeau → **BCK-14** |
| BCK-13 | UUID gymnase validé en forme seule | backend | Faible | confirmé | **corrigé** — `assertVenuesExist` (`ConstraintStateProcessor.php:277-299`) vérifie EN BASE sous TenantFilter, 422 sans oracle cross-club ; clés dérivées du spec du validateur, pas copiées |
| BCK-10 | findOneBy multi-club sans ordre | backend | Faible | confirmé | **corrigé** — repli figé sur l'adhésion la plus ancienne (`TenantFilterListener.php:244-260`, `['createdAt'=>'ASC','id'=>'ASC']`, marqué AUD-BCK-10, P4-8 cité) |
| ENG-28 | Invariants sur pipeline divergent | engine | Faible | confirmé | **corrigé** — harnais unique via le vrai `build_schedule` (`tests/support/pipeline.py:29-45`, marqué AUD-ENG-28), consommé par invariants ET goldens |
| ENG-29 | Harnais injecte coachId refusé | engine | Faible | confirmé | **corrigé** — doublon supprimé, coach par `scopeTargetId` (`pipeline.py:118-119`, daté AUD-ENG-29) |
| ENG-30 | Sémaphore global partagé place-matches | engine | Faible | confirmé | **corrigé** — `_placement_semaphore` séparé (`main.py:126-134`), réglage documenté (`config.py:27-39`) ; ⚠ nouveau partage placement⇄verdict → **ENG-33** |
| ENG-31 | Chemins morts fixed_slots/two-pass | engine | Mineure | confirmé | **corrigé** — `parsed["fixed_slots"]` supprimé, `add_fixed_slots` réduit au gel de baseline avec docblock AUD-ENG-31 (`constraints.py:1295-1317`) |
| FRT-09 | Retry saison = version fantôme | frontend | Faible | confirmé | **corrigé** — règle extraite en module pur `retryTarget.ts` (réutilise le scheduleId sur FAILED avéré seulement, motivation verrou club écrite), 5 tests falsifiants + intégration |
| FRT-18 | Résidu « (429) » brut en toast | frontend | Mineure | confirmé | **corrigé** — table humaine 400-429 (`errorMessage.ts:58-64`, « Trop de requêtes… Patientez ») + réf. incident X-Request-Id sur 5xx ; testé (matche `/patientez/i`, pas `/429/`) |
| FRT-19 | Types API 100 % manuels | frontend | Moyenne | confirmé | **partiel — abaissé à Faible** : toujours 0 codegen (3 239 l. de types manuels) MAIS deux gardes CI neufs : `OpenApiSnapshotMatchesTheLiveContractTest` (snapshot = artefact gardé, estampillé AUD-FRT-19) + `TsUnionsMatchPhpEnumsTest` (17 enums ⇄ unions TS). La dérive de CHAMPS reste invisible → recoupe **FRT-22** |
| FRT-20 | Hook-mocking au lieu du patron API | frontend | Faible | confirmé | **ouvert, tendance stable** (41 fichiers mockent queries vs 19 l'API) ; contrepoids réel : les règles porteuses sont extraites en modules purs testés (retryTarget, decideWeekAdapt, clubView) |
| DOC-04 | Stamps non fiables (5 récidives) | doc | Moyenne | contre-vérifié | **corrigé + MÉCANISÉ, avec réserve** — `DocStampFreshnessTest` (stamp ≥ dernier commit, cite AUD-DOC-04) ; tous stamps alignés au jour de l'audit. Réserve = la migration doc a fait sortir les docs de zone du périmètre → **DOC-31** |
| DOC-28 | frontend-spec s'auto-contredit (token) | doc | Mineure | confirmé | **corrigé** — contradiction purgée, documentée dans le stamp du fichier |
| DOC-29 | testing-strategy/project-map sans stamp | doc | Faible | confirmé | **corrigé + MÉCANISÉ** — stamps substantiels + fichiers dans WATCHED du garde |
| DOC-30 | Artefact xlsx dans initiales figées | doc | Faible | confirmé | **fermé par décision documentée** — règle « figé interdit de MODIFIER, pas d'ARCHIVER » écrite (`specs/README.md:7`, pièce sourcée), initiales inchangées depuis (`git log` vide) |
| UXS-04 | « Dans -35 j » au cockpit | ux | Moyenne | confirmé | **corrigé** — garde `started` sur les 2 cartes (`RadarPanel.tsx:557,629`, commentaire citant le cas), fériés filtrés en amont (`:326`) |
| A11Y-12 | Cibles ~16 px (< 24 WCAG 2.5.8) | ux | Moyenne | confirmé | **corrigé** — `p-1` posé aux 5 sites flaggés (24 px exacts) ; UN résidu dans le même fichier → **A11Y-15** |
| A11Y-13 | Erreurs non reliées aria-describedby | ux | Faible | confirmé | **corrigé** — describedby conditionnel + id + role=alert aux 4 sites, marqués AUD-A11Y-13 |
| A11Y-14 | 2 × text-[9px] | ux | Faible | confirmé | **corrigé** — 0 occurrence 8/9px ; passés à 10px (le plancher 10px généralisé est documenté → A11Y-16 Info) |
| UXC-10 | Empty states inline | ux | Faible | confirmé | **partiel** — ~10 restants (vs ~14), 17 fichiers consomment les primitives ; + un `EmptyState` local non promu (→ UXC-17) |
| UXC-11 | GenerateStep plan/planning + tu/vous | ux | Faible | confirmé | **partiel** — plan/planning unifié (AUD-UXC-11) ; 2 tutoiements résiduels : `GenerateStep.tsx:211` + un nouveau en tooltip (`DayDialog.tsx:255` → UXC-15) |
| UXC-12 | Console superadmin hors design system | ux | Faible | confirmé | **ouvert, inchangé** (257 lignes de palette en dur dans admin/ ; persona fondateur, pondération faible maintenue) |
| UXC-13 | « salle » résiduel module matchs | ux | Faible | confirmé | **corrigé dans les matchs** (AUD-UXC-13 aux 2 sites) ; résidus légitimes (« salle FFBB » = vocabulaire fédéral) + 1 hors contexte : `delete-confirm.tsx:94` « perdront leur salle » |

**Bilan reprise : 19 corrigés/fermés** (dont 5 MÉCANISÉS par des tests — la vraie nouveauté de cette édition) · 4 partiels (FRT-19 abaissé, FRT-20, UXC-10/11) · 1 ouvert (UXC-12, pondéré faible) · aucune régression sur un finding corrigé.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| **DOC-31** | **Le garde de fraîcheur a perdu les docs de zone à la migration** : `DocStampFreshnessTest.php:39-44` ne surveille que `specs/courantes/*` + 3 fichiers — les 6 docs migrés vers `<zone>/docs/` (dont `backend-inventory.md`, le récidiviste 5× de DOC-04) en sont sortis silencieusement ; `constraint-emission.md`/`constraint-vocabulary.md` n'ont par ailleurs AUCUN stamp | doc | **Moyenne** | **confirmé (contre-vérifié à la main)** | nouveau |
| **BCK-15** | **Cascade destructive ÉQUIPE/COACH jamais exécutée en base** — `DeletionImpactParityTest` : parité structurelle 4 familles, mais exécution réelle pour venue+slot seulement ; le `SharedTrainingGroupPruneStep` (« un groupe qui tombe sous 2 membres part ») n'a **0 test** (grep vide). Confirme P4-110, l'étend | backend | **Moyenne** | **confirmé (contre-vérifié à la main : grep 0 test, forTeam absent des cas base)** | nouveau |
| **ALIGN-08** | **Angle mort triple « pas 3 entraînements d'affilée » (dur)** — besoin BCCL, ❌ aux 3 couches (`constraint-coverage.md:28,73` : le soft `spacing` préfère, ne garantit rien) ; **absent de la roadmap** (grep vide) — rendu redevable ici | align | **Moyenne** | **confirmé (coverage doc + roadmap greppée)** | nouveau |
| BCK-14 | Le repli enum silencieux corrigé sur Constraint **survit sur CalendarEntry** : `CalendarEntryStateProcessor.php:513,527` (`tryFrom(...) ?? EVENT/ACTIVE`) — inatteignable aujourd'hui (`Assert\Choice` sur le DTO), mais un `kind` fautif deviendrait EVENT en silence (une période → un événement, conséquences ADR-0002) | backend | Faible | confirmé code-lu | nouveau |
| BCK-17 | 2 résidus de rigueur tenant du lot P3-16 : `DeletionImpactCounter.php:150-153` (SchedulePlan par seasonId seul, filtres off) ; `DeletionImpactController.php:107-110` (garde qui passe silencieusement si club non résolu — RLS couvre, mais fail-open de la défense en profondeur) | backend | Faible | confirmé code-lu | nouveau |
| BCK-16 | N+1 borné : `SharedTrainingGroupStateProvider.php:59` (1 requête `teamIdsOf` par groupe) — volume structurellement faible | backend | Info | confirmé | nouveau |
| ENG-32 | `constraints.py` à **3581 lignes** (~×2), cumule parsing+pose+diagnostic ; `result_builder.py` 1665, `objective.py` 1366 — point chaud de chaque évolution de contrat | engine | Faible | confirmé | nouveau |
| ENG-33 | `_placement_semaphore` (défaut 1) **partagé** entre `/place-matches` (≤60 s) et `/validate-assignments` (transport backend coupé à 20 s) : un placement du club A peut faire échouer un verdict LÉGAL du club B — la classe d'incident du 2026-08-17, réintroduite par la porte du voisin (`main.py:786-789`, `config.py:27-39`) | engine | Faible | confirmé code-lu | nouveau |
| ENG-34 | Limites de déterminisme ASSUMÉES et écrites : >200 de complexité = 8 workers (assignation non bit-for-bit à score stable, commenté `main.py:404-426`) ; `snapshotHash` exclut `previousAssignments` par construction (gardé par test bloquant) — consignées comme arbitrages, pas comme inconnues | engine | Info | confirmé | nouveau |
| ENG-35 | Repli silencieux `contract_version="2.0"` si le fichier CONTRACT_VERSION manque (`config.py:17`, `main.py:152-156`) — un artefact de build incomplet s'annoncerait « 2.0 » au lieu d'échouer | engine | Info | confirmé | nouveau |
| FRT-21 | **Frontières internes non policées** : `shared/` importe `features/` en 8 points (`scheduleStream.ts:1-2`, `delete-confirm.tsx:1`, `useCredits.ts:1-2`…) ; cycles wizard⇄cockpit (14+4 imports) ; aucune règle ESLint boundaries/no-cycle | frontend | Faible | confirmé | nouveau |
| FRT-22 | **Types API tripliqués à typage inégal** : `Team` défini 3× — `wizard/api.ts:21` (union gardée), `matches/api.ts:196` (`level: string` — **échappe au filet d'enums**), `planning/api.ts:251` ; idem Venue/Coach/Category | frontend | Faible | confirmé | nouveau |
| FRT-25 | **Flakiness au seuil vitest 5000 ms sous charge** : run 1 = 4 timeouts (3 dans `PeriodStructure.test.tsx:801-831`), verts isolés et au run 2 ; `vitest.config.ts` sans `testTimeout` ; la CI (runner 2 vCPU) est exposée au même aléa | frontend | Faible | **confirmé (exécuté ×2)** | nouveau |
| FRT-23 | `ExportMenu` : `role="menu"`/`menuitem` sans sémantique de menu (contient des Select, aucune navigation flèches APG) — un lecteur d'écran annonce un mode d'emploi qui ne répond pas (`ExportMenu.tsx:96,117`) | frontend | Faible | confirmé | nouveau |
| FRT-24 | `GenerationWaiting` : la phrase tournante (3 s) est DANS la région `aria-live="polite"` → annonce SR toutes les 3 s pendant toute la génération (`GenerationWaiting.tsx:294-298`) | frontend | Info | confirmé | nouveau |
| FRT-26 | `ClubViewTable.tsx:167` : en-tête de rang en `scope="colgroup"` au lieu de `rowgroup` | frontend | Info | confirmé | nouveau |
| UXC-14 | Amber/green en dur dans le rail cockpit P2-38/40/41 au lieu des tokens `--warning`/`--success` calibrés AA : `MonthCalendar.tsx:100,123`, `WeekPickerDialog.tsx:205`, `DayDialog.tsx:388`, `WindowAlreadyPlannedNotice.tsx:15,17`, `new-password-fields.tsx:47` | ux | Faible | confirmé | nouveau |
| UXC-15 | Tooltip avec tutoiement + jargon : `DayDialog.tsx:255` « Période générique (custom) — à venir. Utilise… » | ux | Faible | confirmé | nouveau |
| UXC-16 | « (verrou HARD, conservé…) » exposé en clair au gestionnaire (`ReservationPanel.tsx:97`) alors que le concept est traduit « Obligatoire » partout ailleurs | ux | Faible | confirmé | nouveau |
| UXC-17 | `EmptyState` local à `PlanningPage.tsx:99` (pattern plus riche que `EmptyHint`) non promu en primitive — candidat de convergence UXC-10 | ux | Info | confirmé | nouveau |
| A11Y-15 | Résidu direct d'A11Y-12 : `SlotReservationModal.tsx:236-243`, cible 16 px sans padding, incohérente avec ses jumeaux :213/:251 | ux | Faible | confirmé | nouveau |
| A11Y-16 | `text-[10px]` généralisé (~22 occ.) dans les grilles denses — plancher assumé, à documenter comme choix plutôt qu'à corriger en bloc | ux | Info | confirmé | nouveau |
| ALIGN-09 | Scission A persistante : `forcedDays`/`preferredDays` — l'engine sait, le wizard n'expose pas (« au moins une séance tel jour ») — `constraint-coverage.md:26,75` | align | Faible | confirmé | nouveau |
| DOC-32 | `CLAUDE.md:32` décrit un proxy `/engine` dev qui n'existe plus (`docker/frontend/nginx.conf:96-102` l'a retiré) ; commentaires nginx assortis périmés — dérive dans le sens sûr | doc | Faible | confirmé | nouveau |
| DOC-33 | Stamps-fleuves : une ligne ~9 Ko de « précédemment : … » empilés (`frontend-spec.md:7`, `project-map.md:3`, `backend-inventory.md:6`) — noie la seule info utile ; l'historique appartient à git | doc | Faible | confirmé | nouveau |

**Zéro nouveau finding ≥ Élevée — première fois dans la série.** Les 3 Moyennes sont contre-vérifiées à la main.

---

## Tableau de posture cybersécurité (A1–A20)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant | **protégé** | RLS FORCE méta-test bloquant (`RlsIsolationTest`) ; 6 tables nouvelles avec ENABLE+FORCE+tenant_isolation+admin_all (`Version20260817120000.php:34-59`, `…0814140000:44-55`, `…0813160000:40-51`) ; nouvelles routes : `denyForeignClub` ×4 (`DeletionImpactController.php:44-99`), club résolu (`VenueUsageStatsController.php:63-64`), appartenance schedule (`FeedbackController.php:117`) | — |
| A2 | Brute-force /login | **protégé** | `security.yaml:31-32` (login_throttling max 5) ; admin_auth 5/15 min (`rate_limiter.yaml:40-43`) | — |
| A3 | Énumération de comptes | **protégé** | `PasswordResetEnumerationTest` **bloquant** (hash factice, mail par bus, 429) ; `RegisterTurnstileTest` bloquant (403 identique email frais vs connu) | — |
| A4 | Falsification JWT | **protégé** | RS256 lexik ; `git ls-files backend/config/jwt` = **0** (re-exécuté) ; admin = session+TOTP | — |
| A5 | Escalade de privilège | **protégé** | écriture=management CENTRAL (`AbstractStateProcessor.php:84-85`) ; `ManagementRoleTest`+`MemberRoleTest` bloquants ; catalogue admin jobs `manualTriggerAllowed` refusé par défaut (`AdminJobController.php:44`) | — |
| A6 | Mass-assignment | **protégé** | clubId/season fixés serveur (processors) ; enums fautifs → **422 bruyant désormais** (BCK-12 corrigé) ; résidu jumeau inatteignable (BCK-14, Faible) | — |
| A7 | Injection SQL | **protégé** | Doctrine paramétré ; SQL brut nouveaux relus : `PeriodWindowUniquenessGuard.php:54-70` (borné club+season, paramétré) ; GUC via set_config paramétré | — |
| A8 | XSS stockée/reflétée | **protégé** | **0** `dangerouslySetInnerHTML` (re-vérifié) ; logos allowlist binaire sans SVG ; XLSX StringValueBinder (anti formule) sur les exports | — |
| A9 | CSRF | **protégé** | cookie `SameSite=Strict` `path=/api` fail-secure ; admin CSRF central ; Turnstile fail-closed sur register (`TurnstileVerifier.php`) | — |
| A10 | DoS bombe de génération | **protégé** | `GenerationComplexityGuard` + `ClubQuotaTest` bloquant (les **3** routes de solve) + caps engine avec `_bound_total_slots` (contournement 50×1000 fermé) + budget adaptatif + `previousAssignments` cap 2000, `sharedTrainings` cap 50 | — |
| A11 | Spam routes anonymes | **protégé** | rate_limiter register/verify/reset/club_approval + Turnstile ; **feedback throttlé PAR USER** (`rate_limiter.yaml:44-51`) — surface neuve couverte | — |
| A12 | SSRF | **protégé** | hosts const (`FfbbApiClient.php:24-26`), `max_redirects=0` (`:18`) — re-vérifié à l'emplacement actuel (`Service/Basketball/`) | — |
| A13 | Abus upload logo | **protégé** | allowlist png/jpeg/webp + finfo octets + 500 KB (inchangé, `ClubLogoController`) | — |
| A14 | Fuite Mercure | **protégé** | hub non-anonyme, secrets **requis** (`docker-compose.prod.yml:324-325`, syntaxe `:?`), port 127.0.0.1 ; `MercureHardeningTest` bloquant ; abo front par cookie httpOnly scoped club | — |
| A15 | Exposition de secrets | **protégé** | 0 clé trackée (re-exécuté) ; `.env.prod` = template 0 secret (vérifié : uniquement des noms en commentaire) ; `.env.prod.gpg` chiffré = le mécanisme de déploiement ; `ProdSecretGuard` + test | — |
| A16 | Erreurs verboses | **protégé** | `.env.prod` force debug off « even if the operator forgets » (`:9`) ; 5xx jamais repris au front, réf. incident X-Request-Id à la place ; résiduel prod-readiness (pas une fuite) : aucun `error_page` nginx → page blanche 50x, déjà tracé P5-16 | — |
| A17 | Clickjacking / en-têtes | **protégé** | `security-headers.conf` + `csp.conf` présents et inclus au nginx prod (inchangé) | — |
| A18 | Dépendance vulnérable | **protégé** | **0 vuln aux 3 audits exécutés ce jour** (npm --omit=dev · composer · pip-audit 62 paquets) + Dependabot actif (lot du 18-08 traité, rector/actions/cache/npm ×7) | — |
| A19 | Usurpation d'approbation de club | **protégé** | inchangé : token 32 octets, 404 byte-identique, rate-limit IP avant résolution, advisory lock anti-double-création | — |
| **A20** | **Compromission de la chaîne de déploiement** (secrets CI, injection dans le job deploy, artefact substitué) — *nouvelle ligne : le workflow scp désormais la landing et roule la VM* | **protégé** | `DEPLOY_PATH`/`VERSION` validés strictement (**5 gardes** `grep -Eq` dans `deploy.yml`, jamais interpolés crus) ; `remote-deploy.sh` exécuté **en fichier**, jamais pipé ; `.env.prod.gpg` déchiffré en RAM runner (`$RUNNER_TEMP`, rm après) ; gate `DEPLOY_ENABLED` + secrets ; rollback = images du registre, jamais rebuild | — |

**Bilan cyber : 20 protégé · 0 partiel · 0 absent · 0 non vérifié.** La surface a encore bougé (4 routes deletion-impact, feedback authentifié, Turnstile, workflow de déploiement qui embarque la landing) et reste tenue. Vs 08-08 : 19/0/0 → 20/0/0.

---

## Détail par critère

### 1. Documentation — 89/100 (exactitude 93 · structure 85 · utilité IA 82 · cycle specs 96)
**Forces.** Les 6 sondages CLAUDE.md : 5 EXACT (liste §4 des 42 blocking-tests exacte **dans les deux sens** et gardée par test ; contrat 2.12 ; priorité 7 ; zéro X-Club-Id front ; boundaries tenues au grep des imports). Le cycle specs est au meilleur niveau de la série : initiales figées (git log vide), roadmap 45=45 (compteur exact), toutes les livraisons #620-#644 tracées et recoupées au code, 5 findings doc de l'édition précédente corrigés dont 3 **mécanisés**. La migration doc de zone est propre (0 lien mort versionné) et gardée par `DocPlacementTest`.
**Faiblesses.** DOC-31 (le garde de fraîcheur a perdu exactement les fichiers qui récidivaient) ; stamps-fleuves de ~9 Ko (DOC-33) ; CLAUDE.md à 238 lignes (dépassement porté par la liste §4 gardée — assumable) ; parenthèse `/engine` périmée (DOC-32).

### 2. Pertinence du besoin — 92/100
La boucle terrain est la plus courte observée : les retours BCCL du 18-08 (P2-37/38/40/41) sont **livrés le jour même ou le lendemain**, chacun avec sa décision fondateur consignée. La landing est publiable (il ne reste que le geste d'ops, dit honnêtement dans la roadmap). Réserve constante : P5-13 (reproduire les 3 plannings réels) reste le juge de paix produit et n'est pas fini.

### 3a. Code backend — 82/100 (correction+sécu 88 · architecture 74 · tests 80 · robustesse 85)
**Forces.** Les 3 findings ouverts corrigés à la règle, pas au cas (BCK-12 avec réfutation honnête documentée ; BCK-13 vérifié en base sans oracle ; BCK-10 figé + tracé P4-8). RLS complet et systématique sur les 6 tables nouvelles. Le lot `src/Deletion/` est un modèle de décomposition (12 fichiers 19-188 l., plan unique compteur⇄deleter). Locks avec marge TTL et release par token ; GUC posé dans les 3 handlers async ; 409 structurés ; `ExportView` liste blanche.
**Faiblesses.** BCK-15 (la seule règle métier propre à la cascade team — le prune de mutualisation — n'a aucun test ; team/coach jamais exécutés en base). **Tendance god-service aggravée** : `CustomRoutesOpenApiFactory` 962→**2513 l.** (+1551, le plus gros fichier de src/), `BcclSeeder` 1482, `AuthController` 967, `PdfGenerator` +315, 38 fichiers > 300 l. — le contraste avec `src/Deletion/` prouve que la discipline inverse est possible dans ce dépôt. BCK-14/16/17 mineurs.

### 3b. Code engine — 91/100 (correction+sécu 93 · architecture 88 · tests 92 · robustesse 90)
**Forces.** Les 4 findings fermés avec marqueurs datés AUD-*. Les nouveautés de contrat sont **falsifiées dans les deux sens** : sharedTrainings (réification ⇔ + Σy==K, membres verrouillés = constante 1, miroir déterministe côté verdict), previousAssignments (préférence jamais dure, séparation lexicographique **prouvée par arithmétique écrite**, `4096 > 2000`), implicitRules (parité génération⇄verdict). Harnais unique = chemin prod, gardé par méta-test. Déterminisme relu au code, pas présumé : 1 worker ≤200 (bit-for-bit goldens), 8 au-delà (assumé, commenté). Snapshot figé+hashé AVANT le POST (`GenerateScheduleHandler.php:206-232`).
**Faiblesses.** `constraints.py` 3581 l. (ENG-32) ; sémaphore placement partagé avec le rail verdict interactif (ENG-33 — la classe d'incident 502/504 du 17-08 peut revenir par le voisin) ; replis Info (ENG-35).

### 3c. Code frontend — 86/100 (correction+sécu 90 · architecture 80 · tests 83 · robustesse 88)
**Forces.** Exécuté : tsc 0 erreur, **1623 tests / 178 fichiers verts** (+656 tests en 11 jours). FRT-09 et le résidu 429 fermés avec tests falsifiants. La vue par club a un contrat de props **strictement identique** à WeekGrid (9 props, TargetMode importé). Doctrine d'erreurs à deux étages documentée. Mercure sans régression (ré-auth à chaque réouverture, fallback qui ralentit sans mourir). 0 innerHTML, 0 any, jsx-a11y bloquant.
**Faiblesses.** FRT-21 (shared→features en 8 points, cycles wizard⇄cockpit, aucune règle ESLint de frontière) ; FRT-22 (Team triplé dont une copie `string` qui échappe au filet d'enums) ; FRT-25 (4 timeouts de contention au seuil 5000 ms — la CI 2 vCPU y est exposée) ; FRT-20 stable ; FRT-23/24/26 a11y mineurs.

### 4. Supply chain — 96/100
0 vulnérabilité aux trois audits exécutés ce jour. Dependabot actif et le lot du 18-08 traité avec journal (`docs/upgrades.md`). Retenue inchangée : pas de SBOM, pip-audit ne couvre pas le paquet local.

### 5. Performance solveur — 90/100
`smoke-solver.sh` : PASS, COMPLETED score 21646, ~52 s mur (dont setup/restauration du script). `onboarding-smoke.sh` : génération d'un club minimal en 17 s bout en bout. Cohérent avec le budget adaptatif 60/180/600 s. Pas de test de charge (axe coûts non couvert).

### Cybersécurité — voir tableau A1–A20 : 20/20 protégé
Fait notable de l'édition : la surface a grandi (deletion-impact ×4, feedback, Turnstile, déploiement) **sans qu'aucune ligne ne se dégrade** — et la nouvelle ligne A20 naît protégée (validations strictes déjà en place dans le workflow).

### RGPD — couvert, sans finding nouveau
Effacement de compte, export club, purge saison, purge club effacé présents ; audit_log immuable côté app_user, purge 12 mois par la connexion admin ; feedback authentifié throttlé lu côté admin seulement. Périmètre PII réel inchangé (comptes + coachs).

### UX — cohérence 84 · simplicité 80 (partiel) · inclusivité 78 (partiel) → général 78
**Le grand mouvement** : les trois plafonds de l'édition précédente (UXS-04, A11Y-12 en Moyenne) ont sauté — corrigés avec preuve. Les nouveaux composants (ClubViewTable, DayDialog, WeekPickerDialog) sont **au-dessus du niveau moyen du dépôt** en a11y (aria-labels, role=status, scope, émojis aria-hidden) et aucune information n'est portée par la seule couleur sur un flux critique. Flux courts (générer = 2 clics depuis le cockpit, indispo gymnase = 4 clics, dates préremplies). Résidus : amber en dur dans le rail cockpit (UXC-14), « verrou HARD » exposé (UXC-16), 2 tutoiements, une cible 16 px (A11Y-15), le rôle menu de l'ExportMenu (FRT-23). ⚠ Pas de parcours navigateur cette édition (le 08-08 en avait un) : les sous-axes simplicité/inclusivité sont **partiels**, leurs notes reposent sur les proxys statiques.

---

## Avis global + axes priorisés

**L'édition du basculement mécanique.** Depuis quatre éditions, le motif dominant était « déclaré ≠ effectif » — le code ou la doc qui promettait ce qu'il ne tenait pas. Cette édition constate que la réponse du dépôt n'a pas été de corriger les cas mais d'installer des **gardes structurelles** : liste bloquante diffée contre la CI, stamps contre les dates git, placement des docs contre les zones, snapshot OpenAPI contre le contrat vivant, unions TS contre les enums PHP, parité annoncé⇄détruit contre le plan de cascade. 19 findings fermés, zéro nouveau ≥ Élevée, la posture cyber à 20/20 sur une surface qui a grandi. Les deux vrais chantiers restants se nomment sans détour : **exécuter la cascade team/coach en base** (BCK-15/P4-110 — du code destructif ne se juge pas sur sa structure) et **arrêter l'obésité des god-services backend** (2513 lignes pour une factory OpenAPI). La note backend qui descend pendant que tout monte est le message de cette édition.

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| DOC-26 resynchroniser liste §4 ⇄ CI + test de diff | ~~P1~~ | — | ✅ mécanisé (`BlockingTestsListMatchesCiTest`) |
| DOC-27 purger coachId/FACILITY_CAPACITY des docs d'alignement | ~~P1~~ | — | ✅ |
| BCK-12 422 sur tryFrom null | ~~P1~~ | — | ✅ (et réfutation partielle documentée) ; ⚠ foyer jumeau → BCK-14 ⬜ |
| UXS-04 garde de négatif radar | ~~P1~~ | — | ✅ |
| A11Y-12/13/14 cibles/describedby/9px | ~~P2~~ | — | ✅ (résidu A11Y-15 ⬜ P3) |
| FRT-19 codegen OU test de dérive contractuelle | ~~P2~~ | — | 🟡 **partiel** — 2 gardes CI posés (snapshot + enums) ; la dérive de champs reste ouverte → FRT-22 ⬜ |
| ENG-28/29 harnais sur le vrai pipeline | ~~P2~~ | — | ✅ les deux |
| ENG-30 file dédiée place-matches | ~~P3~~ | — | ✅ (sémaphore séparé) ; ⚠ nouveau partage avec le verdict → ENG-33 ⬜ |
| DOC-04 généraliser le patron anti-mensonge | ~~P2~~ | — | ✅ mécanisé ; ⚠ périmètre amputé par la migration → DOC-31 ⬜ |
| FRT-09 résidu retry · DOC-28/29/30 | ~~P3~~ | — | ✅ tous |
| **BCK-15** — exécuter `forTeam`/`forCoach` en base dans DeletionImpactParityTest + tester le PruneStep (groupe à 2 tombe / à 3 survit) | **P1** | S | ⬜ nouveau (roadmap P4-110 étendue) |
| **DOC-31** — étendre WATCHED de DocStampFreshnessTest aux `<zone>/docs/` + stamps sur constraint-emission/vocabulary | **P1** | S | ⬜ nouveau (roadmap P4-113) |
| **ALIGN-08** — cadrer « pas 3 entraînements d'affilée » DUR (besoin BCCL, angle mort triple) ou trancher son abandon | **P1** (produit) | M | ⬜ nouveau (roadmap P2-42) |
| BCK-14 — propager le throw enum à CalendarEntry | P2 | S | ⬜ nouveau (roadmap P4-114) |
| ENG-33 — budget/sémaphore propre au rail verdict | P2 | S | ⬜ nouveau (roadmap P4-115) |
| FRT-25 — `testTimeout` vitest adapté aux tests d'écran (CI 2 vCPU exposée) | P2 | S | ⬜ nouveau (roadmap P4-116) |
| FRT-21 — règles ESLint de frontière (shared↛features, no-cycle) | P2 | M | ⬜ nouveau |
| FRT-22 — dédupliquer les types API inter-features ou étendre le garde aux copies | P2 | M | ⬜ nouveau |
| Hook off-site backups (résidu qui traverse les éditions depuis le 07-19) | P2 | S | ⬜ |
| Tendance god-service : geler `CustomRoutesOpenApiFactory` (découpe par domaine) au prochain gros ajout | P2 | M | ⬜ nouveau (tendance BCK-04) |
| Fond de sac : DOC-32/33 · BCK-16/17 · ENG-32/35 · FRT-20/23/24/26 · UXC-10/11/12/14/15/16/17 · A11Y-15/16 · ALIGN-09 | P3 | S | ⬜ (roadmap P4-117 groupée) |

## Features intéressantes à développer (valeur/effort)

1. **Publier la landing** (P5-5 — il ne reste QUE le geste d'ops : VM, DNS, Caddy) — le levier commercial de rentrée, tout le dépôt est prêt.
2. **« Pas 3 entraînements d'affilée » dur** (ALIGN-08/P2-42) — besoin BCCL nommé, le soft existe déjà, le mécanisme dur est un ajout borné au moteur.
3. **Exposer `forcedDays`** (« au moins une séance tel jour », ALIGN-09) — l'engine sait déjà, il ne manque que l'UI du wizard.
4. **Test de restauration** (restore drill sur les dumps) — l'axe backups est outillé mais jamais éprouvé ; candidat d'angle mort pour la prochaine édition.
5. **Parcours navigateur re-ouvert** à la prochaine édition (les axes UX dynamiques sont retombés en partiel).

---

## Annexe méthodologie

**Exécuté** : `tsc -b --force` (0 erreur) ; `vitest run` ×2 (1623 tests, 178 fichiers — run 1 : 4 timeouts de contention, run 2 : 100 % verts) ; `npm audit --omit=dev` + `composer audit` + `pip-audit` (0 vuln ×3, freeze de 62 paquets vérifié non-vide) ; `smoke-solver.sh` (PASS, 52 s) ; `onboarding-smoke.sh` (PASS, 17 s — nouvel axe) ; greps de vérification cyber sur la surface neuve. **Statique** : backend, engine, doc, UX (agents lecture seule).

**Contre-vérifiés à la main (Étape 3)** : BCK-15 (grep : 0 test PruneStep ; forTeam absent des cas base), DOC-31 (WATCHED lu : 4 motifs, docs de zone absents), ALIGN-08 (coverage doc l.28/73 + roadmap greppée vide), alignement 3 couches (spot-check indépendant `maxEndTime`/`minAtVenueId`/`allowedDays` aux 3 couches). Aucun finding ≥ Élevée à contre-vérifier — premier registre de la série sans.

**Limites** : pas de parcours navigateur (les axes UX dynamiques retombent en partiel — régression de couverture vs 08-08, assumée et dite) ; pas de phpunit/pytest lancés par l'audit lui-même (mais la CI de `main` était verte le jour même, 14 checks) ; le déploiement (A20) est vérifié sur le code du workflow, pas sur une exécution réelle (aucune VM n'existe).

**Confiance par axe** : élevée = frontend (exécuté ×2), supply chain (exécuté ×3), perf (exécuté), onboarding (exécuté), cyber (preuves recoupées + 2 smokes) · moyenne = backend, engine, doc, UX-cohérence (lecture de code seule, mais recoupée entre agents et contre-vérifiée sur les Moyennes) · faible = UX-simplicité/inclusivité dynamiques (non exécutés — notes assises sur les proxys statiques).

**Auto-question de biais (honnête)** : (1) sur-poids de ce qui se greppe — les trois quarts des verdicts cyber reposent sur des constantes et des configs lues, pas sur des attaques simulées ; un vrai pentest pourrait contredire des « protégé ». (2) Sous-poids de l'exécution backend/engine : je n'ai pas lancé leurs suites moi-même, je m'appuie sur la CI du jour — un test flaky masqué m'échapperait. (3) Biais de continuité : je reprends le barème et les pondérations de Fable sans les re-questionner, c'est voulu (comparabilité) mais un biais quand même. (4) Biais d'auteur : plusieurs livraisons auditées (deletion-impact, vue par club, migration doc, rotation) sont **mon propre travail de la même session** — les agents étaient indépendants et BCK-15/BCK-17/FRT-23/FRT-26 attaquent précisément ce travail, ce qui est bon signe, mais le risque d'aveuglement existe et je le nomme. (5) Données manquantes : coûts réels, comportement multi-clubs simultanés, restauration de backup jamais éprouvée.

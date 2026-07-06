# Audit ClubScheduler — édition 2026-07-06

| Méta | Valeur |
|---|---|
| Date | 2026-07-06 |
| Modèle | `claude-fable-5` (Claude Fable 5, Anthropic) |
| HEAD | `dc26bc3` (branche `main`) |
| Méthode | 4 agents d'analyse parallèles (doc, backend, engine, frontend) + checks directs (supply chain, Mercure, secrets, prod-readiness, RGPD) + smoke-solver chronométré + burst de charge API + vérification contradictoire manuelle des findings critiques/élevés |
| Édition précédente | `AUDIT-2026-07-03-claude-fable-5.md` (HEAD `d2cfe0c`) — ~25 PRs depuis (séries ENGINE #26–#32, SEC, BCK, cockpit paliers A/B/C, vacances scolaires #62, jours fériés #63) |

## Tableau de couverture

| Axe | Couverture | Note |
|---|---|---|
| Documentation | ✅ couvert | statique + 13 sondages code |
| Besoin produit | ✅ couvert | roadmap + état livré vs specs initiales |
| Code backend | ✅ couvert | statique + `doctrine:schema:validate` (en phase) |
| Code engine | ✅ couvert | statique, chaînes de contraintes suivies bout en bout |
| Code frontend | ✅ couvert | statique + `tsc` (exit 0) + `vitest run` (110 verts) + suite e2e exécutée |
| Supply chain | ✅ couvert | npm audit, composer audit, pip-audit exécutés |
| Infra / Mercure | 🟡 partiel | compose lu (Mercure durci vérifié) ; config prod toujours inexistante |
| Prod-readiness / observabilité | 🟡 partiel | constat statique (Sentry, backups, limites, healthchecks) |
| RGPD | 🟡 partiel | constat statique, pas d'analyse juridique |
| Performance mesurée | ✅ couvert | smoke-solver chronométré 13,1 s + gate perf CI vérifié (§8) |
| UX navigateur | ✅ couvert | parcours Playwright scripté (login → planning → wizard → URL inconnue → 1024px, 7 captures, console surveillée) + e2e exécutée. Cockpit non visitable (gate socle non validé sur la fixture — mutation refusée en audit) |
| **Charge API** (angle mort édition préc.) | 🟡 **ouvert cette édition** | burst 100 GET authentifiés ×20 concurrents (§8bis) — smoke, pas un test de charge |
| Restauration après corruption (backup/restore réel) | ❌ non couvert | toujours bloqué par INF-02 (aucun backup à restaurer) |
| Migration de données entre versions | ❌ non couvert | jamais ouvert — critique dès le premier club pilote |
| **Comportement offline / latence réseau frontend** (nouveau candidat) | ❌ non couvert | déclaré cette édition : perte réseau en cours de wizard/génération, retries, reprise — jamais regardé |

> **Posture de notation** : barre = **application commercialisable** (cible mi-2027). Gravités cotées contre cette barre, sans adoucissement « c'est encore en dev ». La trajectoire se lit dans le registre.

---

## Synthèse des notes

| Critère | 2026-07-03 | **2026-07-06** |
|---|---|---|
| 1. Documentation | 65 | **78** |
| 2. Pertinence du besoin | 85 | **87** |
| 3a. Code backend (Symfony) | 60 | **85** |
| 3b. Code engine (Python / CP-SAT) | 55 | **75** |
| 3c. Code frontend (React) | 68 | **69** |
| 4. Supply chain | 90 | **90** |
| 5. Performance solveur (mesurée + gate) | 80 | **90** |
| 6. UX réelle (navigateur) | 62 | **72** |
| **État global (pondéré)** | 66 | **77** |

Pondération globale explicite : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 % = 79,8, **− 3 pts de malus transversal** pour les Élevées ouvertes hors briques notées (INF-01/02 observabilité+backups, RGPD-01, e2e rouge) → **77**.

**Lecture rapide.** Progression la plus forte depuis le début du registre : **les 6 findings critiques de l'édition précédente sont tous corrigés et vérifiés dans le code** (Club/User verrouillés, RLS réellement actif et fail-closed, coach_unavailability vivante, solve hors event loop, faux diagnostics éliminés), plus 8 élevées closes (Mercure durci, messenger 3 filets, tests cross-tenant, gate perf CI…). Le pattern « déclaré ≠ effectif » de l'édition précédente est en net recul — mais **pas mort** : cette édition le retrouve à plus petite échelle sur la sémantique des contraintes (PREFERRED DAY placebo ENG-10, soft escaladé dur ENG-11, BONUS mort ENG-12) et dans la doc sécurité (bypass `migration_user` fictif DOC-06). Les impasses structurelles restantes sont connues et stables : observabilité zéro, backups zéro, RGPD zéro, e2e rouge non entretenue.

## Barème (inchangé)

| Tranche | Signification |
|---|---|
| 90–100 | Exemplaire, production commerciale |
| 75–89 | Solide, prod envisageable en l'état |
| 60–74 | Bon socle, ≥1 chantier significatif avant prod sereine |
| 40–59 | Fragile, ≥1 défaut critique vérifié |
| 20–39 | Défaillant, refonte partielle |
| 0–19 | Non fonctionnel ou dangereux |

Pondérations : Doc = exactitude 40 / structure 20 / utilité IA 25 / cycle specs 15 · Besoin = réalité 40 / adéquation 30 / viabilité 30 · Code = correction+sécurité 40 / architecture 25 / tests 20 / robustesse 15. Critique confirmé ⇒ plafond 60.

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| SEC-01 | CRUD cross-tenant Club | backend | Critique | confirmé | **corrigé** — `ClubResource.php:19-35` (Post=SUPER_ADMIN, plus de Delete), `ClubStateProvider.php:44-54` (collection = memberships, fail-closed), `ClubAccessTest` 9 tests phase1 |
| SEC-02 | CRUD User ouvert | backend | Critique | confirmé | **corrigé** — `UserResource.php:23-26` (Get/Put self-only, plus de GetCollection/Delete), `UserSelfOnlyTest` 6 tests phase1 |
| SEC-03 | RLS inexistant | backend | Critique | confirmé | **corrigé** — `Version20260703120000.php:77-92` : `FORCE ROW LEVEL SECURITY` + `CREATE POLICY tenant_isolation … TO app_user USING (club_id = NULLIF(current_setting('app.club_id',true),'')::uuid)` (GUC vide ⇒ 0 ligne, fail-closed) ; runtime `app_user`, porte admin séparée ; `set_config(…, false)` paramétré remplace le `SET LOCAL` no-op ; `RlsIsolationTest` 7 tests. Contre-vérifié à la main cette édition. ⚠ incompatibilité pgbouncer documentée dans le code |
| SEC-04 | import-teams sans check | backend | Élevée | confirmé | **corrigé** — `ImportController.php:33-46` (membership→404, rôle→403), `ImportAuthorizationTest` 4 tests |
| SEC-05 | Mercure anonyme + CORS * | infra | Élevée | confirmé | **corrigé** — `docker-compose.yml:252-260` : secret dédié `MERCURE_JWT_SECRET`, plus de directive `anonymous`, `cors_origins` limité aux fronts dev, plus de `publish_origins *` ; `MercureHardeningTest` en blocking CI. Contre-vérifié à la main |
| SEC-06 | ~~Clé JWT commitée~~ + passphrase dupliquée | backend | — | réfuté (éd. préc.) | **résiduel corrigé** — `JWT_PASSPHRASE` unique (`backend/.env:50`), secret Mercure distinct (`:63`) |
| ENG-01 | coach_unavailability morte | engine | Critique | confirmé | **corrigé** — `constraints.py:1619-1629` parse `unavailableDays`/`availableDays` en `set[int]`, matching par jour int (`:769-804`), tous les coachs de l'équipe couverts ; smoke sémantique au format backend réel (`tests/semantic/test_semantic_smoke.py:154-233`) |
| ENG-02 | venue_closures morte | engine | Élevée | confirmé | **corrigé (déplacé backend)** — expansion en FACILITY HARD `forbiddenVenueId` (`ScheduleConstraintBuilder.php:309-337`), honorée engine. Résidu = code mort (voir ENG-15) |
| ENG-03 | Solve bloque l'event loop | engine | Critique | confirmé | **corrigé** — `main.py:161-163` `asyncio.to_thread` + sémaphore `max_concurrent_solves` + locks par club bornés/purgés ; `test_runtime.py:35-58` (/health répond pendant un solve) |
| ENG-04 | Tests ≠ pipeline prod | engine | Élevée | — | **corrigé** — harnais unique `tests/support/pipeline.py` → `app.main.build_schedule` ; plus aucun `_run_pipeline` copié-collé |
| ENG-05 | Duck-typing `Any` cœur engine | engine | Élevée | confirmé | **partiel** — parse typé (`ParsedConstraints` TypedDict) ; mais `AssignmentLike/BoolVarLike/RuleCollection = Any` subsistent (constraints 62 `Any`, result_builder 55, objective 31) |
| ENG-06 | Zéro logging / 500 brutes | engine | Moyenne | — | **partiel (largement corrigé)** — logging opérationnel (`main.py:33-40`), types inconnus loggés WARNING (testé) ; reste : pas d'exception handler global |
| ENG-07 | Ruff quasi désactivé | engine | Mineure | — | **corrigé** — `pyproject.toml:48-56` (E,F,W,I,B,UP,SIM,C4,RUF) + bandit dans make lint |
| ENG-08 | Encodages quadratiques | engine | Moyenne | — | **partiel (encadré)** — O(n³) venue-day supprimé (`constraints.py:604-613`) ; paires restantes documentées + couvertes par le gate perf |
| ENG-09 | 18 faux positifs diagnostics | engine | Élevée | confirmé | **corrigé** — `result_builder.py:543-548` dédup équipes + seuil = capacité réelle ; coach dédup (team,venue) sans masquer les vrais conflits ; `tests/semantic/test_diagnostics.py` |
| BCK-01 | Schedules zombies (messenger) | backend | Élevée | — | **corrigé** — 3 filets vérifiés : catch-all handler→FAILED (`GenerateScheduleHandler.php:100`), `ScheduleGenerationFailureListener` (willRetry false), `app:schedules:reconcile-stuck` horaire via cron-runner ; `messenger.yaml:14-24` failure_transport+retry ; tests dédiés |
| BCK-02 | Release lock non atomique | backend | Moyenne | — | **corrigé** — compare-and-delete Lua (`ClubGenerationLock.php:36-42`) |
| BCK-03 | `method_exists` = sécurité | backend | Moyenne | confirmé | **corrigé** — `TenantOwnedInterface` + `TenantFilter` scoped par colonne `club_id` + `TenantOwnedInterfaceCompletenessTest` |
| BCK-04 | Handler 442 l. / deps nullable | backend | Moyenne | — | **partiel** — handler ↓312 l. ; `ScheduleConstraintBuilder.php:52-57` : 4 deps toujours nullable |
| BCK-05 | Pagination/filtres/DTO | backend | Mineure | — | **corrigé (essentiel)** — pagination 30/p partout + `CollectionPaginationTest`, ApiFilter alignés, 91 `Assert\` dans les DTO |
| BCK-06 | Zéro test cross-tenant Club/User | backend | Élevée | confirmé | **corrigé** — 13 classes `tests/Security/` phase1 |
| FRT-01 | Mutations muettes | frontend | Élevée | — | **corrigé** — `MutationCache.onError` + `QueryCache.onError` globaux → toasts, anti-doublon, skip 401 (`queryClient.ts`) |
| FRT-02 | Erreurs query avalées | frontend | Élevée | — | **partiel** — toast global au 1er échec ; mais `PlanningPage.tsx:81` garde `data = []` sans `isError` → après le toast, l'UI = « planning vide » sans écran d'erreur ni retry |
| FRT-03 | types.gen.ts mort + types dupliqués | frontend | Moyenne | — | **ouvert** — 6 879 l., 0 import ; `Team`/`Venue`/`ScheduleStatus` dupliqués planning/api.ts ↔ wizard/api.ts |
| FRT-04 | Pas de Mercure (polling) | frontend | Moyenne | confirmé | **ouvert** — aucun EventSource ; polling 2,5 s assumé (`planning/queries.ts:13`) |
| FRT-05 | E2E quasi nulle | frontend | Moyenne | — | **ouvert** — toujours 1 spec / 3 tests auth ; zéro e2e wizard/génération/cockpit |
| FRT-06 | Dialogs/rollback/tailles/localStorage | frontend | Mineure | — | **partiel** — confirm-dialog focus-trap ✔ ; mais `modal.tsx` (DayDialog…) sans focus-trap/restitution, `useLaunchGeneration` toujours sans rollback, TeamsStep 462→547 l., réservations localStorage non revalidées |
| FRT-07 | Suite e2e rouge 2/3 | frontend | Moyenne | confirmé (exécuté) | **ouvert** — même assertion `bonjour` périmée (`auth.spec.ts:15,35`) ; rouge depuis ≥3 jours sans que personne ne le voie |
| DOC-01 | project-map « priority 8 » | doc | Élevée | confirmé | **corrigé** — `project-map.md:78` = priority 7, code conforme |
| DOC-02 | AGENTS.md ≥8 faits faux | doc | Élevée | — | **corrigé** — décomptes bannis (« no counts — they rot »), contenus re-sondés exacts |
| DOC-03 | Doc affirme RLS actif (faux) | doc | Élevée | confirmé | **corrigé** — le RLS est désormais réellement actif (cf. SEC-03) ; la doc dit vrai |
| DOC-04 | Inventaires courantes périmés | doc | Moyenne | — | **partiel** — remis à jour le 03/07 puis re-périmés (zéro mention CalendarEntry/holidays malgré PRs #52-#63) ; graduation roadmap ✔ tenue |
| DOC-05 | Contradiction testing-strategy | doc | Mineure | — | **corrigé** — « skipped » disparu ; section stub TENANT.md supprimée |
| DEP-01 | pytest CVE-2025-71176 | deps | Mineure | confirmé (ré-exécuté) | **ouvert** — pytest 8.4.2, fix 9.0.3 (dev-dep) |
| ENG-09 → voir ci-dessus | | | | | |
| UX-01 | 404 brute React Router | frontend | Moyenne | confirmé | **corrigé** — catch-all `router.tsx:34` (`path:"*"` → Navigate /) |
| RGPD-01 | Purge/rétention/audit-trail absents | transverse | Élevée | confirmé (grep) | **ouvert** — rien d'implémenté ; `UserResource.php:21` reconnaît l'effacement GDPR à faire |
| PERF-01 | Pas de gate perf | engine | Moyenne | confirmé (vérifié à la main) | **corrigé** — `tests/perf/test_perf_dense.py` (dense 41 équipes < 180 s) + job CI `engine-perf` (`ci.yml:142-161`) |
| INF-01 | Observabilité zéro (Sentry absent) | infra | Élevée | confirmé (grep ré-exécuté) | **ouvert** |
| INF-02 | Backups PostgreSQL inexistants | infra | Élevée | confirmé (ré-exécuté) | **ouvert** — toujours zéro pg_dump/script/volume |
| INF-03 | Limites RAM absentes | infra | Mineure | confirmé (0 `mem_limit`) | **ouvert** — healthchecks 11 ✔, limites 0 |

**Bilan** : 6 critiques → 0. 22 corrigés · 7 partiels · 10 ouverts · (SEC-06 réfuté éd. préc.).

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| ENG-10 | **PREFERRED DAY = placebo silencieux** : le wizard n'émet que `forbiddenDays` quel que soit le ruleType (`ConstraintsStep.tsx:173`, défaut PREFERRED `:128`) ; l'engine ne lit le soft DAY que via `preferredDays` (`objective.py:356`) et filtre les non-HARD/LOCK (`constraints.py:847`) → « Préféré · pas le mardi » totalement ignoré, sans warning. Même motif qu'ENG-01 | engine | Élevée | **confirmé (contre-vérifié à la main)** | nouveau |
| ENG-11 | **FACILITY `forbiddenVenueId` non-HARD escaladé en interdiction dure** : la branche parse ne teste pas `rule_type` (`constraints.py:1658-1661`) contrairement aux branches preferred/forced → « Préféré · éviter Gymnase X » devient un HARD, INFEASIBLE possible là où l'utilisateur exprimait une préférence | engine | Élevée | **confirmé (contre-vérifié à la main)** | nouveau |
| ENG-12 | ruleType **BONUS sélectionnable dans l'UI mais mort de bout en bout** (TIME/DAY/FACILITY) — placebo silencieux ; idem LOCK+FACILITY preferredVenueId | engine | Moyenne | non vérifié (mécanique corroborée par ENG-10/11) | nouveau |
| ENG-13 | **Multi-contraintes coach : la 2e écrase la 1re** — `result["coach_unavailability"][coach] = unavailable` (affectation, pas union, `constraints.py:1629`) → « indispo lundi » puis « indispo mercredi » = seul mercredi tenu | engine | Moyenne | **confirmé (code lu)** | nouveau |
| ENG-14 | Version de contrat jamais vérifiée à l'entrée engine (`input_schema.py:129` accepte tout) | engine | Mineure | non vérifié | nouveau |
| ENG-15 | Code mort `add_venue_closure_constraints`/`_rule_matches` (plus aucun producteur) — rouvre la porte au motif ENG-02 | engine | Mineure | non vérifié | nouveau |
| SEC-07 | **Endpoints cockpit sans check de rôle** : `isManagementRole` exigé sur Import/ResetSeason/Club PUT mais absent de validate/reopen/set-baseline/manual-edit/generate/reorder/appearance (`ValidateScheduleController.php:45-53` : seul le club est comparé). Impact réel limité aujourd'hui (tous les membres sont `admin`) mais ouvre tout le cockpit au futur rôle coach | backend | Moyenne | **confirmé (contre-vérifié)** | nouveau |
| SEC-08 | `catch (Throwable)` → `getMessage()` brut renvoyé au client (`ManualEditController.php:64-66`) — fuite de détails internes | backend | Mineure | non vérifié | nouveau |
| SEC-09 | `period_reminder_log` global sans RLS alors qu'il référence des `calendar_entry_id` tenant (surface faible, pas d'API) | backend | Mineure | non vérifié | nouveau |
| SEC-10 | Logo de tout club servi en PUBLIC_ACCESS par uuid (`security.yaml:42`) — à confirmer comme choix produit (asset de marque) | backend | Info | non vérifié | nouveau |
| SEC-11 | **Rate limiting absent sur l'API** : burst de 100 GET authentifiés en ~1,4 s, tous 200, aucun throttle (seul register est limité). Confirmé par mesure directe (§8bis) | backend | Moyenne | **confirmé (mesuré)** | nouveau |
| BCK-07 | Contrôles club « souples » (`null !== $currentClubId &&`) sur validate/reopen/set-baseline/reorder/export-pdf : contexte nul ⇒ check sauté, seul RLS protège — robuste aujourd'hui (fail-closed), fragile par construction | backend | Mineure | confirmé | nouveau |
| BCK-08 | `ManualEditController` sans test comportemental (seul le test OpenAPI le cite) — 3 routes touchant un axe structurant sans filet | backend | Moyenne | non vérifié | nouveau |
| FRT-08 | Aucun ErrorBoundary/`errorElement` : une exception de rendu = page brute dev / écran blanc prod (le catch-all URL ne couvre pas ce cas) | frontend | Moyenne | non vérifié | nouveau |
| FRT-09 | `useLaunchGeneration` : create schedule + N templates séquentiels sans cleanup ni idempotence → échec à mi-course = planning fantôme, retry = doublon | frontend | Moyenne | non vérifié | nouveau |
| FRT-10 | Zéro route lazy — cockpit+planning+wizard+dnd-kit dans le bundle initial du /login | frontend | Mineure | non vérifié | nouveau |
| FRT-11 | Suite e2e absente de la CI — rouge 2/3 depuis ≥3 jours sans détection : valeur actuelle négative (fausse confiance) | frontend | Mineure | confirmé (exécuté) | nouveau |
| DOC-06 | **TENANT.md prête à `migration_user` un bypass RLS qu'il n'a pas** (`TENANT.md:50`) : `02-users.sql:32` le crée NOSUPERUSER sans BYPASSRLS, aucune policy ne le vise → default-deny sous FORCE. `RLS.md:14` se contredit. Doc sécurité trompeuse pour un opérateur | doc | Élevée | **confirmé (contre-vérifié à la main)** | nouveau |
| DOC-07 | **project-map.md ignore la vague cockpit** : 0 occurrence CalendarEntry/overlay/holiday ; `:47` liste l'enum Schedule sans `DRAFT` ni `VALIDATED` (réels : `ScheduleStatus.php:9,15`) — le lifecycle VALIDATED est pourtant un axe structurant §7.1 | doc | Élevée | **confirmé (contre-vérifié)** | nouveau |
| DOC-08 | CLAUDE.md §4 : ordre CI inexact — `engine-tests` n'a aucun `needs` (parallèle dès le départ) et le job `engine-perf` n'est documenté nulle part | doc | Moyenne | confirmé (ci.yml lu) | nouveau |
| DOC-09 | « Single pass » (CLAUDE.md §6 + ADR-0001) contredit le solve 2 phases réel (`main.py:350-377`) — ADR jamais amendé | doc | Moyenne | confirmé (indirect) | nouveau |
| DOC-10 | `openapi-snapshot` périmé : meta impose la régénération à chaque changement d'API ; `/api/school-holidays` et `/api/public-holidays` absents (PRs #62/#63) | doc | Moyenne | confirmé (indirect) | nouveau |
| DOC-11 | Comptes « 14 tables RLS » périmés (réel 15 depuis `calendar_entry`) ; deux docs RLS dupliquées qui driftent ensemble | doc | Mineure | non vérifié | nouveau |
| UX-02 | **L'app ouvre sur « Planning vide »** alors qu'un baseline complet existe : après login, le sélecteur pointe par défaut un planning `★ · période` sans aucun créneau (badge « Terminé · score 14224 » d'un autre run) au lieu du baseline plein. Premier écran = message d'échec apparent. Sélection par défaut à corriger (préférer le baseline non-overlay) + trancher l'anomalie d'affichage `★` (baseline) accolé à `· période` (un overlay ne doit jamais être baseline) — artefact de données de test ou bug de libellé du sélecteur | frontend | Moyenne | confirmé (capture 02, parcours live) | nouveau |

Supply chain : `npm audit --omit=dev` → 0 · `composer audit` → 0 · `pip-audit` → 1 (DEP-01). Dependabot/renovate toujours absents.

---

## 1. Documentation — 78/100 (65)

Exactitude 78/100 · structure 80 · utilité IA 85 · cycle specs 62 (pondéré 40/20/25/15).

**Forces.** Les 3 mensonges majeurs de l'édition précédente sont corrigés (priority 7, RLS réellement actif, AGENTS.md assainis) ; 10/13 sondages exacts ; discipline « no counts — they rot » adoptée ; item 12 holidays de backend/AGENTS.md remarquablement précis (vérifié claim par claim) ; roadmap-index tenue avec rigueur (absorption datée, graduation).

**Faiblesses.** Deux inexactitudes de sécurité/architecture subsistent : DOC-06 (bypass `migration_user` fictif — le genre d'erreur qui fait faire une mauvaise manip à un opérateur) et DOC-09 (ADR single-pass jamais amendé alors que le two-phase est livré). Le tier 2 re-périme vite : project-map sans la vague cockpit (DOC-07), inventaires et snapshot OpenAPI décrochés en 3 jours (DOC-04 partiel, DOC-10) — la règle de régénération existe mais n'est pas appliquée.

## 2. Pertinence du besoin — 87/100 (85)

Le besoin reste réel, chiffré, bien cadré (inchangé). Ce qui monte : **la réserve principale de l'édition précédente — « le modèle hebdomadaire pur est une limite produit » — est en cours de résolution effective** : cockpit temporel livré (paliers A/B/C : projection calendrier, périodes closure/holiday avec overlays, rappels cron), vacances scolaires 13 zones + jours fériés importés depuis les API officielles. La « semaine type d'août » devient une saison vivante. Restent les deux verrous business : **bridage freemium spécifié mais non implémenté** (toujours un outil, pas un produit) et transition de saison spécifiée non livrée. Mode démo toujours absent (outil de validation marché).

## 3a. Code backend — 85/100 (60)

Correction+sécurité 34/40 · architecture 21/25 · tests 17/20 · robustesse 13/15.

**Le grand bond de cette édition.** Les 4 critiques/élevées sont réellement closes, chacune avec tests phase1 : Club/User verrouillés fail-closed, **RLS actif** (FORCE + policies + GUC fail-closed + porte admin séparée — contre-vérifié), import-teams contrôlé, messenger ceinturé par 3 filets vérifiés (catch-all → FAILED, failure listener, watchdog cron), lock Lua atomique, `TenantOwnedInterface` + completeness test. `doctrine:schema:validate` en phase. 13 classes de tests Security.

**Reste.** SEC-07 (pas de check de rôle sur le cockpit — bénin tant que tous les membres sont admin, bloquant pour le futur rôle coach), SEC-11 (zéro rate limiting, mesuré), BCK-08 (manual-edit sans test comportemental), BCK-04 résiduel (deps nullable), SEC-08 (fuite `getMessage()`), RGPD-01 (le seul Élevé encore ouvert côté backend).

## 3b. Code engine — 75/100 (55)

Correction+sécurité 29/40 · architecture 19/25 · tests 16/20 · robustesse 11/15.

**La série ENGINE #26–#32 a tenu ses promesses, vérifiées dans le code** : coach_unavailability vivante (matching par jour int, tous coachs, smoke sémantique au format backend réel), solve en `to_thread` + sémaphore (health répond pendant un solve — testé), faux diagnostics éliminés (dédup + capacité réelle, testé), pipelines de test convergés sur `build_schedule` prod, ruff réarmé, gate perf en CI.

**Mais le motif « contrainte saisie ≠ contrainte honorée » n'est pas éteint — il s'est déplacé** sur les combinaisons ruleType×config : ENG-10 (PREFERRED DAY placebo, confirmé), ENG-11 (soft « éviter ce gymnase » escaladé en interdiction dure, confirmé — violation §7.1 dans l'autre sens : INFEASIBLE possible sur une simple préférence), ENG-12 (BONUS mort partout), ENG-13 (2e contrainte coach écrase la 1re, confirmé). Aucun de ces cas n'a de warning ni de test. La couche assignment reste en `Any` généralisé (ENG-05 partiel) — la même cause racine qui avait produit ENG-01/02.

## 3c. Code frontend — 69/100 (68)

Correction+sécurité 31/40 · architecture 17/25 · tests 11/20 · robustesse 10/15. `tsc` exit 0 · vitest **110/110** · e2e **1/3**.

**Progrès réels** : filet d'erreurs global (Mutation/QueryCache → toasts, anti-doublon, 401 centralisé) — le P0 UX de l'édition précédente est fait ; catch-all URL (UX-01 corrigé) ; confirm-dialog avec focus-trap ; cockpit complet et testé (5 fichiers de tests) ; loading géré dans la validation d'étapes ; 0 `any` ; i18n FR propre.

**Dette stable** : types.gen.ts toujours mort + duplication de types inter-features (aucune garantie de contrat côté front), pas de Mercure (polling), e2e sinistrée (3 tests dont 2 rouges périmés, hors CI — FRT-11 : fausse confiance), PlanningPage confond erreur et vide (FRT-02 partiel), `modal.tsx` sans focus-trap, `useLaunchGeneration` sans rollback (FRT-09 : plannings fantômes), zéro lazy route.

## 4. Supply chain — 90/100 (90)

npm 0 · composer 0 · pip 1 (DEP-01 : pytest 8.4.2 → 9.0.3, dev-dep). Toujours pas de scan automatisé (dependabot/renovate absents) → le -10 demeure.

## 5. Infra / Mercure — partiel (non noté)

- **SEC-05 corrigé** : hub durci (secret dédié, plus d'anonymous, CORS restreint) — contre-vérifié dans le compose. Ports tous bindés 127.0.0.1. 11 healthchecks.
- Toujours : aucune config prod distincte, 0 limite RAM (INF-03), pas de rotation de logs.

## 6. RGPD — partiel (non noté)

RGPD-01 ouvert : rien d'implémenté (pas de purge, pas de rétention, pas d'audit trail, pas d'effacement de compte — reconnu dans le code `UserResource.php:21`). Périmètre inchangé et borné : emails/téléphones coachs + comptes users. Jalon pré-commercialisation.

## 7. Prod-readiness / observabilité — partiel (non noté)

INF-01 ouvert (zéro Sentry/monitoring/alerting — grep ré-exécuté à vide), INF-02 ouvert (**zéro backup PostgreSQL — toujours le premier chantier infra avant tout club pilote**), INF-03 ouvert (0 `mem_limit`). Inchangé depuis l'édition précédente : ces trois-là n'ont pas bougé pendant que 14 findings de code étaient corrigés — le déséquilibre code/infra se creuse.

## 8. Performance — 90/100 (80)

- **Mesure** : `smoke-solver.sh` chronométré → **13,1 s** bout-en-bout (create→generate→poll COMPLETED, score 14224), fixture BCCL 49 équipes · 9 salles. Édition précédente : 19,6 s. Critère MVP « < 3 min » tenu avec marge ×13.
- **PERF-01 corrigé** : gate `test_perf_dense.py` (dense 41 équipes < 180 s, statut completed) exécuté en CI (`engine-perf`, sur main). La perf est désormais protégée par un garde-fou automatisé, plus seulement par une mesure d'audit.
- -10 : un seul point de gate (dense), pas de tendance historisée, et le job ne tourne que sur main.

## 8bis. Charge API — ouvert cette édition (partiel, non noté)

Premier smoke de charge du registre : **100 GET `/api/teams` authentifiés, 20 concurrents → 100× HTTP 200, p50 227 ms, p95 268 ms, max 278 ms** (stack dev WSL2, 1 club). Aucun effondrement ; latences saines. Double enseignement : (1) la stack tient une rafale modeste ; (2) **aucun throttle ne s'est déclenché → SEC-11 confirmé par mesure**. Limites : 1 endpoint read, 1 club, pas de writes concurrents ni multi-tenant — un vrai test de charge (N clubs, mix lecture/écriture/génération) reste à faire.

## 9. UX réelle — 72/100 (62)

Parcours Playwright scripté exécuté (chromium du repo, viewport 1440 puis 1024) : login fixture → planning → wizard → URL inconnue → 1024px. 7 captures, console surveillée : **0 erreur console sur tout le parcours**.

### Ce qui a progressé (constaté à l'écran)
- **Le mur de fausses erreurs a disparu** (ENG-09 corrigé) — le tueur de confiance n°1 de l'édition précédente est mort.
- **URL inconnue → redirection /planning propre** (UX-01 corrigé, vérifié en live : `/nimporte-quoi` ne montre plus la page développeur).
- **Wizard de très bon niveau** : aide contextuelle en tête d'étape, et le **micro-copy Rang vs Niveau de jeu est livré** (« il décrit la compétition, pas la priorité de placement » — exactement le fix demandé par l'audit UX du 04/07), regroupement par tier S/A/B, sticky footer Précédent/Suivant, bouton Trier, plein écran.
- Login sobre et propre, dark theme cohérent, 100 % FR.

### Ce qui casse encore (findings)
- **UX-02 (nouveau)** : premier écran après login = **« Planning vide — ce planning ne contient aucun créneau placé »**, alors que le club a un baseline complet (score 14224). Le sélecteur par défaut pointe un planning `★ · période` vide. Pour un gestionnaire, l'app « a perdu son planning ». Sélection par défaut à corriger + anomalie `★`+`· période` à trancher.
- **Cockpit non visitable sur la fixture** : la gate socle (`socleValidatedAt` null → force le baseline) fonctionne comme spécifié, mais conséquence d'audit : **le cockpit — la plus grosse livraison produit depuis l'édition précédente — n'a jamais été vu par un audit**. Valider le socle aurait muté l'état (refusé en audit). À la prochaine édition : fixture avec socle validé.
- FRT-07 : e2e toujours rouge 2/3 (exécutée).

### Note 72 — justification
Les deux défauts qui plombaient l'édition précédente (mur d'erreurs fictives, 404 développeur) sont corrigés et re-vérifiés à l'écran, le wizard est au niveau d'un produit commercialisable ; reste un premier contact trompeur (UX-02), un cockpit jamais audité et une e2e rouge — pas encore le niveau « prod sereine ».

---

## Avis global

**L'édition du redressement.** En 3 jours : 6 critiques → 0, 22 findings corrigés sur 40, chaque correction vérifiée dans le code avec son test. Le pattern transversal de l'édition précédente — « l'écart entre le déclaré et l'effectif » — a été attaqué de front là où il était le plus dangereux (RLS fictif → réel et fail-closed ; contraintes mortes → vivantes et smoke-testées ; doc mensongère → 10/13 sondages exacts). La discipline de correction (chaque fix accompagné d'un test phase1 ou sémantique qui aurait attrapé le bug) est exactement la bonne réponse au diagnostic précédent.

**Ce qui empêche encore de parler de prod.** Trois blocs stables :
1. **Le motif sémantique renaît en périphérie** (ENG-10/11/12/13) : la combinatoire ruleType×famille×config offerte par l'UI dépasse ce que l'engine honore, toujours sans warning. Tant qu'une matrice exhaustive UI↔engine n'existe pas (avec un smoke par case), chaque nouvelle option UI recrée le bug ENG-01 en miniature.
2. **L'infra opérationnelle n'a pas bougé d'un pouce** : backups zéro, observabilité zéro, RGPD zéro, config prod inexistante. 14 findings de code corrigés, 0 finding d'infra — le déséquilibre se creuse et ces chantiers ne se paralléliseront pas d'eux-mêmes.
3. **L'e2e reste un angle mort actif** : 2/3 rouge depuis 3 jours, hors CI, aucun parcours réel couvert — le seul filet qui simule un utilisateur n'existe pas.

### Axes d'amélioration priorisés

**P0 — fiabilité de la promesse produit**
1. Matrice contrainte UI↔engine : pour chaque (famille × ruleType × config) sélectionnable dans le wizard, soit l'engine l'honore (avec smoke sémantique), soit l'UI ne la propose pas, soit un warning explicite est émis. Corrige ENG-10/11/12/13 d'un coup et empêche les récidives. [ENG-10..13]
2. Réparer + câbler l'e2e en CI (assertion périmée = 10 min ; le parcours wizard→génération→cockpit = le vrai chantier). [FRT-07, FRT-11, FRT-05]
3. Corriger TENANT.md (`migration_user`) et amender l'ADR-0001 — doc sécurité/architecture d'abord. [DOC-06, DOC-09]

**P1 — opérationnel avant club pilote**
4. Backups PostgreSQL automatisés + restore testé (ouvre l'axe « restauration » du tableau de couverture). [INF-02]
5. Sentry backend+engine. [INF-01]
6. Rate limiting API (le RateLimiter est déjà là pour register — répliquer). [SEC-11]
7. Check de rôle management sur validate/reopen/set-baseline/manual-edit/generate — avant d'introduire le rôle coach. [SEC-07]
8. Tests comportementaux manual-edit. [BCK-08]
9. ErrorBoundary + état d'erreur PlanningPage. [FRT-08, FRT-02]

**P2 — dette maîtrisée**
10. Typage couche assignment engine (dataclass Assignment) — la cause racine du motif sémantique. [ENG-05]
11. Brancher types.gen.ts ou le supprimer ; dédupliquer les types front. [FRT-03]
12. Rollback/idempotence useLaunchGeneration. [FRT-09]
13. RGPD : purge + effacement de compte + audit trail. [RGPD-01]
14. Mercure côté front (ou assumer le polling et le documenter). [FRT-04]
15. project-map + inventaires : passe de fraîcheur post-cockpit + stamp de date. [DOC-07, DOC-04, DOC-10]
16. Union (pas affectation) des contraintes coach multiples. [ENG-13 — inclus dans P0.1]
17. Dependabot. Lazy routes. Limites RAM compose. [—, FRT-10, INF-03]

### Conseil de méthode

Le smoke sémantique (né du conseil de l'édition précédente) a fait ses preuves — ENG-01/02/09 sont morts et enterrés avec tests. L'étape suivante logique : **le générer depuis la matrice** plutôt que le rédiger cas par cas. Un test paramétré qui itère sur toutes les combinaisons (famille × ruleType × config-clé) offertes par l'UI et vérifie « honorée / rejetée explicitement / warning » aurait attrapé ENG-10, 11, 12 et 13 avant cette édition.

---

## Fonctionnalités intéressantes à développer

(Ratio valeur/effort, état réel au 2026-07-06.)

1. **Bridage freemium Découverte** — spécifié (`bridage-freemium-decouverte.md`), enforcement = 3 gardes, 🟡. Le verrou de conversion : sans lui, pas de SaaS. **Premier candidat post-P0.**
2. **Mode démo** (club fictif, génération 30 s) — levier de vente, effort 🟡, toujours pas livré.
3. **Transition de saison P1** — spécifiée (`transition-de-saison.md`), nécessaire dès la 2e saison d'un club pilote. 🔴 mais phasée.
4. **Plan de vacances éditable + collecte coach P1** — spécifié (`plan-vacances-collecte-coach.md`), zéro engine, différenciateur terrain. 🟡.
5. **Rendu cockpit des jours fériés** — backend livré cette semaine (PR #63), la pastille frontend est le dernier km. 🟢.
6. **Import FFBB équipes** — endpoint backend existant, brancher l'UI wizard. 🟢, supprime la moitié de la saisie.
7. **Grille de réservation / boucle d'ajustement** — même primitive (grille interactive + créneaux vides), à spécifier ensemble. 🔴, différé assumé.

À ne pas faire maintenant : app mobile, multi-sport, connecteurs mairie (inchangé).

---

## Annexe — méthodologie et fiabilité

- 4 agents parallèles (doc/backend/engine/frontend) ; frontend : `tsc`, `vitest run` (110 verts), `npx playwright test` (1/3) exécutés ; backend : `doctrine:schema:validate` exécuté ; supply chain : npm/composer/pip-audit exécutés dans les conteneurs.
- **Contre-vérifiés à la main cette édition** : ENG-10 (chaîne wizard→objective/constraints lue), ENG-11 (branche parse lue), ENG-13 (affectation lue), DOC-06 (TENANT.md vs 02-users.sql), DOC-07 (grep + enum), SEC-03-corrigé (policy FORCE lue dans la migration), SEC-05-corrigé (compose lu), SEC-07 (ValidateScheduleController lu), SEC-11 (mesuré par burst), PERF-01-corrigé (test + job CI lus), DEP-01 (pip-audit ré-exécuté), INF-01/02/03 (greps ré-exécutés).
- Les findings `non vérifié` proviennent des agents ; tous les critiques/élevés nouveaux ont été contre-vérifiés (aucun ne reste non vérifié).
- Perf : smoke chronométré 13,1 s (49 équipes, COMPLETED 14224). Charge : burst 100×GET auth, p95 268 ms, 0 erreur.
- UX : parcours Playwright scripté (chromium repo, script jetable hors dépôt), 7 captures (login, planning, wizard, URL inconnue, 1024px), console surveillée (0 erreur). Nouveau finding UX-02 confirmé par capture. Cockpit non visité (gate socle — mutation d'état refusée en audit).
- Limites : cockpit jamais vu par un audit (fixture au socle non validé — à préparer pour la prochaine édition) ; charge = smoke read-only mono-club ; RGPD/infra = constat statique.
- Angles morts déclarés : restauration backup réelle (bloqué INF-02), migration de données N→N+1, **comportement offline/latence frontend (nouveau candidat cette édition)**.

# Audit ClubScheduler — édition 2026-07-08

| Méta | Valeur |
|---|---|
| Date | 2026-07-08 |
| Modèle | `claude-opus-4-8` (Claude Opus 4.8, Anthropic) |
| HEAD | `b00c81b` (branche `docs/audit-ux-coherence-simplicity` = code de `main` `5e4a98e` + skill audit ; aucun code applicatif ne diffère de main) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, **UX**) + checks directs (supply chain, Mercure, secrets, prod-readiness, RGPD) + smoke-solver chronométré + vérification contradictoire manuelle des findings élevés/critiques |
| Édition précédente | `AUDIT-2026-07-06-claude-fable-5.md` (HEAD `dc26bc3`) — PRs #119-#123 depuis (fixtures BCCL, édition contraintes wizard + modes imposé/uniquement, readonly/récap organisés, calendrier vacances/indispo, skill audit) |

> **Nouveauté de cette édition** : 3 axes UX additifs (Cohérence · Simplicité-Intuitivité · Inclusivité-a11y), notés **à part** du `/100` des briques, **sévérité extrême**, score général = le plus bas des sous-axes. Les éditions passées n'avaient pas ces axes → baseline `non couvert`, pas de rétro-notation.

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + ~11 sondages code |
| Besoin produit | ✅ couvert | roadmap + livré vs specs |
| Code backend | ✅ couvert | statique, RLS/policies relus dans les migrations |
| Code engine | ✅ couvert | statique, chaînes de contraintes suivies bout en bout |
| Code frontend | ✅ couvert | statique + `tsc` (exit 0) + `vitest` (253 verts) + `playwright --list` (9 specs) |
| Supply chain | ✅ couvert | npm/composer/pip-audit exécutés (0 vuln) |
| Infra / Mercure | 🟡 partiel | compose lu (Mercure durci) ; config prod toujours inexistante |
| Prod-readiness / observabilité | 🟡 partiel | constat statique (Sentry, backups, limites) |
| RGPD | 🟡 partiel | constat statique |
| Performance mesurée | ✅ couvert | smoke-solver ~14 s + gate perf CI |
| **UX-Cohérence** (NOUVEAU) | ✅ couvert | statique (inventaire primitives, comptes one-off/hex/hors-palette/terminologie) |
| **UX-Simplicité & Intuitivité** (NOUVEAU) | 🟡 partiel | proxys statiques couverts ; parcours navigateur **non exécuté** (conteneur front figé, MCP chrome indispo) |
| **Inclusivité / a11y** (NOUVEAU) | 🟡 partiel | statique (couleur-seule, aria, focus, contraste) ; audit contraste dynamique non exécuté |
| Restauration après corruption (backup/restore réel) | ❌ non couvert | toujours bloqué par INF-02 (aucun backup) |
| Migration de données entre versions | ❌ non couvert | jamais ouvert |
| Comportement offline / latence réseau front | ❌ non couvert | candidat de l'éd. préc., toujours pas regardé (FRT-14 effleure le sujet) |

> **Posture** : barre = **application commercialisable** (cible mi-2027). Sévérité assumée ; « ça tourne » n'est jamais un signal de réussite. La trajectoire se lit dans le registre.

---

## Synthèse des notes

| Critère | 2026-07-06 | **2026-07-08** |
|---|---|---|
| 1. Documentation | 78 | **84** |
| 2. Pertinence du besoin | 87 | **88** |
| 3a. Code backend | 85 | **86** |
| 3b. Code engine | 75 | **78** |
| 3c. Code frontend | 69 | **71** |
| 4. Supply chain | 90 | **92** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 77 | **78** |

Pondération globale (inchangée) : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %. Le slot **UX** du global (7,5 %) est cette édition alimenté par le **Score UX général = 50** (nouvel axe, cf. ci-dessous) qui remplace l'ex-« UX navigateur » — évolution de méthode documentée en annexe. Calcul = 84·.10 + 88·.10 + 86·.25 + 78·.20 + 71·.15 + 92·.05 + 90·.075 + 50·.075 = **80,0**, **−2 pts** de malus transversal (INF-01/02 observabilité+backups, RGPD-01, ENG-16 sur axe structurant) → **78**.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | Note /100 | Plafond appliqué |
|---|---|---|
| UX-Cohérence | **55** | 2 findings Élevés confirmés (UXC-02, UXC-05) → plafond 60 |
| UX-Simplicité & Intuitivité | **58** | 1 Élevé confirmé (UXS-02 jargon) → plafond 60 |
| Inclusivité / a11y | **50** | 2 Élevés confirmés (A11Y-01, A11Y-03) → plafond 60 |
| **Score UX général** | **50** | = le PLUS BAS des sous-axes (« robuste en tout point ») |

Baseline : nouvel axe → éditions ≤07-06 marquées `non couvert`, aucune rétro-notation.

**Lecture rapide.** Édition de consolidation profonde côté moteur et sécurité : **les 6 findings sémantiques « déclaré ≠ effectif » de l'engine (ENG-10/11/12/13/14/15) sont tous corrigés et vérifiés au code** (matrice UI↔engine, avoided_venue=-60, coach union/intersection, garde-fou CONTRACT_VERSION, code mort supprimé), la surface d'écriture cockpit est verrouillée par rôle (SEC-07), le rate-limiting API est vivant (SEC-11), l'e2e est enfin dans la CI (FRT-05/07/11), la doc sécurité/archi ne ment plus (DOC-06→11). **Mais le motif « déclaré ≠ effectif » ressurgit — cette fois de mon propre fait (#120) : ENG-16, `forcedDays` « uniquement » n'interdit pas les autres jours** pour une équipe multi-séances, et la matrice ne le voit pas car elle ne teste que `sessionsPerWeek=1`. Le nouvel axe UX, noté sévèrement, expose une dette réelle : information de gymnase portée par la **seule couleur** dans les cellules de grille (daltonisme), `modal.tsx` sans focus-trap sur toutes les modales cockpit, 28 classes de couleur hors-palette, ~14 états-vides ré-inventés. Les impasses structurelles restent stables et connues : observabilité zéro, backups zéro, RGPD zéro, config prod inexistante.

## Barème (inchangé)

| Tranche | Signification |
|---|---|
| 90–100 | Exemplaire, production commerciale |
| 75–89 | Solide, prod envisageable en l'état |
| 60–74 | Bon socle, ≥1 chantier significatif |
| 40–59 | Fragile, ≥1 défaut critique vérifié |
| 20–39 | Défaillant, refonte partielle |
| 0–19 | Non fonctionnel ou dangereux |

Critique confirmé ⇒ plafond brique 60. Axes UX : plafonds durcis (moyen→75 / élevé→60 / critique→40), général = min.

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| ENG-05 | `Any` cœur engine | engine | Élevée | confirmé | **corrigé** — `AssignmentVariable` dataclass typé (`constraints.py:66-96`), `_normalise_assignments` ; `Any` résiduels = types ortools non publics, documentés |
| ENG-06 | Zéro exception handler global | engine | Moyenne | confirmé | **corrigé** — `main.py:45-58` `@app.exception_handler(Exception)` log+500 propre |
| ENG-10 | PREFERRED DAY placebo | engine | Élevée | confirmé | **corrigé** — `objective.py:350-433` bonus sur complément de `forbiddenDays`, agrégé par équipe |
| ENG-11 | forbiddenVenueId soft escaladé dur | engine | Élevée | confirmé | **corrigé** — `constraints.py:1720-1740` PREFERRED→`avoided_venues` (malus −60), HARD→forbidden |
| ENG-12 | BONUS mort | engine | Moyenne | non vérifié | **corrigé** — BONUS normalisé PREFERRED (`constraints.py:1614-1616`) ; LOCK FACILITY forcé dur |
| ENG-13 | Multi-contraintes coach : 2e écrase 1re | engine | Moyenne | confirmé | **corrigé** — UNION blacklists / INTERSECTION whitelists (`constraints.py:1662-1689`), test dédié |
| ENG-14 | Contrat jamais vérifié à l'entrée | engine | Mineure | non vérifié | **corrigé (portée route)** — `/generate` rejette MAJOR≠2.0 (`main.py:463-468`) ; jamais couvert par les tests (voir ENG-19) |
| ENG-15 | Code mort venue_closure | engine | Mineure | non vérifié | **corrigé** — définitions supprimées |
| SEC-07 | Endpoints cockpit sans rôle | backend | Moyenne | confirmé | **corrigé** — `assertManager()` sur validate/reopen/set-baseline/generate/manual-edit/logo + processors ; `ManagementRoleTest` blocking |
| SEC-08 | getMessage() brut au client | backend | Mineure | non vérifié | **partiel** — neutralisé sur 3 catch ; **résidu** `ManualEditController.php:154` (409 InvalidArgumentException non-ORM) |
| SEC-09 | period_reminder_log sans RLS | backend | Mineure | non vérifié | **corrigé (design)** — ledger global sans données tenant, UNIQUE idempotence, zéro API |
| SEC-10 | Logo public par uuid | backend | Info | non vérifié | **accepté (design)** — GET public, POST/DELETE manager, uuid non-devinable |
| SEC-11 | Rate limiting absent | backend | Moyenne | mesuré | **corrigé** — `ApiRateLimitSubscriber.php:57` 300/min/user, `ApiRateLimitTest` blocking |
| BCK-04 | Handler/deps nullable | backend | Moyenne | — | **partiel** — handler décomposé ; `ScheduleConstraintBuilder` 776 l., 4 deps nullable documentées |
| BCK-07 | Contrôles club « souples » | backend | Mineure | confirmé | **ouvert (mitigé RLS)** — `AbstractStateProcessor.php:163,188` check sauté si `$clubId` null |
| BCK-08 | ManualEdit sans test comportemental | backend | Moyenne | non vérifié | **corrigé** — `Integration/Api/ManualEditBehaviorTest.php` |
| FRT-02 | Query error avalée → vide trompeur | frontend | Élevée | — | **partiel** — toast global présent ; `PlanningPage.tsx:94` toujours `data=[]` sans `isError`/retry |
| FRT-03 | types.gen.ts mort + types dupliqués | frontend | Moyenne | — | **ouvert** — 6879 l. 0 import ; Team/Venue/Coach/ScheduleStatus dupliqués planning↔wizard (voir FRT-15) |
| FRT-04 | Pas de Mercure (polling) | frontend | Moyenne | confirmé | **ouvert** — 0 EventSource ; polling 2,5 s |
| FRT-05 | E2E quasi nulle | frontend | Moyenne | — | **corrigé** — 9 specs/4 fichiers |
| FRT-06 | modal focus-trap/rollback/localStorage | frontend | Mineure | — | **ouvert (partiel)** — `modal.tsx` sans focus-trap (5 dialogues) ; localStorage réservations ; voir FRT-12/13, A11Y-03 |
| FRT-07 | E2E rouge 2/3 | frontend | Moyenne | confirmé | **corrigé** — assertion « Étape 1/6 » valide (`auth.spec.ts:18`) |
| FRT-11 | E2E absente de la CI | frontend | Mineure | confirmé | **corrigé** — job `e2e` Playwright + job `frontend` en CI |
| DOC-04 | Inventaires courantes périmés | doc | Moyenne | — | **partiel** — backend/engine/openapi à jour ; `frontend-wizard.md` en retard (pas d'édition contraintes #120) |
| DOC-06 | migration_user bypass RLS fictif | doc | Élevée | confirmé | **corrigé** — `TENANT.md:60` dit « NOSUPERUSER without BYPASSRLS … default-deny », exact vs `02-users.sql` |
| DOC-07 | project-map ignore cockpit | doc | Élevée | confirmé | **corrigé** — enum Schedule 6 cases exact, CalendarEntry/overlay/holiday décrits |
| DOC-08 | CLAUDE §4 ordre CI inexact | doc | Moyenne | confirmé | **corrigé** — engine-tests sans needs, engine-perf documenté (résiduel DOC-12) |
| DOC-09 | ADR single-pass vs 2 phases | doc | Moyenne | confirmé | **corrigé** — ADR-0001 amendé 2026-07-07 |
| DOC-10 | openapi-snapshot périmé | doc | Moyenne | confirmé | **corrigé** — school/public-holidays présents, stamp @2026-07-08 |
| DOC-11 | Comptes RLS périmés | doc | Mineure | non vérifié | **corrigé** — décomptes bannis, renvois mutuels |
| DEP-01 | pytest CVE-2025-71176 | deps | Mineure | confirmé | **corrigé** — pip-audit → 0 vuln connue |
| RGPD-01 | Purge/rétention/audit-trail | transverse | Élevée | confirmé | **ouvert** — rien (SeasonDataPurger = données saison, pas effacement compte GDPR) ; `UserResource.php:21` le reconnaît |
| INF-01 | Observabilité zéro (Sentry) | infra | Élevée | confirmé | **ouvert** |
| INF-02 | Backups PostgreSQL absents | infra | Élevée | confirmé | **ouvert** |
| INF-03 | Limites RAM absentes | infra | Mineure | confirmé | **ouvert** — 0 `mem_limit` ; 11 healthchecks |
| UX-02 | App ouvre sur « planning vide » | frontend | Moyenne | confirmé | **non re-testé** (parcours navigateur non exécuté cette édition) — repris sous couverture partielle |

**Bilan reprise** : 17 corrigés · 5 partiels · 6 ouverts (RGPD-01, INF-01/02/03, FRT-03/04, BCK-07) · 3 acceptés/design (SEC-09/10). Aucune régression sur un finding corrigé.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| ENG-16 | **`forcedDays` HARD (« uniquement ces jours ») n'interdit PAS les autres jours** : le parseur pose seulement `sum(vars jours forcés) ≥ 1` (`constraints.py:930-936`) = « au moins une séance ces jours-là ». Pour une équipe multi-séances, une règle DURE « uniquement » est silencieusement violée. La matrice `constraint_matrix.py` (cellule HONORED_HARD ajoutée en #120) n'est verte que parce que `test_constraint_matrix` n'exerce que `sessionsPerWeek=1`. **Régression sémantique introduite en #120.** | engine | **Élevée** | **confirmé (contre-vérifié à la main)** | nouveau |
| ENG-17 | `coachId` des créneaux générés vient **uniquement des slotTemplates** (`result_builder.py:171,863-871`) → les coachs définis par contrainte TEAM_COACH sortent `coachId=None` ; diagnostics coach_overload/double-booking **inertes** pour eux (la contrainte est appliquée, mais invisible en sortie) | engine | Moyenne | confirmé code-lu | nouveau |
| ENG-18 | **MIN_SESSIONS dur / « minimum garanti » de tier = no-op** : `main.py:293-297` passe `adjusted_min_by_team=0` partout → `constraints.py:970` `if minimum≤0: continue` → 0 contrainte dure. Le docstring (`constraints.py:6-8`) et le diagnostic ERROR « minimum garanti » (`result_builder.py:474`) sont trompeurs (le choix soft-only est défendable, le wording non) | engine | Moyenne | confirmé code-lu | nouveau |
| ENG-19 | Test `forcedDays` fragile encodant un comportement non garanti (`test_constraint_fixes.py:101-119`) : l'assertion ne tient que par le tie-break déterministe, et **contredit** la sémantique de la matrice (ENG-16) | engine | Mineure | confirmé code-lu | nouveau |
| ENG-20 | `_day_constraint_conflict_team_ids` ne couvre que `HARD`, pas `LOCK` (`main.py:117`) — incohérence fragile si un min dur est ré-activé | engine | Mineure | confirmé code-lu | nouveau |
| BCK-09 | **PUT réassigne `seasonId` au season du contexte de façon inconditionnelle** (`AbstractStateProcessor.php:167-170`) + `find()` sans `season_filter` → un PUT par id sur une entité d'une autre saison (même club) la **migre silencieusement** vers la saison courante ; seul l'archived-guard (409) protège | backend | Moyenne | confirmé code-lu | nouveau |
| SEC-12 | `/api/constraints/validate` sans `assertManager()` — tout membre actif énumère erreurs/conflits (lecture seule) | backend | Faible | confirmé code-lu | nouveau |
| BCK-10 | `MembershipController::requireActiveAdmin()` résout l'adhésion sans `clubId` (`:95`) → non déterministe en multi-club | backend | Faible | confirmé code-lu | nouveau |
| SEC-13 | `ConstraintInput.config` (`?array` libre) + `scopeTargetId` non validés à la persistance (`ConstraintInput.php:25,39`) — validation sémantique seulement au `validate` explicite | backend | Faible | confirmé code-lu | nouveau |
| FRT-12 | **Trois implémentations de modale divergentes** : `Modal` (sans focus-trap), `ConfirmDialog` (avec), `ValidateDialog` div brut (`PlanningPage.tsx:49-75`) — a11y à géométrie variable | frontend | Moyenne | confirmé code-lu | nouveau |
| FRT-13 | **Aucune restauration du focus au trigger** à la fermeture des modales (WCAG 2.4.3) | frontend | Moyenne | confirmé code-lu | nouveau |
| FRT-14 | Polling (2,5 s / 5 s) non suspendu sur onglet caché — requêtes/batterie gaspillées | frontend | Basse | confirmé code-lu | nouveau |
| FRT-15 | `types.gen.ts` = **38 % du code source, 100 % mort** (6879 l., 0 import) : source de vérité fantôme à côté des types manuels dupliqués (FRT-03) | frontend | Moyenne | confirmé code-lu | nouveau |
| DOC-12 | CLAUDE.md §4 **omet le job CI `frontend`** (tsc+vite build+vitest, gate PR réel) | doc | Moyenne | confirmé code-lu | nouveau |
| DOC-13 | Spec cockpit **livrée mais coincée en `specs/evolution/`** + liens relatifs cassés depuis `courantes/` (viole `specs/README.md`) | doc | Moyenne | confirmé code-lu | nouveau |

### Nouveaux findings — axes UX additifs

| ID | Titre | Gravité | Preuve |
|---|---|---|---|
| UXC-01 | `VenueSwatch` contournée : pastille couleur ré-implémentée inline ×3 + 3 tailles divergentes (`size-2/3/4`) | Moyenne | `VenuesStep.tsx:253`, `WeekendGrid.tsx:73`, `WeekGrid.tsx:84` |
| UXC-02 | **2 modales concurrentes non convergentes** (`modal.tsx` sans focus-trap vs `confirm-dialog.tsx` avec) → a11y à deux vitesses | Élevée | `modal.tsx`, `confirm-dialog.tsx:34-67` |
| UXC-03 | **~14 états-vides ré-inventés** (`<p>Aucun…</p>`) vs 1 `EmptyState` local non partagé (`PlanningPage.tsx:77`) | Moyenne | 14 fichiers listés (VenuesStep/TeamsStep/WeekGrid/…) |
| UXC-04 | 18 valeurs hex en dur / 3 fichiers ; `tint()` dupliquée WeekGrid↔WeekendGrid | Moyenne | `color.ts`, `VenuesStep.tsx`, `WeekGrid.tsx:131`, `WeekendGrid.tsx:102` |
| UXC-05 | **28 classes couleur hors-palette (`amber-*`/`emerald-*`) / 11 fichiers** ; aucun token `warning`/`success` → sémantique statut non thémable | Élevée | 11 fichiers (toaster/PlacementPanel/ConflictRadar/MonthCalendar/…) |
| UXC-06 | Divergence « planning » (64) vs « calendrier » (10) dans l'UI ; sinon terminologie cohérente (gymnase/coach/créneau) | Faible | routes/titres |
| UXS-01 | Flux-clés comptés : wizard 6 étapes, indispo 2 écrans, placement 1 panneau — raisonnables | Info | `steps.ts:10-15` |
| UXS-02 | **Jargon exposé au gestionnaire** : « overlay »+« socle » dans un même libellé (`GenerateStep.tsx:135`), « socle » dans des titres (`BaselineBanner.tsx:113`, `PlanningPage.tsx:314`). Bon point : HARD/PREFERRED traduits (`RULE_LABEL`) | Élevée | ≥4 libellés |
| UXS-03 | Composants > 400 l. à actions concurrentes : `TeamsStep` 551, `ConstraintsStep` 501 | Moyenne | — |
| A11Y-01 | **Gymnase distingué par la SEULE couleur dans les cellules de grille** (nom en `title`/survol seul) sur l'écran planning — daltonien + tactile = gymnase indistinguable en vue équipe/coach | Élevée | `WeekGrid.tsx:118,131-132`, `WeekendGrid.tsx:101-103` |
| A11Y-02 | Statuts couleur partiellement redondants (bon : Diagnostics/ConflictRadar/Placement) ; risque : wash vacances `MonthCalendar.tsx:80` (label seulement si `inMonth`) | Moyenne | — |
| A11Y-03 | **`modal.tsx` sans focus-trap ni restitution** → toutes les modales cockpit (DayDialog, SeasonSchedules, Placement, ImportFbi, FixtureForm) touchées | Élevée | `modal.tsx:16-40` (= FRT-06/12/13) |
| A11Y-04 | Couverture aria-label BONNE : 0 bouton-icône sans label (102 aria-label / 42 boutons bruts) | Info (force) | — |
| A11Y-05 | Emoji porteur d'info avec `title` seul (sans `role="img"`+`aria-label`) : `MonthCalendar.tsx:87,91-93` (le férié voisin `:88` est correct → incohérence interne) | Moyenne | — |
| A11Y-06 | Texte < 12px (9 occ./5 fichiers) + paires contraste à risque (`text-amber-700/300` sur wash, `text-[9px]` sur `bg-accent/20`) | Moyenne | `WeekGrid.tsx:93,137`, `MonthCalendar.tsx:97`, … |

Supply chain : `npm audit --omit=dev` → 0 · `composer audit` → 0 · `pip-audit` → 0. Dependabot/renovate toujours absents.

---

## Détail par critère

### 1. Documentation — 84/100 (78)
**Forces.** Les 6 findings doc de l'édition précédente (DOC-06 bypass RLS fictif, DOC-07 project-map cockpit, DOC-08/09/10/11) sont **tous corrigés et vérifiés au code** — dont les deux plus dangereux (sécurité opérateur + ADR). Sondages CLAUDE.md exacts (blocking-tests, SCORE_FORMULA_VERSION V6, CONTRACT_VERSION 2.0, tiers solveur 60/180/600 mot pour mot). openapi-snapshot et inventaires backend/engine à jour.
**Faiblesses.** Le cycle specs re-décroche sur le front : `frontend-wizard.md` ne documente pas l'édition des contraintes #120 (DOC-04) ; la spec cockpit **livrée** reste dans `evolution/` avec des liens cassés (DOC-13) ; CLAUDE.md §4 omet le job CI `frontend` (DOC-12). Dérive descriptive, sans impact sécurité/contrat.

### 2. Pertinence du besoin — 88/100 (87)
Le produit gagne en complétude utile (édition des contraintes, vues readonly/récap organisées par tier, calendrier vacances/indispo). Adéquation forte au métier FFBB. Viabilité inchangée : dépend toujours des chantiers infra/RGPD non ouverts.

### 3a. Backend — 86/100 (85)
Correction+sécurité 88 · archi 82 · tests 88 · robustesse 82.
**Forces.** RLS **réellement complet** (17 entités FORCE+policy, fail-closed, relu dans les migrations), listener priorité 7 après firewall, rate-limiting vivant (SEC-11), surface d'écriture cockpit verrouillée par rôle (SEC-07), messenger 3 filets, lock Redis CAS atomique, 22/22 DTO validés, invariant CLUB⇒target null testé.
**Faiblesses.** **BCK-09** (réassignation silencieuse de saison au PUT — le seul point d'intégrité à trancher avant GA), résidus SEC-08 (`:154`) et BCK-07 (null-check souple, mitigé par RLS), SEC-12/13/BCK-10 (défenses-en-profondeur mineures). Aucune nouvelle Élevée.

### 3b. Engine — 78/100 (75)
Correction+sécurité 76 · archi 82 · tests 78 · robustesse 84.
**Forces.** **Le cluster sémantique complet est mort** : ENG-10/11/12/13/14/15 corrigés et vérifiés (matrice UI↔engine, malus avoided_venue réel, coach union/intersection, garde-fou contrat, code mort supprimé) ; exception handler global (ENG-06) ; cœur typé (ENG-05) ; solve hors event loop et tests == pipeline prod confirmés.
**Faiblesses.** **ENG-16 rouvre le motif « déclaré ≠ effectif » sur l'axe structurant des contraintes, de mon propre fait (#120)** : `forcedDays` « uniquement » n'interdit pas les autres jours (faux pour toute équipe multi-séances), non vu par une matrice qui ne teste que 1 séance/sem. Plus ENG-18 (« minimum garanti » qui ne garantit rien) et ENG-17 (diagnostics coach inertes pour les coachs par contrainte). Le plafond ne tombe pas (Élevée, pas Critique confirmée), mais la note reste bridée par la récurrence du motif sur l'axe le plus sensible.

### 3c. Frontend — 71/100 (69)
Archi 70 · types 62 · data-fetching 76 · tests 84 · robustesse 66.
**Forces.** `tsc` 0, **253 tests verts**, **e2e enfin dans la CI** (9 specs, jobs `e2e`+`frontend`) — FRT-05/07/11 clos. Filet d'erreurs TanStack global solide, Toaster a11y correct, DnD clavier, 0 TODO/1 any.
**Faiblesses.** Dette structurelle stable : **FRT-15** (`types.gen.ts` = 38 % du code, mort, doublant des types manuels divergents FRT-03), **FRT-09** (schedules fantômes sur échec réseau partiel — pollue les données club), **FRT-08** (aucun ErrorBoundary → écran blanc sur tout throw en render), pas de temps réel Mercure (FRT-04), modales à a11y variable (FRT-12/13), 0 code-splitting (FRT-10).

### 4. Supply chain — 92/100 (90)
0 vuln (npm/composer/pip-audit) ; DEP-01 (pytest CVE) corrigé. Reste : ni Dependabot ni renovate → veille manuelle.

### 5. Performance — 90/100 (90)
Smoke-solver COMPLETED ~14 s ; gate perf CI (`engine-perf` main-only, dense 41 équipes < 180 s). Stable.

### Infra / Prod-readiness / RGPD (partiels, non notés en brique — malus transversal)
**INF-01** Sentry absent · **INF-02** backups PostgreSQL inexistants (bloque toute restauration) · **INF-03** 0 `mem_limit` · **config prod distincte inexistante** · **RGPD-01** aucune purge/rétention/anonymisation/audit-trail de données perso. Ces quatre impasses sont les mêmes depuis 3 éditions — ce sont les vrais bloquants GA, pas le code applicatif.

### UX (axes additifs — détail)
**Cohérence 55.** Deux modales et ~14 états-vides ré-inventés, 28 classes hors-palette sans token statut, `VenueSwatch`/`tint()` contournés : le socle de primitives existe (`shared/components/ui/*`, `StructureSummary`) mais n'est pas systématiquement réutilisé → risque de « se sentir perdu » sur les écrans secondaires.
**Simplicité 58.** Flux-clés courts et lisibles (bon), HARD/PREFERRED traduits, mais le **jargon interne fuit** dans des libellés visibles (« socle », « overlay ») — un gestionnaire lambda ne sait pas ce qu'est un « socle ».
**Inclusivité 50.** Deux Élevées : **information de gymnase portée par la seule couleur** dans les cellules de grille (daltonien/tactile perdus sans survol) et **modales sans focus-trap** (clavier/lecteur d'écran piégés). Bon socle par ailleurs (aria-label complet, DnD clavier, rôles alert/status). Le score général UX = **50** = ce maillon le plus faible.

---

## Avis global + axes priorisés

**P0 (bloquant GA, indépendant du code).**
1. INF-02 backups + restauration testée · INF-01 observabilité (Sentry) · RGPD-01 purge/rétention/audit-trail · config prod distincte.

**P1 (correction ciblée, fort ratio).**
2. **ENG-16** — faire de `forcedDays` un vrai « uniquement » (interdire les jours non-forcés : `var==0` sur le complément) OU renommer le mode UI/matrice en « au moins un ces jours » et corriger la cellule matrice + tester `sessionsPerWeek>1`.
3. **BCK-09** — ne réassigner `seasonId` qu'à la création, jamais au PUT ; charger l'entité avec le `season_filter`.
4. **A11Y-01 / A11Y-03 / UXC-02** — nom de gymnase textuel dans les cellules (pas que la couleur) ; unifier une seule modale avec focus-trap + restitution.
5. **FRT-09** — rendre `useLaunchGeneration` idempotent + cleanup sur échec (schedules fantômes).

**P2 (dette / cohérence).**
6. FRT-15 (trancher types.gen.ts : brancher ou supprimer) · FRT-08 (ErrorBoundary) · UXC-05 (tokens `warning`/`success`) · UXC-03 (`EmptyState` partagé) · ENG-18 (wording « garanti ») · DOC-13/DOC-04/DOC-12.

## Features intéressantes à développer (valeur/effort)
- **Temps réel Mercure côté front** (FRT-04) : le backend est déjà durci/testé ; brancher un `EventSource` supprime le polling et la batterie gaspillée (FRT-14). Ratio élevé.
- **Token de statut sémantique** (`warning`/`success`) + composant `EmptyState`/`Modal` uniques : paye la dette UXC-02/03/05 d'un coup et fige la cohérence.
- **Export/backup club** (aligné INF-02) : un `pg_dump` planifié + restauration testée débloque le P0 le plus lourd.

## Annexe méthodologie
- **Exécuté** : `tsc` (0), `vitest` (253/253), `playwright --list` (9 specs), `npm/composer/pip-audit` (0), smoke-solver chronométré (~14 s), lecture des migrations RLS/policies, contre-vérification manuelle d'**ENG-16** (`constraints.py:930-936`) et **BCK-09** (`AbstractStateProcessor.php:167-170`).
- **Statique uniquement** : infra/prod-readiness/RGPD (constat) ; **axes UX** (proxys statiques : inventaires, comptes one-off/hex/hors-palette/aria — le **parcours navigateur n'a pas été exécuté** : conteneur front figé + MCP chrome indisponible → sous-axes Simplicité/Inclusivité marqués `partiel`).
- **Findings contre-vérifiés à la main** : ENG-16 (confirmé), BCK-09 (confirmé). Les autres nouveaux sont `confirmé code-lu` par les agents (lignes citées) ; aucun finding critique non vérifié n'est publié.
- **Limites** : pas de test de charge, pas de restauration réelle (INF-02), pas d'audit contraste dynamique, pas de re-test du parcours UX-02 de l'édition précédente.
- **Évolution de méthode** : l'ex-brique « UX navigateur » (7,5 % du global) est remplacée par le **Score UX général** (nouvel axe additif, noté à part et sévèrement) ; les 6 briques historiques gardent leur pondération (comparabilité préservée), les 3 sous-axes UX démarrent leur trajectoire à cette édition (baseline `non couvert` avant).

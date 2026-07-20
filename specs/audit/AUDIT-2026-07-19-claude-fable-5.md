# Audit ClubScheduler — édition 2026-07-19

| Méta | Valeur |
|---|---|
| Date | 2026-07-19 |
| Modèle | `claude-fable-5` (Fable 5, Anthropic — session basculée depuis `claude-opus-4-8[1m]` via `/model` avant l'audit) |
| HEAD | `1484925` (`main` — clôture P2-5 E3/E4/E6 #264 mergée) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, UX) + checks directs (supply chain, Mercure, secrets, prod-readiness, RGPD, cyber A1-A18) + smoke-solver + **backup/restore-check EXÉCUTÉS** + vérification contradictoire manuelle |
| Édition précédente | `AUDIT-2026-07-10-claude-fable-5.md` (HEAD `67e9641`) — depuis : **108 commits / ~90 PRs** (superadmin SA0 complet, Sentry 3 zones, backups+restore-check, RGPD export/effacement/purges, module matchs lots, plans de période E1/5b + clôture E3/E4/E6, register UX, FFBB lot C, saisons) |

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + 8 sondages (6 EXACT sur le 1er rang, 2 mensonges de 2e rang contre-vérifiés) |
| Besoin produit | ✅ couvert | roadmap + livré vs specs |
| Code backend | ✅ couvert | statique, RLS relu au SQL, SA0 vérifié contre son spec |
| Code engine | ✅ couvert | statique, chaînes de contraintes suivies bout en bout, diffs depuis 07-10 audités |
| Code frontend | ✅ couvert | statique + `tsc -b` (0 erreur) + `vitest` (**483 verts / 77 fichiers**) exécutés |
| Supply chain | ✅ couvert | npm/composer/pip-audit exécutés (0 vuln) + gate CI + **Dependabot actif** |
| Cybersécurité — surface d'attaque | ✅ couvert | A1-A18 verdictés un par un |
| Infra / Mercure | 🟡 partiel | compose lu (Mercure durci, 11 healthchecks) ; **0 `mem_limit`**, pas de compose prod |
| Prod-readiness / observabilité | ✅ couvert | Sentry 3 zones vérifié (DSN-vide-inactif) ; **backup exécuté** (dump 482 KiB) |
| RGPD | ✅ couvert | statique + routes/purges/tests présents (`RgpdExportTest`, `AccountErasureTest`, 4 jobs planifiés) |
| Performance mesurée | ✅ couvert | smoke-solver COMPLETED (score 24348, **~9 s wall**) |
| UX-Cohérence | ✅ couvert | statique (inventaire 17 primitives + comptes reproductibles) |
| UX-Simplicité & Intuitivité | 🟡 partiel | proxys statiques ; parcours navigateur non exécuté |
| Inclusivité / a11y | 🟡 partiel | statique (couleur-seule, aria, focus) ; contraste dynamique non exécuté |
| Coûts / scalabilité financière | ❌ non couvert (pas de données réelles) | ligne permanente — aucune donnée facturation/infra prod |
| **Restauration après corruption** | ✅ **couvert (EXÉCUTÉ — 1re fois)** | `app:db:backup` puis `app:db:restore-check` lancés en vrai : dump → base jetable → **45 tables, 3 clubs, « The backup is real »** |
| Comportement offline / latence front | ❌ non couvert | jamais ouvert |
| **Montée de version sur données réelles** (migrations up/down sur dump) | ❌ non couvert (nouveau candidat 2 bis) | rendu visible et redevable ; testable désormais (dumps réels disponibles) |

> **Posture** : barre = **application commercialisable** (cible mi-2027). Sévérité assumée ; « ça tourne » n'est jamais un signal de réussite.

---

## Synthèse des notes

| Critère | 2026-07-10 | **2026-07-19** |
|---|---|---|
| 1. Documentation | 82 | **83** |
| 2. Pertinence du besoin | 88 | **90** |
| 3a. Code backend | 83 | **86** |
| 3b. Code engine | 81 | **82** |
| 3c. Code frontend | 72 | **77** |
| 4. Supply chain | 94 | **96** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 79 | **83** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 83·.10 + 90·.10 + 86·.25 + 82·.20 + 77·.15 + 96·.05 + 90·.075 + 72·.075 = **83,7**, **−1 pt** de malus transversal (INF-03 `mem_limit`, config prod = couche env seule, dumps on-host, SEC-16 JWT localStorage) → **83**.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 07-10 | **07-19** | Plafond appliqué |
|---|---|---|---|
| UX-Cohérence | 76 | **80** | aucun finding ≥ Moyen confirmé côté gestionnaire ; résidus Faibles |
| UX-Simplicité & Intuitivité | 77 | **74** | UXS-03 **aggravé** (8 composants > 400 l., max 716) → plafond 75 |
| Inclusivité / a11y | 68 | **72** | A11Y-07/08/09 corrigés ; 2 nouveaux Moyens (A11Y-10/11) + A11Y-06 aggravé → plafond 75 |
| **Score UX général** | 68 | **72** | = le PLUS BAS des sous-axes (inclusivité) |

**Lecture rapide.** Édition de **déblocage** : les **4 impasses GA identiques depuis 4 éditions sont fermées et prouvées** — observabilité (Sentry 3 zones, DSN-vide-inactif vérifié aux 3 endroits), **backups avec restore-check que cet audit a EXÉCUTÉ** (dump réel restauré dans une base jetable : 45 tables, 3 clubs), RGPD (export art. 20, effacement-anonymisation, 4 purges planifiées, testés), couche env prod (`.env.prod` template 0 secret, debug off automatique). Les deux failles Élevées de l'édition précédente (**SEC-14** tables globales écrivables cross-tenant, **SEC-15** mass-assignment facturation) sont **corrigées avec tests NR bloquants**, et la posture cyber atteint pour la première fois **18 protégé / 0 partiel / 0 absent**. L'engine a soldé ENG-18/21/22/23/24 (le placebo SOFT lock est mort : rejet 400). Le motif « déclaré ≠ effectif » survit sur UN nouveau cas : **ALIGN-07** — une réservation HARD consomme le créneau ENTIER d'un gymnase divisible (capacité 2 → les deux moitiés). Les dettes qui restent sont structurelles et connues : Mercure jamais consommé (polling 2,5 s), bundle unique 763 KB (+20 %), JWT en localStorage, inventaires doc de 2e rang qui rotent (DOC-04, 4e récidive), et une discipline de taille de composants qui glisse (8 fichiers > 400 l.).

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| SEC-14 | Tables globales Plan/PriorityTier/Sport écrivables | backend | Élevée | **contre-vérifié à la main** | **corrigé** — `GetCollection`+`Get` seulement (`PriorityTierResource.php:20-21`, idem Sport/SubscriptionPlan) + NR `GlobalReferenceTablesReadOnlyTest` (phase1) |
| SEC-15 | Mass-assignment plan/quota sur Club PUT | backend | Moyenne | contre-vérifié | **corrigé** — champs absents de `ClubInput` (commentaire SEC-15), sortie read-only + NR `ClubBillingNotClientWritableTest` |
| SEC-08 | getMessage() brut au client (résidu ManualEdit) | backend | Mineure | confirmé | **corrigé** — sanitisation + catch-all générique (`ManualEditController.php:151-167`) |
| SEC-13 | ConstraintInput.config non validé | backend | Faible | confirmé | **ouvert** — `?array` libre ; atténué (builder défensif + Pydantic engine) |
| SEC-16 | JWT en localStorage | frontend | Moyenne | confirmé | **ouvert** — `authStore.ts:12-30` inchangé ; atténué (0 innerHTML, CSP) |
| BCK-04 | ScheduleConstraintBuilder volumineux | backend | Moyenne | confirmé | **ouvert, aggravé** — 895 l. (+78) ; nouveau voisin `SchedulePlanProvisioner` 847 l. |
| BCK-07 | check club sauté si `$clubId` null | backend | Mineure | confirmé | **ouvert (mitigé RLS)** — backstop DB prouvé (`testNoGucSeesNoRows`) |
| BCK-10 | requireActiveAdmin sans clubId | backend | Faible | confirmé | **ouvert (mono-club)** — RLS borne la requête |
| ENG-17 | Diagnostics coach inertes (TEAM_COACH) | engine | Moyenne | confirmé | **ouvert (différé assumé)** — `coachId` des seuls slotTemplates (`result_builder.py:911-919`) ; « vrai changement solveur » |
| ENG-18 | « minimum garanti » = no-op | engine | Moyenne | confirmé | **corrigé (honnêteté)** — docstrings/diagnostics disent « cible soft » ; résidu 1 commentaire menteur `constraints.py:249` |
| ENG-21 | SOFT lock placebo | engine | Moyenne | confirmé | **corrigé** — code engine mort retiré ; backend **rejette SOFT en 400** (`ManualEditController.php:109-113`) |
| ENG-22 | UNKNOWN muet/trompeur | engine | Moyenne | confirmé | **corrigé** — `diag-timeout`/`diag-solver-error` explicites, diagnostics gated OPTIMAL/FEASIBLE (`result_builder.py:545-579`) |
| ENG-23 | Cap A10 incohérent (422 faux-block) | engine/backend | Moyenne | confirmé | **corrigé** — cap engine retiré (`input_schema.py:158-161`), borne = backend brut ≤500, trade-off documenté |
| ENG-24 | coach_overload en unités fausses | engine | Moyenne | confirmé | **corrigé** — jours travaillés distincts vs `maxDaysOverride` + NR |
| ENG-25 | Déterminisme inter-process (hash) | engine | Mineure | confirmé | **partiel** — résidu : itération set str `objective.py:405`, `PYTHONHASHSEED` non fixé ; valeur objective stable |
| ENG-26 | Harnais test version=1.0 | engine | Mineure | confirmé | **ouvert** — `tests/support/pipeline.py:137` ; le gate réel 422rait ce payload |
| FRT-02 | Query error avalée | frontend | Élevée | confirmé | **corrigé pour l'essentiel** — filet global Query+MutationCache (`queryClient.ts`) ; résidu : `PlanningPage.tsx:92` sans `isError` in-place |
| FRT-04 | Pas de Mercure (polling) | frontend | Moyenne | confirmé | **ouvert** — 0 EventSource ; polling 2 500 ms ; le backend publie dans le vide, proxy `/.well-known/mercure` mort |
| FRT-08 | Pas d'ErrorBoundary brandé | frontend | Moyenne | confirmé | **corrigé** — `app/ErrorBoundary.tsx` (FR, retry, Sentry), monté hors providers |
| FRT-09 | Schedules fantômes au retry | frontend | Moyenne | confirmé | **partiel** — période ré-adopte l'overlay en vol ; saison recrée une version à chaque retry (assumable versions, résidu bas) |
| FRT-10 | 0 code-splitting | frontend | Moyenne | confirmé | **ouvert, aggravé** — bundle unique **763 KB** (+20 %), 0 `lazy()`, Sentry+admin livrés à la page login |
| FRT-15 | types.gen.ts mort 8816 l. | frontend | Moyenne | confirmé | **corrigé** — fichier supprimé, types croisés entre features (source unique par type) |
| FRT-16 | Timeout client 5 min < solveur | frontend | Moyenne | confirmé | **corrigé** — `TIMEOUT_MS = 20 min` avec justification queue+lock+600 s |
| FRT-17 | Proxy /engine mort | frontend | Mineure | confirmé | **corrigé** — retiré, commentaire cite la frontière §2 |
| FRT-18 | Messages serveur bruts en toast | frontend | Mineure | confirmé | **partiel** — `errorMessage.ts:30` passe encore `error/message/detail` verbatim ; fallback fuit le code HTTP |
| DOC-14 | backend-inventory ment (register…) | doc | Élevée | confirmé | **partiel** — register/verify/guard corrigés ; `Reservation` toujours absente ; stamp non bumpé malgré édits |
| DOC-15 | frontend-spec ment (register) | doc | Élevée | confirmé | **corrigé** — 202, `/verify-email/:token`, JWT à la vérification |
| DOC-16 | testing-strategy incomplet | doc | Élevée | confirmé | **partiel (récidive)** — 8 jobs décrits, MAIS liste bloquante re-driftée (13/15) et budget perf faux (« 180 s » vs 60 réels, `test_perf_dense.py:30`) |
| DOC-17 | CLAUDE.md §4 sans dependency-audit | doc | Moyenne | confirmé | **corrigé** |
| DOC-18 | TENANT.md « SET LOCAL » faux | doc | Moyenne | confirmé | **corrigé** — `set_config(..., false)` décrit, ancien qualifié de no-op |
| DOC-19 | engine-inventory sans bornes A10 | doc | Moyenne | confirmé | **partiel** — bornes+politique bump écrites, MAIS « fichier = 2.0 » **faux** (2.1 depuis 07-11, contre-vérifié) et fenêtres horaires coach absentes |
| DOC-20 | README specs / ffbb / docs morts | doc | Mineure | confirmé | **partiel** — module-matchs listé ; overview omet superadmin-auth + types-de-planning ; `enregistrement-ffbb.md` pré-A3 ; `plan-de-test-post-36.md` toujours mort-vivant |
| DOC-04 | Inventaires 2e rang périmés (motif) | doc | Moyenne | confirmé | **ouvert (4e récidive)** — 1er rang exact, 2e rang rote en ~1 semaine ; stamps « Last verified » non fiables |
| RGPD-01 | Purge/rétention/audit-trail | transverse | Élevée | **contre-vérifié à la main** | **corrigé** — `/api/me/export`, `/api/club/export` (sous GUC RLS), `DELETE /api/me` (anonymisation), purge club +30 j, 4 jobs planifiés (`AdminJobCatalog:48-51`), `AuditTrail`, testés (`RgpdExportTest`, `AccountErasureTest`, `PurgeSeasonsCommandTest`) |
| INF-01 | Observabilité zéro (Sentry) | infra | Élevée | **contre-vérifié** | **corrigé** — 3 zones (`sentry.yaml`, `engine/app/main.py:53-59` fail-open, `frontend/main.tsx:14` guard DSN), DSN vide/malformé → inactif sans 500 (testé) |
| INF-02 | Backups PostgreSQL absents | infra | Élevée | **EXÉCUTÉ par l'audit** | **corrigé** — pg_dump -Fc piloté activité, `.part`+rename atomique, rétention 14, purge orphelins, hook off-site, cron quotidien ; **restore-check réel lancé : 45 tables, 3 clubs**. Réserve : dumps sur le même hôte (couche « disque mort » = snapshot hébergeur, documenté) |
| INF-03 | Limites RAM absentes | infra | Mineure | confirmé | **ouvert** — 0 `mem_limit` dans compose |
| UXC-01 | VenueSwatch contournée (résidu) | ux | Moyenne | confirmé | **corrigé** — seule impl restante = la primitive (6 importeurs) |
| UXC-03 | Empty states réinventés (résidus) | ux | Moyenne | confirmé | **corrigé aux sites baseline** (`EmptyBlock` pour les grilles) ; 3 **nouveaux** sites inline (→ UXC-10) |
| UXC-04 | Hex en dur (résidu #666666 ×3) | ux | Moyenne | confirmé | **corrigé** — ×1 restant = constante sémantique `DEFAULT_VENUE_COLOR` (donnée, pas style) |
| UXC-07 | « salle » vs « gymnase » | ux | Faible | confirmé | **partiel** — DayDialog purgé ; restent `ClubPage.tsx:340` « Salle principale » + `cockpit/queries.ts:195` |
| UXC-08 | tu/vous mélangés (GenerateStep) | ux | Faible | confirmé | **ouvert** — 5 chaînes tutoiement (`GenerateStep.tsx:155-184`) vs vouvoiement dans 21 fichiers ; + nouveau site `cockpit/queries.ts:195` |
| UXS-03 | Composants > 400 lignes | ux | Moyenne | confirmé | **ouvert, aggravé** — **8 fichiers** (716/610/588/536/515/512…) vs 3 à la baseline |
| A11Y-06 | Texte < 12px | ux | Moyenne | confirmé | **ouvert, aggravé** — 24 occ / 13 fichiers (16/10 hors admin) vs 11/7 |
| A11Y-07 | aria-label écrase les marqueurs (MonthCalendar) | ux | Moyenne | confirmé | **corrigé** — `dayLabel` composé (date humaine + vacances/férié/entrées), commentaire cite A11Y-07 |
| A11Y-08 | Férié = point rouge couleur-seule | ux | Mineure | confirmé | **corrigé** — badge lettre « F » + title (commentaire cite A11Y-08) |
| A11Y-09 | Champs DayDialog sans nom accessible | ux | Moyenne | confirmé | **corrigé** — labels/aria posés aux 4 sites baseline ; 1 **nouveau** orphelin (→ A11Y-10) |

**Bilan reprise : 24 corrigés** (dont les 4 impasses GA et les 2 Élevées sécurité) · 9 partiels · 10 ouverts (dont 3 aggravés) · aucune régression sur un finding corrigé.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| ALIGN-07 | **Réservation/lock HARD consomme le créneau ENTIER d'un gymnase divisible** : `model.py:141` — `blocked_venue_slots.add((venue, day, start))` supprime les variables de TOUTES les autres équipes sur ce créneau, même à `capacity=2`. Une réservation de match (slotTemplate HARD, `ScheduleConstraintBuilder.php:395-403`) sur un gym divisible avale silencieusement les deux moitiés — « déclaré ≠ effectif » sur l'axe constraint semantics | engine | **Moyenne** | **confirmé (contre-vérifié à la main)** | nouveau |
| SEC-17 | `TenantFilterListener` ne saute pas `/api/admin/**` et pose le GUC `app.club_id` sur un `X-Club-Id` reçu hors identité `User` (anti-spoof seulement `if ($user instanceof User)`, `TenantFilterListener.php:88`) — contredit « la session admin ne pose jamais app.club_id » (SA0). Impact ~nul (endpoints admin sur connexion `admin`, RLS restrictif) ; un early-return serait sain | backend | Faible | confirmé (contre-vérifié) | nouveau |
| SEC-18 | CSRF admin **opt-in par controller** (`AdminSessionCsrf::isValid` appelé à la main, `AdminJobController.php:33`…) — un futur endpoint d'écriture admin qui oublie l'appel n'a aucune protection centrale | backend | Faible | confirmé code-lu | nouveau |
| BCK-11 | `team_tag_assignment` hors RLS (pas de `club_id`) — scoping ORM `SeasonFilter` uniquement ; seul objet tenant-lié sans backstop DB | backend | Faible | confirmé code-lu | nouveau |
| ENG-27 | `maxTeams=0` (gym fermé via capacité) ignoré quand < 2 candidats sur le créneau (`add_room_at_most_one` skippe, `constraints.py:293-300`) | engine | Mineure | confirmé code-lu | nouveau |
| DOC-21 | `backend/docs/commands.md:12-13` **ment sur les cibles Make** : « `make test` = PHPUnit --group phase1 » (réel : `--testsuite Unit`, `Makefile:37`) et « tests-complete = suites unit et integration » (réel : `phpunit tests/`, le dossier entier). Un agent qui suit ce doc croit lancer le gate bloquant | doc | Moyenne | **confirmé (contre-vérifié)** | nouveau |
| DOC-22 | **Sentry/backups invisibles depuis les index** : ni CLAUDE.md ni `docs/project-map.md` ne pointent le runbook `docs/ops/backup-restore.md` ni l'existence de Sentry — la feature prod-readiness livrée le 07-18 n'a aucun fil depuis l'ordre de lecture agent | doc | Moyenne | confirmé | nouveau |
| DOC-23 | `specs/courantes/superadmin-auth.md` en retard sur ses livraisons : header s'arrête à SA3-D (07-16) alors que la roadmap déclare SA2-stats, SA4 v1, alerting + data-freshness livrés 07-18 — la « vérité courante » ne les mentionne pas | doc | Moyenne | confirmé code-lu | nouveau |
| DOC-24 | **Table d'alignement 3 couches périmée sur `venue_closed`** : `frontend/docs/constraint-emission.md:45` et `backend/docs/constraint-coverage.md:39` décrivent « → `forbiddenVenueId`/équipe (expansion backend) » alors que 5b (#263) a REMPLACÉ l'expansion par le retrait de créneaux (`VenueClosureDays`, `expandClosedVenues` supprimé) | doc | Moyenne | **confirmé (contre-vérifié)** | nouveau |
| DOC-25 | Docs morts/périmés groupés : `docs/cleanup-candidates.md` (« 4 blocking tests », `TenantCacheIsolationTest` « needs implementing » — il tourne en CI), `docs/plan-de-test-post-36.md` (point-in-time #37→#100, PRs à ~#250), `specs/README.md` overview incomplet (omet superadmin-auth, types-de-planning), `enregistrement-ffbb.md` § « Aujourd'hui » pré-A3 | doc | Mineure | confirmé code-lu | nouveau (groupé) |
| UXC-09 | Terminologie : « plan » vs « planning » à 26 lignes d'écart (`GenerateStep.tsx:154` vs `:180`) ; « Salle principale » (`ClubPage.tsx:340`) vs 83 « gymnase » ; `text-green-600` en dur là où le token `success` existe (`new-password-fields.tsx:47`) | ux | Faible | confirmé | nouveau (groupé) |
| UXC-10 | 3 nouveaux empty states inline hors primitive : `RadarPanel.tsx:395` (markup exact d'EmptyHint recopié), `SlotReservationModal.tsx:82`, `MatchesPage.tsx:169` | ux | Faible | confirmé | nouveau |
| A11Y-10 | `ResourceFilter.tsx:56-63` : input de recherche placeholder-only **sans nom accessible** (seul orphelin de l'app depuis la correction A11Y-09) + backdrop `aria-hidden` **focusable** (`button` sans `tabIndex=-1`, `:53`) — élément atteignable au clavier mais invisible aux AT | ux | Moyenne | confirmé | nouveau |
| A11Y-11 | Erreurs de champ signalées par la **seule bordure** `border-destructive` (`CoachesStep.tsx:238`, `VenuesStep.tsx:287`, `TeamsStep.tsx:492`) — `aria-invalid` est posé (les AT sont servis) mais AUCUN message texte/icône pour l'œil : un daltonien rouge/gris ne voit pas le champ en faute | ux | Moyenne | confirmé | nouveau |

---

## Tableau de posture cybersécurité (A1–A18)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant (club_id) | **protégé** | RLS FORCE + policy sur TOUTES les tables club_id, **auto-gardé** par `RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls` (pg_policies) + `TenantOwnedInterfaceCompletenessTest` ; listener prio 7 (`TenantFilterListener.php:38-43`), spoof → 403 | résidu SEC-17 (défense en profondeur) |
| A2 | Brute-force /login | **protégé** | `security.yaml:31-32` throttle 5 ; admin : throttle par IP sur password ET totp (`AdminAuthController.php:41,66`) | — |
| A3 | Énumération de comptes | **protégé** | register 202 uniforme (inchangé depuis #153) | — |
| A4 | Falsification JWT | **protégé** | RS256 lexik, clés hors repo (`git ls-files config/jwt` vide) ; admin = session distincte + TOTP | — |
| A5 | Escalade de privilège | **protégé** *(était partiel)* | SEC-14 corrigé : tables globales read-only + NR `GlobalReferenceTablesReadOnlyTest` ; gate management (`ManagementRoleTest`) | — |
| A6 | Mass-assignment | **protégé** *(était partiel)* | SEC-15 corrigé : billing/quota hors `ClubInput` + NR `ClubBillingNotClientWritableTest` | — |
| A7 | Injection SQL | **protégé** | Doctrine paramétré ; GUC via `set_config` paramétré (`TenantConnectionContext.php:28-30`) | — |
| A8 | XSS stockée/reflétée | **protégé** | React escaping, **0** `dangerouslySetInnerHTML` ; logos allowlist binaire (pas de SVG) | — |
| A9 | CSRF | **protégé** | tenant : Bearer stateless (`security.yaml:22,39`) ; admin : session + token CSRF explicite (`AdminSessionCsrf`) — résidu SEC-18 (opt-in) | — |
| A10 | DoS bombe de génération | **protégé** | cap backend pré-dispatch ≤500 brut (`GenerationComplexityGuard`) ; cap engine retiré SCIEMMENT (trust boundary interne documentée, `input_schema.py:16-18`) ; timeout adaptatif | — |
| A11 | Spam routes anonymes | **protégé** | `rate_limiter.yaml` register/verify/reset | — |
| A12 | SSRF | **protégé** | FFBB : hosts **const** (`FfbbApiClient.php:24-25`), `max_redirects=0`, code club format-validé ; logo fetch borné taille+MIME (`FfbbLogoFetcher`) ; engine URL fixe | — |
| A13 | Abus upload logo | **protégé** | allowlist png/jpeg/webp + `finfo` réel + taille (`ClubLogoController.php:29-31,64`) ; SVG absent de l'allowlist | — |
| A14 | Fuite Mercure | **protégé** | `ClubTopicUpdate` private ; hub non-anonyme, secret dédié, CORS borné, port 127.0.0.1 (compose) | — |
| A15 | Exposition de secrets | **protégé** | `.env.prod` = template 0 secret (en-tête explicite) + `ProdSecretGuard` ; clés JWT non trackées | — |
| A16 | Erreurs verboses | **protégé** | `APP_DEBUG=0` auto en prod (`.env.prod:11`), SEC-08 résidu corrigé (`ManualEditController.php:151-167`) ; résidu bas FRT-18 (messages serveur curés passés bruts) | — |
| A17 | Clickjacking / en-têtes | **protégé** | `docker/frontend/csp.conf` + `security-headers.conf` (CSP, HSTS, XFO DENY, nosniff) | — |
| A18 | Dépendance vulnérable | **protégé** | 0 vuln (npm --omit=dev / composer / pip-audit exécutés) + gate CI + **Dependabot actif** (7 PRs en traitement) | — |

**Bilan cyber : 18 protégé · 0 partiel · 0 absent · 0 non vérifié** — première édition à poser ce tableau plein. Les résidus (SEC-17/18, FRT-18) sont de la défense en profondeur, pas des trous exploitables. Vs 07-10 : 15/3/0.

---

## Détail par critère

### 1. Documentation — 83/100 (82)
**Forces.** Le 1er rang est exact et vivant : 8 sondages CLAUDE.md/ci.yml — blocking-tests 15/15 au step près, graphe CI, prio 7, tiers solveur, FFBB hosts — tous EXACT. Roadmap = vrai registre daté (§Livrés sourcé, P0 purgé avec trace) ; `openapi-snapshot.meta` frais (82 paths vérifiés) ; `specs/initiales` intactes. 5 des 8 findings doc baseline corrigés ou quasi.
**Faiblesses.** Le motif **DOC-04 en est à sa 4e récidive** : le 2e rang rote en une semaine (`engine-inventory` affirme CONTRACT_VERSION 2.0 — 2.1 depuis 8 jours ; `commands.md` ment sur `make test` ; `testing-strategy` re-drifté 13/15 + budget perf 3× trop laxiste). La feature prod-readiness (Sentry/backups) a son runbook mais **aucun fil depuis les deux index** (DOC-22). La table d'alignement 3 couches — l'outil anti-scission — est elle-même périmée sur `venue_closed` (DOC-24). Les stamps « Last verified » ne sont pas bumpés aux retouches : le mécanisme de confiance affiché est non fiable.

### 2. Pertinence du besoin — 90/100 (88)
Complétude utile en hausse (P2-5 soldé : les 3 types de planning tiennent leur spec ; module matchs opérationnel ; superadmin = socle d'exploitation réel). La **viabilité n'est plus suspendue** aux chantiers infra/RGPD : ils sont livrés. Reste suspendue à la config prod d'orchestration et au différenciateur P2-1 (collecte coach) non entamé.

### 3a. Backend — 86/100 (83)
Correction+sécurité 88 · archi 78 · tests 90 · robustesse 87.
**Forces.** Les 3 findings sécurité baseline corrigés **avec tests NR bloquants** ; RLS complet et **auto-vérifié par méta-tests** (pg_policies + marqueur⇔colonne) ; SA0 conforme à son spec (firewall séparé, TOTP AES-GCM, throttle 2 étapes, `session->migrate`, **audit fail-closed** → 503) ; async solide (statut terminal garanti, lock CAS Lua, failure transport) ; chaîne backup/Sentry d'une qualité au-dessus du marché (fail-open observabilité, fail-safe backup, restore-check testé en intégration ET exécuté par l'audit).
**Faiblesses.** 2 god-services ~850-900 l. dont un qui regrossit (BCK-04) ; défense en profondeur incomplète (SEC-13 config libre, BCK-07 pattern null-club sur backstop RLS, SEC-17 listener sans skip admin, SEC-18 CSRF opt-in) ; dumps on-host (couche disque déléguée, documentée).

### 3b. Engine — 82/100 (81)
Correction+sécurité 79 · archi 80 · tests 78 · robustesse 84.
**Forces.** ENG-18/21/22/23/24 réellement soldés — le SOFT lock placebo est mort (rejet 400 côté backend), UNKNOWN/MODEL_INVALID explicables, unités coach corrigées ; contrat bumpé proprement 2.0→2.1 au seul changement d'enveloppe ; **claim « zéro engine » de 5b vérifié** (forbidden_assignments day-blind → le retrait de créneaux backend est le seul encodage correct) ; replay possible depuis le snapshot persisté (payload+seed+version) ; concurrence prouvée par tests.
**Faiblesses.** **ALIGN-07** (réservation HARD avale un créneau divisible entier — le seul « déclaré ≠ effectif » neuf) ; ENG-17 toujours différé (diagnostics coach aveugles sur le chemin TEAM_COACH dominant) ; ENG-26 (harnais 1.0 que le gate 422rait) ; résidu ENG-25 (1 itération set str + PYTHONHASHSEED) ; ~80 l. de plomberie hard-min toujours-zéro et vestiges two-pass.

### 3c. Frontend — 77/100 (72)
Archi 78 · types 88 · data-fetching 72 · tests 78 · robustesse 70.
**Forces.** 5 findings baseline fermés (types.gen supprimé — source unique par type, ErrorBoundary brandé + Sentry, timeout 20 min justifié, proxy engine mort retiré, filet erreur global) ; `tsc` 0, **483 tests verts / 77 fichiers** (+178 depuis 07-10) ; hygiène sécurité propre (0 innerHTML, rel posés, autocomplete corrects).
**Faiblesses.** Les 3 dettes structurelles restantes sont intactes ou pires : **FRT-04** (0 SSE — le backend publie dans le vide, polling 2,5 s), **FRT-10** (bundle unique **763 KB**, +20 %, 0 lazy — Sentry+admin à la page login), **SEC-16** (JWT localStorage). Discipline de taille en glissade : 8 fichiers > 400 l. (max 716).

### 4. Supply chain — 96/100 (94)
0 vuln aux 3 audits exécutés + gate CI bloquant + **Dependabot actif** (l'écart « ni Dependabot ni renovate » de la baseline est fermé).

### 5. Performance — 90/100 (90)
smoke-solver COMPLETED, score 24348, **~9 s wall** (create→generate→poll complet) ; gate perf CI main-only (budget réel 60 s).

### Infra / Prod-readiness / RGPD
**Fermés cette édition** : Sentry 3 zones (DSN-vide-inactif vérifié), backups+restore-check (exécutés), RGPD (export/effacement/purges testés), `.env.prod` template sûr (debug off auto). **Restent** : INF-03 (0 `mem_limit`), pas de compose/orchestration prod, dumps on-host (hook off-site à configurer). Malus transversal réduit à −1.

### UX (axes additifs — détail)
**Cohérence 80.** Primitives consolidées (17 ui + business, `EmptyBlock` pour les grilles, tint unique, VenueSwatch seule impl). Résidus Faibles : 3 empty inline neufs (UXC-10), plan/planning + salle ×2 + green-600 (UXC-09), tu/vous (UXC-08). L'admin est un 2e système de style complet (~60 classes slate/cyan) — hors persona gestionnaire, non compté.
**Simplicité 74.** Jargon : propre (RULE_LABEL, STATUS_LABELS, socle/overlay bannis de l'écran). MAIS UXS-03 **aggravé** (8 composants > 400 l., max 716 — proxy de charge cognitive et de maintenabilité des écrans) → plafond 75 ; fallback erreur fuit le code HTTP ; parcours navigateur non exécuté (partiel).
**Inclusivité 72.** Le flux exceptions — maillon faible de la baseline — est soldé (A11Y-07/08/09 corrigés au site près). Nouveaux Moyens : A11Y-10 (dernier champ orphelin + backdrop focusable aria-hidden), A11Y-11 (erreur = bordure seule pour l'œil), A11Y-06 aggravé (24 occ < 12px) → plafond 75. Score général UX = **72** = ce maillon.

---

## Avis global + axes priorisés

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| INF-02 backups + restauration testée · INF-01 Sentry · RGPD-01 purge/rétention | ~~P0~~ | — | ✅ **livrés et prouvés** (backup/restore exécutés par l'audit ; Sentry 3 zones ; RGPD testé) |
| SEC-14 tables globales read-only · SEC-15 billing hors DTO | ~~P1~~ | — | ✅ + NR phase1 |
| ENG-21 SOFT lock · ENG-23 cap A10 · FRT-16 timeout client | ~~P1~~ | — | ✅ (rejet 400 / cap retiré / 20 min) |
| A11Y-07/09 noms accessibles calendrier/DayDialog | ~~P2~~ | — | ✅ |
| DOC-15 frontend-spec register | ~~P2~~ | — | ✅ (DOC-14/16 restent partiels ⬜) |
| **Config prod d'orchestration** : compose prod (ou équivalent), `mem_limit` (INF-03), hook off-site backups configuré | **P1** | M | ⬜ (résidu du P0 fermé) |
| **ALIGN-07** — réservation HARD sur gym divisible : bloquer UNE part, pas le créneau entier (dimension capacité dans `blocked_venue_slots`) + NR sémantique | **P1** | S/M | ⬜ nouveau |
| **Doc drift bundle** : `commands.md` cibles Make (DOC-21) · `testing-strategy` 15/15 + 60 s (DOC-16) · `engine-inventory` 2.1 (DOC-19) · table alignement `venue_closed` (DOC-24) · index → runbook backups (DOC-22) · `superadmin-auth.md` SA4 (DOC-23) | **P1** | S | ⬜ — une passe `documentation-update` dédiée solde les 6 |
| FRT-04 Mercure côté front (le backend publie déjà) — supprime le polling 2,5 s | P2 | M | ⬜ (4e édition) |
| FRT-10 code-splitting (lazy admin/wizard/Sentry) — bundle 763 KB | P2 | M | ⬜ aggravé |
| SEC-16 JWT hors localStorage (cookie httpOnly ou mémoire+refresh) | P2 | M | ⬜ |
| A11Y-10/11 — champ ResourceFilter + messages d'erreur texte sous les champs | P2 | S | ⬜ nouveau |
| ENG-17 coachId de sortie (vrai changement solveur) · ENG-26 harnais 2.1 | P2 | M/S | ⬜ (différé assumé) |
| UXS-03 — découpage des 8 composants > 400 l. (AdminDashboard 716 en tête) | P2 | M | ⬜ aggravé |
| FRT-09 résidu saison · FRT-18 messages bruts · UXC-08/09/10 · SEC-17/18 · BCK-11 · ENG-27 | P3 (fond de sac) | S | ⬜ |

## Features intéressantes à développer (valeur/effort)
- **Temps réel Mercure côté front** (FRT-04) : tout le backend est prêt (topics privés, hub durci) — un `EventSource` supprime le polling et rend la génération « vivante ». Ratio le plus élevé du backlog technique.
- **P2-1 collecte coach** (lien tokenisé sans login) : LE différenciateur commercial déclaré, maintenant que le socle (plans de période complets) est solide.
- **Restauration en un clic** (sur INF-02 livré) : le restore-check prouve le dump ; un job « restaurer l'environnement de recette depuis le dump N » transformerait la preuve en outil d'exploitation.

## Annexe méthodologie
**Exécuté vs statique.** Exécuté : `tsc -b` (0), `vitest` (483 verts/77 fichiers), `npm audit --omit=dev` + `composer audit` + `pip-audit` (0 vuln), `smoke-solver` (COMPLETED 24348, ~9 s), **`app:db:backup` + `app:db:restore-check`** (dump 482 KiB → base jetable, 45 tables, 3 clubs), `docker compose ps` (12 services healthy), `git ls-files`, greps ciblés. Statique : backend/engine/doc/UX (lecture de code, lignes citées).
**Contre-vérifiés à la main (Étape 3)** : SEC-14 (ops read-only + tests lus), RGPD-01 (routes+jobs+tests), INF-01 (3 fichiers Sentry), INF-02 (exécution), ALIGN-07 (`model.py` relu), BCK-N1/SEC-17 (listener relu), DOC-21 (`Makefile` vs `commands.md`), DOC-19 (`CONTRACT_VERSION`=2.1 vs « 2.0 »), DOC-24 (mes propres PRs 5b vs table d'alignement).
**Confiance par axe** : Frontend/Supply/Perf/**Prod-readiness/Restauration** **élevée** (exécutés), Cyber **élevée** (spot-checks directs + contre-vérif), Backend/Engine/Doc/UX **moyenne** (lecture de code), Besoin **moyenne**. Aucune note à confiance faible.
**Limites.** Parcours navigateur UX non exécuté → UX-Simplicité/Inclusivité `partiel`, contrastes estimés non mesurés. `vite build` non relancé (taille bundle lue depuis `dist/` existant). Migrations up/down sur dump réel : non testées (nouvel axe candidat, visible ci-dessus). Charge multi-clubs : jamais mesurée.
**Auto-question de biais.** (1) L'édition arrive juste après MES propres PRs (P2-5, prod-readiness) — risque de complaisance sur ce périmètre ; contre-mesure appliquée : c'est précisément là que j'ai cherché (DOC-24 — la table d'alignement périmée par ma PR 5b — et le stamp non bumpé de DOC-14 sont des découvertes contre mon propre travail). (2) Sur-poids persistant du greppable vs l'exécuté — réduit cette édition (backup/restore et Sentry vérifiés en exécution), mais le comportement navigateur et la charge restent des angles morts. (3) Les scores backend/engine reposent sur des agents dont je n'ai relu qu'un échantillon des preuves — les verdicts Élevés sont tous contre-vérifiés, les Moyens partiellement.

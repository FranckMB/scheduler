# Audit ClubScheduler — édition 2026-07-10

| Méta | Valeur |
|---|---|
| Date | 2026-07-10 |
| Modèle | `claude-fable-5` (Fable 5, Anthropic) |
| HEAD | `67e9641` (branche `docs/audit-skill-refinements` = code de `main` `a5fdde0` + raffinements du skill audit ; aucun code applicatif ne diffère de main) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, UX) + checks directs (supply chain, Mercure, secrets, prod-readiness, RGPD, cyber A1-A18) + smoke-solver + vérification contradictoire manuelle des findings élevés |
| Édition précédente | `AUDIT-2026-07-08-claude-opus-4-8.md` (HEAD `b00c81b`) — depuis : batch cyber A3/A18/A10 (#153/#154/#156), unification modale a11y (#157), refonte UX (tokens warning/success, VenueSwatch, empty-hint, wording gestionnaire), corrections engine ENG-16/19/20 (#126) |

> **Nouveautés du skill cette édition** (PR #158) : brief Engine « déterminisme & rejouabilité », ligne de couverture permanente **Coûts / scalabilité financière**, recos avec tag effort **S/M/L** + colonne **traité** (✅/⬜/⛔), annexe **confiance par axe** + auto-question de biais.

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + 7 sondages code (tous exacts sur CLAUDE.md) |
| Besoin produit | ✅ couvert | roadmap + livré vs specs |
| Code backend | ✅ couvert | statique, RLS/policies relues au SQL |
| Code engine | ✅ couvert | statique, chaînes de contraintes suivies bout en bout |
| Code frontend | ✅ couvert | statique + `tsc` (exit 0) + `vitest` (305 verts) |
| Supply chain | ✅ couvert | npm/composer/pip-audit exécutés (0 vuln) + **gate CI actif** (#154) |
| **Cybersécurité — surface d'attaque** | ✅ couvert | A1-A18 verdictés un par un (voir tableau de posture) |
| Infra / Mercure | 🟡 partiel | compose lu (Mercure durci) ; config prod toujours inexistante |
| Prod-readiness / observabilité | 🟡 partiel | constat statique (Sentry, backups, limites) |
| RGPD | 🟡 partiel | constat statique |
| Performance mesurée | ✅ couvert | smoke-solver COMPLETED (score 6487, ~s) + gate perf CI |
| UX-Cohérence | ✅ couvert | statique (inventaire primitives, comptes reproductibles) |
| UX-Simplicité & Intuitivité | 🟡 partiel | proxys statiques couverts ; parcours navigateur non exécuté |
| Inclusivité / a11y | 🟡 partiel | statique (couleur-seule, aria, focus) ; contraste dynamique non exécuté |
| **Coûts / scalabilité financière** | ❌ non couvert (pas de données réelles) | ligne permanente — aucune donnée facturation/infra prod ; jamais de chiffres fabriqués |
| Restauration après corruption | ❌ non couvert | toujours bloqué par INF-02 (aucun backup) |
| Comportement offline / latence front | ❌ non couvert | jamais ouvert |

> **Posture** : barre = **application commercialisable** (cible mi-2027). Sévérité assumée ; « ça tourne » n'est jamais un signal de réussite. La trajectoire se lit dans le registre.

---

## Synthèse des notes

| Critère | 2026-07-08 | **2026-07-10** |
|---|---|---|
| 1. Documentation | 84 | **82** |
| 2. Pertinence du besoin | 88 | **88** |
| 3a. Code backend | 86 | **83** |
| 3b. Code engine | 78 | **81** |
| 3c. Code frontend | 71 | **72** |
| 4. Supply chain | 92 | **94** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 78 | **79** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 82·.10 + 88·.10 + 83·.25 + 81·.20 + 72·.15 + 94·.05 + 90·.075 + 68·.075 = **80,9**, **−2 pts** de malus transversal (INF-01/02 observabilité+backups, RGPD-01, **SEC-14** cross-tenant Élevée nouvelle) → **79**.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 07-08 | **07-10** | Plafond appliqué |
|---|---|---|---|
| UX-Cohérence | 55 | **76** | plus aucune Élevée confirmée (UXC-02/05 corrigés) ; résidus Moyens |
| UX-Simplicité & Intuitivité | 58 | **77** | UXS-02 (jargon) corrigé ; reste UXS-03 (Moyenne) |
| Inclusivité / a11y | 50 | **68** | A11Y-01/03 corrigés ; 2 nouveaux Moyens (A11Y-07/09) → plafond 75 |
| **Score UX général** | 50 | **68** | = le PLUS BAS des sous-axes (inclusivité) |

**Lecture rapide.** Édition de **récolte** : le batch cyber (A3 énumération register, A18 gate CI d'audit, A10 bombe de génération) et les correctifs A5/A6/A14/A15/A16/A17 remontent nettement la posture d'attaque (**15 protégé / 3 partiel / 0 absent**, contre 10/8/0 il y a deux jours) ; la dette UX de la baseline est **largement soldée** — le finding critique « gymnase par la seule couleur » (A11Y-01) est corrigé, les modales unifiées avec focus-trap (A11Y-03/UXC-02), les tokens `warning`/`success` créés (28 classes hors-palette → 3), le jargon « socle/overlay » banni des libellés (UXS-02) — le Score UX général bondit de 50 à **68**. Côté engine, **ENG-16 (« uniquement » qui n'interdisait rien) est vraiment corrigé et testé en multi-séances** — le motif « déclaré ≠ effectif » recule sur l'axe §7.1. **Mais** deux failles neuves l'entretiennent : **SEC-14** — les tables globales `Plan`/`PriorityTier`/`Sport` sont écrivables par **tout membre authentifié**, et `PriorityTier` est lu par le solveur de **tous** les clubs (cross-tenant + falsification pricing) — et **ENG-21** — le SOFT lock émet une pénalité 10 000 que l'engine **n'lit jamais** (placebo). Les inventaires `specs/courantes` mentent sur le contrat register (201→202) que mes propres PRs ont changé sans les mettre à jour (DOC-14/15). Les quatre impasses GA demeurent, identiques depuis 4 éditions : **observabilité zéro, backups zéro, RGPD zéro, config prod inexistante**.

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| ENG-16 | `forcedDays` « uniquement » n'interdit pas les autres jours | engine | Élevée | confirmé | **corrigé** — mode « uniquement » émet `allowedDays` (whitelist), engine interdit le complément (`constraints.py:917-920`), conflit explicite, **multi-séances testé** (`test_constraint_fixes.py:185-204`). Résidu Mineur : matrice toujours mono-séance (ENG-16a) |
| ENG-17 | Diagnostics coach inertes pour les coachs TEAM_COACH | engine | Moyenne | confirmé | **ouvert** — `coachId` de sortie vient des seuls slotTemplates (`result_builder.py:171,866-874`) |
| ENG-18 | « minimum garanti » = no-op | engine | Moyenne | confirmé | **partiel** — soft-only assumé + documenté, wording diagnostic corrigé (« cible ») ; 2 docstrings mentent encore (`constraints.py:172,972`) |
| ENG-19 | Test forcedDays fragile | engine | Mineure | confirmé | **corrigé** |
| ENG-20 | `_day_constraint_conflict` HARD-only | engine | Mineure | confirmé | **corrigé** — couvre `(HARD, LOCK)` |
| SEC-08 | getMessage() brut au client | backend | Mineure | confirmé | **partiel (by design)** — résidu `ManualEditController.php:154` (message métier borné) |
| SEC-12 | `/constraints/validate` sans assertManager | backend | Faible | confirmé | **corrigé** — `assertManager()` en tête |
| SEC-13 | ConstraintInput non validé | backend | Faible | confirmé | **partiel** — `scopeTargetId` a `#[Assert\Uuid]` ; `config` reste `?array` libre |
| BCK-04 | ScheduleConstraintBuilder volumineux | backend | Moyenne | confirmé | **ouvert (assumé)** — 817 l. (+41), 4 deps nullable documentées |
| BCK-07 | check club sauté si `$clubId` null | backend | Mineure | confirmé | **ouvert (mitigé RLS)** — filet DB (0 ligne → 404) intact |
| BCK-09 | PUT migre silencieusement la saison | backend | Moyenne | confirmé | **corrigé** — saison estampillée seulement si absente (`AbstractStateProcessor.php:170-175`) + `season_filter` sur le find. **Le #1 pré-GA est fermé.** |
| BCK-10 | requireActiveAdmin sans clubId | backend | Faible | confirmé | **ouvert (mono-club)** — pas de fuite (re-vérif appartenance cible) |
| FRT-02 | Query error avalée → vide trompeur | frontend | Élevée | confirmé | **partiel** — filet global toast (`queryClient.ts:24-53`) ; UI reste `data=[]` sans `isError`/retry (`PlanningPage.tsx:103`) |
| FRT-03/15 | types.gen.ts mort + types dupliqués | frontend | Moyenne | confirmé | **ouvert, aggravé** — `types.gen.ts` = **8816 l.** (+28 %), 0 import ; duplication étendue à 3 features (planning/wizard/matches) |
| FRT-04 | Pas de Mercure (polling) | frontend | Moyenne | confirmé | **ouvert** — 0 EventSource |
| FRT-06/12/13 | Modales divergentes / focus-trap / restitution | frontend | Moyenne | confirmé | **corrigé** — `useModalA11y` (trap+restitution+Escape) sur `modal.tsx`+`confirm-dialog.tsx` ; 0 dialog brut restant ; testé |
| FRT-08 | Pas d'ErrorBoundary | frontend | Moyenne | confirmé | **partiel** — `errorElement` router (plus d'écran blanc en route) ; non brandé, throw hors router reste blanc |
| FRT-09 | Schedules fantômes (launch non idempotent) | frontend | Moyenne | confirmé | **partiel** — overlay réutilisé/ré-adopté ; mode saison recrée un DRAFT à chaque retry |
| FRT-10 | 0 code-splitting | frontend | Moyenne | confirmé | **ouvert** — bundle unique 639 KB |
| FRT-14 | Polling non suspendu onglet caché | frontend | Basse | confirmé | **corrigé (défaut framework)** — TanStack v5 saute les refetch en `hidden` ; résidu : boucle export brute (bornée 60 s) |
| DOC-04 | Inventaires courantes périmés | doc | Moyenne | confirmé | **ouvert, déplacé** — frontend-wizard à jour ; backend-inventory/frontend-spec mentent (→ DOC-14/15) |
| DOC-12 | CLAUDE.md §4 CI incomplet | doc | Moyenne | confirmé | **partiel (récidive)** — job `frontend` ajouté ; `dependency-audit` (#154) manque (→ DOC-17) |
| DOC-13 | Spec cockpit coincée en evolution | doc | Moyenne | confirmé | **corrigé** — graduée en `courantes/`, liens OK |
| RGPD-01 | Purge/rétention/audit-trail | transverse | Élevée | confirmé | **ouvert** — rien |
| INF-01 | Observabilité zéro (Sentry) | infra | Élevée | confirmé | **ouvert** |
| INF-02 | Backups PostgreSQL absents | infra | Élevée | confirmé | **ouvert** |
| INF-03 | Limites RAM absentes | infra | Mineure | confirmé | **ouvert** — 0 `mem_limit` |
| UXC-01 | VenueSwatch contournée | ux | Moyenne | confirmé | **partiel** — 3 inline supprimés ; 2e impl concurrente dans `StructureSummary.tsx:36` (3 usages) |
| UXC-02 | 2 modales divergentes | ux | Élevée | confirmé | **corrigé** — voir FRT-06/12/13 |
| UXC-03 | ~14 empty states réinventés | ux | Moyenne | confirmé | **largement corrigé** — `empty-hint.tsx` × 11 usages ; 3 résidus inline (WeekGrid/WeekendGrid/PlanningPage) |
| UXC-04 | Hex en dur + tint dupliqué | ux | Moyenne | confirmé | **corrigé** — `tint()` unifié (`color.ts:104`) ; résidu `#666666` ×3 |
| UXC-05 | 28 classes amber/emerald hors-palette | ux | Élevée | confirmé | **corrigé** — tokens `--warning`/`--success` (light+dark) ; 3 résidus / 2 fichiers |
| UXC-06 | planning vs calendrier | ux | Faible | confirmé | **corrigé** — distinction sémantique assumée |
| UXS-02 | Jargon overlay/socle exposé | ux | Élevée | confirmé | **corrigé** — libellés « planning principal/secondaire » ; 0 jargon visible |
| UXS-03 | Composants > 400 lignes | ux | Moyenne | confirmé | **ouvert, aggravé** — 3 fichiers (552/498/413) |
| A11Y-01 | Gymnase par la SEULE couleur | ux | Élevée | confirmé | **corrigé** — nom du gymnase en texte (sous-en-tête + libellé cellule selon la vue) |
| A11Y-03 | modal.tsx sans focus-trap | ux | Élevée | confirmé | **corrigé** — `useModalA11y` |
| A11Y-05 | Emoji title-only | ux | Moyenne | confirmé | **corrigé** — `role="img"` + aria-label (`MonthCalendar`) |
| A11Y-06 | Texte < 12px + contrastes | ux | Moyenne | confirmé | **ouvert, aggravé** — 11 occ / 7 fichiers ; risque `text-warning` en light (~3:1) |

**Bilan reprise** : **18 corrigés** · 9 partiels · 8 ouverts · aucune régression sur un finding corrigé. Le #1 pré-GA (BCK-09) et le critique UX (A11Y-01) sont fermés.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| SEC-14 | **Tables globales `Plan`/`PriorityTier`/`Sport` écrivables par tout membre authentifié** : `PriorityTierResource`/`PlanResource` exposent `Post/Put/Delete` **sans `security`**, processors sans `requiresManagementRole` (défaut false), pas de `club_id` (hors RLS). `PriorityTier` lu **globalement** par le solveur (`ScheduleConstraintBuilder.php:127,202` `findBy([])`) → un `PUT /api/priority-tiers/{id}` altère le catalogue de rangs de **tous les clubs** ; `PUT /api/plans` falsifie le catalogue de facturation. Zéro test sécurité. | backend | **Élevée** | **confirmé (contre-vérifié à la main)** | nouveau |
| SEC-15 | **Mass-assignment facturation/quota sur `Club` PUT** : `ClubInput` expose `planId`/`billingCycle`/`planExpiresAt`/`generationCountSeason` en groupe write → un admin de club s'auto-assigne un plan, prolonge l'expiration, ou remet le compteur de quota à zéro. Latent (aucun quota appliqué), GA-bloquant. | backend | Moyenne | confirmé code-lu | nouveau |
| SEC-16 | JWT persisté en **localStorage** (`authStore.ts:12-31`, zustand persist `cs-auth`) — token bearer exfiltrable par XSS. Atténué (0 `dangerouslySetInnerHTML`, headers CSP), mais au barème commercial : cookie httpOnly ou mémoire+refresh. | frontend | Moyenne | confirmé code-lu | nouveau |
| ENG-21 | **SOFT lock = placebo** : la pénalité `SOFT_LOCK_PENALTY = 10_000` émise par le backend (`ScheduleConstraintBuilder.php:47,521-526`, champ `pendingConstraintSuggestion`) n'est **lue par aucun code engine** (aucun terme d'objectif « garder ce créneau ») ; seule trace = WARNING post-hoc. « Déclaré ≠ effectif » sur l'axe **planning lifecycle**. Atténuation : cockpit n'émet peut-être pas SOFT, mais l'API l'accepte. | engine | Moyenne | confirmé code-lu | nouveau |
| ENG-23 | **Cap A10 incohérent** : le backend compte les contraintes **brutes** (permanentes, `GenerationComplexityGuard.php:90`, ≤500) tandis que l'engine plafonne le tableau **étendu** (`input_schema.py:156` max_length=500 après expansion CLUB→N TEAM, `ScheduleConstraintBuilder.php:713-719`). Un club légitime (60 équipes × 9 règles club = 540 rangées étendues) passe le guard backend puis prend un **422 engine** → génération échouée sans franchir aucun cap déclaré. Faux-blocage bien avant le « ~10× un gros club » annoncé. | engine/backend | Moyenne | confirmé code-lu (chiffré, non reproduit) | nouveau |
| ENG-22 | **Timeout/UNKNOWN inexplicable** : `status="failed"` sans cause + `_diagnose_unplaced` affirme faussement « tous les créneaux étaient déjà occupés » (`result_builder.py:297-306,59`). INFEASIBLE bien expliqué ; UNKNOWN (budget épuisé) muet et trompeur pour le gestionnaire. | engine | Moyenne | confirmé code-lu | nouveau |
| ENG-24 | **`coach_overload` : confusion d'unités** (blocs 15 min vs séances vs jours, `result_builder.py:377-381`) → « 12 séances au-dessus de la limite de 4 » pour un coach 2×90 min : fausse alarme systématique. Masqué aujourd'hui par ENG-17. | engine | Moyenne | confirmé code-lu | nouveau |
| ENG-25 | **Déterminisme inter-redémarrages non garanti** même à workers=1 : ordre d'insertion des contraintes dépend du hash de chaînes (`constraints.py:401-405,683-684`), `PYTHONHASHSEED` non fixé → proto permuté entre process → tie-break entre optima égaux peut différer. Valeur d'objectif stable. | engine | Mineure | confirmé code-lu ; impact non vérifié empiriquement | nouveau |
| ENG-26 | Harnais de test `make_payload` déclare `version=1.0` — payload que `/generate` rejetterait (`pipeline.py:137`) ; suites OK car court-circuit via `build_schedule`. Contredit « contract-accurate ». | engine | Mineure | confirmé code-lu | nouveau |
| FRT-16 | Timeout client `TIMEOUT_MS=5 min` (`GenerateStep.tsx:23`) < budget solveur phase 1 (600 s adaptatif) + phase 2 → génération longue légitime déclarée « échec » pendant qu'elle tourne → relance → fantômes (croise FRT-09). | frontend | Moyenne | confirmé code-lu | nouveau |
| FRT-17 | Proxy dev `/engine` → :8000 (`vite.config.ts:30-33`) sans aucun usage — contredit la frontière « frontend NEVER calls engine » de CLAUDE.md ; config morte. | frontend | Mineure | confirmé code-lu | nouveau |
| FRT-18 | `detail`/`message`/`error` serveur bruts affichés tels quels dans les toasts (`errorMessage.ts:30-33`) — fuite de messages internes/anglais Symfony à l'utilisateur (croise SEC-08). | frontend | Mineure | confirmé code-lu | nouveau |
| DOC-14 | `specs/courantes/backend-inventory.md:120` ment sur `/api/register` (201+JWT depuis #153 = 202 générique + `/api/register/verify` absent) ; ignore aussi Reservation (#132), gate `/api/club_users` (#147), GenerationComplexityGuard (#156). | doc | Élevée | confirmé code-lu | nouveau |
| DOC-15 | `specs/courantes/frontend-spec.md:450` même mensonge register (« 201… Stocker token ») + omet `/verify-email/:token` ; stamp 07-03, ~70 PRs de retard. | doc | Élevée | confirmé code-lu | nouveau |
| DOC-16 | `docs/testing/testing-strategy.md` (doc « détail » CI, cible du renvoi CLAUDE.md:42) omet 4 jobs (`frontend`/`e2e`/`engine-perf`/`dependency-audit`) + 6 des 12 blocking + 4/14 guardrails — **moins exact que l'index qui pointe vers lui**. | doc | Élevée | confirmé code-lu | nouveau |
| DOC-17 | CLAUDE.md §4 omet le job `dependency-audit` (#154). | doc | Moyenne | confirmé code-lu | nouveau |
| DOC-18 | `backend/docs/TENANT.md:39` « Executes `SET LOCAL app.club_id` » contredit le code (`set_config(..., false)`, `TenantConnectionContext.php:30`) et sa propre ligne 8. | doc | Moyenne | confirmé code-lu | nouveau |
| DOC-19 | `engine-inventory.md:91` sans les bornes payload A10 (#156) ; politique de bump `CONTRACT_VERSION` (enveloppe changée sans bump) non écrite. | doc | Moyenne | confirmé code-lu | nouveau |
| DOC-20 | `specs/README.md:26` omet `module-matchs.md` ; `enregistrement-ffbb.md:12` décrit le register pré-A3 ; `testing-strategy.md:54` (`test_two_pass` « fallback ») trompeur ; `plan-de-test-post-36.md` doc point-in-time morte. | doc | Mineure | confirmé code-lu | nouveau (groupé) |
| UXC-07 | « Salle » vs « gymnase » : synonyme introduit dans le flux indispo (`DayDialog.tsx:183,202`) contre 61 « gymnase » ailleurs. | ux | Faible | confirmé code-lu | nouveau |
| UXC-08 | Tutoiement/vouvoiement mélangés dans `GenerateStep.tsx:133-164` (« Génère »/« ton » vs « Lancez »/« votre ») — flux le plus visible. | ux | Faible | confirmé code-lu | nouveau |
| A11Y-07 | `MonthCalendar.tsx:97` `aria-label="Jour {ISO}"` **écrase** le nom accessible calculé → le SR annonce l'ISO brut, perd vacances/fériés/événements. Flux exceptions dégradé au lecteur d'écran. | ux | Moyenne | confirmé code-lu | nouveau |
| A11Y-08 | Jour férié = point 6px `bg-destructive` (`MonthCalendar.tsx:107`) — info visuelle par la couleur seule (l'œil n'a qu'un point rouge sans forme). | ux | Mineure | confirmé code-lu | nouveau |
| A11Y-09 | Champs sans nom accessible dans `DayDialog` : `<select>` gymnase (`:201`), inputs placeholder-only (`:160,209,236`) — WCAG 4.1.2/3.3.2, seuls orphelins de l'app. | ux | Moyenne | confirmé code-lu | nouveau |

---

## Tableau de posture cybersécurité (A1–A18)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant (club_id) | **protégé** | RLS FORCE+policy 17 tables (`Version20260703120000.php:76-84` +4 migrations), listener prio-7 après firewall (`TenantFilterListener.php:43`), fail-closed (`NULLIF`→0 ligne) | — |
| A2 | Brute-force /login | **protégé** | `security.yaml:28` `login_throttling max_attempts:5` | — |
| A3 | Énumération de comptes | **protégé** | register 202 identique fresh/taken (`AuthController.php:408`), gate login-only, backfill (#153) | — |
| A4 | Falsification JWT | **protégé** | lexik RS256, clés hors repo (`git ls-files config/jwt` vide), TTL | — |
| A5 | Escalade de privilège | **partiel** | `/club_users` gaté (SEC-07/#147) MAIS `Plan`/`PriorityTier`/`Sport` writes non gatés | **SEC-14** |
| A6 | Mass-assignment / over-posting | **partiel** | 22 DTO fixés serveur MAIS `ClubInput` expose plan/quota | **SEC-15** |
| A7 | Injection SQL | **protégé** | Doctrine paramétré, GUC `set_config(...)` paramétré (`TenantConnectionContext.php:30`) | — |
| A8 | XSS stockée/reflétée | **protégé** | React escaping, 0 `dangerouslySetInnerHTML` ; résidu bas messages bruts (FRT-18) | — |
| A9 | CSRF | **protégé** | auth Bearer, aucun cookie ambiant d'écriture | — |
| A10 | DoS bombe de génération | **protégé** | cap pré-dispatch (`GenerateScheduleController.php:66-79`) + max_length engine (#156) ; résidu faux-block ENG-23 (pas un DoS) | — |
| A11 | Spam routes anonymes | **protégé** | rate-limit register/reset/verify (`rate_limiter.yaml`) | — |
| A12 | SSRF | **protégé** | URL engine fixe (`http://engine:8000`), engine réactif sans sortie réseau | — |
| A13 | Abus upload logo | **protégé** | validation MIME+taille, service `nosniff`+Content-Disposition (édition précédente, inchangé) | — |
| A14 | Fuite Mercure | **protégé** | `ClubTopicUpdate::private()` (`:27` `private: true`), hub non-anonyme | — |
| A15 | Exposition de secrets | **protégé** | `.env.prod` = template sans secret + `ProdSecretGuard` (Kernel) ; clés JWT hors repo | — |
| A16 | Erreurs verboses | **protégé** | `ProdSecretGuard` APP_DEBUG, pages génériques ; résidu SEC-08 (`ManualEdit:154`) | — |
| A17 | Clickjacking / en-têtes | **protégé** | CSP+HSTS+X-Frame `DENY`+nosniff (`docker/frontend/csp.conf`, `security-headers.conf`) + test | — |
| A18 | Dépendance vulnérable | **protégé** | 0 vuln (npm/composer/pip) + **gate CI bloquant** (#154, job `dependency-audit`) | — |

**Bilan cyber : 15 protégé · 3 partiel (A5/A6 = SEC-14/15, A10 résidu non-DoS) · 0 absent · 0 non vérifié.** Progrès net vs le 07-08 (10/8/0). Le seul trou neuf est **SEC-14** (écriture cross-tenant sur tables globales) — Élevée, contre-vérifiée.

---

## Détail par critère

### 1. Documentation — 82/100 (84)
**Forces.** CLAUDE.md reste un index exemplaire : 7 sondages (blocking-tests, CONTRACT_VERSION 2.0, tiers 60/180/600, RLS FORCE, listener prio-7, pivot 07-15, SOFT_LOCK_PENALTY) tous exacts au code. Le cycle openapi-snapshot/ADR fonctionne (#153/#156 reflétés le jour même aux endroits canoniques).
**Faiblesses.** Les inventaires de second rang **mentent** sur le contrat register (201→202/verify) que mes PRs A3/A18/A10 ont changé sans les mettre à jour (DOC-14/15/16/17/19). `testing-strategy.md` — le doc « détail » CI — est moins exact que l'index. Motif structurel : le maillon faible est toujours le doc canonique de **second rang**, jamais CLAUDE.md.

### 2. Pertinence du besoin — 88/100 (88)
Complétude utile stable ; viabilité toujours suspendue aux chantiers infra/RGPD non ouverts.

### 3a. Backend — 83/100 (86)
Correction+sécurité 82 · archi 82 · tests 88 · robustesse 84.
**Forces.** BCK-09 fermé (le #1 pré-GA), A3/A10 qualité confirmée (202 identique + verrou pessimiste + gate login-only ; cap pré-dispatch permanent-only), RLS réel fail-closed relu au SQL, lock Redis CAS atomique.
**Faiblesses.** **SEC-14 (Élevée)** — tables globales écrivables cross-tenant, la première fuit sur le solveur de tous les clubs. **SEC-15 (Moyenne, GA-bloquant)** — mass-assignment plan/quota. Résidus stables (SEC-08, BCK-07/10, SEC-13, BCK-04 817 l.).

### 3b. Engine — 81/100 (78)
Correction+sécurité 78 · archi 84 · tests 82 · robustesse 82.
**Forces.** **ENG-16 réellement corrigé et testé multi-séances** — la faiblesse récurrente de l'axe §7.1 recule ; ENG-19/20 corrigés, gate contrat testé, concurrence propre (solve hors event loop prouvé par test), snapshot+seed persistés = rejouabilité effective.
**Faiblesses.** Le motif « déclaré ≠ effectif » n'est pas éteint : **ENG-21 SOFT lock placebo** (pénalité 10 000 au vide), ENG-17 (coachId aveugle inchangé), ENG-24 (coach_overload en unités fausses), ENG-22 (UNKNOWN muet/trompeur), ENG-23 (cap A10 incohérent → faux-block). Régime multi-workers non déterministe **assumé et honnêtement encodé** dans les tests.

### 3c. Frontend — 72/100 (71)
Archi 70 · types 60 · data-fetching 78 · tests 86 · robustesse 66.
**Forces.** Dette a11y **soldée proprement** (modales `useModalA11y` + tests, gymnase textuel) ; 305 tests verts (+52), tsc strict 0 ; filet erreur global query/mutation.
**Faiblesses.** Dette structurelle inchangée voire aggravée : **FRT-15** (`types.gen.ts` 8816 l., 0 import, dup ×3), FRT-04 (pas de Mercure), FRT-08 (pas d'ErrorBoundary brandé), FRT-10 (bundle 639 KB), FRT-09/FRT-16 (fantômes + timeout client < solveur), SEC-16 (JWT localStorage).

### 4. Supply chain — 94/100 (92)
0 vuln + **gate CI bloquant** (#154) : l'audit n'est plus manuel. Reste : ni Dependabot ni renovate.

### 5. Performance — 90/100 (90)
smoke-solver COMPLETED (score 6487, ~s) ; gate perf CI stable.

### Infra / Prod-readiness / RGPD (partiels — malus transversal)
**INF-01** Sentry absent · **INF-02** backups inexistants · **INF-03** 0 `mem_limit` · config prod distincte inexistante · **RGPD-01** aucune purge/anonymisation. Quatre impasses identiques depuis 4 éditions — les vrais bloquants GA.

### UX (axes additifs — détail)
**Cohérence 76.** Refonte réussie : tokens `warning`/`success`, `tint()`/`VenueSwatch`/`empty-hint` partagés. Résidus : 2e `VenueSwatch` dans StructureSummary (UXC-01), 3 empty inline (UXC-03), tu/vous mélangés (UXC-08).
**Simplicité 77.** Jargon banni (UXS-02 ✓), flux courts et lisibles, erreurs actionnables. Reste UXS-03 (3 composants >400 l.).
**Inclusivité 68.** A11Y-01/03/05 corrigés (le critique couleur-seule mort). Nouveau maillon faible = le flux **exceptions** (MonthCalendar + DayDialog) : aria-label écrasant (A11Y-07), champs sans nom (A11Y-09), point férié couleur-seule (A11Y-08), texte <12px aggravé (A11Y-06). Score général UX = 68 = ce maillon.

---

## Avis global + axes priorisés

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| INF-02 backups + restauration testée · INF-01 Sentry · RGPD-01 purge/rétention · config prod distincte | **P0** | L | ⬜ (4 éditions) |
| **SEC-14** — retirer les écritures `Plan`/`PriorityTier`/`Sport` de l'API tenant (super-admin only) ou gate management + test | **P1** | S | ⬜ nouveau |
| **SEC-15** — champs plan/quota de `ClubInput` fixés serveur (retirés du groupe write) | **P1** | S | ⬜ nouveau |
| **ENG-21** — consommer la pénalité SOFT lock dans l'objectif OU retirer SOFT de l'API si non implémenté | P1 | M | ⬜ nouveau |
| **ENG-23** — aligner le cap contraintes (compter l'étendu côté backend, ou plafonner le brut côté engine) | P1 | S | ⬜ (résidu code-review A10) |
| **FRT-16** — timeout client ≥ budget solveur (650 s) + idempotence saison (FRT-09) | P1 | S | ⬜ |
| DOC-14/15/16 — inventaires backend/frontend + testing-strategy au contrat register 202/verify | **P2** | S | ⬜ (dette de mes PRs) |
| A11Y-07/09 — nom accessible sur les jours calendrier + champs DayDialog | P2 | S | ⬜ nouveau |
| FRT-15 (trancher types.gen.ts) · FRT-08 (ErrorBoundary brandé) · FRT-04 (Mercure) · UXS-03 | P2 | M | ⬜ |
| ENG-16 « uniquement » vrai interdit multi-séances | ~~P1~~ | — | ✅ #126 (testé `test_constraint_fixes.py:185`) |
| BCK-09 seasonId réassigné qu'à la création | ~~P1~~ | — | ✅ (`AbstractStateProcessor.php:170`) |
| A11Y-01/03 + UXC-02 (gymnase textuel, modale unifiée) | ~~P1~~ | — | ✅ #157 + refonte UX |
| UXC-05 tokens warning/success · UXC-03 EmptyState partagé | ~~P2~~ | — | ✅ (`index.css:30`, `empty-hint.tsx`) |

## Features intéressantes à développer (valeur/effort)
- **Temps réel Mercure côté front** (FRT-04) : backend déjà durci ; un `EventSource` supprime polling + batterie (FRT-14 résidu). Ratio élevé.
- **Super-admin / RBAC réel** : débloque SEC-14/15 proprement (rôles > « tout membre = admin ») et prépare la facturation.
- **Export/backup club** (INF-02) : `pg_dump` planifié + restauration testée = P0 le plus lourd débloqué.

## Annexe méthodologie
**Exécuté vs statique.** Exécuté : `tsc` (0), `vitest` (305 verts), `npm/composer/pip audit` (0 vuln), `smoke-solver` (COMPLETED 6487), `docker compose ps`, `git ls-files`, greps ciblés. Statique (lecture seule) : backend/engine/doc/UX — tous les findings `confirmé code-lu` sur lignes citées. Contre-vérifiés à la main : SEC-14 (Étape 3, confirmé), BCK-09 (réfuté comme ouvert = corrigé), ENG-16 (corrigé), cyber A1-A18 (spot-checks directs).
**Confiance par axe** (clé = degré d'exécution) : Frontend **élevée** (tsc+vitest lancés), Supply chain **élevée** (audits lancés), Performance **élevée** (smoke lancé), Cyber **élevée** (checks directs + contre-vérif), Backend/Engine/Doc/UX **moyenne** (lecture de code, non exécuté). Aucune note à confiance faible.
**Limites.** Parcours navigateur UX non exécuté (MCP chrome indispo) → UX-Simplicité/Inclusivité `partiel`, contraste `text-warning` estimé non mesuré. Occurrences réelles non observées : rangées legacy `forcedDays` (ENG-16b), UNKNOWN solveur (ENG-22), faux-block ENG-23 (scénario chiffré). Coûts/scalabilité : non couvert (aucune donnée facturation/infra prod).
**Auto-question de biais.** Sur-poids probable de ce qui se **greppe** (comptes hex, classes hors-palette, aria-label) vs sous-poids de ce qui demande l'**exécution** (contraste réel light/dark, comportement solveur sur gros club, latence réseau front) — d'où le `partiel` assumé sur UX-dynamique et l'absence de mesure de charge. Donnée manquante systématique : l'**infra prod** (backups/observabilité/coûts) reste jugée sur son absence dans le repo, jamais sur un environnement déployé — verdict `absent` fiable, mais dimensionnement des chantiers (effort L) approximatif.

# Backlog priorisé — effort × impact (cap commercialisation mi-2027)

> **Statut** : vue de pilotage, régénérée le 2026-07-11 (SHA `main` au moment de l'écriture).
> **Nature** : `roadmap.md` est la **carte** (tout ce que la vision contient) ; **ce fichier est la coupe priorisée** — quoi faire, dans quel ordre, à quel coût. Un item livré **quitte** ce fichier (il reste tracé dans `roadmap.md` / specs courantes).
> **Source** : agrégat des specs `evolution/`, `docs/technical-debt.md`, et des audits `specs/audit/` (dernier : 2026-07-10, global **79/100**, 4 impasses GA ouvertes).

## Légende

**Impact** 🔴 bloque la vente / l'intégrité des données · 🟠 fort levier (débloque plusieurs sujets, ou différenciateur) · 🟡 valeur produit ciblée · ⚪ polish / dette.
**Effort** **S** ≤ 1 PR · **M** 2-3 PR · **L** lot phasé 4-6 PR · **XL** recherche + gros lot.

---

## P0 — Bloquants GA & intégrité (à solder AVANT de vendre)

Les « 4 impasses GA » sont ouvertes **depuis 4 éditions d'audit** — aucune n'est commencée.

| # | Sujet | Impact | Effort | Pourquoi maintenant | Dépend de |
|---|-------|:---:|:---:|---|---|
| P0-1 | **RGPD socle** — purge/rétention effective, audit trail, droit à l'effacement/export. Couvre aussi DP1 (contacts président/correspondant FFBB = données perso). | 🔴 | L | Vente FR/UE **illégale** sans. Rien construit ; `app:seasons:purge` existe mais manuel/partiel. | — |
| P0-2 | **Config prod** — profil prod distinct, secrets managés, `APP_ENV=prod`/`DEBUG=0` durci, healthchecks, limites RAM appliquées. | 🔴 | M | Aucune config prod n'existe → pas déployable proprement. | — |
| P0-3 | **Backups PostgreSQL** — `pg_dump` planifié + restauration testée. | 🔴 | S | Zéro backup = perte totale sur incident. Trivial techniquement, impardonnable si absent. | P0-2 |
| P0-4 | **Observabilité** — Sentry (erreurs) + logs structurés sans PII + métriques. | 🔴 | M | Zéro visibilité prod ; un incident client = aveugle. | P0-2 |
| ~~P0-5~~ | **Vol de créneau inter-version** — ✅ **livré 2026-07-11**. L'import matche par **placement** (plus par id global) et scope l'id des nouvelles lignes par schedule → l'import ne vole plus le créneau HARD d'une version sœur. Backend seul, sans migration (engine/contrat/golden inchangés). Voir planning-versions §D3quater. | 🔴 | ~~M~~ | Fait. | — |

## P1 — Enablers à fort levier (débloquent plusieurs features)

| # | Sujet | Impact | Effort | Débloque | Note |
|---|-------|:---:|:---:|---|---|
| P1-1 | **Rôles non-admin + modèle de permissions** (`ClubUser.role` hardcodé `admin` aujourd'hui) | 🟠 | L | self-service coach · collecte vacances (P2-1) · salle convivialité · comptes coach | `isManagementRole` existe comme amorce ; voters à câbler partout |
| P1-2 | **Console superadmin — lot socle SA0** (accès `admin` Doctrine + audit + bannière) | 🟠 | L | crons vacances/purge · **refresh FFBB manuel** · métriques · reconcile stuck · impersonation lecture | spec'd SA0→SA5 (#175) ; attaquer SA0 d'abord, le reste phasé |
| P1-3 | **Bridage freemium Découverte** | 🟠 | M | monétisation (le gate du plan gratuit) | doc'd, 0 code ; anti-abus par identité = hors v1 (assumé) |

## P2 — Différenciateurs & complétion des chantiers en cours

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P2-1 | **Plan de vacances éditable + collecte coach (P1 structure, P2 collecte)** | 🟠 | L | **le différenciateur commercial** ; collecte = lien tokenisé sans login (patron reset-pwd). P2 gagne à ce que P1-1 existe mais pas bloqué. |
| P2-2 | **Boucle d'ajustement — « corriger sur place »** (glisser une équipe dans un créneau vide) | 🟠 | M | Le fork « naviguer » est fait (#180) ; « réparer » transforme le rapport en outil. Même primitive que la grille de réservation. |
| P2-3 | **Versions D4 — « Travailler sur cette version »** + savepoint auto de l'état vivant | 🟡 | M | Moitié manquante de la décision 5 des versions (D1-D3 livrés). |
| P2-4 | **Compte démo** | 🟡 | S/M | Onboarding/vente ; besoin spécifié ([`compte-demo.md`](compte-demo.md)), 0 code. |

## P3 — Complétude modules (valeur ciblée, pas urgent)

| # | Sujet | Impact | Effort | Note |
|---|-------|:---:|:---:|---|
| P3-1 | **Matchs — reste palier A** : volet joueur (`CoachPlayerMembership`), `Team.preferredMatchWindow`, envelope HARD jour/coup d'envoi | 🟡 | M | Paliers B (dérogation/trajet/annuaire adverse) / C plus tard |
| P3-2 | **Overlays cockpit** — reste ouvert : DayDialog période `custom` **générante** (aujourd'hui 422, mitigé par bouton désactivé) | 🟡 | M | `cutoff` = **✅ livré** (reliquats 2026-07-06) ; `mutualisation` moteur = **❌ abandonné** (résolu via réservation, salle divisible + capacité 2) ; **versions d'overlay = ✅ livré (2026-07-11)** — cf. P3-5 |
| P3-3 | **Modèle « templates → occurrences »** | 🟡 | L | Fondation absente ; débloque « éditer baseline ⇒ répercuter sur secondaires » (cascade reportée) |
| P3-4 | **Enregistrement FFBB** (légitimité / anti-squatting du code club) | 🟡 | M | spec'd (#145) ; A5/A6 déjà fermés |
| P3-5 | **Versions — diff/comparaison · restaurer une ARCHIVED** | 🟡 | L | hors périmètre D assumé. *(**versions d'overlay = ✅ livré 2026-07-11**, planning-versions §D3ter)* |
| P3-6 | **`solver_metrics` — persistance + partition + purge 6 mois** | 🟡 | M | déjà calculées (`SolverMetricsMapper`), pas persistées ; alimente la console superadmin |
| P3-7 | **Import équipes Excel — UI wizard** | ⚪ | S | backend `FfbbExcelImporter` existe ; l'API FFBB doit à terme remplacer l'import manuel |

## P4 — Dette & polish (avant GA, par lots opportunistes)

| # | Sujet | Impact | Effort | Réf |
|---|-------|:---:|:---:|---|
| P4-1 | **FRT-02** — erreur de query avalée → vide trompeur (pas d'`isError`/retry côté UI) | 🟡 | S | audit 07-10 (Élevée, partiel) |
| P4-2 | **ENG-17 + ENG-24** — `coachId` de sortie vient des seuls slotTemplates → diagnostics coach (overload/double-booking) **inertes** pour les coachs par contrainte TEAM_COACH ; `coach_overload` confond les unités | 🟡 | M | audit (Moyenne × 2) |
| P4-3 | **FE1 / composants wizard > 400 lignes** (3 fichiers 552/498/413) | ⚪ | M | technical-debt FE1 / UXS-03 |
| P4-4 | **FE2 — registre tu/vous incohérent** (wizard tutoie, cockpit/validation vouvoie) | ⚪ | S | trancher un registre app-wide + sweep |
| P4-5 | **FRT-18 / SEC-08 résiduel** — messages serveur bruts (Symfony/anglais) affichés en toast + `ManualEditController:154` | ⚪ | S | audit (Mineure) |
| P4-6 | **Bundle unique 639 KB** — pas de code-splitting | ⚪ | S | audit (perf front) |
| P4-7 | **B4 — publish Mercure dupliqué** entre handlers | ⚪ | S | technical-debt B4 |
| P4-8 | **BCK-10** — `requireActiveAdmin()` résout l'adhésion sans `clubId` (non déterministe multi-club ; pas de fuite grâce à RLS) | ⚪ | S | audit (Faible) |
| P4-9 | **Radar « jour férié » à retravailler** (cibler quels fériés + texte d'impact) | ⚪ | S | décision produit |
| P4-10 | **#3b — désactiver « Régénérer » si rien n'a changé** depuis la dernière génération | ⚪ | M | besoin de détection de changement fiable |

## Parking (idées gardées, non cadrées)

- **Reverse-engineering des contraintes** — déduire les contraintes d'un planning saisi à la main (idée club #92). Fort attrait, effort **XL**, aucun cadrage.
- **Réservation salle de convivialité** (V2 « club hub », self-service coach) — triviale en soi, bloquée sur P1-1 (comptes/rôles).

---

## Ordre d'attaque conseillé

1. **P0 en premier** — sans RGPD + prod + backups + observabilité, l'app n'est pas vendable, quel que soit le produit. P0-5 (intégrité versions) en parallèle car structurant.
2. **P1-1 (rôles)** ensuite — c'est le verrou qui débloque le plus de valeur aval (P2-1, salle convivialité, comptes coach).
3. **P1-2 (superadmin SA0)** — solde d'un coup toute la colonne « manuel aujourd'hui » (crons, refresh FFBB, purge, métriques).
4. Puis **P2** (différenciateurs) selon l'appétit commercial, **P3/P4** par lots opportunistes.

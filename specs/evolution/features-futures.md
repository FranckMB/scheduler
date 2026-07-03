# Features Futures — Traçabilité des features non-implémentées

Last verified @ 2026-06-30

> Document vivant de traçabilité des features non incluses dans le plan critique MVP.
> Source préservée : `.omo/drafts/features-futures.md` (ne pas modifier — c'est l'instantané d'origine).

---

## Méthodologie

- **Source** : `.omo/drafts/features-futures.md` (123 lignes, 21 features inventoriées)
- **Vérification de statut** : inspection du codebase (`backend/src/`, `backend/migrations/`, `frontend/src/`, `frontend/tests/`) + `.omo/boulder.json` pour les travaux complétés
- **Statuts possibles** : `Non commencé` / `En cours` / `Délégué futur plan` / `Abandonné`
- **Règle** : aucune feature inventée au-delà du draft ; le draft n'est pas modifié

---

## P1.5 — À implémenter après le MVP strict

### 1. Régénération partielle guidée

- **Statut** : Non commencé
- **Service** : `PartialRegenService`
- **Description** : Lorsqu'une salle ferme ou un coach devient indisponible, identification automatique des slots affectés et régénération partielle
- **État actuel** : Service non créé, table `schedule_slot_occurrences` absente. Aucune migration trouvée.
- **Référence plan** : §6.2 (PartialRegenService), §14.2

### 2. Périodes d'exception + alertes J-14

- **Statut** : Non commencé
- **Tables** : `period_templates`, `period_template_slots`, `period_assignments`, `period_coach_responses`
- **Description** : Vacances scolaires, coupures, mutualisations. Alertes automatiques J-14/J-7/J-3
- **État actuel** : Tables absentes, pas de Symfony Scheduler. Aucune migration trouvée.
- **Référence plan** : §3.6, §8.2

### 3. Gestion saisons + wizard transition hybride

- **Statut** : Non commencé
- **Service** : `SeasonTransitionService`
- **Description** : Transition annuelle 5 étapes (pré-rempli depuis saison précédente)
- **État actuel** : Non implémenté. Service non trouvé dans `backend/src/`.
- **Référence plan** : §9.2, §9.3

### 4. Édition manuelle avancée (ManualEditService)

- **Statut** : En cours
- **Service** : `ManualEditService`
- **Description** : Dialogue post-édition avec création de contraintes permanentes, tracking `source_occurrence_id`
- **État actuel** : Service de base créé (`backend/src/Service/ManualEditService.php`, 141 lignes) + `ManualEditController.php`. Manquent : tracking `source_occurrence_id` (0 occurrence) et création de contraintes permanentes (0 occurrence). Version basique opérationnelle, version avancée non commencée.
- **Référence plan** : §6.2 (ManualEditService), §11.4

### 5. Matrice temps trajets + passerelles

- **Statut** : Non commencé
- **Tables** : `venue_travel_times`, `team_links`, `category_passway_rules`
- **Description** : Temps de trajet entre salles, passerelles entre équipes (U15→U18), hiérarchie globale
- **État actuel** : Tables absentes. Contraintes OR-Tools en stub (`travel_feasibility`, `required_bridge`). Aucune migration trouvée.
- **Référence plan** : §3.4, §4.1 (contraintes 1.4 et 1.9)

### 6. Audit trail complet

- **Statut** : Non commencé
- **Table** : `audit_logs`
- **Service** : `AuditListener`
- **Description** : Append-only logs, purgé après 1 an (RGPD), écrit via Messenger async
- **État actuel** : Table absente, service non créé. Aucune migration trouvée.
- **Référence plan** : §3.2, §6.2 (AuditListener)

### 7. Solver metrics

- **Statut** : Non commencé
- **Table** : `solver_metrics`
- **Description** : Métriques performance par génération, partitionné par mois, purgé après 6 mois
- **État actuel** : Table absente. `GenerateScheduleHandler` référence `solver_metrics` comme clé du résultat engine (lecture), pas comme table DB. Feature DB non commencée.
- **Référence plan** : §3.5

### 8. Schedule slot occurrences (calendrier réel J+14)

- **Statut** : Non commencé
- **Table** : `schedule_slot_occurrences`
- **Description** : Réalité calendaire (dates exactes, exceptions, annulations), fenêtre glissante J+14
- **État actuel** : Table absente. Aucune migration trouvée.
- **Référence plan** : §3.5

### 9. Cache invalidation ciblée (Phase 2)

- **Statut** : En cours
- **Fichier** : `CacheInvalidationListener.php`
- **Description** : Actuellement écoute TOUTES les entités. Phase 2 : cibler uniquement Venue, Coach, Team, Schedule
- **État actuel** : Stub Phase 1 présent (`backend/src/EventListener/CacheInvalidationListener.php`). Le fichier indique lui-même : « listens for all entities; in Phase 2 it will target only Venue, Coach, Team, Schedule ». Phase 2 non implémentée.
- **Référence plan** : §6.2 (CacheInvalidationListener)

### 10. ClubTimeService

- **Statut** : Non commencé
- **Service** : `ClubTimeService`
- **Description** : Conversions UTC ↔ timezone locale club
- **État actuel** : Non créé. Service non trouvé dans `backend/src/`.
- **Référence plan** : §6.2

### 11. ScheduleDiffService

- **Statut** : Non commencé
- **Service** : `ScheduleDiffService`
- **Description** : Diff lisible entre deux snapshots de `slot_templates`
- **État actuel** : Non créé. Service non trouvé dans `backend/src/`.
- **Référence plan** : §6.2

---

## P2 — Évolutions

### 12. Tests frontend (React Testing Library + Vitest)

- **Statut** : En cours
- **Description** : Tests composants critiques, mocking MSW
- **État actuel (re-vérifié 2026-07-03)** : suite Vitest + RTL + MSW **vivante et verte** — 13 fichiers, 52 tests (`vitest run`) : libs pures (grid, ranking), stores, pages (PlanningPage, WizardPage, Login/Register). Reste : composants non couverts (SlotDetail, DiagnosticsPanel, drag&drop effectif).

### 13. E2E Playwright

- **Statut** : En cours (régression)
- **Description** : Onboarding → génération → export PDF
- **État actuel (re-vérifié 2026-07-03, exécution réelle)** : les 4 specs historiques ont **disparu** (frontend reconstruit) — il ne reste que `tests/e2e/auth.spec.ts` (3 tests), et la suite est **rouge : 2/3 échouent** (assertion `getByText(/bonjour/i)` périmée, l'UI n'affiche plus « Bonjour »). À faire : réparer auth.spec + réécrire le parcours complet wizard→génération→work-loop (audit FRT-05/FRT-07).

### 14. App mobile React Native

- **Statut** : Non commencé
- **Description** : Consultation + exceptions rapides + notifications
- **État actuel** : Mentionné dans stack mais pas de code. Aucun répertoire React Native trouvé.

### 15. Super-admin dashboard

- **Statut** : Non commencé
- **Description** : Dashboard gestion multi-clubs
- **État actuel** : Non implémenté.

### 16. Stats & analytics

- **Statut** : Non commencé
- **Description** : Taux remplissage, heures coach/semaine
- **État actuel** : Non implémenté.

### 17. Notifications coaches

- **Statut** : Non commencé
- **Description** : Email PDF, push mobile
- **État actuel** : Non implémenté.

---

## V2 — Futures

### 18. Multi-sport

- **Statut** : Non commencé
- **Description** : Handball, gym, volley...
- **État actuel** : Basket uniquement.

### 19. Import FFBB matchs

- **Statut** : Non commencé
- **Description** : Import calendrier compétitions FFBB
- **État actuel** : Non implémenté.

### 20. Connecteurs mairie

- **Statut** : Non commencé
- **Description** : Connexion API réservation gymnases municipaux
- **État actuel** : Non implémenté.

### 21. Planning matchs

- **Statut** : Non commencé
- **Description** : Génération planning entraînements + matchs
- **État actuel** : Non implémenté.

---

## Synthèse des statuts

| Statut | Count | Features |
|--------|-------|----------|
| Non commencé | 17 | 1, 2, 3, 5, 6, 7, 8, 10, 11, 14, 15, 16, 17, 18, 19, 20, 21 |
| En cours | 4 | 4 (ManualEditService basique), 9 (CacheInvalidation stub Phase 1), 12 (vitest config sans tests), 13 (4 specs Playwright non validés) |
| Délégué futur plan | 0 | — |
| Abandonné | 0 | — |

---

## Source préservée

Le draft original `.omo/drafts/features-futures.md` est préservé intact comme instantané de référence. Ce document est la version vivante : les statuts et états actuels sont mis à jour lors des vérifications.

## Dernier plan critique exécuté

- **Plan** : `.omo/plans/mvp-critique.md`
- **Scope** : Export PDF + Diagnostics métier + Wizard refondu + Import Excel + Création compte + Édition manuelle
- **Date** : 2026-06-10

---

## Backlog — PREFERRED TIME (contrainte solveur, engine)

- **Statut** : non implémenté. Le solveur gère `PREFERRED DAY` mais pas `PREFERRED TIME` (préférence d'un créneau horaire, pas seulement d'un jour).
- **Sites code** (les deux pointent ici) :
  - `engine/app/solver/objective.py` — bonus soft, branche `family != "DAY"`.
  - `engine/app/solver/constraints.py` — `rule_type == "PREFERRED" and family == "TIME"`.
- **Besoin** : prendre en compte une fenêtre/horaire préféré comme terme soft de l'objectif (analogue au `add_preferred_day_bonus` existant).

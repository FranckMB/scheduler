# RGPD — registre des traitements & mécanismes

> **Statut** : socle technique **livré** (P0-1, lots 1-5, 2026-07-11). Les textes juridiques
> (CGU, politique de confidentialité, DPA) sont des **placeholders structurés**
> (`frontend/src/features/legal/PrivacyPage.tsx`) à faire rédiger avant commercialisation.
> Ce fichier est le **registre des traitements** (art. 30) côté ingénierie : inventaire → base
> légale → durée → mécanisme de purge → où c'est testé.

## 1. Les deux casquettes

| Casquette | Données | Qui exerce les droits |
|-----------|---------|----------------------|
| **Responsable de traitement** | comptes `User` (identité gestionnaire, email, hash) | l'utilisateur, en self-service (Profil) |
| **Sous-traitant** | données du club (`Coach`, contacts dirigeants, plannings) | le **club** (responsable), via les outils de l'app ; DPA dans les CGU |

## 2. Registre des traitements

| Traitement | Données | Base légale | Durée | Purge (mécanisme) | Testé par |
|------------|---------|-------------|-------|-------------------|-----------|
| Compte gestionnaire | User : email, prénom/nom, hash | contrat | activité + 24 mois (préavis à 23) | `app:users:purge-inactive` (cron horaire) — anonymisation | `InactiveUsersRetentionTest` |
| Compte jamais vérifié | User non vérifié + token | contrat (précontractuel) | 7 jours | `app:users:purge-unverified` (cron) | — |
| Données du club | Coach (email/tél), équipes, plannings, contraintes | contrat (via le club) | saison courante + N-1 | `app:seasons:purge` (cron, grâce 30 j post-bascule) ; suppression manuelle par le club (cascade, auditée) | `AccountErasureTest` (d) |
| Effacement de compte | anonymisation immédiate + purge club orphelin | obligation légale (art. 17) | grâce 30 j (annulable, revalidée) | `DELETE /api/me` → `app:clubs:purge-erased` — **l'identité publique FFBB du club survit** | `AccountErasureTest` |
| Portabilité | export JSON compte / workspace club | obligation légale (art. 20) | à la demande (10/h par user) | `GET /api/me/export`, `GET /api/club/export` (management) | `RgpdExportTest` |
| Contacts officiels FFBB | président/correspondant (nom/tél/email publiés par la FFBB) | **intérêt légitime** (organisation des rencontres, annuaire adverse) | tant que publiés (refresh FFBB) ; **survivent** à la purge du club | opposition : exclusion du refresh (à outiller avec l'annuaire) | revue DP1 |
| Journal d'audit | actions sensibles — **ids uniquement, jamais de PII** | intérêt légitime (accountability art. 5.2) | 12 mois | `app:audit:purge` (connexion admin — append-only DB pour le runtime) | `AuditTrailTest` |
| Consentement | `termsAcceptedAt` + `termsVersion` au register | obligation légale (preuve) | vie du compte (anonymisé avec lui) | — | `ConsentTest` |

## 3. Mécanismes clés (pointeurs code)

- **Anonymisation** : `AccountErasureService` — email → `deleted-{id}@anonymized.invalid`, hash aléatoire, transactionnel, memberships désactivés club-par-club sous GUC RLS.
- **Purge club différée** : `Club.erasureScheduledAt` (+30 j) → `PurgeErasedClubsCommand` (revalide à l'échéance, auto-annule si un membre actif est revenu). Fiche FFBB épargnée, état d'abonnement vidé (`ErasedClubPurger`).
- **Win-back** : ré-inscription sur l'ARA d'un club sans membre actif = reprise directe (owner), re-seed si purgé (`AuthController::verifyEmail`).
- **Activité** : `LoginSuccessListener` (throttlé 1 écriture/jour, best-effort — l'authenticator JWT déclenche l'événement à chaque requête).
- **Audit** : `AuditTrail` (INSERT DBAL + SAVEPOINT, no-PII) ; append-only tenu par la DB (aucune policy UPDATE/DELETE) ; lecture = future console superadmin (SA1).
- **Consentement** : requis au register (400 sinon, validation payload-only = enumeration-safe A3) ; version des textes = `AuthController::TERMS_VERSION`.

## 4. Doctrine backups (à implémenter en P0-3)

Les sauvegardes contiennent des données effacées : purge **naturelle par rotation 30 j**, **aucune
restauration sélective** de données effacées (une restauration complète post-incident ré-exécute
les purges au cron suivant — les champs `anonymizedAt`/`erasureScheduledAt` restaurés re-déclenchent
les mécanismes). À graver dans la config de backup P0-3.

## 5. Logs & PII

- Backend : sweep 2026-07-11 — aucun email/nom dans les `logger->…` (codes FFBB publics, ids,
  messages d'exception). Règle : **jamais d'email/nom dans un log** ; les ids suffisent.
- Engine : les access-logs uvicorn contiennent des IPs (données perso) — rétention/config à poser
  dans le profil prod (**P0-2**), noté ici pour ne pas le perdre.
- Mercure : payloads `{status, score, unplaced, warnings}` — pas de PII.

## 6. Reste à faire (hors P0-1)

- Textes juridiques finaux (CGU, politique, DPA) — fondateur/juriste, avant commercialisation.
- Mécanisme d'opposition outillé pour les contacts FFBB (avec l'annuaire adverse, roadmap matchs B).
- Backups + config prod (P0-2/P0-3) ; alerting cron (P0-4) — les purges tournent sous `|| true`.

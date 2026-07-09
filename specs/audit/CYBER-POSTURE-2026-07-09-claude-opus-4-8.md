# Posture cybersécurité ClubScheduler — 2026-07-09

> Évaluation **non destructive** (lecture code + config) de la protection contre les
> attaques les plus probables pour ce projet (liste `A1–A18` du skill `/audit`,
> Étape 2 quinquies). Modèle : claude-opus-4-8. Branche : `feat/audit-cybersecurity-axis`.
> Verdicts : `protégé` / `partiel` / `absent` / `non vérifié`. **Non commité** — snapshot pour décision.

## Tableau de posture

| # | Attaque | Verdict | Preuve (fichier:ligne) |
|---|---------|---------|------------------------|
| A1 | Accès cross-tenant (club X → club Y) | ✅ protégé | `TenantFilterListener.php:88-102,119-136` (header spoofé → 403) ; RLS FORCE `Version20260703120000.php:74-92` ; IDOR `AbstractStateProcessor.php:163-165` ; tests RlsIsolation/TenantIsolation/SeasonIsolation |
| A2 | Brute-force / credential stuffing login | ✅ protégé | `security.yaml:34-35` `login_throttling max_attempts:5` (IP+username) ; SEC-11 `ApiRateLimitSubscriber.php:60` |
| A3 | Énumération de comptes | ⚠️ partiel | reset OK (`PasswordController.php:44-61`, toujours 200) ; **register fuit** `409 'Email already registered'` `AuthController.php:86-88` |
| A4 | Falsification JWT | ✅ protégé | RS256, clés hors repo (`git ls-files config/jwt` vide), firewall stateless `security.yaml:22,28`, TTL 1 h |
| A5 | Escalade de privilège | ⚠️ partiel | gate SEC-07 large (`ManagementAccessGuard.php:45-61`) **mais** `ClubUserResource.php:20-26` Post/Put/Delete **sans `security`** → un membre peut poser `role` |
| A6 | Mass assignment / over-posting | ⚠️ partiel | clubId/seasonId server-set (`AbstractStateProcessor.php:135-139`) **mais** `ClubUserInput.php:16-21` expose `role`/`isActive` en write **non gated** (même racine que A5) |
| A7 | Injection SQL | ✅ protégé | GUC `set_config('app.club_id', ?, …)` en bound param `TenantConnectionContext.php:29-32` ; QueryBuilder paramétré partout ; engine = zéro SQL |
| A8 | XSS stockée/reflétée (+ SVG logo) | ✅ protégé | aucun `dangerouslySetInnerHTML`/`innerHTML` dans `frontend/src` ; SVG rejeté à l'upload `ClubLogoController.php:28-32` |
| A9 | CSRF | ✅ protégé | auth `Bearer` stateless, aucun cookie ambiant (`security.yaml:19,25` ; `client.ts:17-20`) |
| A10 | DoS bombe de génération | ⚠️ partiel | bornes taille (`nginx 20m`) + timeout 600 s + sémaphore 1, **mais aucun cap de complexité** (`input_schema.py:126-131` listes sans `max_length`, pas de précheck contrôleur) |
| A11 | Spam routes anonymes (register/reset) | ✅ protégé | limiters par IP `5/15min` (`AuthController.php:56-59`, `PasswordController.php:35-37`) + throttle reset par compte |
| A12 | SSRF | ✅ protégé | URL engine constante `EngineClient.php:17` ; logo = fichier uploadé (pas de fetch URL) ; imports = CLI only |
| A13 | Abus upload logo | ✅ protégé | MIME réel + taille 500 Ko + allowlist `ClubLogoController.php:61-67` + SEC-07 ; chemin par UUID |
| A14 | Fuite Mercure (SSE cross-club) | ⚠️ partiel | hub durci (pas d'anonyme, CORS allow-list, secret dédié `docker-compose.yml:255-263`) **mais** updates publiés **PUBLIC** `ScheduleProgressPublisher.php:36,68` ; risque pratique faible (aucun JWT abonné émis, front polle) |
| A15 | Exposition de secrets | ⚠️ partiel | clés JWT/`.env.local` non trackées ✅ **mais** `APP_SECRET` concret commité `.env.dev:3`/`.env.test:2` + mots de passe dev par défaut |
| A16 | Erreurs verboses en prod | ⚠️ partiel | aucun défaut commité `APP_ENV=prod`/`APP_DEBUG=0` (`.env:1-2` debug ON) — sûr seulement via `.env.local` non tracké ; engine masque bien ses traces |
| A17 | Clickjacking / en-têtes de sécurité | ⚠️ partiel | CORS restreint ✅ **mais** aucun CSP/HSTS/X-Frame-Options/X-Content-Type-Options (`nelmio/security-bundle` absent, nginx sans `add_header`) |
| A18 | Dépendance vulnérable | ⚠️ partiel | Dependabot 4 écosystèmes ✅ **mais** aucun gate CI `composer/npm/pip audit` (`ci.yml` sans étape audit) |

**Bilan : 10 protégé · 8 partiel · 0 absent · 0 non vérifié.** Le cœur multi-tenant (A1), l'auth (A2/A4), l'injection (A7/A8), CSRF (A9), SSRF (A12), upload (A13) sont **solides et testés**. Les 8 `partiel` sont des durcissements avant commercialisation, aucun trou critique **actif** à ce jour.

## Remédiations priorisées

### P0 — à traiter en premier
- **A5+A6 (même racine)** : `POST/PUT/DELETE /api/club_users` n'a ni gate management ni champs `role`/`isActive` server-controlled. Bénin aujourd'hui (tout membre = `admin` via `AuthController.php:344`) mais devient un **chemin d'auto-escalade actif dès l'introduction du rôle coach non-management**. → ajouter `security` sur `ClubUserResource` + `requiresManagementRole()` sur son processor + retirer `role`/`isActive` des champs write (fixés serveur), + test `ManagementRoleTest` sur `/api/club_users`.
- **A17** : ajouter les en-têtes de sécurité (CSP, HSTS, X-Frame-Options `DENY`, X-Content-Type-Options `nosniff`) via `nelmio/security-bundle` ou `add_header` nginx.

### P1
- **A10** : borne de complexité (`max_length` sur les listes du payload engine + précheck `n_teams × n_venues` côté contrôleur avant dispatch).
- **A18** : ajouter `composer audit` / `npm audit --omit=dev` / `pip-audit` en **gate CI** bloquant.
- **A3** : uniformiser la réponse register (ne pas révéler l'existence d'un email — 200 générique ou message neutre).
- **A15** : roter l'`APP_SECRET` commité (`.env.dev`/`.env.test`) et documenter le profil prod ; **A16** : livrer/documenter `APP_ENV=prod`+`APP_DEBUG=0`.

### P2 (durcissement défense-en-profondeur)
- **A14** : passer les updates Mercure en `private` (avant d'émettre un jour des JWT abonnés).
- **A8/A13** : sur la route de service logo, `X-Content-Type-Options: nosniff` + `Content-Disposition` + clamp du `Content-Type` sur l'allowlist (`ClubLogoController.php:111-114`).

## Méthodologie
4 investigateurs parallèles read-only (tenant/authz · auth · injection/xss/upload · infra/dos/ssrf/secrets), chaque verdict re-vérifié au code. Aucun test offensif actif. Snapshot au SHA de la branche `feat/audit-cybersecurity-axis`.

# Console superadmin — authentification, télémétrie et API de supervision

> **État courant (2026-07-16)** : SA0, SA1 et le premier incrément backend SA2 sont livrés. Les sondes de santé, l'écran de supervision React et les actions cross-tenant restent dans
> [`../evolution/console-superadmin.md`](../evolution/console-superadmin.md).

Le frontend React SA0 est désormais livré sur `/admin` : client HTTP à cookie de session
séparé, store admin en mémoire uniquement, login mot de passe/TOTP, garde de route, shell
de console et logout CSRF. Il ne lit ni ne persiste le JWT club.

## Identité et frontière de sécurité

- `SuperAdmin` est une identité globale séparée de `User` et `ClubUser` ; elle ne porte
  aucun club, rôle tenant ou saison.
- Le firewall Symfony stateful `admin` couvre exclusivement `/api/admin/**` et utilise
  `SuperAdminProvider`. Un JWT club présenté à cette surface reste anonyme et reçoit 401.
- Les identités et l'audit sont lus/écrits par la connexion Doctrine `admin`, seule porte
  autorisée à franchir RLS. Le rôle runtime `app_user` n'a aucun privilège sur
  `super_admin` ou `admin_audit_log`.

## Parcours d'authentification

1. `POST /api/admin/auth/password` reçoit `{email,password}`. Une réponse 200 crée un
   challenge de session de cinq minutes mais n'authentifie pas encore l'appelant.
2. `POST /api/admin/auth/totp` reçoit le code RFC 6238 à six chiffres. Un code valide
   régénère la session et crée le token `ROLE_SUPER_ADMIN`.
3. `GET /api/admin/auth/me` hydrate la session séparée.
4. `POST /api/admin/auth/logout` exige `X-CSRF-Token`, invalide la session et répond 204.

Les deux étapes publiques partagent une limite glissante de 5 tentatives par IP sur
15 minutes. Les erreurs d'identifiant, de mot de passe et de compte désactivé ne révèlent
pas l'existence du compte. L'état `enabled` est revalidé à chaque restauration de la
session : désactiver une identité révoque donc aussi ses sessions existantes.

## MFA et création de compte

`app:superadmin:create <email>` demande et confirme interactivement un mot de passe
conforme à la politique serveur (12 caractères, une majuscule et un caractère spécial),
crée l'identité, puis affiche une seule fois la clé Base32 et l'URI
`otpauth://` à enregistrer dans une application compatible TOTP. Le secret stocké est
chiffré en AES-256-GCM avec une clé dérivée de `APP_SECRET`.

## Audit et garanties

Chaque réponse `/api/admin/**`, succès comme refus, ajoute une ligne avec acteur éventuel,
route, méthode, statut et horodatage. Aucun corps de requête, mot de passe, code ou secret
TOTP n'est journalisé. Si l'écriture d'audit échoue, la réponse devient 503 et la session
admin est invalidée : la surface échoue fermée.

La non-régression de l'axe auth/memberships est dans `SuperAdminAccessTest` (`phase1`) ;
les primitives TOTP et le fail-closed audit ont leurs tests unitaires.

## Capture métriques SA1

- `solver_metrics` conserve une ligne immutable par tentative de génération : club,
  schedule, issue terminale (`COMPLETED`, `FAILED` ou `INFEASIBLE`), durée solveur, taille du problème, conflits, score,
  version du solveur et horodatage.
- La table porte `club_id`, est sous RLS `FORCE` et respecte `TenantOwnedInterface`.
  Le rôle runtime ne peut jamais lire les métriques d'un autre club ; la connexion
  `admin` les lira pour les agrégations SA2.
- `Club.lastActivityAt` est mis à jour au plus une fois par jour lors d'une activité
  authentifiée et à la mise en file d'une génération. La rétention six mois, le
  partitionnement mensuel et la purge sont reportés au lot d'exploitation dédié.

## Supervision read-only SA2 — API parc et solveur

Deux routes protégées par la session `ROLE_SUPER_ADMIN` exposent des agrégats calculés
sur la connexion Doctrine `admin`. Elles ne positionnent jamais `app.club_id` et ne
réutilisent ni le firewall ni le JWT club :

- `GET /api/admin/overview` retourne le nombre de clubs opérationnels, actifs à 7/30
  jours, nouveaux sur 7 jours et désabonnés, ainsi que les métriques solveur des 30
  derniers jours (volumes, issues, taux `INFEASIBLE`, p50/p95 et série journalière) ;
- `GET /api/admin/clubs?page=1&limit=25&query=...` recherche sur nom, slug ou code FFBB
  et retourne une liste paginée avec offre/compteur, dates d'activité, saison courante,
  volumétrie active de la saison et indicateurs solveur sur 30 jours. `limit` est borné
  à 100 et `query` à 100 caractères.

La « saison courante » est la saison couvrant la date du jour ; en son absence, l'API
retourne la saison la plus récente. Toutes les lectures sont auditées par la garantie
fail-closed SA0. `SuperAdminAccessTest` couvre le rejet d'un JWT club et la lecture
cross-tenant par un superadmin authentifié.

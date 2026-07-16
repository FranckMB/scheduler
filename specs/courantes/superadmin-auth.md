# Console superadmin — authentification, télémétrie et API de supervision

> **État courant (2026-07-16)** : SA0, SA1, la console read-only SA2, le socle
> d'historisation SA3-A et la supervision read-only des jobs SA3-B sont livrés. La
> planification étendue, les relances et les actions cross-tenant restent dans
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
  `super_admin`, `admin_audit_log` ou `admin_job_run`.

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

## Santé technique SA2

`GET /api/admin/health` exécute des sondes read-only bornées sur la base admin, Redis,
l'engine et Mercure. Il expose également le backlog et les échecs des transports
Messenger, le nombre de retries depuis minuit UTC, et le dernier heartbeat du worker.

Le worker écrit au plus un heartbeat toutes les 10 secondes dans le cache Redis, avec
une expiration à 60 secondes. L'API le considère `up` jusqu'à 30 secondes ; une absence
de heartbeat est `unknown`, un heartbeat trop ancien est `down`. Messenger passe
`degraded` dès qu'un message est dans la failure queue ou que le backlog atteint 100.

Chaque sonde réseau a un timeout court. Une dépendance indisponible ne fait jamais tomber
l'endpoint : son composant passe `down`/`unknown` et le statut global devient `degraded`.
Les erreurs, DSN et URL internes ne sont jamais incluses dans la réponse. La route reste
protégée et auditée comme toutes les routes `/api/admin/**`.

## Écran de supervision React SA2

La route protégée `/admin` consomme les trois lectures SA2 sans réutiliser le JWT ni le
store club. Elle présente les indicateurs du parc et du solveur, la série quotidienne,
les sondes d'infrastructure, l'état Messenger/worker et la liste paginée des clubs.
La recherche par nom, slug ou code FFBB est envoyée à l'API ; aucun filtrage cross-tenant
n'est réalisé côté navigateur.

La santé est rafraîchie toutes les 30 secondes, l'overview toutes les 60 secondes, et un
bouton permet de rafraîchir les trois panneaux. Chaque flux conserve ses propres états
chargement, erreur et vide afin qu'une sonde indisponible ne masque pas les autres
informations. SA2 ne déclenche aucune mutation ou action de support.

## Socle d'exécution des jobs SA3-A

La table globale `admin_job_run`, accessible uniquement par la connexion Doctrine
`admin`, conserve pour chaque exécution la clé du job, la commande allowlistée, l'origine,
le statut, les horodatages, la durée et le code de sortie. Elle ne stocke ni sortie
console ni texte d'exception afin de ne pas transformer la télémétrie en journal de
données métier.

`app:jobs:run <clé>` est l'unique wrapper opérationnel. Son catalogue fermé contient les
huit tâches déjà exécutées chaque heure par `cron-runner` : rappels de périodes et de
transition, réconciliation des générations bloquées, purges des comptes non vérifiés,
clubs effacés, comptes inactifs, anciennes saisons et audit. Un verrou advisory
PostgreSQL empêche le chevauchement d'un même job ; une tentative `running` abandonnée
est marquée `interrupted` au prochain démarrage acquis.

SA3-A ne change pas la cadence existante. Les imports annuels, les autres purges, le
prochain run et la relance superadmin appartiennent aux PR suivantes de SA3.

## Supervision read-only des jobs SA3-B

`GET /api/admin/jobs`, protégé par le firewall et la session superadmin séparée, rapproche
le catalogue fermé de la dernière ligne `admin_job_run` de chaque job. La réponse expose
la clé, le libellé, la commande, la cadence déclarée et, lorsqu'elle existe, la dernière
exécution avec son statut, son origine, ses dates, sa durée et son code de sortie. Un JWT
club ne peut pas accéder à cette route.

Le dashboard React `/admin` affiche ces huit jobs dans un panneau indépendant. Un job sans
historique est explicitement marqué « Jamais exécuté » ; une indisponibilité de ce flux ne
masque ni la santé technique, ni les indicateurs, ni les comptes clubs. Le flux est
rafraîchi toutes les 60 secondes et par le bouton d'actualisation global.

La cadence `hourly` est descriptive : la boucle actuelle repose encore sur `sleep 3600`,
donc SA3-B n'affiche pas de prochain run calculé qui serait trompeur après un redémarrage.
Cette PR reste entièrement en lecture seule et n'ajoute aucun bouton de relance.

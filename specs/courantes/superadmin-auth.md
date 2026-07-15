# Authentification superadmin — SA0

> **État courant (2026-07-16)** : socle backend livré. L'interface React, les métriques,
> la supervision et les actions cross-tenant restent dans
> [`../evolution/console-superadmin.md`](../evolution/console-superadmin.md).

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

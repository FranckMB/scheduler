# Canal signalement, support & reproduction — cadrage (P5-6)

> **Statut : CADRAGE — aucune décision prise, aucun code.** Besoin fondateur du 2026-08-09 :
> un endroit où un gestionnaire signale un bug, une contrainte manquante, une idée — et de quoi
> **reproduire** ce qu'un utilisateur a rencontré. Base saine d'emblée (pas un `mailto:`
> jetable), sans sur-ingénierie tickets. Ce doc pose l'état des lieux vérifié, les options et
> les décisions à trancher. Il graduera en `courantes/` une fois le lot livré.

## 1. État des lieux (vérifié au code, 2026-08-13)

**Ce qui existe déjà et qui est FORT — la reproduction d'une génération :**
- `Schedule::$snapshotData` : le payload engine **complet et figé**, écrit AVANT l'appel moteur,
  avec son sha256 — **rejouable tel quel sur `/generate`**. La repro d'un solve est un solved
  problem.
- `ScheduleStructureSnapshot` : photo de la structure au moment du solve (plans SEASON,
  COMPLETED seulement).
- Versions/seed/métriques par solve : `solverVersion`, `seed`, `wallTimeMs`, tailles, score
  (`Schedule` + `solver_metrics`) ; diagnostics par génération (`ScheduleDiagnostic`).

**Ce qui existe à moitié :**
- **Sentry câblé partout, actif nulle part** : bundle backend (erreurs + échecs Messenger),
  init engine, `@sentry/react` + ErrorBoundaries — mais les 3 DSN sont vides (P5-1, le compte
  n'existe pas). Le jour où P5-1 est fait, les erreurs techniques remontent seules — le canal
  signalement n'a PAS à transporter les stack traces.

**Ce qui manque :**
- **Aucun canal utilisateur** : zéro mécanisme de feedback in-app, zéro endpoint, le seul
  contact est le `mailto:` placeholder de la landing (hors app).
- **Aucune corrélation** : pas de request-id (rien ne relie une erreur front, une entrée Sentry
  backend et un solve), pas de logging structuré (Monolog absent, logs texte Docker 30 Mo),
  pas de lien génération→utilisateur (`Schedule` porte club+saison, pas l'auteur du clic).

## 2. Les trois familles d'options

### A. In-app minimal (recommandation pressentie)
Un bouton « Signaler » dans l'app → formulaire court (type : bug / contrainte manquante / idée ;
texte libre) → une entité `Feedback` tenant-scopée + **contexte auto-joint** : URL/écran,
`clubId`, `seasonId`, le `scheduleId` courant s'il y en a un (= le snapshot rejouable est
automatiquement référencé), user-agent, version app. Consultation : console superadmin
(liste + détail + statut traité/non traité — PAS un workflow de tickets). Notification :
un email vers `support@` à chaque dépôt (le mail part par le bus, rail déjà async).
- **Pour** : la donnée de repro est LÀ où le signalement naît ; tenant/RGPD maîtrisés maison ;
  zéro dépendance ; s'aligne sur la console SA existante.
- **Contre** : c'est nous qui stockons (purge/retention à définir) ; l'UI de traitement reste
  rudimentaire (voulu).

### B. Externe (Tally/Formbricks/GitHub Issues public…)
- **Pour** : zéro code.
- **Contre** : perd le contexte auto (l'utilisateur recopie à la main — la moitié de la valeur),
  RGPD à contractualiser, identité club à ressaisir, et l'aversion « base saine d'emblée »
  pointait précisément ça.

### C. Email seul (`support@maratech.fr` affiché dans l'app)
- **Pour** : existe dès que la boîte existe.
- **Contre** : c'est le `mailto:` jetable refusé au cadrage — aucun contexte, aucun suivi,
  aucune structure. Peut vivre en COMPLÉMENT (l'alias existe de toute façon), pas en canal
  principal.

## 3. Décisions — TRANCHÉES par le fondateur le 2026-08-13

| # | Décision |
|---|---|
| D1 | **In-app, DEUX portes** : (a) un « Signaler » **contextuel sur la page** (planning/wizard) — contexte auto-joint + champ descriptif du bug ; (b) un « Signaler un bug » **dans le burger** — zone libre : choix d'un topic (bug / contrainte manquante / idée) + commentaire libre. La porte (b) existe partout, la (a) là où il y a un contexte à capturer |
| D2 | **Contexte MAXIMAL, redondance assumée** : « je préfère être redondant et pouvoir reproduire plutôt que devoir redemander » — écran, club, saison, `scheduleId` ET **copie** des diagnostics + du payload rejouable dans le signalement lui-même. Justification technique de la redondance : le planning référencé peut être supprimé/régénéré après coup — la copie rend le signalement **impérissable** |
| D3 | **Signé, et TOUT LE MONDE peut signaler** (Gestionnaires ET Membres) |
| D4 | **Digest quotidien** vers `support@` (pas un email par dépôt) — la console SA reste la vue temps réel |
| D5 | **Lot séparé, mais VOULU** (pas un différé poli) : la corrélation request-id/logs structurés a sa propre ligne roadmap (P5-11) |
| D6 | **Console superadmin : liste des signalements en cours + statut traité/non traité** — « pour ne pas oublier » |

Niveau plan (à trancher à l'implémentation, validés avec le plan) : pages exactes de la porte
contextuelle, heure du digest, rétention/purge (pressenti : alignée sur la rétention club),
taille max du commentaire.

## 4. Ce que ce lot ne sera PAS
Un système de tickets (statuts multiples, assignation, SLA), un chat, un forum, une base de
connaissances, ni le remplaçant de Sentry (les erreurs techniques remontent par P5-1). Pas de
pièces jointes v1 (surface upload = lot sécurité à part entière si le besoin émerge).

## 5. Estimation si option A
S/M : entité + endpoint POST tenant-scopé (management par défaut ? ou tout membre ? — à
trancher en D3bis), formulaire front (bouton global), vue console SA, email par le bus, tests
(tenant, rôles, texte public sans identifiants internes). Axe auth non touché ; axe tenant
touché par la nouvelle entité → NR d'isolation standard (patron des entités tenant-owned).

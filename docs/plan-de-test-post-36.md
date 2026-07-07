# Plan de test manuel — validation du travail depuis la PR #36

> Périmètre : PRs #37 → #57 (série QW, série BCK, cockpit paliers A/B/C) + corrections de l'audit du 2026-07-05 (PR `fix/audit-post-36`).
> **Extension (depuis le samedi 2026-07-04, PRs #58 → #100)** — §7 à §12 : calendriers (vacances/fériés/coupure), transition de saison, module matchs, sémantique des contraintes, perf/UX, sécurité + corrections de l'**audit 2026-07-06**.
> À dérouler **dans l'ordre** : chaque section s'appuie sur l'état laissé par la précédente.
> Convention : ☐ à cocher quand le résultat attendu est observé.

## 0. Prérequis (5 min)

1. ☐ `make start` à la racine — vérifier `docker compose ps` : tous les conteneurs **healthy**, y compris le nouveau **`clubscheduler-cron-runner`**.
2. ☐ `cd frontend && npm run dev` — app sur http://localhost:5173.
3. ☐ Vacances scolaires seedées : `docker compose exec php-fpm php bin/console app:school-holidays:seed` (idempotent, safe à relancer).
4. ☐ Mailpit ouvert : http://localhost:8025 (emails du cron).
5. Repartir d'un club propre : soit un **nouveau compte** (recommandé — teste le register au passage), soit « Réinitialiser le club » (§1.6) sur un club existant.

---

## 1. Wizard & confiance (série QW, PRs #46–#50)

### 1.1 Feedback d'erreur global (QW-1)
- ☐ Wizard → Équipes : couper le backend (`docker compose stop php-fpm`), tenter d'ajouter une équipe → **un toast d'erreur apparaît** (plus d'échec silencieux), la saisie n'est pas perdue. Relancer (`docker compose start php-fpm`).
- ☐ Vérifier qu'aucune action en échec ne produit **deux** toasts pour la même erreur (correction audit : dédoublonnage global/local).

### 1.2 Confirmations de suppression (QW-2)
- ☐ Supprimer une équipe → **modale de confirmation** (pas de suppression 1-clic).
- ☐ Supprimer un gymnase avec créneaux → confirmation mentionnant la cascade.

### 1.3 Libellés FR (QW-2/QW-3)
- ☐ Étape Contraintes : types affichés **Obligatoire / Préféré / Bonus / Verrouillé** (plus de HARD/PREFERRED bruts).
- ☐ Jours : libellé « **à éviter** » (polarité explicite).
- ☐ Micro-copy Rang (S–D, priorité) vs Niveau de jeu (division FFBB) présente sur l'étape Équipes.

### 1.4 Profil (QW-5)
- ☐ Menu burger → Profil : modifier prénom/nom → sauvegardé (recharger pour vérifier).
- ☐ Changer le mot de passe (ancien requis, min 8) → reconnexion OK avec le nouveau.
- ☐ Email déjà pris → message d'erreur clair, pas de changement.
- ☐ Clic sur le **logo/nom du club** dans le header → retour à l'accueil.

### 1.5 Erreurs de saisie zone (correction audit)
- ☐ Écran /club : la zone scolaire n'accepte que **A, B ou C** (autre valeur → erreur 422 affichée, plus d'échec silencieux).

### 1.6 Quick wins confiance saisie (PR #60)
- ☐ **Auto-sélection gymnase** : étape Gymnases, ajoute un 2ᵉ gymnase → il devient **sélectionné automatiquement** (le panneau bascule dessus). Pose un créneau → il tombe bien sur le nouveau gymnase, pas sur le 1ᵉʳ.
- ☐ **Pas de flash d'erreur au chargement** : recharge le wizard (F5) sur l'étape Équipes/Gymnases/Coachs → **aucun** « Ajoutez au moins une équipe/gymnase/coach » rouge ne clignote avant l'affichage des données.
- ☐ **Focus rendu au champ** : ajoute une équipe (Entrée ou bouton +) → le curseur **repart direct dans le champ nom**, prêt pour la suivante. Idem sur Gymnases (champ nom) et Coachs (champ prénom).

### 1.7 Réinitialiser le club (QW-4 + correction audit)
À faire **en dernier** de cette section si tu veux garder tes données, ou sur un club jetable :
- ☐ /club → « Réinitialiser » : modale de confirmation « Tout supprimer ».
- ☐ Après reset : équipes, gymnases, **créneaux d'entraînement**, coachs, plannings, **entrées du calendrier cockpit** tous vides ; l'accueil redevient le work-loop (le jalon socle est remis à zéro). *(Correction audit : avant, les créneaux et le calendrier survivaient au reset.)*
- ☐ Avec un compte membre non-admin : le bouton n'est pas proposé / l'appel est refusé (403).

---

## 2. Cycle de vie du planning (socle)

Saisir un petit club (2 gymnases, ~6 équipes, 2 coachs) puis :

1. ☐ **Générer** : étape Génération → planning `COMPLETED` avec score.
2. ☐ **Valider** : bouton Valider → modale de responsabilité → statut **Validé**, édition verrouillée (drag&drop, édition manuelle et régénération refusés).
3. ☐ **Accueil = cockpit** : retourner à l'accueil (`/`) → l'écran **cockpit 3 zones** s'affiche (bandeau socle · calendrier · radar). C'est le jalon sticky : il ne se reverrouille jamais (sauf reset club).
4. ☐ **Renommer un planning validé** : impossible (verrou total) ; rouvrir d'abord.
5. ☐ **Supprimer le planning principal** : refusé avec message (« désigner un autre principal d'abord ») — *garde ajoutée par l'audit.*
6. ☐ **Supprimer un planning validé non-principal** : refusé tant qu'il n'est pas rouvert — *garde ajoutée par l'audit.*

---

## 3. Cockpit palier A — voir venir (PR #52–#54)

1. ☐ **Bandeau** : nom/statut/score du planning principal + boutons Ouvrir · Modifier… · Tous les plannings. Pendant le chargement : « Chargement… » (plus de flash « Aucun planning principal » — *correction audit*).
2. ☐ **Calendrier** : mois courant, jour du jour **entouré**, navigation ←/→. Les **vacances scolaires de ta zone** apparaissent (🏖 + fond).
3. ☐ **Radar** : les 3 prochaines vacances listées « Dans N j · pas de plan ». Si zone non renseignée : carte « Zone scolaire à renseigner » (et **pas** de flash de cette carte au chargement — *correction audit*).
4. ☐ **Clic sur un jour** → modale : créer un **Événement** (titre, jusqu'au, perturbant) → 🎉 (ou 🚫 si perturbant) sur le calendrier ; les événements perturbants remontent au radar.
5. ☐ **Signaler une indisponibilité** (clic jour → salle + fenêtre) → période ⛔ sur le calendrier + carte radar « **N séances à replacer** · plan secondaire absent » (le compte vient des séances du socle sur la salle fermée dans la fenêtre).
6. ☐ Bouton « **Créer une période…** » : grisé avec tooltip (différé — assumé).
7. ☐ **Supprimer une entrée** (clic jour → poubelle) : **confirmation demandée** — *correction audit : avant, suppression 1-clic.*

## 4. Cockpit palier B — plans de période (PR #55–#56)

1. ☐ Radar vacances → « **Adapter** » : bascule dans le **wizard mode période** (bandeau « Mode période — {titre} », dates affichées).
2. ☐ Étapes Équipes/Gymnases/Coachs : structure **en lecture seule** (héritée du socle) ; l'étape Contraintes est active et liée à la période.
3. ☐ Étape Génération : message overlay + **avertissement premier calendrier secondaire** (« …fige ton planning principal ») — *ajout audit (spec §2bis).*
4. ☐ **Générer le plan de période** → COMPLETED → le plan s'affiche. Retour cockpit : la carte radar passe à « **Plan secondaire généré** » avec **Voir le plan** / **Ajuster** (à jour immédiatement — *correction audit du cache*).
5. ☐ Sélecteur de plannings (work-loop) : l'overlay porte un badge « Période » et n'est jamais auto-sélectionné.
6. ☐ **Quitter le wizard en pleine génération** puis revenir (radar → Ajuster) : l'écran **reprend l'attente en cours** au lieu de proposer un second lancement — *correction audit.*
7. ☐ **Ajuster** à nouveau (période avec plan) : la génération **régénère** l'overlay existant (pas de doublon, pas de 422).
8. ☐ **Modifier une période qui a un plan** (si tu passes par l'API/PUT) : type/dates/kind **refusés en 422** avec message « supprime le plan d'abord » ; le titre reste modifiable — *garde ajoutée par l'audit.*
9. ☐ **Supprimer une période avec plan généré** (clic jour → poubelle) : confirmation **destructive** mentionnant la suppression du plan ; après confirmation, l'overlay disparaît **aussi du bandeau et de « Tous les plannings »** sans recharger — *correction audit.*
10. ☐ **Période dont le plan est validé** : la suppression de la période est **refusée** (409 — rouvrir le plan de période d'abord). Le reopen destructeur du socle, lui, supprime bien tout (chemin autorisé, confirmé) — *correction revue.*
11. ☐ **Radar et entrées ignorées** : marquer une période `ignored` (API) → elle **disparaît du radar** (le calendrier la montre encore) — *correction revue.*

### 4bis. Reopen proportionné (le garde-fou du socle)
1. ☐ Sans aucun plan de période : bandeau → « Modifier… » → **une** confirmation simple → le socle se rouvre, arrivée sur le work-loop.
2. ☐ Re-valider le socle, générer un plan de période, puis « Modifier… » : 1ʳᵉ confirmation **annonce la suppression de N calendriers secondaires**, puis 2ᵉ confirmation **destructive** avec le compte exact renvoyé par le serveur (409). Confirmer → socle rouvert, **plans de période supprimés**, les périodes elles-mêmes restent (repassent « à adapter » au radar).

## 5. Cockpit palier C — rappels (PR #57 + corrections)

Sur un club avec une période **sans plan** démarrant dans ≤ 14 jours (en créer une via « Signaler une indisponibilité ») :
1. ☐ Répétition à blanc : `docker compose exec php-fpm php bin/console app:periods:remind --dry-run` → la période est listée « would remind » avec le bon bucket (J-14/7/3).
2. ☐ Envoi réel : relancer sans `--dry-run` → email dans **Mailpit** aux gestionnaires (sujet 🔴 si J-3), lien vers le cockpit.
3. ☐ Relancer immédiatement → **0 envoi** (dédup par palier).
4. ☐ Une période **cutoff/custom** (à créer via API) ne déclenche **aucun** rappel — *correction audit : le CTA email menait à un 422.*
5. ☐ Le conteneur `cron-runner` exécute tout ça **toutes les heures** tout seul : `docker logs clubscheduler-cron-runner` montre les 2 commandes (rappels + réconciliation des générations zombies).

## 6. Divers backend (série BCK, spot-checks rapides)

1. ☐ Génération qui plante (arrêter l'engine : `docker compose stop engine`, lancer une génération) → le planning finit **FAILED** avec message, jamais bloqué en « génération… » infini. Relancer l'engine.
2. ☐ Marquer une entrée radar « ignorée » (si l'UI le propose ; sinon via API `status=ignored`) → **plus de conflits** remontés pour elle — *correction audit.*
3. ☐ Pagination : listes > 30 éléments (équipes) → le total affiché est le vrai total.

---

# EXTENSION — développements depuis le samedi 2026-07-04 (PRs #58 → #100)

> Prérequis en plus du §0 : jours fériés seedés — `docker compose exec php-fpm php bin/console app:public-holidays:import` (idempotent). Catalogue-ligue matchs seedé — `docker compose exec php-fpm php bin/console app:league-windows:seed`.

## 7. Calendriers — vacances scolaires, jours fériés & coupure (PRs #62/#63/#65/#67)

1. ☐ **Vacances scolaires (API Éducation nationale)** : cockpit → calendrier → les vacances de **ta zone** (A/B/C, dérivée du code club) s'affichent (🏖 + fond). Renseigner une zone différente sur /club → les vacances changent de dates.
2. ☐ **Jours fériés** : un jour férié national (ex. 14 juillet, 1er mai) porte une **pastille fériés** sur le calendrier. Un territoire DOM/TOM verrait ses fériés locaux en plus.
3. ☐ **Radar fériés** : un férié à ≤ 30 j remonte au radar (« 14 juillet · Dans N j · jour férié »). *(Le raffinage de cette carte est en roadmap — cf. §11.)*
4. ☐ **Coupure (cutoff)** : créer une période **de type coupure** (fin d'année, arrêt) → elle borne le calendrier ; aucun rappel n'est déclenché pour une coupure (cf. §5.4 déjà couvert).

## 8. Transition de saison (PRs #68/#69/#70 = P1, #75/#76 = P2)

> La bascule pivote au **15 juillet** ; avant cette date la saison courante reste 2025-2026.

1. ☐ **Sélecteur de saison** : le header/menu montre la saison courante. Bouton « **Préparer la saison suivante** » visible.
2. ☐ **Préparer N+1** : cliquer → une saison suivante est créée, le sélecteur **liste les deux** et permet de basculer sur la nouvelle (brouillon).
3. ☐ **Copie de structure** : la saison N+1 hérite de la **structure** (équipes/gymnases/coachs) de N — pas des plannings.
4. ☐ **Re-préparer** (cliquer 2×) : **pas de doublon** — la saison suivante existante est réutilisée.
5. ☐ **Isolation** : en saison N+1, tu ne vois **pas** les plannings/contraintes de N (et inversement). Les données sont cloisonnées par saison.
6. ☐ **Lecture seule d'une saison archivée** : une saison passée (N-1) est **en lecture seule** — toute écriture (créer/éditer une équipe, générer) est refusée (**409** « saison archivée »).
7. ☐ **Re-datation des événements** (P2) : lors de la transition, l'appli **propose de re-dater** les événements/exceptions de N vers N+1 (une seule fois). Accepter → ils apparaissent aux nouvelles dates ; re-proposer → plus proposé (idempotent).
8. ☐ **Alertes d'anticipation** (P2) : à l'approche du pivot, un **bandeau/rappel** invite à préparer la saison suivante (message permanent dans le cockpit + email cron si configuré).

## 9. Module matchs — palier A (PRs #71/#72/#73/#74)

Nav → **« Matchs »** (`/matchs`).

1. ☐ **Grille week-end** : la page affiche une grille **centrée week-end** ; un match = un bloc de **2h15** (empreinte-temps : 30 échauffement + 1h45).
2. ☐ **Envelope-ligue** : pour une équipe dont la catégorie mappe une fenêtre de la ligue (seed AURA), la pose est **bornée par la fenêtre** (garde HARD) ; sinon repère indicatif.
3. ☐ **Placer un match domicile** : clic sur un créneau → panneau → poser heure+salle → le match passe **PLACED**.
4. ☐ **Match amical** : créer un match **sans compétition** (amical) → accepté (competition nulle).
5. ☐ **Radar de conflits** : si deux matchs d'un même coach se chevauchent, ou un match chevauche un entraînement (plan effectif à la date), le **radar signale le conflit** (coach). *(Le volet joueur = suite du palier A.)*
6. ☐ **Import FBI** : bouton « **Importer FBI** » → dialog (choisir l'**Équipe** + le **Fichier FBI** .xlsx) → rapport **créés / ignorés / erreurs par ligne**. Re-importer le même fichier → tout en **ignorés** (idempotent par n° FBI). ⚠ Format supposé tant qu'un vrai export FBI n'est pas validé.

## 10. Sémantique des contraintes — matrice UI↔engine (PR #78, audit P0.1)

> Vérifie qu'une contrainte **saisie** est bien **honorée** par le solveur (ou signalée). Saisir chaque cas dans le wizard Contraintes puis générer.

1. ☐ **« Préféré · pas le mardi »** (DAY, Préféré) : le solveur **évite le mardi quand il peut** (bonus soft) — plus ignoré en silence. Auparavant : placebo total.
2. ☐ **« Préféré · éviter Gymnase X »** (FACILITY, Préféré) : traité en **malus soft** — le plan **reste faisable** (plus d'INFEASIBLE sur une simple préférence). Auparavant : escaladé en interdiction dure.
3. ☐ **Type BONUS disparu de l'UI** : le sélecteur de type ne propose plus que **Obligatoire / Préféré / Verrouillé** (BONUS retiré, mort de bout en bout).
4. ☐ **Multi-indispo coach** : sur un même coach, saisir « indispo lundi » **puis** « indispo mercredi » → les **deux** sont tenues (union) — auparavant la 2ᵉ écrasait la 1ʳᵉ.
5. ☐ **« Toutes les équipes »** (scope CLUB) : une contrainte club se **propage bien à chaque équipe** (case morte corrigée).

## 11. Perf, robustesse & UX (PRs #96, #100)

1. ☐ **Génération rapide même sur gros club** : générer un club **dense** (beaucoup d'équipes/contraintes de préférence gymnase) → le planning `COMPLETED` en **quelques secondes** (avant : jusqu'à ~10 min). *(Fix workers adaptatifs.)*
2. ☐ **UX-02 — accueil sur le bon planning** : après login, l'app ouvre sur le **planning principal plein** (ou le dernier plan de saison fini) — **jamais** sur un « ★ · période » **vide**. Le bouton « Définir principal » **n'apparaît pas** quand un overlay de période est sélectionné.
3. ☐ **Garde-fou génération bloquée** (outillage) : si le worker est mort, le smoke `smoke-solver.sh` échoue **à ~90s avec un diagnostic** (worker/NOGROUP) au lieu de pendre 10 min. *(Vérif dev, pas gestionnaire.)*

## 12. Sécurité (audit 2026-07-06 — surtout automatisé)

> La plupart des durcissements SEC sont couverts par des tests bloquants ; peu ont une surface manuelle tant que le rôle **coach** n'existe pas (tous les membres sont `admin`).

1. ☐ **SEC-08 — pas de fuite d'erreur** : provoquer une erreur d'édition manuelle (ex. one-time update en conflit) → le message client est **générique** (« The request could not be processed. »), pas un détail interne. Le détail est loggé serveur.
2. ☐ **SEC-10 — logo public assumé** : le logo d'un club reste accessible sans login (`/api/clubs/{id}/logo` GET) — choix produit (mails/PDF).
3. ◻️ **SEC-07 (rôle management)** / **SEC-11 (rate limiting 300/min per-user)** : automatisés (ManagementRoleTest / ApiRateLimitTest) — pas de test manuel gestionnaire.

---

## Récap des corrections apportées par l'audit (2026-07-05)

| # | Correction | Où le voir |
|---|-----------|------------|
| 1 | Période sous plan : type/dates/kind verrouillés (422) | §4.8 |
| 2 | Reset club purge créneaux + calendrier + jalon socle | §1.6 |
| 3 | Planning principal / validé non supprimable (409) | §2.5–2.6 |
| 4 | Statut non falsifiable via PUT (409) | (API only) |
| 5 | Rappels limités aux périodes qui peuvent avoir un plan | §5.4 |
| 6 | « Aujourd'hui » du cron = fuseau du club | (implicite §5) |
| 7 | Zone scolaire validée A/B/C | §1.5 |
| 8 | Entrée ignorée → zéro conflit | §6.2 |
| 9 | Cache : radar/bandeau à jour après génération/suppression d'overlay | §4.4, §4.9 |
| 10 | Suppression d'entrée/période confirmée (destructive si plan) | §3.7, §4.9 |
| 11 | Double-toast d'erreur éliminé | §1.1 |
| 12 | Avertissement « 1ᵉʳ calendrier secondaire fige le socle » | §4.3 |
| 13 | Reprise d'une génération de période en vol | §4.6 |
| 14 | Sortie propre du mode période si la période a été supprimée | (implicite §4) |
| 15 | `cron-runner` : rappels + anti-zombie réellement planifiés (healthcheck fiable) | §5.5 |
| 16 | Plan de période validé indestructible via la suppression d'entrée | §4.10 |
| 17 | Rename jamais bloqué par un statut périmé (statut ignoré au PUT) | (API only) |
| 18 | Feedback d'erreur garanti même si la fenêtre est fermée pendant l'action | §1.1 |
| 19 | Radar : les entrées « ignorées » ne réapparaissent plus | §4.11 |

## Récap des corrections apportées par l'audit (2026-07-06)

> Traçabilité : PRs #80 → #100. La plupart sont backend/tooling/tests (sans surface manuelle) ; colonne « Où le voir » = section manuelle quand elle existe, sinon nature.

| Axe | Correction | Où le voir |
|-----|-----------|------------|
| PERF | Solveur gros club 612 s → ~2 s (workers adaptatifs) | §11.1 |
| UX-02 | Un overlay n'est jamais baseline ; accueil sur le vrai plan | §11.2 |
| ENG-10..13 | Matrice contrainte UI↔engine (préférences honorées, BONUS retiré, union coach) | §10 |
| SEC-08 | Plus de `getMessage()` brut au client (manual-edit) | §12.1 |
| SEC-07 | Garde de rôle management sur les écritures cockpit | automatisé (ManagementRoleTest) |
| SEC-11 | Rate limiting API 300/min par utilisateur | automatisé (ApiRateLimitTest) |
| SEC-09/10 | Ledgers hors-RLS / logo public = résiduels assumés + documentés | §12.2 |
| ENG-05/06/14/15 | Typage assignment, handler d'exception, garde version contrat, retrait code mort | automatisé (suite engine) |
| BCK-08 | Tests comportementaux manual-edit | automatisé (ManualEditBehaviorTest) |
| BCK-07 | Check d'appartenance explicite sur export-pdf | (API only) |
| Supply chain | pytest CVE corrigé + Dependabot (4 écosystèmes) | (CI) |
| DOC | Inventaires/roadmap à jour, `documentation-update` invocable | (docs) |

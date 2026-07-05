# Plan de vacances & collecte des demandes coach — besoin spécifié

> **Statut** : **besoin spécifié** (discovery close, décisions tranchées §6) — **pas un plan** (ni tâches, ni effort chiffré ; l'exécution se planifie par phase §5).
> **Nature** : fixe le vrai besoin métier du *plan de période de vacances* et de la *collecte distribuée des demandes coach*, après confrontation au terrain (gestionnaire d'un club réel).
> **Rattachement roadmap** : `roadmap.md` §2 (modèle temporel & périodes d'exception). Remplace la lecture « collecte de dispos → contrainte dure » de la ligne *collecte des dispos coach par lien sans login*, qui était à côté du métier.
> **Réutilise l'existant** : cockpit palier B (overlays de période, `Schedule.calendarEntryId`, contraintes datées `Constraint.calendarEntryId`), patron token `ResetPasswordRequest`, capacité de créneau (`Venue.canSplit` + `VenueTrainingSlot.capacity`). **Zéro changement engine.**

---

## 1. Le problème

À chaque vacances, le plan hebdomadaire ne tient plus : certaines équipes ne s'entraînent pas, d'autres en réduit, les gymnases ouvrent autrement (horaires, jours fermés), et des équipes partagent un créneau (SM1+SM2). Le gestionnaire **négocie** ce plan réduit : il demande aux coachs leurs **souhaits**, puis **tranche** (le DT peut n'accorder qu'1 séance à des U13 qui en veulent 3). Aujourd'hui : téléphone + ressaisie à la main.

**Ce n'est pas une collecte de disponibilités qui deviendrait une contrainte dure.** Le coach émet un **souhait** ; le gestionnaire **arbitre et décide**. Le lien coach n'écrit **jamais** une contrainte directement.

## 2. Le workflow réel

1. **Setup période** — le gestionnaire crée une période `holiday`, choisit les **équipes actives** (certaines désactivées pour la période), édite la **structure de vacances** (créneaux salle + nb de séances par équipe), pose les **mutualisations**.
2. **Solliciter les coachs** — il choisit **quels coachs** recevoir l'email + une **date limite** → liens tokenisés sans login.
3. **Demande coach** — page publique sans login : le coach dépose sa demande (par équipe : garde / rien / réduit + commentaire libre). **Lien mort après la date limite.**
4. **Écran demandes** — le gestionnaire voit la liste des demandes reçues, **coche « traité »** ligne par ligne (pris en compte, oui/non). Il lit, puis **construit le plan lui-même** avec la structure éditable (§1). Aucune contrainte auto-générée, aucun pré-remplissage automatique.
5. **Génération overlay** — depuis les **décisions du gestionnaire** (existant, palier B).

## 3. Le modèle : structure de période par override `calendarEntryId`

Même patron que les contraintes datées (déjà en place) : un `calendarEntryId` nullable sur les entités de structure, résolu au build overlay.

- **Copie-sur-édition** : à l'entrée en mode période, la structure saisonnière (créneaux salle `VenueTrainingSlot` + séances/activation par équipe) est **clonée** en version période (`calendarEntryId` = la période). Pré-remplie — le gestionnaire part du saisonnier et **ajuste** (retire un créneau du matin, met les U13 à 1 séance, désactive une équipe).
- **Build overlay** : utilise le jeu **période** (`calendarEntryId` = la période) là où il existe, sinon le saisonnier. Le plan **de base reste intact** hors vacances.
- **Mutualisation = gratuite** : salle divisible (`canSplit`) + capacité 2 sur le créneau + réservation de SM1 **et** SM2 dessus → le solveur place les deux. Couvre le partiel (1 des 2 créneaux partagé, l'autre solo) et le borné (1ʳᵉ semaine). **Aucune primitive moteur de fusion `team_ids`.**

## 4. Nouveau vs existant

| Élément | État |
|---|---|
| Overlays de période, `Schedule.calendarEntryId`, contraintes datées | ✅ existant (palier B) |
| `VenueTrainingSlot.calendarEntryId` (créneaux salle par période) | ⬜ **nouveau** (même patron que Constraint) |
| Nb de séances + activation par (équipe, période) | ⬜ **nouveau** (override du `sessionsPerWeek` saisonnier) |
| Mode période : structure **éditable** (au lieu de lecture seule) | ⬜ **nouveau** (frontend) |
| Sollicitation coach + token + date limite | ⬜ **nouveau** (patron `ResetPasswordRequest`) |
| Page publique sans login (dépôt de demande) | ⬜ **nouveau** |
| Écran demandes + case « traité » | ⬜ **nouveau** |
| Mutualisation | ✅ existant (capacité + réservation) |
| Moteur (engine) | ✅ inchangé |

## 5. Phasage

| Phase | Contenu | Livre |
|---|---|---|
| **P1 — Structure de période éditable** | `calendarEntryId` sur `VenueTrainingSlot` + séances/activation par (équipe, période) ; mode période éditable (copie-sur-édition) ; build overlay résout période→saisonnier. Mutualisation via réservation (déjà là). | Le gestionnaire fait **tout le plan de vacances à la main** (créneaux salle modifiés, volume réduit, équipes off, créneaux partagés). Cœur utile **seul**. |
| **P2 — Collecte coach** | Bouton « Solliciter les coachs » (choix coachs + date limite) → emails tokenisés → page publique sans login (dépôt demande) → écran demandes + case « traité ». | La **collecte distribuée** — le différenciateur commercial. S'appuie sur P1 (le gestionnaire agit dans la structure P1). |

## 6. Décisions tranchées

1. **Structure de période éditable** (créneaux salle **et** séances) — retenu (a). La lecture seule de palier B devient éditable en copie-sur-édition.
2. **Souhait coach ≠ contrainte** : le coach propose, le gestionnaire dispose. Arbitrage = simple **liste + case « traité »**, pas d'automatisation ni de seeding.
3. **Volume seul** côté coach (garde / rien / réduit + commentaire) — **pas** de dispos horaires fines.
4. **Date limite** fixée par le gestionnaire → le lien tokenisé expire à cette date.
5. **Mutualisation par réservation** sur créneau à capacité 2 — **pas** de réécriture engine.
6. **Équipe désactivée** pour la période → absente de l'overlay ; son plan de base reste intact hors vacances.

## 7. Hors scope

- **Gymnases dispo seulement aux vacances** — cas réel mais aucun club connu ne le demande → **différé**. Le patron `calendarEntryId` s'étend à `Venue` plus tard **sans rework** (le design ne le bloque pas).
- Dispos horaires fines côté coach · compte / login coach · auto-génération de l'overlay · app mobile · toute réécriture engine.

## 8. Axes structurants (§7.1) & vérification

- **constraint semantics** : le nb de séances **et** les créneaux salle de la période doivent être **honorés par le solveur** → NR sémantique + `smoke-solver.sh` sur le chemin overlay (planning `COMPLETED`, séances au bon volume/salle).
- **planning lifecycle** : overlays de période (déjà gardé).
- **auth & sécurité (P2)** : **endpoint public tokenisé** → `club_id` (GUC RLS) dérivé du **token → coach → club**, jamais d'un input ; durcissement repris de reset-password (token opaque, expiration = date limite, anti-énumération, rate-limit). **`/security-review` obligatoire en P2.**

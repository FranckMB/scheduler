# Couverture des contraintes — besoins gestionnaire

> **But** : liste **exhaustive** des besoins qu'un gestionnaire de club peut vouloir exprimer, et
> **ce que l'application couvre** aujourd'hui — pour voir clairement les cas couverts (✅), partiels
> (🟡) et **non couverts** (❌). Le vocabulaire moteur correspondant est détaillé dans
> `engine/docs/constraint-vocabulary.md`. Exemples pris sur le club de démo **BCCL**.
>
> Légende : ✅ couvert (dur ou soft explicite) · 🟡 partiel / approximé / non garanti · ❌ non couvert.

## Axe HORAIRE (heure de début)

| Besoin gestionnaire | Contrainte / mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Ne pas commencer avant X h » | TIME `minStartTime` (HARD) | ✅ | Adultes ≥ 18h50 |
| « Ne pas commencer après X h » | TIME `maxStartTime` (HARD) | ✅ | EMB ≤ 17h30 |
| « Préférer plus tôt / plus tard » | TIME `min/maxStartTime` (PREFERRED) | ✅ soft | U13 début préféré < 19h00 |
| **« Finir avant X h »** | pas de `maxEndTime` — approximé via début max | 🟡 | U15 « fin 20h30 » = début ≤ 19h00 (séance 90 min) |

## Axe JOUR

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Pas d'entraînement tel jour » (dur) | DAY `forbiddenDays` (HARD) | ✅ | U9/U11 pas le mercredi |
| « Éviter tel jour » (préférence) | DAY `forbiddenDays` (PREFERRED) | ✅ soft | SM2 évite le vendredi |
| « Uniquement tel(s) jour(s) » | DAY `allowedDays` (whitelist, HARD) | ✅ | Vétérans le vendredi uniquement |
| **« Au moins une séance tel jour »** | DAY `forcedDays` (engine-only, **pas exposé dans l'UI**) | 🟡 | — (le moteur sait, le wizard ne l'émet pas) |
| **« Espacer les séances d'un jour »** / « pas 2 jours d'affilée » | non modélisé (hors repos post-match soft) | ❌ | besoin BCCL « implicite », non garanti |
| **« Pas 3 entraînements d'affilée »** | pas de `max_consecutive_days` | ❌ | besoin BCCL, non couvert |

## Axe GYMNASE

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Cette équipe joue dans tel gymnase (obligatoire) » | FACILITY `forcedVenueId` (HARD) | ✅ | SM4 → Jean Vilar |
| « Réserver un gymnase à un groupe (exclusif) » | FACILITY `forcedVenueId`/`preferredVenueId` HARD + `targetTag` → interdit hors tag | ✅ | Camus réservé Loisir 1/2/3 |
| « Éviter tel gymnase » (dur) | FACILITY `forbiddenVenueId` (HARD) | ✅ | Vétérans interdits sur 5 gymnases |
| « Préférer tel gymnase » | FACILITY `preferredVenueId` (PREFERRED, +60) | ✅ soft | Matéo préféré aux Régionales |
| « Pas ce type d'équipe dans ce gymnase » | FACILITY `forbiddenVenueId` + `targetTag` | ✅ | Jean Vilar pas de féminines |
| « Gymnase fermé sur une période » | période cockpit `venue_closed` → `forbiddenVenueId` daté | ✅ | (calendrier cockpit) |
| **« Au moins une séance dans tel gymnase »** | **aucun** (`forcedVenueId` = TOUTES les séances ; `preferredVenueId` = soft, non garanti) | ❌ | contourner via l'onglet **« Réserver »** (épingle 1 séance sur un créneau du gymnase, lock HARD) |
| « Nb max d'équipes par créneau d'un gymnase » | FACILITY_CAPACITY `maxTeams` (écran Gymnases, `canSplit`) | ✅ | ADN divisible en 3 |

## Axe COACH

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Coach indisponible tel jour » | COACH_AVAILABILITY `unavailableDays` (UNION, dur) | ✅ | Lionel indispo vendredi |
| « Coach disponible uniquement tel jour » | COACH_AVAILABILITY `availableDays` (INTERSECTION, dur) — mode « disponible uniquement » du wizard | ✅ *(aligné : le wizard l'expose désormais)* | coach dispo seulement le mardi |
| « Un coach ne peut pas être sur 2 séances à la fois » | `COACH_NO_OVERLAP` (implicite) | ✅ | — |
| « Un coach qui joue aussi n'est pas convoqué en double » | `COACH_PLAYER_NO_OVERLAP` (implicite) | ✅ | Mathis coach U13M2 + joueur U21M1 ; Florian coach U18F3 + joueur Loisir 3 |

## Axe PRIORITÉ / RÉPARTITION

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Servir d'abord les équipes importantes » | PRIORITY_TIER `orToolsWeight` S=10000…D=1 | ✅ soft | rangs S/A/B/C/D |
| « Garantir N séances/semaine par équipe » | `MIN_SESSIONS` — **cible soft**, pas un plancher dur | 🟡 | ⚠ « minimum » non garanti (audit ENG-18) |
| « Jamais 2 équipes sur le même créneau » | `VENUE_AT_MOST_ONE` / capacité (implicite) | ✅ | — |
| « Jour de repos après un match » | bonus soft `add_match_day_rest_bonus` | ✅ soft | — |

## Synthèse des trous (❌ / 🟡 prioritaires)

1. **« Au moins une séance dans tel gymnase »** (❌) — le cas le plus demandé sans solution native ; aujourd'hui seulement via une **réservation** manuelle (onglet « Réserver »). Candidat feature : un minimum de séances par gymnase (équivalent de `forcedDays` pour les salles).
2. **Espacement / anti-jours-consécutifs** (❌) — « espacer d'un jour », « pas 3 d'affilée », « repos entre 2 séances » : non modélisés (hors repos post-match). Candidat : `max_consecutive_days` + contrainte d'écart.
3. **« Finir avant X h »** (🟡) — pas de `maxEndTime`, approximé via le début max.
4. **Minimum de séances garanti** (🟡) — actuellement une cible soft ; à trancher si un plancher dur est voulu (risque d'INFEASIBLE si capacité insuffisante).
5. **« Au moins une séance tel jour »** (🟡) — le moteur sait (`forcedDays`) mais le wizard ne l'expose pas.

> Détail moteur exhaustif (toutes les clés + mécanismes) : `engine/docs/constraint-vocabulary.md`.
> Offre réellement câblée dans le wizard : `docs/architecture/constraint-matrix.md`.

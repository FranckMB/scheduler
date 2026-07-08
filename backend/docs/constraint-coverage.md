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
| **« Finir avant X h »** | TIME `maxEndTime` (HARD, mode « Fini avant ») — fin = début + durée du créneau | ✅ *(ALIGN-04)* | U15 « fini avant 20h30 » |

## Axe JOUR

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Pas d'entraînement tel jour » (dur) | DAY `forbiddenDays` (HARD) | ✅ | U9/U11 pas le mercredi |
| « Éviter tel jour » (préférence) | DAY `forbiddenDays` (PREFERRED) | ✅ soft | SM2 évite le vendredi |
| « Uniquement tel(s) jour(s) » | DAY `allowedDays` (whitelist, HARD) | ✅ | Vétérans le vendredi uniquement |
| **« Au moins une séance tel jour »** | DAY `forcedDays` (engine-only, **pas exposé dans l'UI**) | 🟡 | — (le moteur sait, le wizard ne l'émet pas) |
| **« Espacer les séances d'un jour »** / « pas 2 jours d'affilée » | règle **implicite soft** `spacing` (poids −2, malus sur jours consécutifs) — activée pour toutes les équipes, ne bloque jamais | ✅ soft *(ALIGN-06)* | besoin BCCL « implicite » — préféré, pas garanti |
| **« Pas 3 entraînements d'affilée »** (dur) | pas de `max_consecutive_days` (contrainte dure d'écart) | ❌ | besoin BCCL, non couvert (le soft `spacing` ne le garantit pas) |

## Axe GYMNASE

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Cette équipe joue dans tel gymnase (obligatoire) » | FACILITY `forcedVenueId` (HARD) | ✅ | SM4 → Jean Vilar |
| « Réserver un gymnase à un groupe (exclusif) » | FACILITY `forcedVenueId`/`preferredVenueId` HARD + `targetTag` → interdit hors tag | ✅ | Camus réservé Loisir 1/2/3 |
| « Éviter tel gymnase » (dur) | FACILITY `forbiddenVenueId` (HARD) | ✅ | Vétérans interdits sur 5 gymnases |
| « Préférer tel gymnase » | FACILITY `preferredVenueId` (PREFERRED, +60) | ✅ soft | Matéo préféré aux Régionales |
| « Pas ce type d'équipe dans ce gymnase » | FACILITY `forbiddenVenueId` + `targetTag` | ✅ | Jean Vilar pas de féminines |
| « Gymnase fermé sur une période » | période cockpit `venue_closed` → `forbiddenVenueId` daté | ✅ | (calendrier cockpit) |
| **« Au moins une séance dans tel gymnase »** | FACILITY `minAtVenueId` + `minAtVenueCount` (HARD, mode « au moins N ») — plancher, ≠ forçage ; les autres séances restent libres | ✅ *(ALIGN-05)* | « au moins 1 séance à Armand » ; fail-fast backend si N > séances/semaine |
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

## Angles morts traités (2026-07-08)

Les 3 angles morts historiques d'alignement sont désormais couverts :
- **ALIGN-04 « Finir avant X h »** → TIME `maxEndTime` (HARD, mode « Fini avant »).
- **ALIGN-05 « Au moins une séance dans tel gymnase »** → FACILITY `minAtVenueId` + `minAtVenueCount` (plancher HARD, fail-soft si inatteignable, fail-fast backend si N > séances/semaine).
- **ALIGN-06 espacement des séances** → règle implicite soft `spacing` (malus jours consécutifs, jamais bloquant).

## Synthèse des trous restants (❌ / 🟡)

1. **« Pas 3 entraînements d'affilée » / écart dur** (❌) — le soft `spacing` **préfère** espacer mais ne garantit rien ; une contrainte **dure** `max_consecutive_days` reste non modélisée.
2. **Minimum de séances garanti** (🟡) — `MIN_SESSIONS` est une cible soft ; à trancher si un plancher dur est voulu (risque d'INFEASIBLE si capacité insuffisante).
3. **« Au moins une séance tel jour »** (🟡) — le moteur sait (`forcedDays`) mais le wizard ne l'expose pas.

> Détail moteur exhaustif (toutes les clés + mécanismes) : `engine/docs/constraint-vocabulary.md`.
> Offre réellement câblée dans le wizard : `docs/architecture/constraint-matrix.md`.

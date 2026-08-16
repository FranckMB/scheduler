"""Level-2 linear objective for the OR-Tools CP-SAT scheduler model.

The T24 score formula is intentionally fixed. Any change to one of these
weights must be accompanied by a new SCORE_FORMULA_VERSION.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from types import MappingProxyType
from typing import Any, cast

from .compromise import (
    FAMILY_COACH_DAY_CAP,
    FAMILY_DAY,
    FAMILY_MATCH_REST,
    FAMILY_SPACING,
    FAMILY_TIME,
    FAMILY_VENUE,
    CompromiseTermInfo,
)
from .helpers import MISSING, assignment_team_id, assignment_var, get_field, scalar_id
from .model import _format_time, _time_to_minutes

AssignmentLike = Any
BoolVarLike = Any

SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V10"

LEVEL_2_OBJECTIVE_WEIGHTS = MappingProxyType(
    {
        "S": 10000,
        "A": 1000,
        "B": 100,
        "session_count": 20,
        # V10 — LE REMPLISSAGE PRIME SUR LE CONFORT (arbitrage fondateur 2026-08-15 :
        # « s'il y a 90 créneaux je veux 90 placés, quitte à ce que certains n'aient pas
        # ce qu'ils veulent »). Le confort ne sert qu'à départager des solutions qui placent
        # le MÊME nombre de séances. Les poids de confort sont donc recalés SOUS le seuil
        # d'une séance nue (tier D 1 + session_count 20 = 21).
        #
        # P1 — cumul MAX de conforts sur une séance = preferred 10 + preferred_day 5 +
        #      preferred_time 5 = 20 < 21 : une préférence empilée ne vaut jamais une séance
        #      nue. Une séance placée dans un gymnase/jour/heure non préférés bat toujours
        #      un trou.
        # P6 — discriminance vs nudges : preferred 10 > rest(3) + spacing(2), donc PREFERRED
        #      oriente réellement. L'égalité preferred_day(5) = rest(3) + spacing(2) est une
        #      INDIFFÉRENCE ASSUMÉE (arbitrée par l'orchestrateur) : 12/6/6 casserait P1
        #      (24 > 21), 10/6/4 casserait l'égalité jour = heure.
        "preferred": 10,
        # Soft "avoid this venue" (ENG-11): a true malus on the avoided slot —
        # a complement bonus would hand the team a flat objective advantage at
        # every other venue and bias cross-team allocation.
        #
        # P2 — |avoided_venue| 10 < 21 : supprimer une séance pour FUIR un gymnase évité
        #      relâche 10 < 21, donc fuir ne supprime jamais une séance (à −60, fuir en
        #      supprimant la séance relâchait 60 > 21 — le trou vécu côté avoided).
        "avoided_venue": -10,
        # Hiérarchie gymnase > jour préservée (ratio preferred:preferred_day = 2:1) ;
        # égalité jour = heure préservée (preferred_day == preferred_time).
        "preferred_day": 5,
        "preferred_time": 5,
        "C": 10,
        "D": 1,
        "rest": 3,
        # P4-51 — le plafond de jours d'un coach (maxDaysOverride) est PRÉFÉRÉ, pas dur
        # (arbitrage fondateur 2026-08-09) : un malus par jour travaillé AU-DELÀ du
        # plafond. 15 < 21 (valeur minimale d'une séance placée : tier D 1 + session 20),
        # donc regrouper des séances ne peut JAMAIS en supprimer une — seulement les
        # déplacer. Et 15 > rest(3) + spacing(2) : le regroupement bat les nudges. Quand
        # le solveur n'y arrive pas, le diagnostic coach_overload (ENG-24) le dit.
        "overload_day": -15,
        # Implicit spacing nudge (ALIGN-06): a small malus when a team trains on
        # two CONSECUTIVE days. Low weight (< rest) so it only breaks ties — never
        # moves or drops a real placement (each session is worth ≥ 21).
        "spacing": -2,
        # Les 4 règles implicites « bien-être » réglées PREFERRED par le club (V9). Chaque
        # littéral de violation AGRÉGÉ (constraints.py) porte un malus de −6.
        #
        # Preuve d'empilement (patron CHAINING_TIER_WEIGHTS ci-dessous) — pourquoi −6 oriente
        # sans jamais SUPPRIMER une séance. Une séance placée vaut au minimum 21 (tier D 1 +
        # session_count 20). En la retirant, on soulage au pire : 1 littéral âge (un couple
        # inversé de son gymnase-jour) + k littéraux repos (les k coach-personnes de l'équipe)
        # + k littéraux chaîne (mêmes k). Cas dominant k=1 → 3 littéraux → 3×6 = 18 < 21 :
        # supprimer la séance coûte plus que ce qu'elle rapporte en malus évités, jamais
        # rentable. Et 6 > rest(3) + spacing(2) : le malus bat les nudges, donc PREFERRED
        # oriente réellement. Le résiduel k≥2 (équipe à plusieurs coach-personnes) n'est pas
        # couvert par cette arithmétique seule ; il est gardé par le test NR « PREFERRED ne
        # supprime jamais une séance » (fixture pire-cas tier D à 2 coach-personnes).
        "coach_rest_violation": -6,
        "salarie_violation": -6,
        "chain_violation": -6,
        "age_violation": -6,
        # V10 — malus PAR séance SOUS le quota hebdomadaire (une équipe à 1 sur 2 demandées
        # paie −1000 ; 2 manquantes = −2000). Construit dans build_schedule via
        # ``add_missing_session_penalty`` (patron overload_day), passé en soft_terms.
        #
        # P3 — pourquoi les plafonds de confort ne suffisent PAS : le pire swing NET d'un
        #      déplacement bénéficiaire (une équipe gagne son confort maximal 20 en volant
        #      un créneau, ET relâche un avoided_venue 10) = 20 + 10 = 30 > 21. Sans malus,
        #      ce déplacement supprimerait une séance pour un gain de confort de 30 (net +9
        #      après la séance perdue à 21). C'est missing_session qui ferme le trou :
        #      supprimer une séance coûte au moins 1 (tier D) + 20 (session_count) + 1000
        #      (missing_session) = 1021, hors d'atteinte de tout empilement de confort.
        # P4 — dominance : le relief MAX réaliste en supprimant une séance ≈ 107 (confort
        #      direct + littéraux de règles), et chaque déplacement en cascade rend au plus
        #      +30 net ; atteindre 1021 exigerait > 30 mouvements nets — hors de portée des
        #      datasets réels. Le résiduel est gardé par le test NR (même schéma que V9).
        # P5 — les tiers restent souverains : missing_session s'applique SYMÉTRIQUEMENT aux
        #      deux camps d'un conflit de créneau (chaque équipe sous quota paie le sien),
        #      donc il n'inverse jamais l'ordre S > A > B > C > D d'un arbitrage de créneau.
        "missing_session": -1000,
    }
)

# Une équipe à ZÉRO séance paie UNPLACED_PENALTY (question « placée ou pas »), ET, depuis
# V10, spw × |missing_session| (question « combien manque-t-il »). Deux questions distinctes :
# l'ordre 0 placée < 1 placée < 2 placées reste STRICTEMENT décroissant en pénalité.
UNPLACED_PENALTY = 100000

# P3-21 — terme de STABILITÉ (convergence moteur). Chaque variable ``model.x[(team, venue,
# day, start)]`` dont la clé figure dans ``previousAssignments`` porte +STABILITY_TERM_WEIGHT
# dans l'objectif de PHASE 2 (placement déjà verrouillé). But : faire converger une
# régénération vers son placement précédent en DÉPARTAGEANT les ex æquo exacts, jamais en
# arbitrant.
#
# Un créneau HARD n'a PAS de variable (``model.build_model`` l.92-98 : ``continue`` avant le
# ``NewBoolVar``), donc il est absent de ``model.x`` — le terme de stabilité ne peut JAMAIS
# payer double un pin, il ne touche que des créneaux réellement choisis par le solveur.
#
# Séparation LEXICOGRAPHIQUE avec le chaînage — la phase 2 maximise
#   placement + CHAINING_STABILITY_MULTIPLIER × chaînage + STABILITY_TERM_WEIGHT × stabilité.
# Preuve d'empilement (même patron que les « ceilings » de CHAINING_TIER_WEIGHTS) :
#   - masse MAX de stabilité = STABILITY_TERM_WEIGHT × cap(previousAssignments = 2000) = 2000 ;
#   - plus petit incrément de chaînage = min(CHAINING_TIER_WEIGHTS) = 1 (tier D) ;
#   - CHAINING_STABILITY_MULTIPLIER = 4096 > 2000 ⇒ UN SEUL point de chaînage prime TOUTE la
#     stabilité empilée. La stabilité ne départage donc que les solutions à (placement,
#     chaînage) IDENTIQUES — jamais un arbitrage de chaînage.
#   - le placement est verrouillé (``placement_expression >= optimum``) AVANT la phase 2 : la
#     stabilité ne peut pas non plus déplacer une séance (le placement reste optimal). Elle
#     n'arbitre donc ni le score, ni le chaînage — seulement les ex æquo exacts.
# Le score RAPPORTÉ recalcule placement + chaînage aux poids d'ORIGINE (stabilité exclue,
# main._solve) : SCORE_FORMULA_VERSION est inchangé.
STABILITY_TERM_WEIGHT = 1
CHAINING_STABILITY_MULTIPLIER = 4096


def build_stability_terms(
    x: Mapping[Any, BoolVarLike],
    previous_assignments: Iterable[Mapping[str, Any]] | None,
) -> list[tuple[BoolVarLike, int]]:
    """P3-21 — termes de stabilité : +STABILITY_TERM_WEIGHT par variable dont la clé
    ``(team_id, venue_id, day_of_week, start)`` figure dans ``previous_assignments``.

    La clé est normalisée EXACTEMENT comme ``model.x`` (start passé par
    ``_format_time(_time_to_minutes(...))``). Un créneau HARD est absent de ``x`` (pas de
    variable) → jamais compté : pas de double paiement d'un pin. Dédup par clé. Champ
    vide/None → ``[]`` (aucun terme) : l'appelant garde alors le chemin phase-2 historique."""
    terms: list[tuple[BoolVarLike, int]] = []
    if not previous_assignments:
        return terms

    seen: set[tuple[str, str, int, str]] = set()
    for prev in previous_assignments:
        team_id = _scalar_id(_get(prev, "teamId", "team_id", default=None))
        venue_id = _scalar_id(_get(prev, "venueId", "venue_id", default=None))
        day = _get(prev, "dayOfWeek", "day_of_week", default=None)
        start = _get(prev, "startTime", "start_time", default=None)
        if team_id is None or venue_id is None or day is None or start is None:
            continue
        try:
            slot_key = (str(team_id), str(venue_id), int(_scalar_id(day)), _format_time(_time_to_minutes(start)))
        except (TypeError, ValueError):
            continue
        if slot_key in seen:
            continue
        seen.add(slot_key)
        var = x.get(slot_key)
        if var is not None:
            terms.append((var, STABILITY_TERM_WEIGHT))

    return terms


def is_team_satisfied_by_hard_locks(
    team_id: str,
    locked_slots: Iterable[Mapping[str, Any]],
    sessions_per_week: int,
) -> bool:
    """Return True if the team's weekly sessions are fully covered by HARD locks.

    Each entry in *locked_slots* represents one HARD-locked session for a team.
    If the count of HARD locks for *team_id* is greater than or equal to
    *sessions_per_week*, the team is fully satisfied and must NOT receive the
    ``-UNPLACED_PENALTY`` term in the objective.
    """

    hard_count = sum(1 for slot in locked_slots if str(slot.get("team_id", "")) == str(team_id))
    return hard_count >= sessions_per_week


# Small INTEGER tiebreaker weights for the same-venue same-day chaining bonus
# (a PERSON present at both back-to-back sessions — coach OR player of the team).
# The scale below is UNCHANGED; what shifts is that a single consecutive pair can
# now carry up to *k* distinct common people, each its own `chained` term, so the
# pair's total chaining reward stacks to k × weight ≤ 8k rather than a flat ≤ 8.
# Two ceilings still keep it a *bonus*, never a decider:
#   1. Dropping a placed session to chain others costs tier(≥1) + session_count(20)
#      AND fires missing_session (−1000) → ≥ 1021. Even a fully stacked pair
#      (k people at S) would need k > 127 to overcome that — unreachable, since k
#      is the handful of people two adjacent same-venue sessions actually share.
#   2. Between adjacent tiers the smallest placement gap is C−D = 9 (30 vs 21).
#      A lone term (≤ 8) never steals a slot from a higher tier; stacking widens
#      only the C↔D wobble slightly (the club treats C/D as indifferent), and the
#      missing_session floor above still forbids ever dropping a session for it.
# Hence per-person max weight = 8. Order preserved (S>A>B>C>D); each term takes the
# pair's highest tier (chaining SF1(S)+U15F(B) → 8, taken on the S).
CHAINING_TIER_WEIGHTS = MappingProxyType(
    {
        "S": 8,
        "A": 6,
        "B": 4,
        "C": 2,
        "D": 1,
    }
)

TIER_WEIGHT_NAMES = ("S", "A", "B", "C", "D")
BONUS_WEIGHT_NAMES = (
    "preferred",
    "avoided_venue",
    "preferred_day",
    "preferred_time",
    "rest",
    "session_count",
    "spacing",
    "overload_day",
    # V9 — littéraux de violation des règles implicites PREFERRED, passés en soft_terms
    # (jamais des bonus par assignment : aucun champ d'assignment ne porte ces noms).
    "coach_rest_violation",
    "salarie_violation",
    "chain_violation",
    "age_violation",
    # V10 — littéral « une séance de plus manque au quota », passé en soft_terms depuis
    # add_missing_session_penalty (jamais un bonus par assignment : aucun champ ne le porte).
    "missing_session",
)

_PRIORITY_TIER_FIELDS = (
    "priority_tier",
    "priorityTier",
    "priority_tier_id",
    "priorityTierId",
    "tier",
    "tier_id",
    "tierId",
)

_PRIORITY_RANK_FIELDS = ("priority_rank", "priorityRank", "tier_rank", "tierRank")

_TIER_ALIASES = {
    "S": "S",
    "A": "A",
    "B": "B",
    "C": "C",
    "D": "D",
    "TIER_S": "S",
    "TIER_A": "A",
    "TIER_B": "B",
    "TIER_C": "C",
    "TIER_D": "D",
}

_ONE_BASED_TIER_IDS = {1: "S", 2: "A", 3: "B", 4: "C", 5: "D"}
_ZERO_BASED_TIER_RANKS = {0: "S", 1: "A", 2: "B", 3: "C", 4: "D"}

_BONUS_FIELD_ALIASES = {
    "preferred": (
        "preferred",
        "is_preferred",
        "isPreferred",
        "preferred_slot",
        "preferredSlot",
        "preferred_venue",
        "preferredVenue",
    ),
    "rest": ("rest", "rest_satisfied", "restSatisfied", "respects_rest", "respectsRest"),
}

_EXPLICIT_BONUS_FIELDS = (
    "soft_bonuses",
    "softBonuses",
    "objective_bonuses",
    "objectiveBonuses",
    "bonus_weights",
    "bonusWeights",
    "bonuses",
)

_MISSING = MISSING


@dataclass(frozen=True)
class Level2ObjectiveStats:
    """Summary of the T24 objective installed on the CP-SAT model."""

    score_formula_version: str
    placement_terms: int
    soft_bonus_terms: int
    total_terms: int
    chaining_bonus: int
    coefficient_by_assignment: Mapping[Any, int]
    # Two-phase support: the placement-only objective expression and the built
    # chaining terms (var, weight). When add_level_2_objective is called with
    # apply_chaining=False, chaining is NOT in the objective yet — the caller can
    # lock placement (placement_expression >= optimum) then optimise chaining in
    # a bounded second pass. Proving optimality of the tiny chaining bonuses in a
    # single objective is what blows up solve time on real datasets.
    placement_expression: Any = None
    chaining_terms: tuple[tuple[Any, int], ...] = ()


def add_venue_preference_bonus(
    x: Mapping[Any, BoolVarLike],
    parsed: Mapping[str, Any],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Termes soft « gymnase préféré / à éviter » — maison unique génération ⇄ évaluation (D-6).

    Extrait tel quel de l'assemblage inline de ``main.build_schedule`` : le bonus ``preferred``
    tombe sur tout créneau d'un gymnase préféré de l'équipe, le MALUS ``avoided_venue`` sur tout
    créneau d'un gymnase à éviter (un vrai malus, pas un bonus-complément qui biaiserait
    l'allocation inter-équipes). ``info_out`` (défaut None → chemin /generate byte-identique)
    récolte la métadonnée de nommage des compromis.
    """
    preferred_venues: dict[str, set[str]] = parsed.get("preferred_venues", {}) or {}
    avoided_by_team: dict[str, set[str]] = {}
    for avoided in parsed.get("avoided_venues", []) or []:
        avoided_by_team.setdefault(avoided["scope_target_id"], set()).add(avoided["venue_id"])

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for slot_key, var in x.items():
        team_id = str(slot_key[0])
        venue_id = str(slot_key[1])
        preferred_set = preferred_venues.get(team_id)
        if preferred_set is not None and venue_id in preferred_set:
            soft_terms.append((var, "preferred"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=True,
                        key=(FAMILY_VENUE, team_id, venue_id, "preferred"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="preferred",
                    )
                )
        avoided_set = avoided_by_team.get(team_id)
        if avoided_set is not None and venue_id in avoided_set:
            soft_terms.append((var, "avoided_venue"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=False,
                        key=(FAMILY_VENUE, team_id, venue_id, "avoided"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="avoided",
                    )
                )

    return soft_terms


def add_coach_day_cap_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    coaches: Iterable[Any],
    team_coach_map: Mapping[str, list[str]] | None,
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """P4-51 — le plafond de jours d'un coach, en termes SOFT (arbitrage : préféré, pas dur).

    ⚑ Avant cette fonction, ``maxDaysOverride`` n'était appliqué NULLE PART. Pire : la
    contrainte du jour de repos SAUTAIT les coachs dont l'override est ≤ 4, au motif —
    faux — que « le plafond garantit déjà le repos ». Régler « max 3 jours » sur un coach
    RETIRAIT donc sa garantie de repos sans rien plafonner : l'inverse exact du libellé.
    Le skip est mort (`add_coach_rest_day_constraints`), et le plafond agit ici.

    Un littéral par jour au-delà du plafond : ``over_d`` est vrai ssi le coach travaille
    au moins *d* jours (d > plafond). Chaque littéral actif coûte ``overload_day``. Un
    dépassement de 2 jours coûte donc 2 × 15 — proportionnel, et purement booléen.

    Les jours comptés vont de 1 à 7 : le diagnostic ``coach_overload`` (ENG-24) compte
    tous les jours travaillés, samedi compris — l'objectif doit compter comme lui, sinon
    le solveur optimise une définition et le récap en juge une autre.
    """

    terms: list[tuple[BoolVarLike, str]] = []
    if not team_coach_map:
        return terms

    caps: dict[str, int] = {}
    for coach in coaches:
        raw = coach.get("maxDaysOverride") if isinstance(coach, Mapping) else getattr(coach, "max_days_override", None)
        if raw is None and isinstance(coach, Mapping):
            raw = coach.get("max_days_override")
        cid = coach.get("id") if isinstance(coach, Mapping) else getattr(coach, "id", None)
        if cid is not None and raw is not None and 0 < int(raw) < 7:
            caps[str(cid)] = int(raw)

    if not caps:
        return terms

    day_vars: dict[tuple[str, int], list[BoolVarLike]] = {}
    for key, var in x.items():
        team_id, _venue_id, day_of_week, _start = key
        for coach_id in team_coach_map.get(str(team_id), []):
            if str(coach_id) in caps:
                day_vars.setdefault((str(coach_id), int(day_of_week)), []).append(var)

    for coach_id, cap in caps.items():
        is_working: list[BoolVarLike] = []
        for day in range(1, 8):
            vars_of_day = day_vars.get((coach_id, day), [])
            if not vars_of_day:
                continue
            working = cast(Any, model).NewBoolVar(f"cap_is_working_{coach_id}_d{day}")
            day_sum = sum(cast(Any, v) for v in vars_of_day)
            cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(working)
            cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(working.Not())
            is_working.append(working)

        total = sum(cast(Any, w) for w in is_working)
        for over in range(cap + 1, len(is_working) + 1):
            literal = cast(Any, model).NewBoolVar(f"cap_over_{coach_id}_{over}")
            cast(Any, model).Add(total >= over).OnlyEnforceIf(literal)
            cast(Any, model).Add(total <= over - 1).OnlyEnforceIf(literal.Not())
            terms.append((literal, "overload_day"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=literal,
                        family=FAMILY_COACH_DAY_CAP,
                        honored_when_active=False,
                        key=(FAMILY_COACH_DAY_CAP, coach_id),
                        coach_id=coach_id,
                    )
                )

    return terms


def add_missing_session_penalty(
    model: Any,
    assignments_by_team: Mapping[Any, Sequence[BoolVarLike]],
    remaining_by_team: Mapping[str, int],
    weights: Mapping[str, int],
    *,
    hard_satisfied_team_ids: set[str] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """V10 — LE REMPLISSAGE PRIME SUR LE CONFORT : un malus PAR séance sous le quota.

    Pour chaque équipe ayant des variables candidates, ``remaining`` = le nombre de séances
    encore à placer après crédit des verrous HARD (``max(0, spw − verrous HARD)``), fourni
    par ``remaining_by_team`` — la MÊME source que la borne ``sum(vars) <= remaining`` posée
    dans ``build_schedule`` (une seule définition de « combien reste-t-il à placer »).

    Pour ``m`` de 1 à ``remaining``, un littéral ``miss_m`` est vrai ssi ``sum(vars) <=
    remaining − m`` : il compte « au moins m séances manquent ». Chaque littéral actif coûte
    ``missing_session`` (−1000) : 1 manquante → −1000, 2 → −2000, monotone. Une équipe
    satisfaite par des verrous HARD (``remaining`` ≤ 0, ou ``hard_satisfied_team_ids``) ou
    sans variable candidate n'émet AUCUN littéral (ni malus indu, ni terme mort).

    Complète ``UNPLACED_PENALTY`` sans le remplacer : une équipe à zéro paie les deux
    (100000 + spw × 1000). Voir la preuve d'empilement (P3/P4/P5) sur ``missing_session``
    dans ``LEVEL_2_OBJECTIVE_WEIGHTS``.
    """

    if "missing_session" not in weights:
        raise KeyError("missing_session")

    terms: list[tuple[BoolVarLike, str]] = []
    for team_id, team_vars in assignments_by_team.items():
        if not team_vars:
            continue
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        remaining = int(remaining_by_team.get(str(team_id), 0))
        if remaining <= 0:
            continue
        team_sum = sum(cast(Any, v) for v in team_vars)
        for m in range(1, remaining + 1):
            miss = cast(Any, model).NewBoolVar(f"miss_{team_id}_{m}")
            cast(Any, model).Add(team_sum <= remaining - m).OnlyEnforceIf(miss)
            cast(Any, model).Add(team_sum >= remaining - m + 1).OnlyEnforceIf(miss.Not())
            terms.append((miss, "missing_session"))

    return terms


def add_level_2_objective(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    soft_terms: Iterable[Any] = (),
    hard_satisfied_team_ids: set[str] | None = None,
    score_formula_version: str = SCORE_FORMULA_VERSION,
    apply_chaining: bool = True,
    team_player_map: Mapping[str, list[str]] | None = None,
    info_out: list[CompromiseTermInfo] | None = None,
) -> Level2ObjectiveStats:
    """Maximize the fixed T24 weighted score for candidate placements.

    Each selected placement receives the weight of its priority tier (S/A/B/C/D).
    If the placement also marks soft criteria as satisfied, their fixed bonus
    weights are added to the same linear term. Extra soft literals can be passed
    through soft_terms as (literal, weight_name) pairs or mapping/object
    values with var/weight_name fields.

    When *hard_satisfied_team_ids* is provided, teams whose weekly sessions are
    fully covered by HARD locks are excluded from the ``placed.Not() *
    -UNPLACED_PENALTY`` term — their solver variables are forced to 0 by the
    remaining_sessions constraint, which would otherwise trigger the penalty
    even though the team is effectively placed.
    """

    if score_formula_version != SCORE_FORMULA_VERSION:
        raise ValueError(
            f"unsupported score_formula_version {score_formula_version!r}; expected {SCORE_FORMULA_VERSION!r}"
        )

    assignment_list = _normalise_assignments(assignments)
    teams_by_id = _teams_by_id(teams)
    variables: list[BoolVarLike] = []
    coefficients: list[int] = []
    coefficient_by_assignment: dict[Any, int] = {}
    placement_terms = 0
    soft_bonus_terms = 0

    for assignment in assignment_list:
        variable = _var(assignment)
        tier_name = _priority_tier_name(assignment, teams_by_id)
        coefficient = LEVEL_2_OBJECTIVE_WEIGHTS[tier_name]
        active_bonuses = _active_bonus_weight_names(assignment)
        for bonus_name in active_bonuses:
            coefficient += LEVEL_2_OBJECTIVE_WEIGHTS[bonus_name]

        variables.append(variable)
        coefficients.append(coefficient)
        coefficient_by_assignment[_assignment_key(assignment, variable)] = coefficient
        placement_terms += 1
        soft_bonus_terms += len(active_bonuses)

    # Soft bonus: reward every placed session to maximise total session count
    for assignment in assignment_list:
        variable = _var(assignment)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS["session_count"])
        soft_bonus_terms += 1

    # Unplaced penalty: objective -= UNPLACED_PENALTY * (1 - placed) per team.
    assignments_by_team: dict[Any, list[BoolVarLike]] = {}
    for assignment in assignment_list:
        team_id = _team_id(assignment)
        if team_id is not None:
            assignments_by_team.setdefault(team_id, []).append(_var(assignment))

    for team_id, team_vars in assignments_by_team.items():
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        placed = model.NewBoolVar(f"placed_{team_id}")
        model.Add(sum(team_vars) >= 1).OnlyEnforceIf(placed)
        model.Add(sum(team_vars) == 0).OnlyEnforceIf(placed.Not())
        variables.append(placed.Not())
        coefficients.append(-UNPLACED_PENALTY)

    for soft_term in soft_terms:
        variable, weight_name = _soft_term_variable_and_weight(soft_term)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS[weight_name])
        soft_bonus_terms += 1

    # Placement objective (tiers + session_count + unplaced penalty + soft terms).
    placement_expression = (
        sum(coefficient * variable for variable, coefficient in zip(variables, coefficients, strict=False))
        if variables
        else 0
    )

    # Chaining terms are BUILT (vars + linking constraints) regardless — a
    # two-phase caller needs them present in the model. Whether they enter the
    # objective now depends on apply_chaining: single-phase (default) folds them
    # into the one Maximize; two-phase (apply_chaining=False) maximises placement
    # only, then the caller locks placement and optimises chaining separately.
    chaining_pairs = add_chaining_bonus(
        model, assignment_list, teams=teams, team_player_map=team_player_map, info_out=info_out
    )

    if apply_chaining and chaining_pairs:
        model.Maximize(placement_expression + sum(weight * variable for variable, weight in chaining_pairs))
    else:
        model.Maximize(placement_expression)

    return Level2ObjectiveStats(
        score_formula_version=SCORE_FORMULA_VERSION,
        placement_terms=placement_terms,
        soft_bonus_terms=soft_bonus_terms,
        total_terms=len(variables) + len(chaining_pairs),
        chaining_bonus=len(chaining_pairs),
        coefficient_by_assignment=coefficient_by_assignment,
        placement_expression=placement_expression,
        chaining_terms=tuple(chaining_pairs),
    )


def _group_team_slots(
    x: Mapping[Any, BoolVarLike],
) -> dict[str, list[tuple[Any, int | None, int | None, BoolVarLike]]]:
    """Group vars by team once (O(slots)): {team: [(slot_key, day, start_min, var)]}."""
    grouped: dict[str, list[tuple[Any, int | None, int | None, BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        try:
            day: int | None = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            day = None
        grouped.setdefault(str(_scalar_id(slot_key[0])), []).append(
            (slot_key, day, _safe_minutes(slot_key[3]), variable)
        )
    return grouped


def _add_preferred_bonus(
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    family: str,
    weight_name: str,
    criterion: Any,
    matches: Any,
    info_out: list[CompromiseTermInfo] | None = None,
    compromise_family: str | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Shared soft-bonus builder for PREFERRED+<family> windows.

    ``criterion(config)`` extracts a per-window value (or None to skip the
    window); ``matches(day, start_min, crit)`` decides whether a slot earns the
    bonus. This factors add_preferred_day_bonus / add_preferred_time_bonus into
    one place (audit review F3) — a fix to the slot-matching/dedup logic now
    lives once.

    ``info_out``/``compromise_family`` (défaut None → chemin /generate byte-identique)
    récoltent la métadonnée de nommage des compromis, AGRÉGÉE par équipe (une entrée par
    (famille, équipe) : deux créneaux préférés d'une même équipe ne comptent qu'une préférence).
    """
    if weight_name not in weights:
        raise KeyError(weight_name)

    grouped = _group_team_slots(x)
    soft_terms: list[tuple[BoolVarLike, str]] = []
    seen_keys: set[Any] = set()

    for time_window in time_windows:
        if _get(time_window, "ruleType", "rule_type", default=None) != "PREFERRED":
            continue
        if _get(time_window, "family", default=None) != family:
            continue
        team_id = _scalar_id(_get(time_window, "scope_target_id", "scopeTargetId", "team_id", "teamId", default=None))
        if team_id is None:
            continue

        crit = criterion(_get(time_window, "config", default={}) or {})
        if crit is None:
            continue

        for slot_key, day, start_min, variable in grouped.get(str(team_id), []):
            if slot_key in seen_keys:
                continue
            if matches(day, start_min, crit):
                soft_terms.append((variable, weight_name))
                seen_keys.add(slot_key)
                if info_out is not None and compromise_family is not None:
                    info_out.append(
                        CompromiseTermInfo(
                            var=variable,
                            family=compromise_family,
                            honored_when_active=True,
                            key=(compromise_family, str(team_id)),
                            team_id=str(team_id),
                        )
                    )

    return soft_terms


def add_preferred_day_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms for preferred-day windows.

    Two config shapes are honored (ENG-10 — the wizard only ever emits
    ``forbiddenDays`` whatever the ruleType, so a PREFERRED day rule used to be
    a silent placebo when only ``preferredDays`` was read):
    - ``preferredDays``: bonus on those days;
    - ``forbiddenDays`` on a PREFERRED rule: bonus on every day OUTSIDE the set
      — the positive complement of "avoid these days" (equivalent malus, keeps
      all objective coefficients positive). ``preferredDays`` wins when both
      are present.
    """
    del model

    def day_set(config: Mapping[str, Any], key: str) -> set[int]:
        """SEC-13 — UNE seule orthographe : le camelCase du contrat.

        Cette fonction acceptait aussi un alias snake_case (`preferred_days`,
        `forbidden_days`). Personne ne l'a jamais émis, et depuis SEC-13 l'API
        REFUSE les clés hors liste blanche : garder l'alias, c'était garder deux
        façons d'écrire la même règle — donc deux façons de la chercher le jour
        où elle ne s'applique pas. La liste blanche du backend et ce que lit le
        moteur sont désormais le MÊME ensemble, et le job CI « Engine semantics »
        le vérifie clé par clé, par le comportement.
        """
        days: set[int] = set()
        for value in config.get(key) or ():
            try:
                days.add(int(_scalar_id(value)))
            except (TypeError, ValueError):
                continue
        return days

    # AGGREGATE the PREFERRED DAY windows per team FIRST: two independent
    # "avoid Monday" + "avoid Wednesday" complements would otherwise cancel
    # each other through the shared per-slot dedup (each window bonusing the
    # other's avoided day → flat objective, both preferences ignored). One
    # synthetic window per team, avoided = union, preferred = union.
    preferred_by_team: dict[str, set[int]] = {}
    avoided_by_team: dict[str, set[int]] = {}
    for time_window in time_windows:
        if _get(time_window, "ruleType", "rule_type", default=None) != "PREFERRED":
            continue
        if _get(time_window, "family", default=None) != "DAY":
            continue
        team_id = _scalar_id(_get(time_window, "scope_target_id", "scopeTargetId", "team_id", "teamId", default=None))
        if team_id is None:
            continue
        config = _get(time_window, "config", default={}) or {}
        preferred_by_team.setdefault(str(team_id), set()).update(day_set(config, "preferredDays"))
        avoided_by_team.setdefault(str(team_id), set()).update(day_set(config, "forbiddenDays"))

    synthetic_windows: list[dict[str, Any]] = []
    # ENG-25 — `sorted`, et pas seulement pour la forme : ces clés sont des `str`,
    # dont le hash est randomisé par processus (PYTHONHASHSEED). Sans tri, l'ordre
    # des fenêtres synthétiques changeait d'un PROCESSUS à l'autre, donc l'ordre
    # d'ajout des termes à l'objectif, donc le chemin de recherche de CP-SAT : deux
    # runs du MÊME payload avec le MÊME `solverSeed` pouvaient rendre deux
    # affectations différentes (de valeur d'objectif identique — mais un
    # gestionnaire qui régénère à l'identique voyait son planning bouger).
    # ⚠ On NE fige PAS `PYTHONHASHSEED` : ce serait traiter le symptôme, et le
    # figer désarme la protection contre les collisions de hash. L'ordre se
    # décide là où il compte.
    for team_id in sorted(preferred_by_team.keys() | avoided_by_team.keys()):
        preferred = preferred_by_team.get(team_id) or set()
        avoided = avoided_by_team.get(team_id) or set()
        if not preferred and not avoided:
            continue
        synthetic_windows.append(
            {
                "ruleType": "PREFERRED",
                "family": "DAY",
                "scope_target_id": team_id,
                "config": {"preferredDays": sorted(preferred), "forbiddenDays": sorted(avoided)},
            }
        )

    def criterion(config: Mapping[str, Any]) -> tuple[set[int], set[int]] | None:
        preferred = day_set(config, "preferredDays")
        avoided = day_set(config, "forbiddenDays")
        if not preferred and not avoided:
            return None
        return (preferred, avoided)

    def matches(day: int | None, _start: Any, crit: tuple[set[int], set[int]]) -> bool:
        if day is None:
            return False
        preferred, avoided = crit
        if preferred:
            # Explicit preferred days win; a day both preferred and avoided is
            # contradictory — preferred keeps it.
            return day in preferred
        return day not in avoided

    return _add_preferred_bonus(
        x,
        synthetic_windows,
        weights,
        family="DAY",
        weight_name="preferred_day",
        criterion=criterion,
        matches=matches,
        info_out=info_out,
        compromise_family=FAMILY_DAY,
    )


def add_preferred_time_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms for PREFERRED TIME windows.

    A PREFERRED+TIME constraint rewards a team's sessions starting inside
    [minStartTime, maxStartTime] (either bound absent = unconstrained on that
    side). Soft only — never a hard window. A malformed bound is ignored, not a
    500 (audit review).
    """
    del model

    def criterion(config: Mapping[str, Any]) -> tuple[int | None, int | None] | None:
        lo = _safe_minutes(config.get("minStartTime"))
        hi = _safe_minutes(config.get("maxStartTime"))
        return None if lo is None and hi is None else (lo, hi)

    def matches(_day: int | None, start_min: int | None, bounds: tuple[int | None, int | None]) -> bool:
        lo, hi = bounds
        if start_min is None:
            return False
        if lo is not None and start_min < lo:
            return False
        return not (hi is not None and start_min > hi)

    return _add_preferred_bonus(
        x,
        time_windows,
        weights,
        family="TIME",
        weight_name="preferred_time",
        criterion=criterion,
        matches=matches,
        info_out=info_out,
        compromise_family=FAMILY_TIME,
    )


def _safe_minutes(value: Any) -> int | None:
    if value is None:
        return None
    try:
        return _time_to_minutes(value)
    except (TypeError, ValueError):
        return None


def add_match_day_rest_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms rewarding a rest day AFTER a team's match day.

    Implicit rule (no UI constraint): for a team playing on match_day m, the day
    after (m mod 7 + 1) should be left free of training. Nominal case: matches
    Sat/Sun, training Mon-Fri — a Saturday match's rest day (Sunday) simply has
    no slots (no-op); a SUNDAY match makes Monday the rest day, gently avoided.

    Reified per team/week: rest_ok is true iff no session lands on the rest day.
    Safety: placing a session is worth its tier weight + session_count (20) — at
    least 21 — while the rest bonus is only ``rest`` (3), so the solver never
    drops or moves a real placement to collect it; it only breaks ties. No term
    is emitted when the team has no slot that day (avoids a constant bonus that
    would inflate the score).
    """

    if "rest" not in weights:
        raise KeyError("rest")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        match_day = _get(team, "match_day", "matchDay", default=None)
        if team_id is None or match_day is None:
            continue
        try:
            match_day_int = int(_scalar_id(match_day))
        except (TypeError, ValueError):
            continue

        rest_day = match_day_int % 7 + 1
        rest_day_vars = team_day_vars.get(str(team_id), {}).get(rest_day, [])
        if not rest_day_vars:
            continue

        rest_ok = model.NewBoolVar(f"rest_ok_{team_id}")
        model.Add(sum(rest_day_vars) == 0).OnlyEnforceIf(rest_ok)
        model.Add(sum(rest_day_vars) >= 1).OnlyEnforceIf(rest_ok.Not())
        soft_terms.append((rest_ok, "rest"))
        if info_out is not None:
            info_out.append(
                CompromiseTermInfo(
                    var=rest_ok,
                    family=FAMILY_MATCH_REST,
                    honored_when_active=True,
                    key=(FAMILY_MATCH_REST, str(team_id)),
                    team_id=str(team_id),
                )
            )

    return soft_terms


def add_spacing_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Implicit soft rule (ALIGN-06): gently discourage a team training on two
    CONSECUTIVE days (spacing). Malus only — never blocks feasibility; the low
    weight means it only breaks ties, never moves a real placement."""
    if "spacing" not in weights:
        raise KeyError("spacing")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is None:
            continue
        days = team_day_vars.get(str(team_id), {})
        # One reified "team trains on day D" bool per day, reused across both
        # adjacent pairs (D is the right end of (D-1,D) and the left end of
        # (D,D+1)) — building it per-pair doubled the vars/constraints (C8).
        present: dict[int, BoolVarLike] = {}
        for day in sorted(days):
            if day + 1 not in days:
                continue
            for d in (day, day + 1):
                if d not in present:
                    has_d = model.NewBoolVar(f"has_{team_id}_{d}")
                    model.Add(sum(days[d]) >= 1).OnlyEnforceIf(has_d)
                    model.Add(sum(days[d]) == 0).OnlyEnforceIf(has_d.Not())
                    present[d] = has_d
            both = model.NewBoolVar(f"consec_{team_id}_{day}")
            model.AddBoolAnd([present[day], present[day + 1]]).OnlyEnforceIf(both)
            model.AddBoolOr([present[day].Not(), present[day + 1].Not()]).OnlyEnforceIf(both.Not())
            soft_terms.append((both, "spacing"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=both,
                        family=FAMILY_SPACING,
                        honored_when_active=False,
                        key=(FAMILY_SPACING, str(team_id)),
                        team_id=str(team_id),
                    )
                )

    return soft_terms


def add_chaining_bonus(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    team_player_map: Mapping[str, list[str]] | None = None,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Build SOFT bonus terms for same-venue back-to-back sessions.

    For each pair of consecutive slots (A, B) in the same venue on the same
    day where A.end == B.start, and for each PERSON present at both slots —
    a coach of the session OR a player of its team (see *team_player_map*) —
    create a ``chained`` BoolVar that is true when both sessions are placed.
    The bonus weight is ``CHAINING_TIER_WEIGHTS[tier]`` where the tier is the
    highest-tier team across the two sessions.

    *team_player_map* maps ``str(team_id) -> [person_id, ...]`` (built from the
    coach/player links). With it None, only coaches count and the result is
    byte-identical to the coach-only behaviour.

    Returns a list of ``(chained_var, weight)`` terms. The caller MUST fold
    these into its single ``model.Maximize(...)`` — this function must not call
    Maximize itself, or CP-SAT's single-objective model would drop them.
    """

    assignment_list = _normalise_assignments(assignments)
    if len(assignment_list) < 2:
        return []

    teams_by_id = _teams_by_id(teams)

    slot_lookup: dict[tuple[str, str, int], list[dict[str, Any]]] = {}

    for assignment in assignment_list:
        venue_id = _get_venue_id(assignment)
        slot_id = _get_slot_id(assignment)
        if venue_id is None or slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue

        day = parts[0]
        start_minutes = _parse_time_minutes(parts[1])
        if start_minutes is None:
            continue

        start_val = _get(assignment, "start", "start_minute", "starts_at", default=None)
        end_val = _get(assignment, "end", "end_minute", "ends_at", default=None)

        start_min = int(start_val) if start_val is not None else start_minutes
        end_min = int(end_val) if end_val is not None else None

        key = (str(venue_id), day, start_min)
        slot_lookup.setdefault(key, []).append(
            {
                "assignment": assignment,
                "start": start_min,
                "end": end_min,
            }
        )

    chaining_pairs: list[tuple[BoolVarLike, int]] = []
    seen_pairs: set[tuple[str, str]] = set()

    for key, entries in slot_lookup.items():
        venue_id, day, _start_min = key
        for entry in entries:
            end_min = entry["end"]
            if end_min is None:
                continue

            next_key = (venue_id, day, end_min)
            next_entries = slot_lookup.get(next_key)
            if next_entries is None:
                continue

            for next_entry in next_entries:
                pair_id_a = str(_assignment_key(entry["assignment"], _var(entry["assignment"])))
                pair_id_b = str(_assignment_key(next_entry["assignment"], _var(next_entry["assignment"])))
                pair_key = (pair_id_a, pair_id_b)
                if pair_key in seen_pairs:
                    continue
                seen_pairs.add(pair_key)

                persons_a = _person_ids_for(entry["assignment"], team_player_map)
                persons_b = _person_ids_for(next_entry["assignment"], team_player_map)
                common_persons = persons_a & persons_b

                for person_id in common_persons:
                    tier_a = _priority_tier_name(entry["assignment"], teams_by_id)
                    tier_b = _priority_tier_name(next_entry["assignment"], teams_by_id)
                    highest_tier = _higher_tier(tier_a, tier_b)
                    weight = CHAINING_TIER_WEIGHTS.get(highest_tier, 0)
                    if weight == 0:
                        continue

                    var_a = _var(entry["assignment"])
                    var_b = _var(next_entry["assignment"])
                    # Cheap encoding: `chained` only ever appears in the objective
                    # with a positive weight, so two linear upper bounds suffice —
                    # the maximiser pushes it to min(var_a, var_b) = "both placed".
                    # Avoids the reified AddBoolAnd/AddBoolOr + OnlyEnforceIf, which
                    # blow up the model on real datasets (BCCL solve > 30 s).
                    chained = model.NewBoolVar(f"chained_{person_id}_{pair_id_a}_{pair_id_b}")
                    model.Add(chained <= var_a)
                    model.Add(chained <= var_b)

                    chaining_pairs.append((chained, int(weight)))
                    if info_out is not None:
                        try:
                            day_int: int | None = int(day)
                        except (TypeError, ValueError):
                            day_int = None
                        info_out.append(
                            CompromiseTermInfo(
                                var=chained,
                                family="chaining",
                                honored_when_active=True,
                                key=("chaining", str(person_id), venue_id, day, pair_id_a, pair_id_b),
                                venue_id=venue_id,
                                day_of_week=day_int,
                            )
                        )

    return chaining_pairs


def _normalise_assignments(assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike]) -> list[AssignmentLike]:
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return list(assignments)


def _assignment_from_mapping_item(key: Any, variable: BoolVarLike) -> Mapping[str, Any]:
    if isinstance(key, tuple):
        values = list(key)
        return {
            "var": variable,
            "team_id": str(values[0]) if len(values) > 0 and values[0] is not None else None,
            "slot_id": str(values[1]) if len(values) > 1 and values[1] is not None else None,
            "venue_id": str(values[2]) if len(values) > 2 and values[2] is not None else None,
            "coach_id": str(values[3]) if len(values) > 3 and values[3] is not None else None,
            "session_id": str(values[4]) if len(values) > 4 and values[4] is not None else None,
            "id": ":".join(str(value) for value in values),
        }
    return {"var": variable, "id": str(key)}


def _teams_by_id(teams: Iterable[Any]) -> dict[Any, Any]:
    indexed: dict[Any, Any] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is not None:
            indexed[team_id] = team
    return indexed


def _priority_tier_name(assignment: AssignmentLike, teams_by_id: Mapping[Any, Any]) -> str:
    rank_value = _get(assignment, *_PRIORITY_RANK_FIELDS, default=_MISSING)
    if rank_value is not _MISSING:
        return _normalise_priority_rank(rank_value)

    tier_value = _get(assignment, *_PRIORITY_TIER_FIELDS, default=_MISSING)
    if tier_value is not _MISSING:
        return _normalise_priority_tier(tier_value)

    team_id = _team_id(assignment)
    team = teams_by_id.get(team_id)
    if team is not None:
        rank_value = _get(team, *_PRIORITY_RANK_FIELDS, default=_MISSING)
        if rank_value is not _MISSING:
            return _normalise_priority_rank(rank_value)

        tier_value = _get(team, *_PRIORITY_TIER_FIELDS, default=_MISSING)
        if tier_value is not _MISSING:
            return _normalise_priority_tier(tier_value)

    raise ValueError("assignment is missing a priority tier (S/A/B/C/D or priority_tier_id 1..5)")


def _normalise_priority_tier(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ONE_BASED_TIER_IDS:
            return _ONE_BASED_TIER_IDS[scalar]
        raise ValueError(f"unknown priority_tier_id {scalar!r}; expected 1..5")

    text = str(scalar).strip().upper().replace("-", "_").replace(" ", "_")
    if text.isdigit():
        return _normalise_priority_tier(int(text))
    if text in _TIER_ALIASES:
        return _TIER_ALIASES[text]
    raise ValueError(f"unknown priority tier {value!r}; expected S/A/B/C/D or 1..5")


def _normalise_priority_rank(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ZERO_BASED_TIER_RANKS:
            return _ZERO_BASED_TIER_RANKS[scalar]
        raise ValueError(f"unknown priority rank {scalar!r}; expected 0..4")

    text = str(scalar).strip()
    if text.isdigit():
        return _normalise_priority_rank(int(text))
    return _normalise_priority_tier(text)


def _active_bonus_weight_names(assignment: AssignmentLike) -> tuple[str, ...]:
    active: set[str] = set()

    for weight_name, aliases in _BONUS_FIELD_ALIASES.items():
        if any(bool(_get(assignment, alias, default=False)) for alias in aliases):
            active.add(weight_name)

    explicit = _get(assignment, *_EXPLICIT_BONUS_FIELDS, default=())
    if isinstance(explicit, Mapping):
        for weight_name, enabled in explicit.items():
            if enabled:
                active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit is not None and not isinstance(explicit, (str, bytes)):
        for weight_name in explicit:
            active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit:
        active.add(_normalise_bonus_weight_name(explicit))

    return tuple(weight_name for weight_name in BONUS_WEIGHT_NAMES if weight_name in active)


def _soft_term_variable_and_weight(term: Any) -> tuple[BoolVarLike, str]:
    if isinstance(term, Sequence) and not isinstance(term, (str, bytes, Mapping)):
        if len(term) != 2:
            raise ValueError("soft objective term tuples must contain (variable, weight_name)")
        return term[0], _normalise_bonus_weight_name(term[1])

    variable = _var(term)
    weight_name = _get(
        term,
        "weight_name",
        "weightName",
        "objective_weight",
        "objectiveWeight",
        "weight",
        "type",
        "kind",
        default=_MISSING,
    )
    if weight_name is _MISSING:
        raise ValueError("soft objective term is missing a weight_name/objective_weight field")
    return variable, _normalise_bonus_weight_name(weight_name)


def _normalise_bonus_weight_name(value: Any) -> str:
    text = str(_scalar_id(value)).strip()
    normalized = text
    if normalized not in BONUS_WEIGHT_NAMES:
        raise ValueError(f"unknown level-2 bonus weight {value!r}")
    return normalized


def _get(source: Any, *names: str, default: Any = None) -> Any:
    return get_field(source, *names, default=default, skip_none=True)


def _scalar_id(value: Any) -> Any:
    return scalar_id(value)


def _var(assignment: AssignmentLike) -> BoolVarLike:
    return assignment_var(assignment, skip_none=True)


def _team_id(assignment: AssignmentLike) -> Any:
    return assignment_team_id(assignment, skip_none=True)


def _assignment_key(assignment: AssignmentLike, variable: BoolVarLike) -> Any:
    explicit = _scalar_id(_get(assignment, "id", "assignment_id", "assignmentId", "key", default=None))
    if explicit is not None:
        return explicit
    return variable.Index() if hasattr(variable, "Index") else id(variable)


def _get_venue_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(
        _get(assignment, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None)
    )
    return str(result) if result is not None else None


def _get_slot_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(_get(assignment, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None))
    return str(result) if result is not None else None


def _parse_time_minutes(time_str: str) -> int | None:
    try:
        parts = time_str.split(":")
        if len(parts) < 2:
            return None
        return int(parts[0]) * 60 + int(parts[1])
    except (ValueError, TypeError):
        return None


def _coach_ids_for(assignment: AssignmentLike) -> set[str]:
    coach_id = _scalar_id(_get(assignment, "coach_id", "trainer_id", "coach", "trainer", default=None))
    result: set[str] = set()
    if coach_id is not None:
        result.add(str(coach_id))
    return result


def _person_ids_for(
    assignment: AssignmentLike,
    team_player_map: Mapping[str, list[str]] | None,
) -> set[str]:
    """People present at a session: its coach(es) UNION the players of its team.

    A ``set`` gives the double-role dedup for free — someone who both coaches and
    plays the session counts once. With *team_player_map* None the result equals
    ``_coach_ids_for`` exactly (byte-identical legacy behaviour).
    """
    result = _coach_ids_for(assignment)
    if team_player_map:
        team_id = _team_id(assignment)
        if team_id is not None:
            result.update(team_player_map.get(str(team_id), []))
    return result


def _higher_tier(tier_a: str, tier_b: str) -> str:
    tier_order = {"S": 0, "A": 1, "B": 2, "C": 3, "D": 4}
    rank_a = tier_order.get(tier_a, 99)
    rank_b = tier_order.get(tier_b, 99)
    return tier_a if rank_a <= rank_b else tier_b


__all__ = [
    "BONUS_WEIGHT_NAMES",
    "CHAINING_STABILITY_MULTIPLIER",
    "CHAINING_TIER_WEIGHTS",
    "LEVEL_2_OBJECTIVE_WEIGHTS",
    "SCORE_FORMULA_VERSION",
    "STABILITY_TERM_WEIGHT",
    "TIER_WEIGHT_NAMES",
    "UNPLACED_PENALTY",
    "Level2ObjectiveStats",
    "add_chaining_bonus",
    "add_level_2_objective",
    "add_missing_session_penalty",
    "add_preferred_day_bonus",
    "add_venue_preference_bonus",
    "build_stability_terms",
    "is_team_satisfied_by_hard_locks",
]

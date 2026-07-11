import type { Coach, Constraint, Team, TeamTag } from "@/features/wizard/api";
import { compareTeamsByRank } from "@/shared/lib/teamTiers";

import { orderedCoaches } from "./ranking";
import { groupTagsByAxis } from "./tagLabels";

/** Constraint families in the order of the wizard's constraint tabs. */
export const FAMILY_ORDER = ["TIME", "DAY", "FACILITY", "FACILITY_CAPACITY", "COACH_AVAILABILITY"] as const;

export const FAMILY_LABEL: Record<string, string> = {
  TIME: "Horaire",
  DAY: "Jours",
  FACILITY: "Gymnase",
  FACILITY_CAPACITY: "Capacité gymnase",
  COACH_AVAILABILITY: "Dispo coach",
};

/** Absent-from-refs fallback — shared so both order sources tie identically. */
export const RANK_FALLBACK = 9999;

/** Canonical tag order (by axis then label) — the single source for BOTH the
 *  constraint tab's list and the recap, so they never diverge. */
export function orderedTagNames(tags: TeamTag[]): string[] {
  return groupTagsByAxis(tags).flatMap((g) => g.tags).map((t) => t.name);
}

/**
 * A numeric sort key mirroring the constraint tab's section order: group (tag)
 * constraints by axis first, then club-wide, then teams by rank, then coaches
 * in staffing order, then the rest. Reused by the recap so both screens present
 * constraints in the SAME order (avoids two divergent sorts — DRY).
 */
export function makeConstraintRank(
  teams: Team[],
  tags: TeamTag[],
  coaches: Coach[],
  coachPlayerIds: Set<string>,
): (c: Constraint) => number {
  const BAND = { tag: 0, club: 1, team: 2, coach: 3, other: 4 } as const;
  const step = 100_000;

  const tagRank = new Map(orderedTagNames(tags).map((name, i) => [name, i]));
  // Teams ordered by compareTeamsByRank — the SAME comparator the constraint tab
  // uses for its team sections (avoids a divergent order for orphan teams).
  const teamRank = new Map([...teams].sort(compareTeamsByRank).map((t, i) => [t.id, i]));
  const coachRank = new Map(orderedCoaches(coaches, coachPlayerIds).map(({ coach }, i) => [coach.id, i]));

  return (c: Constraint): number => {
    const tag = "string" === typeof c.config?.targetTag ? (c.config.targetTag as string) : null;
    if ("CLUB" === c.scope && null !== tag) {
      return BAND.tag * step + (tagRank.get(tag) ?? RANK_FALLBACK);
    }
    if ("TEAM" === c.scope && null !== c.scopeTargetId) {
      return BAND.team * step + (teamRank.get(c.scopeTargetId) ?? RANK_FALLBACK);
    }
    if ("COACH" === c.scope && null !== c.scopeTargetId) {
      return BAND.coach * step + (coachRank.get(c.scopeTargetId) ?? RANK_FALLBACK);
    }
    if ("CLUB" === c.scope) {
      return BAND.club * step;
    }
    return BAND.other * step;
  };
}

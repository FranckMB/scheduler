import type { Coach, Constraint, PriorityTier, Team, TeamTag } from "@/features/wizard/api";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";

import { orderedCoaches } from "./ranking";
import { groupTagsByAxis } from "./tagLabels";

/** Constraint families in the order of the wizard's constraint tabs. */
export const FAMILY_ORDER = ["TIME", "DAY", "FACILITY", "COACH_AVAILABILITY"] as const;

export const FAMILY_LABEL: Record<string, string> = {
  TIME: "Horaire",
  DAY: "Jours",
  FACILITY: "Gymnase",
  COACH_AVAILABILITY: "Dispo coach",
};

/**
 * A numeric sort key mirroring the constraint tab's section order: group (tag)
 * constraints by axis first, then club-wide, then teams by rank, then coaches
 * in staffing order, then the rest. Reused by the recap so both screens present
 * constraints in the SAME order (avoids two divergent sorts — DRY).
 */
export function makeConstraintRank(
  teams: Team[],
  tiers: PriorityTier[],
  tags: TeamTag[],
  coaches: Coach[],
  coachPlayerIds: Set<string>,
): (c: Constraint) => number {
  const BAND = { tag: 0, club: 1, team: 2, coach: 3, other: 4 } as const;
  const step = 100_000;

  const tagRank = new Map(groupTagsByAxis(tags).flatMap((g) => g.tags).map((t, i) => [t.name, i]));
  const teamRank = new Map(groupTeamsByTier(teams, tiers).flatMap((g) => g.teams).map((t, i) => [t.id, i]));
  const coachRank = new Map(orderedCoaches(coaches, coachPlayerIds).map(({ coach }, i) => [coach.id, i]));

  return (c: Constraint): number => {
    const tag = "string" === typeof c.config?.targetTag ? (c.config.targetTag as string) : null;
    if ("CLUB" === c.scope && null !== tag) {
      return BAND.tag * step + (tagRank.get(tag) ?? 9999);
    }
    if ("TEAM" === c.scope && null !== c.scopeTargetId) {
      return BAND.team * step + (teamRank.get(c.scopeTargetId) ?? 9999);
    }
    if ("COACH" === c.scope && null !== c.scopeTargetId) {
      return BAND.coach * step + (coachRank.get(c.scopeTargetId) ?? 9999);
    }
    if ("CLUB" === c.scope) {
      return BAND.club * step;
    }
    return BAND.other * step;
  };
}

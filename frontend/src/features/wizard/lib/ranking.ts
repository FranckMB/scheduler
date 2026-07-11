import type { Coach, PriorityTier, Team } from "../api";

export interface RankedTeam {
  team: Team;
  /** 1-based global rank across all tiers (1 = most important). */
  globalNumber: number;
}

/**
 * Global order = tiers by importance (id asc: 1=S…5=D), then tierOrder within the
 * tier, then name. The global number lets selectors surface important teams first.
 */
export function orderedTeams(teams: Team[]): RankedTeam[] {
  const sorted = [...teams].sort(
    (a, b) => a.priorityTierId - b.priorityTierId || a.tierOrder - b.tierOrder || a.name.localeCompare(b.name, "fr"),
  );
  return sorted.map((team, index) => ({ team, globalNumber: index + 1 }));
}

/** Teams of a tier, ordered by tierOrder then name. */
export function teamsOfTier(teams: Team[], tierId: number): Team[] {
  return teams
    .filter((t) => t.priorityTierId === tierId)
    .sort((a, b) => a.tierOrder - b.tierOrder || a.name.localeCompare(b.name, "fr"));
}

/** Tiers present, ordered by importance (id asc). */
export function usedTiers(teams: Team[], tiers: PriorityTier[]): PriorityTier[] {
  const present = new Set(teams.map((t) => t.priorityTierId));
  return tiers.filter((t) => present.has(t.id)).sort((a, b) => a.id - b.id);
}

/** Which staffing bucket a coach falls into (drives the display order). */
export type CoachGroup = "salaried" | "player" | "other";

export interface RankedCoach {
  coach: Coach;
  group: CoachGroup;
}

const COACH_GROUP_RANK: Record<CoachGroup, number> = { salaried: 0, player: 1, other: 2 };

/**
 * Display order for coaches: salaried employees first, then coach-players (a coach
 * with an active CoachPlayerMembership), then the rest — each bucket alphabetical.
 * `coachPlayerIds` is the set of coach ids with an active player membership.
 */
export function orderedCoaches(coaches: Coach[], coachPlayerIds: Set<string>): RankedCoach[] {
  const groupOf = (c: Coach): CoachGroup => (c.isEmployee ? "salaried" : coachPlayerIds.has(c.id) ? "player" : "other");
  const fullName = (c: Coach): string => `${c.firstName} ${c.lastName}`.trim();
  return coaches
    .map((coach) => ({ coach, group: groupOf(coach) }))
    .sort((a, b) => COACH_GROUP_RANK[a.group] - COACH_GROUP_RANK[b.group] || fullName(a.coach).localeCompare(fullName(b.coach), "fr"));
}

/**
 * orderedCoaches split into its three buckets (each already ordered), for
 * section/optgroup rendering. `coachPlayerIds` = ids with an active player membership.
 */
export function groupedCoaches(coaches: Coach[], coachPlayerIds: Set<string>): Record<CoachGroup, Coach[]> {
  const groups: Record<CoachGroup, Coach[]> = { salaried: [], player: [], other: [] };
  for (const { coach, group } of orderedCoaches(coaches, coachPlayerIds)) {
    groups[group].push(coach);
  }
  return groups;
}

/** Coach staffing tags ("salarié · coach-joueur") — undefined when neither. */
export function coachMeta(isEmployee: boolean, isPlayer: boolean): string | undefined {
  return [isEmployee ? "salarié" : null, isPlayer ? "coach-joueur" : null].filter(Boolean).join(" · ") || undefined;
}

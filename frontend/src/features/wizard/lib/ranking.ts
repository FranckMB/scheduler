import type { PriorityTier, Team } from "../api";

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

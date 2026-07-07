// Single home for the priority-tier "découpage" S/A/B/C/D shared by the teams
// step and every team selector. The order here is THE canonical team order:
// tiers by importance (id asc, 1=S…5=D), then the manager's manual tierOrder,
// then name. Reordering teams in the wizard (drag & drop) therefore reorders
// them in every selector — one source of truth.

/** Manager-facing meaning of each priority tier (bare letters are FFBB levels). */
export const TIER_MEANING: Record<string, string> = {
  S: "Fanion",
  A: "Importante",
  B: "Moyenne",
  C: "De base",
  D: "Bonus",
};

export interface TierLike {
  id: number;
  label: string;
  name: string;
}

export interface TeamLike {
  id: string;
  name: string;
  priorityTierId: number;
  tierOrder: number;
}

export interface TeamTierGroup<T extends TeamLike> {
  tier: TierLike;
  teams: T[];
}

/** "S · Fanion" — the label of a tier group (optgroup / zone header). */
export const tierGroupLabel = (tier: TierLike): string => `${tier.label} · ${TIER_MEANING[tier.label] ?? tier.name}`;

/**
 * Teams grouped by the tiers actually used, tiers ordered by importance (id
 * asc), teams within a tier by manual tierOrder then name. Empty tiers are
 * dropped so a selector shows only groups that contain teams.
 */
export function groupTeamsByTier<T extends TeamLike>(teams: T[], tiers: TierLike[]): TeamTierGroup<T>[] {
  const present = new Set(teams.map((t) => t.priorityTierId));
  return tiers
    .filter((tier) => present.has(tier.id))
    .sort((a, b) => a.id - b.id)
    .map((tier) => ({
      tier,
      teams: teams
        .filter((t) => t.priorityTierId === tier.id)
        .sort((a, b) => a.tierOrder - b.tierOrder || a.name.localeCompare(b.name, "fr")),
    }));
}

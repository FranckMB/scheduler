import { describe, expect, it } from "vitest";

import { groupTeamsByTier, type TeamLike, tierGroupLabel, type TierLike } from "./teamTiers";

const tiers: TierLike[] = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 2, label: "A", name: "Importante" },
  { id: 3, label: "B", name: "Moyenne" },
  { id: 4, label: "C", name: "De base" },
  { id: 5, label: "D", name: "Bonus" },
];

const team = (id: string, name: string, priorityTierId: number, tierOrder: number): TeamLike => ({ id, name, priorityTierId, tierOrder });

describe("groupTeamsByTier", () => {
  it("groups by tier in importance order (S→D), then tierOrder within a tier", () => {
    const teams = [team("d1", "Loisir", 5, 0), team("s2", "SM2", 1, 1), team("s1", "SM1", 1, 0), team("b1", "U15", 3, 0)];
    const groups = groupTeamsByTier(teams, tiers);
    expect(groups.map((g) => g.tier.label)).toEqual(["S", "B", "D"]); // only used tiers, ordered
    expect(groups[0].teams.map((t) => t.id)).toEqual(["s1", "s2"]); // tierOrder 0 before 1
  });

  it("drops empty tiers so a selector shows only groups with teams", () => {
    const groups = groupTeamsByTier([team("a1", "SF1", 2, 0)], tiers);
    expect(groups).toHaveLength(1);
    expect(groups[0].tier.label).toBe("A");
  });

  it("falls back to name order when tierOrder ties", () => {
    const teams = [team("z", "Zoulou", 1, 0), team("a", "Alpha", 1, 0)];
    expect(groupTeamsByTier(teams, tiers)[0].teams.map((t) => t.name)).toEqual(["Alpha", "Zoulou"]);
  });
});

describe("tierGroupLabel", () => {
  it("renders 'letter · meaning'", () => {
    expect(tierGroupLabel(tiers[0])).toBe("S · Fanion");
  });

  it("falls back to the tier name for an unknown letter", () => {
    expect(tierGroupLabel({ id: 9, label: "Z", name: "Custom" })).toBe("Z · Custom");
  });
});

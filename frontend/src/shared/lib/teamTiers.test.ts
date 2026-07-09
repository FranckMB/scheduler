import { describe, expect, it } from "vitest";

import { compareTeamsByRank, groupTeamsByTier, type TeamLike, tierGroupLabel, type TierLike } from "./teamTiers";

const tiers: TierLike[] = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 2, label: "A", name: "Importante" },
  { id: 3, label: "B", name: "Moyenne" },
  { id: 4, label: "C", name: "De base" },
  { id: 5, label: "D", name: "Bonus" },
];

const team = (id: string, name: string, priorityTierId: number, tierOrder: number): TeamLike => ({ id, name, priorityTierId, tierOrder });

describe("compareTeamsByRank", () => {
  it("orders by tier (S→D), then tierOrder, then name — beating alphabetical", () => {
    const teams = [team("d", "Alpha", 5, 0), team("s1", "Zoulou", 1, 1), team("s0", "Yankee", 1, 0)];
    expect([...teams].sort(compareTeamsByRank).map((t) => t.name)).toEqual(["Yankee", "Zoulou", "Alpha"]);
  });
});

describe("groupTeamsByTier", () => {
  it("groups by tier in importance order (S→D), then tierOrder within a tier", () => {
    const teams = [team("d1", "Loisir", 5, 0), team("s2", "SM2", 1, 1), team("s1", "SM1", 1, 0), team("b1", "U15", 3, 0)];
    const groups = groupTeamsByTier(teams, tiers);
    expect(groups.map((g) => g.tier?.label)).toEqual(["S", "B", "D"]); // only used tiers, ordered (all known → no null bucket)
    expect(groups[0].teams.map((t) => t.id)).toEqual(["s1", "s2"]); // tierOrder 0 before 1
  });

  it("drops empty tiers so a selector shows only groups with teams", () => {
    const groups = groupTeamsByTier([team("a1", "SF1", 2, 0)], tiers);
    expect(groups).toHaveLength(1);
    expect(groups[0].tier?.label).toBe("A");
  });

  it("falls back to name order when tierOrder ties", () => {
    const teams = [team("z", "Zoulou", 1, 0), team("a", "Alpha", 1, 0)];
    expect(groupTeamsByTier(teams, tiers)[0].teams.map((t) => t.name)).toEqual(["Alpha", "Zoulou"]);
  });

  it("NEVER drops a team whose tier is not in the loaded set — trailing null bucket", () => {
    const teams = [team("s1", "SM1", 1, 0), team("x1", "Mystère", 99, 0)];
    const groups = groupTeamsByTier(teams, tiers);
    expect(groups.map((g) => g.tier?.label ?? "NULL")).toEqual(["S", "NULL"]);
    expect(groups.at(-1)?.teams.map((t) => t.id)).toEqual(["x1"]);
  });

  it("puts ALL teams in the null bucket when tiers are not loaded yet", () => {
    const groups = groupTeamsByTier([team("a", "Alpha", 1, 0)], []);
    expect(groups).toHaveLength(1);
    expect(groups[0].tier).toBeNull();
  });
});

describe("tierGroupLabel", () => {
  it("renders 'letter · meaning'", () => {
    expect(tierGroupLabel(tiers[0])).toBe("S · Fanion");
  });

  it("falls back to the tier name for an unknown letter", () => {
    expect(tierGroupLabel({ id: 9, label: "Z", name: "Custom" })).toBe("Z · Custom");
  });

  it("labels the null (unranked) bucket 'Autres'", () => {
    expect(tierGroupLabel(null)).toBe("Autres");
  });
});

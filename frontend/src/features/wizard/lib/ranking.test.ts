import { describe, expect, it } from "vitest";

import type { PriorityTier, Team } from "../api";
import { orderedTeams, teamsOfTier, usedTiers } from "./ranking";

function team(over: Partial<Team>): Team {
  return { id: "id", name: "T", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, sessionsPerWeek: 2, isActive: true, ...over };
}

const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Elite", color: null },
  { id: 2, label: "A", name: "Régional+", color: null },
  { id: 5, label: "D", name: "Loisir", color: null },
];

describe("orderedTeams", () => {
  it("ranks by tier (id asc) then tierOrder then name", () => {
    const teams = [
      team({ id: "loisir", name: "Loisir1", priorityTierId: 5, tierOrder: 0 }),
      team({ id: "sB", name: "SF2", priorityTierId: 1, tierOrder: 1 }),
      team({ id: "sA", name: "SF1", priorityTierId: 1, tierOrder: 0 }),
      team({ id: "a", name: "U18", priorityTierId: 2, tierOrder: 0 }),
    ];
    expect(orderedTeams(teams).map((r) => [r.team.id, r.globalNumber])).toEqual([
      ["sA", 1],
      ["sB", 2],
      ["a", 3],
      ["loisir", 4],
    ]);
  });
});

describe("teamsOfTier / usedTiers", () => {
  const teams = [team({ id: "a", priorityTierId: 1, tierOrder: 1 }), team({ id: "b", priorityTierId: 1, tierOrder: 0 }), team({ id: "c", priorityTierId: 2 })];
  it("orders teams within a tier by tierOrder", () => {
    expect(teamsOfTier(teams, 1).map((t) => t.id)).toEqual(["b", "a"]);
  });
  it("lists only present tiers, by importance", () => {
    expect(usedTiers(teams, tiers).map((t) => t.id)).toEqual([1, 2]);
  });
});

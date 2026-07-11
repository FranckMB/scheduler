import { describe, expect, it } from "vitest";

import type { Coach, PriorityTier, Team } from "../api";
import { coachMeta, groupedCoaches, orderedCoaches, orderedTeams, teamsOfTier, usedTiers } from "./ranking";

function team(over: Partial<Team>): Team {
  return { id: "id", name: "T", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true, ...over };
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

function coach(over: Partial<Coach>): Coach {
  return { id: "id", firstName: "F", lastName: "L", email: null, isEmployee: false, isActive: true, ...over };
}

describe("orderedCoaches", () => {
  it("orders salaried first, then coach-players, then the rest — each alphabetical", () => {
    const coaches = [
      coach({ id: "other-z", firstName: "Zoe", isEmployee: false }),
      coach({ id: "player-1", firstName: "Bob", isEmployee: false }),
      coach({ id: "salaried-2", firstName: "Bea", isEmployee: true }),
      coach({ id: "salaried-1", firstName: "Ana", isEmployee: true }),
      coach({ id: "other-a", firstName: "Amy", isEmployee: false }),
      coach({ id: "player-2", firstName: "Cid", isEmployee: false }),
    ];
    const players = new Set(["player-1", "player-2"]);
    expect(orderedCoaches(coaches, players).map((r) => [r.coach.id, r.group])).toEqual([
      ["salaried-1", "salaried"],
      ["salaried-2", "salaried"],
      ["player-1", "player"],
      ["player-2", "player"],
      ["other-a", "other"],
      ["other-z", "other"],
    ]);
  });

  it("treats a salaried coach-player as salaried (salaried wins)", () => {
    const coaches = [coach({ id: "sp", isEmployee: true })];
    expect(orderedCoaches(coaches, new Set(["sp"]))[0].group).toBe("salaried");
  });
});

describe("groupedCoaches", () => {
  it("splits coaches into the three ordered buckets", () => {
    const coaches = [coach({ id: "o", firstName: "Zoe" }), coach({ id: "p", firstName: "Bob" }), coach({ id: "s", firstName: "Ana", isEmployee: true })];
    const groups = groupedCoaches(coaches, new Set(["p"]));
    expect(groups.salaried.map((c) => c.id)).toEqual(["s"]);
    expect(groups.player.map((c) => c.id)).toEqual(["p"]);
    expect(groups.other.map((c) => c.id)).toEqual(["o"]);
  });
});

describe("coachMeta", () => {
  it("joins the tags, undefined when neither", () => {
    expect(coachMeta(true, true)).toBe("salarié · coach-joueur");
    expect(coachMeta(true, false)).toBe("salarié");
    expect(coachMeta(false, true)).toBe("coach-joueur");
    expect(coachMeta(false, false)).toBeUndefined();
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

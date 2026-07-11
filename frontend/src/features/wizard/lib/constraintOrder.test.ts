import { describe, expect, it } from "vitest";

import type { Coach, Constraint, PriorityTier, Team, TeamTag } from "@/features/wizard/api";

import { FAMILY_ORDER, makeConstraintRank } from "./constraintOrder";

const teams = [
  { id: "t-b", name: "SM1", priorityTierId: 3 },
  { id: "t-s", name: "Fanion", priorityTierId: 1 },
] as unknown as Team[];
const tiers = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 3, label: "B", name: "Moyenne" },
] as unknown as PriorityTier[];
const tags = [
  { id: "g-fem", name: "FEMININE", axis: "GENRE" },
  { id: "g-adulte", name: "SENIOR", axis: "AGE" },
] as unknown as TeamTag[];
const coaches = [
  { id: "co-vol", firstName: "Zoe", lastName: "V", isEmployee: false },
  { id: "co-sal", firstName: "Ana", lastName: "S", isEmployee: true },
] as unknown as Coach[];

const c = (over: Partial<Constraint>): Constraint => ({ id: "x", name: "n", scope: "TEAM", scopeTargetId: null, family: "TIME", ruleType: "HARD", config: {}, isActive: true, ...over }) as Constraint;

describe("makeConstraintRank", () => {
  const rank = makeConstraintRank(teams, tiers, tags, coaches, new Set());

  it("orders bands: tag → club → team → coach → other", () => {
    const tag = rank(c({ scope: "CLUB", scopeTargetId: null, config: { targetTag: "FEMININE" } }));
    const club = rank(c({ scope: "CLUB", scopeTargetId: null, config: {} }));
    const team = rank(c({ scope: "TEAM", scopeTargetId: "t-s" }));
    const coach = rank(c({ scope: "COACH", scopeTargetId: "co-sal" }));
    expect(tag).toBeLessThan(club);
    expect(club).toBeLessThan(team);
    expect(team).toBeLessThan(coach);
  });

  it("within teams, follows the rank (S before B)", () => {
    expect(rank(c({ scope: "TEAM", scopeTargetId: "t-s" }))).toBeLessThan(rank(c({ scope: "TEAM", scopeTargetId: "t-b" })));
  });

  it("within tags, follows axis order (Genre before Âge)", () => {
    expect(rank(c({ scope: "CLUB", scopeTargetId: null, config: { targetTag: "FEMININE" } }))).toBeLessThan(
      rank(c({ scope: "CLUB", scopeTargetId: null, config: { targetTag: "SENIOR" } })),
    );
  });

  it("within coaches, salaried before volunteer", () => {
    expect(rank(c({ scope: "COACH", scopeTargetId: "co-sal" }))).toBeLessThan(rank(c({ scope: "COACH", scopeTargetId: "co-vol" })));
  });

  it("FAMILY_ORDER matches the constraint tabs", () => {
    expect(FAMILY_ORDER).toEqual(["TIME", "DAY", "FACILITY", "COACH_AVAILABILITY"]);
  });
});

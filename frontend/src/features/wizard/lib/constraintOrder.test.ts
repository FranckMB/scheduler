import { describe, expect, it } from "vitest";

import type { Coach, Constraint, PriorityTier, Team, TeamTag, Venue } from "@/features/wizard/api";

import { FAMILY_ORDER, groupConstraints } from "./constraintOrder";

const teams = [
  { id: "t-b", name: "SM1", priorityTierId: 3, tierOrder: 0 },
  { id: "t-s", name: "Fanion", priorityTierId: 1, tierOrder: 0 },
] as unknown as Team[];
const tags = [
  { id: "g-fem", name: "FEMININE", axis: "GENRE" },
  { id: "g-adulte", name: "SENIOR", axis: "AGE" },
] as unknown as TeamTag[];
const coaches = [
  { id: "co-vol", firstName: "Zoe", lastName: "V", isEmployee: false },
  { id: "co-sal", firstName: "Ana", lastName: "S", isEmployee: true },
] as unknown as Coach[];
const venues = [
  { id: "v-camus", name: "Camus" },
  { id: "v-mateo", name: "Matéo" },
] as unknown as Venue[];

const tiers = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 3, label: "B", name: "Moyenne" },
] as unknown as PriorityTier[];

const ctx = {
  teams,
  tiers,
  tags,
  coaches,
  coachPlayerIds: new Set<string>(),
  venues,
  coachName: (id: string) => coaches.find((c) => c.id === id)?.firstName ?? "Coach",
  venueName: (id: string) => venues.find((v) => v.id === id)?.name ?? "Gymnase",
};

const c = (over: Partial<Constraint>): Constraint => ({ id: Math.random().toString(), name: "n", scope: "TEAM", scopeTargetId: null, family: "TIME", ruleType: "HARD", config: {}, isActive: true, ...over }) as Constraint;

describe("groupConstraints", () => {
  it("FAMILY_ORDER covers every constraint family (FACILITY_CAPACITY included)", () => {
    expect(FAMILY_ORDER).toEqual(["TIME", "DAY", "FACILITY", "FACILITY_CAPACITY", "COACH_AVAILABILITY"]);
  });

  it("TIME/DAY → groups by tag axis (Genre before Âge), then teams by their RANG group", () => {
    const sections = groupConstraints(
      [
        c({ scope: "CLUB", config: { targetTag: "SENIOR" } }), // axis AGE
        c({ scope: "CLUB", config: { targetTag: "FEMININE" } }), // axis GENRE
        c({ scope: "TEAM", scopeTargetId: "t-b" }), // SM1 (tier B)
        c({ scope: "TEAM", scopeTargetId: "t-s" }), // Fanion (tier S)
      ],
      "TIME",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Genre", "Âge", "S · Fanion", "B · Moyenne"]);
  });

  it("FACILITY → groups by venue, A→Z", () => {
    const sections = groupConstraints(
      [
        c({ family: "FACILITY", config: { preferredVenueId: "v-mateo" } }),
        c({ family: "FACILITY", config: { forcedVenueId: "v-camus" } }),
      ],
      "FACILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Camus", "Matéo"]);
  });

  it("COACH_AVAILABILITY → groups by staffing (Salariés before Bénévoles)", () => {
    const sections = groupConstraints(
      [
        c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-vol" }),
        c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-sal" }),
      ],
      "COACH_AVAILABILITY",
      ctx,
    ).map((s) => s.label);
    expect(sections).toEqual(["Salariés", "Bénévoles"]);
  });

  it("never drops a coach constraint whose coach is absent (→ « Coach retiré »)", () => {
    const sections = groupConstraints([c({ family: "COACH_AVAILABILITY", scope: "COACH", scopeTargetId: "co-gone" })], "COACH_AVAILABILITY", ctx);
    expect(sections).toHaveLength(1);
    expect(sections[0].label).toBe("Coach retiré");
    expect(sections[0].items).toHaveLength(1);
  });
});

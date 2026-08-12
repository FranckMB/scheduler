import { describe, expect, it } from "vitest";

import type { Constraint, Slot } from "../api";
import { applicableConstraints } from "./applicableConstraints";

const slot = (over: Partial<Slot> = {}): Slot => ({
  id: "s1",
  scheduleId: "sch1",
  teamId: "team-A",
  venueId: "venue-1",
  coachId: "coach-X",
  dayOfWeek: 2,
  startTime: "18:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
  temporaryLock: false,
  ...over,
});

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "Contrainte",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "HARD",
  isActive: true,
  ...over,
});

describe("applicableConstraints", () => {
  it("garde une contrainte CLUB pour tout créneau", () => {
    const club = constraint({ id: "club", scope: "CLUB", scopeTargetId: null });
    expect(applicableConstraints(slot(), [club]).map((c) => c.id)).toEqual(["club"]);
  });

  it("garde une contrainte TEAM seulement pour SON équipe", () => {
    const mine = constraint({ id: "mine", scope: "TEAM", scopeTargetId: "team-A" });
    const other = constraint({ id: "other", scope: "TEAM", scopeTargetId: "team-B" });
    expect(applicableConstraints(slot(), [mine, other]).map((c) => c.id)).toEqual(["mine"]);
  });

  it("garde une contrainte FACILITY seulement pour SON gymnase", () => {
    const here = constraint({ id: "here", scope: "FACILITY", scopeTargetId: "venue-1" });
    const elsewhere = constraint({ id: "elsewhere", scope: "FACILITY", scopeTargetId: "venue-9" });
    expect(applicableConstraints(slot(), [here, elsewhere]).map((c) => c.id)).toEqual(["here"]);
  });

  it("garde une contrainte COACH seulement pour SON coach (jamais si le créneau n'a pas de coach)", () => {
    const coach = constraint({ id: "coach", scope: "COACH", scopeTargetId: "coach-X" });
    expect(applicableConstraints(slot(), [coach]).map((c) => c.id)).toEqual(["coach"]);
    expect(applicableConstraints(slot({ coachId: null }), [coach])).toEqual([]);
  });

  it("écarte les contraintes inactives", () => {
    const off = constraint({ id: "off", scope: "CLUB", isActive: false });
    expect(applicableConstraints(slot(), [off])).toEqual([]);
  });
});

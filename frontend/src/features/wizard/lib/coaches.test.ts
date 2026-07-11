import { describe, expect, it } from "vitest";

import type { Coach, CoachPlayerMembership } from "../api";
import { coachCategory, groupCoaches } from "./coaches";

const coach = (over: Partial<Coach>): Coach => ({ id: "c", firstName: "A", lastName: "Z", email: null, isEmployee: false, isActive: true, ...over });
const player = (coachId: string): CoachPlayerMembership => ({ id: "m-" + coachId, teamId: "t", coachId, isActive: true });

describe("coachCategory", () => {
  it("classes an employee as salarié even if they also play", () => {
    expect(coachCategory(coach({ id: "c1", isEmployee: true }), [player("c1")])).toBe("salarie");
  });
  it("classes a non-employee with a player membership as coach-joueur", () => {
    expect(coachCategory(coach({ id: "c2" }), [player("c2")])).toBe("coach_joueur");
  });
  it("classes the rest as bénévole", () => {
    expect(coachCategory(coach({ id: "c3" }), [])).toBe("benevole");
  });
});

describe("groupCoaches", () => {
  it("splits into the three groups, each sorted by first name", () => {
    const coaches = [
      coach({ id: "s2", firstName: "Zoé", isEmployee: true }),
      coach({ id: "s1", firstName: "Alice", isEmployee: true }),
      coach({ id: "j1", firstName: "Bruno" }),
      coach({ id: "b2", firstName: "Yves" }),
      coach({ id: "b1", firstName: "Chloé" }),
    ];
    const { salaries, coachJoueurs, benevoles } = groupCoaches(coaches, [player("j1")]);
    expect(salaries.map((c) => c.firstName)).toEqual(["Alice", "Zoé"]);
    expect(coachJoueurs.map((c) => c.firstName)).toEqual(["Bruno"]);
    expect(benevoles.map((c) => c.firstName)).toEqual(["Chloé", "Yves"]);
  });
});

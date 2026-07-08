import { describe, expect, it } from "vitest";

import { humanizeConstraintError } from "./constraintErrors";

describe("humanizeConstraintError", () => {
  it("maps a known validator message to French", () => {
    expect(humanizeConstraintError("FACILITY family requires venueId, forbiddenVenueId or preferredVenueId in config.")).toBe("Une contrainte de gymnase doit désigner un gymnase.");
  });

  it("maps a contradiction message", () => {
    expect(humanizeConstraintError("Contradictory day constraints: allowed days overlap with forbidden days.")).toBe("Contraintes de jour contradictoires : un même jour est à la fois autorisé et interdit.");
  });

  it("describes the time contradiction as start bounds, not a non-existent end time", () => {
    const fr = humanizeConstraintError("Contradictory time constraints: maxStartTime is less than minStartTime.");
    expect(fr).toContain("heure de début");
    expect(fr).not.toContain("heure de fin");
  });

  it("handles the parametrised scope message for any family", () => {
    expect(humanizeConstraintError("Scope TEAM requires a scope_target_id.")).toBe("Cette contrainte doit cibler une équipe ou un groupe.");
    expect(humanizeConstraintError("Scope GROUP requires a scope_target_id.")).toBe("Cette contrainte doit cibler une équipe ou un groupe.");
  });

  it("passes unknown messages through unchanged", () => {
    expect(humanizeConstraintError("Some brand new message")).toBe("Some brand new message");
  });
});

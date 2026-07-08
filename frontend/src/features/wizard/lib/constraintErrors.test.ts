import { describe, expect, it } from "vitest";

import { humanizeConstraintError } from "./constraintErrors";

describe("humanizeConstraintError", () => {
  it("maps a known validator message to French", () => {
    expect(humanizeConstraintError("FACILITY family requires venueId or targetTag in config.")).toBe("Une contrainte de gymnase doit cibler un gymnase.");
  });

  it("maps a contradiction message", () => {
    expect(humanizeConstraintError("Contradictory day constraints: allowed days overlap with forbidden days.")).toBe("Contraintes de jour contradictoires : un même jour est à la fois autorisé et interdit.");
  });

  it("handles the parametrised scope message for any family", () => {
    expect(humanizeConstraintError("Scope TEAM requires a scope_target_id.")).toBe("Cette contrainte doit cibler une équipe ou un groupe.");
    expect(humanizeConstraintError("Scope GROUP requires a scope_target_id.")).toBe("Cette contrainte doit cibler une équipe ou un groupe.");
  });

  it("passes unknown messages through unchanged", () => {
    expect(humanizeConstraintError("Some brand new message")).toBe("Some brand new message");
  });
});

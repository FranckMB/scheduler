import { describe, expect, it } from "vitest";

import { isPasswordValid, passwordChecks, validatePassword } from "./passwordPolicy";

describe("passwordPolicy (mirrors backend)", () => {
  it("accepts ≥12 chars with an uppercase and a special char", () => {
    expect(validatePassword("Password123!")).toBeNull();
    expect(isPasswordValid("Château-Fort-42")).toBe(true);
  });

  it("rejects too short (< 12)", () => {
    expect(isPasswordValid("Passw0rd!23")).toBe(false); // 11
  });

  it("rejects missing uppercase", () => {
    expect(isPasswordValid("password123!x")).toBe(false);
  });

  it("rejects missing special char", () => {
    expect(isPasswordValid("Password12345")).toBe(false);
  });

  it("reports each rule independently for the live checklist", () => {
    expect(passwordChecks("")).toEqual({ length: false, upper: false, special: false });
    expect(passwordChecks("aaaaaaaaaaaa")).toEqual({ length: true, upper: false, special: false });
    expect(passwordChecks("Aaaaaaaaaaaa")).toEqual({ length: true, upper: true, special: false });
    expect(passwordChecks("Password123!")).toEqual({ length: true, upper: true, special: true });
  });
});

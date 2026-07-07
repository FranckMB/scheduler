import { describe, expect, it } from "vitest";

import { isPasswordValid, validatePassword } from "./passwordPolicy";

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
});

import { beforeEach, describe, expect, it } from "vitest";

import { useAuthStore } from "./authStore";

describe("authStore", () => {
  beforeEach(() => {
    useAuthStore.getState().clear();
  });

  it("sets and clears the token", () => {
    useAuthStore.getState().setToken("jwt-123");
    expect(useAuthStore.getState().token).toBe("jwt-123");
    useAuthStore.getState().clear();
    expect(useAuthStore.getState().token).toBeNull();
  });

  it("migrate returns a safe default on null persisted state (Zustand 5 null-check)", () => {
    const options = useAuthStore.persist.getOptions();
    const migrated = options.migrate?.(null, 1) as { token: string | null };
    expect(migrated.token).toBeNull();
  });
});

import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { listSchedules } from "./api";

// UX-02 silent regression: API Platform 4 OMITS null fields from JSON, so a null
// `planType`/`schedulePlanId` (an anomalous unlinked version) arrives ABSENT
// (undefined). Every `"SEASON" === planType` / grouping-by-plan check would then
// silently mis-fire. listSchedules must normalise the boundary to a real null.
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));

const mockGet = vi.mocked(api.get);
const respondOnce = (member: unknown[]) => mockGet.mockReturnValueOnce({ json: async () => ({ member }) } as never);

describe("listSchedules — null normalisation (UX-02)", () => {
  afterEach(() => mockGet.mockReset());

  it("normalises ABSENT planType/schedulePlanId (unlinked anomaly) to null", async () => {
    // A version whose null plan fields the backend omitted entirely.
    respondOnce([{ id: "s1", name: "Plan", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y" }]);
    const [plan] = await listSchedules();
    expect(plan.planType).toBeNull(); // pre-fix: undefined → socle checks silently fail
    expect(plan.schedulePlanId).toBeNull();
  });

  it("preserves a present planType/schedulePlanId (period overlay)", async () => {
    respondOnce([{ id: "s2", name: "Overlay", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y", planType: "CLOSURE", schedulePlanId: "plan-9" }]);
    const [overlay] = await listSchedules();
    expect(overlay.planType).toBe("CLOSURE");
    expect(overlay.schedulePlanId).toBe("plan-9");
  });

  it("normalises an ABSENT score (DRAFT/in-flight plan) to null", async () => {
    // A null score is omitted too → would render the literal "score undefined".
    respondOnce([{ id: "s3", name: "Draft", status: "DRAFT", createdAt: "x", updatedAt: "y" }]);
    const [draft] = await listSchedules();
    expect(draft.score).toBeNull();
  });
});

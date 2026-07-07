import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { listSchedules } from "./api";

// UX-02 silent regression: API Platform 4 OMITS null fields from JSON, so a
// season plan's `calendarEntryId: null` arrives ABSENT (undefined). Every
// `null === calendarEntryId` overlay check then silently mis-fires and the
// planning lands on nothing. listSchedules must normalise the boundary.
vi.mock("@/shared/api/client", () => ({ api: { get: vi.fn() } }));

const mockGet = vi.mocked(api.get);
const respondOnce = (member: unknown[]) => mockGet.mockReturnValueOnce({ json: async () => ({ member }) } as never);

describe("listSchedules — null normalisation (UX-02)", () => {
  afterEach(() => mockGet.mockReset());

  it("normalises an ABSENT calendarEntryId (season plan) to null", async () => {
    // Exactly what the backend sends for a season plan — no calendarEntryId key.
    respondOnce([{ id: "s1", name: "Plan", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y" }]);
    const [plan] = await listSchedules();
    expect(plan.calendarEntryId).toBeNull(); // pre-fix: undefined → overlay checks silently fail
  });

  it("preserves a present calendarEntryId (period overlay)", async () => {
    respondOnce([{ id: "s2", name: "Overlay", status: "COMPLETED", score: null, createdAt: "x", updatedAt: "y", calendarEntryId: "entry-9" }]);
    const [overlay] = await listSchedules();
    expect(overlay.calendarEntryId).toBe("entry-9");
  });

  it("normalises an ABSENT score (DRAFT/in-flight plan) to null", async () => {
    // A null score is omitted too → would render the literal "score undefined".
    respondOnce([{ id: "s3", name: "Draft", status: "DRAFT", createdAt: "x", updatedAt: "y" }]);
    const [draft] = await listSchedules();
    expect(draft.score).toBeNull();
  });
});

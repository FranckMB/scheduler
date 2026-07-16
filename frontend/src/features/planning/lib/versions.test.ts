import { describe, expect, it } from "vitest";

import type { Schedule } from "../api";
import { liveContextScheduleId, overlayVersionLabels, versionLabels, visibleOverlayVersions, visibleSeasonPlans } from "./versions";

const plan = (over: Partial<Schedule>): Schedule => ({
  id: "id",
  name: "Plan",
  status: "COMPLETED",
  score: null,
  createdAt: "2026-07-01T10:00:00+00:00",
  updatedAt: "2026-07-01T10:00:00+00:00",
  calendarEntryId: null,
  ...over,
});

describe("visibleSeasonPlans", () => {
  it("keeps season versions, hides overlays", () => {
    const list = [
      plan({ id: "a" }),
      plan({ id: "ov", calendarEntryId: "ce1" }),
    ];
    expect(visibleSeasonPlans(list).map((s) => s.id)).toEqual(["a"]);
  });
});

describe("versionLabels", () => {
  it("numbers visible season plans chronologically (V1 oldest) with a date stamp", () => {
    const list = [
      plan({ id: "new", createdAt: "2026-07-10T14:32:00+00:00" }),
      plan({ id: "mid", createdAt: "2026-07-09T09:00:00+00:00" }),
      plan({ id: "old", createdAt: "2026-07-08T09:00:00+00:00" }),
    ];
    const labels = versionLabels(list);
    expect(labels.get("old")).toMatch(/^V1 — 8 juil\./);
    expect(labels.get("mid")).toMatch(/^V2 — /);
    expect(labels.get("new")).toMatch(/^V3 — 10 juil\./);
  });
});

describe("visibleOverlayVersions", () => {
  it("keeps only that period's overlay versions, chronological", () => {
    const list = [
      plan({ id: "season" }),
      plan({ id: "ov1", calendarEntryId: "ce1", createdAt: "2026-07-08T09:00:00+00:00" }),
      plan({ id: "ov2", calendarEntryId: "ce1", createdAt: "2026-07-10T09:00:00+00:00" }),
      plan({ id: "ovOther", calendarEntryId: "ce2" }),
    ];
    expect(visibleOverlayVersions(list, "ce1").map((s) => s.id)).toEqual(["ov1", "ov2"]);
  });
});

describe("overlayVersionLabels", () => {
  it("numbers a period's overlay versions V{n}", () => {
    const list = [
      plan({ id: "ov2", calendarEntryId: "ce1", createdAt: "2026-07-10T14:32:00+00:00" }),
      plan({ id: "ov1", calendarEntryId: "ce1", createdAt: "2026-07-08T09:00:00+00:00" }),
      plan({ id: "other", calendarEntryId: "ce2", createdAt: "2026-07-09T09:00:00+00:00" }),
    ];
    const labels = overlayVersionLabels(list, "ce1");
    expect(labels.get("ov1")).toMatch(/^V1 — 8 juil\./);
    expect(labels.get("ov2")).toMatch(/^V2 — 10 juil\./);
    expect(labels.has("other")).toBe(false);
  });
});

describe("liveContextScheduleId — the ★ (latest generated, = live context)", () => {
  it("is the LATEST season version when no server pointer is set (fallback)", () => {
    const list = [
      plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00" }),
      plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    // No isLiveContext pointer → fall back to the latest (V2).
    expect(liveContextScheduleId(list, null)).toBe("v2");
  });

  it("honors the server pointer (isLiveContext) over the latest — « Charger V1 » moves the ★", () => {
    const list = [
      plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00", isLiveContext: true }),
      plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    // V1 is the loaded context even though V2 is newer.
    expect(liveContextScheduleId(list, null)).toBe("v1");
  });

  it("scopes to the selected overlay's own versions", () => {
    const list = [
      plan({ id: "s1", createdAt: "2026-07-05T10:00:00+00:00" }),
      plan({ id: "o1", calendarEntryId: "p1", createdAt: "2026-07-01T10:00:00+00:00" }),
      plan({ id: "o2", calendarEntryId: "p1", createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    expect(liveContextScheduleId(list, "p1")).toBe("o2");
    expect(liveContextScheduleId(list, null)).toBe("s1");
  });

  it("is null when there is no version", () => {
    expect(liveContextScheduleId([], null)).toBeNull();
  });
});

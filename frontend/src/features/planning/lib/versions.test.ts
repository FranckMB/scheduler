import { describe, expect, it } from "vitest";

import type { Schedule } from "../api";
import { versionLabels, visibleSeasonPlans } from "./versions";

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
  it("hides ARCHIVED versions and overlays", () => {
    const list = [
      plan({ id: "a" }),
      plan({ id: "arch", status: "ARCHIVED" }),
      plan({ id: "ov", calendarEntryId: "ce1" }),
    ];
    expect(visibleSeasonPlans(list).map((s) => s.id)).toEqual(["a"]);
  });
});

describe("versionLabels", () => {
  it("numbers visible season plans chronologically (V1 oldest) with a date stamp", () => {
    const list = [
      plan({ id: "new", createdAt: "2026-07-10T14:32:00+00:00" }),
      plan({ id: "old", createdAt: "2026-07-08T09:00:00+00:00" }),
      plan({ id: "arch", status: "ARCHIVED", createdAt: "2026-07-09T09:00:00+00:00" }),
    ];
    const labels = versionLabels(list);
    expect(labels.get("old")).toMatch(/^V1 — 8 juil\./);
    // The archived sibling KEEPS its number slot (V2), so the version the
    // manager validated as V3 stays V3 after its siblings are archived.
    expect(labels.get("arch")).toMatch(/^V2 — /);
    expect(labels.get("new")).toMatch(/^V3 — 10 juil\./);
  });
});

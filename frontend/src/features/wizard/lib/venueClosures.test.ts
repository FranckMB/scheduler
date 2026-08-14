import { describe, expect, it } from "vitest";

import type { Closure } from "@/features/cockpit/api";

import { closureForSlot, closureLabel, closurePeriodLabel, closuresByVenue, isSlotClosed } from "./venueClosures";

const closure = (over: Partial<Closure>): Closure => ({
  constraintId: "c1",
  venueId: "v1",
  title: "Travaux",
  startDate: "2026-05-01",
  endDate: "2026-05-10",
  weekdays: [5, 6, 7],
  ...over,
});

describe("venueClosures — helpers purs de fermeture", () => {
  it("closuresByVenue regroupe par gymnase", () => {
    const map = closuresByVenue([closure({ venueId: "v1" }), closure({ venueId: "v1", constraintId: "c2" }), closure({ venueId: "v2" })]);
    expect(map.get("v1")).toHaveLength(2);
    expect(map.get("v2")).toHaveLength(1);
    expect(map.get("v3")).toBeUndefined();
  });

  it("isSlotClosed lit weekdays au grain JOUR — vendredi fermé, lundi ouvert", () => {
    const closures = [closure({ weekdays: [5, 6, 7] })];
    expect(isSlotClosed({ dayOfWeek: 5 }, closures)).toBe(true);
    expect(isSlotClosed({ dayOfWeek: 1 }, closures)).toBe(false);
  });

  it("closureForSlot rend la fermeture qui touche le jour, sinon null", () => {
    const c = closure({ weekdays: [5] });
    expect(closureForSlot({ dayOfWeek: 5 }, [c])).toBe(c);
    expect(closureForSlot({ dayOfWeek: 1 }, [c])).toBeNull();
  });

  it("closureLabel : « Indispo du 1/5 au 10/5 — Travaux » (j/m, sans zéro ni année)", () => {
    expect(closureLabel(closure({ startDate: "2026-05-01", endDate: "2026-05-10", title: "Travaux" }))).toBe("Indispo du 1/5 au 10/5 — Travaux");
  });

  it("closurePeriodLabel : jours abrégés en plage contiguë « ven–dim »", () => {
    expect(closurePeriodLabel(closure({ weekdays: [5, 6, 7] }))).toBe("Indispo ven–dim du 1/5 au 10/5 — Travaux");
  });

  it("closurePeriodLabel : jours non contigus listés « lun, mer, ven »", () => {
    expect(closurePeriodLabel(closure({ weekdays: [1, 3, 5] }))).toBe("Indispo lun, mer, ven du 1/5 au 10/5 — Travaux");
  });

  it("closurePeriodLabel : 7 jours fermés → « toute la période »", () => {
    expect(closurePeriodLabel(closure({ weekdays: [1, 2, 3, 4, 5, 6, 7] }))).toBe("Indispo toute la période du 1/5 au 10/5 — Travaux");
  });
});

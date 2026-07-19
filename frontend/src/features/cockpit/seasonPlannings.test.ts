import { describe, expect, it } from "vitest";

import type { Schedule } from "@/features/planning/api";

import type { SchedulePlan } from "./api";
import { seasonPlannings } from "./seasonPlannings";

const s = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", ...over });

const sp = (over: Partial<SchedulePlan>): SchedulePlan => ({ id: "pl", type: "HOLIDAY", name: "Plan", calendarEntryId: "e1", chosenScheduleId: null, teamSelectionInitialized: false, ...over });

describe("seasonPlannings — open plannings & plan name (founder feedback 2026-07-18)", () => {
  it("labels the season row with the plan's real name when provided", () => {
    const rows = seasonPlannings([s({ id: "v1" })], "Planning de la saison 2026-2027");
    expect(rows[0].label).toBe("Planning de la saison 2026-2027");
  });

  it("falls back to « Planning principal » without a plan name", () => {
    expect(seasonPlannings([s({ id: "v1" })])[0].label).toBe("Planning principal");
  });

  it("lists an overlay with NO finished version as an OPEN row on its latest version", () => {
    const rows = seasonPlannings([
      s({ id: "o1", name: "Vacances Noël", status: "PENDING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-03T10:00:00+00:00" }),
      s({ id: "o2", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-04T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "o2", isOpen: true, isOverlay: true, schedulePlanId: "p2" });
  });

  it("a planning with a finished version stays a closed row (isOpen false), even with newer in-flight versions", () => {
    const rows = seasonPlannings([
      s({ id: "v1", status: "COMPLETED" }),
      s({ id: "v2", status: "GENERATING", createdAt: "2026-07-05T10:00:00+00:00" }),
    ]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ id: "v1", isOpen: false });
  });

  // B1 (retour fondateur 2026-07-19) : un plan de période créé mais SANS aucune
  // version générée doit rester visible (« en cours », à reprendre).
  it("lists a period plan with ZERO generated version as an OPEN row keyed on the plan id", () => {
    const rows = seasonPlannings(
      [s({ id: "v1", status: "COMPLETED" })], // seulement le socle a des versions
      null,
      [sp({ id: "pl-tou", name: "Vacances Toussaint — semaine du 20 oct.", calendarEntryId: "e-tou", type: "HOLIDAY" })],
    );
    const openRow = rows.find((r) => r.schedulePlanId === "pl-tou");
    expect(openRow).toMatchObject({ id: "pl-tou", label: "Vacances Toussaint — semaine du 20 oct.", isOpen: true, isOverlay: true, isChosen: false });
  });

  it("does not duplicate a period plan that already has a version, nor list the SEASON plan or an entry-less plan", () => {
    const rows = seasonPlannings(
      [s({ id: "o1", name: "Toussaint", planType: "CLOSURE", schedulePlanId: "pl-a", status: "COMPLETED", createdAt: "2026-07-03T10:00:00+00:00" })],
      null,
      [
        sp({ id: "pl-a", type: "HOLIDAY", calendarEntryId: "e-a" }), // a déjà une version → pas de doublon
        sp({ id: "pl-season", type: "SEASON", calendarEntryId: "e-s" }), // socle → ignoré
        sp({ id: "pl-orphan", type: "CLOSURE", calendarEntryId: null }), // sans entrée → ignoré
      ],
    );
    expect(rows.filter((r) => r.schedulePlanId === "pl-a")).toHaveLength(1);
    expect(rows.some((r) => r.schedulePlanId === "pl-season")).toBe(false);
    expect(rows.some((r) => r.schedulePlanId === "pl-orphan")).toBe(false);
  });
});

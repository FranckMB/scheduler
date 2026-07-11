import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import type { Schedule } from "@/features/planning/api";

vi.mock("@/features/planning/queries", () => ({ useVenues: () => ({ data: [] }) }));
vi.mock("@/features/planning/store", () => ({ usePlanningStore: () => vi.fn() }));
// Stub the export dropdown (its own async flow is tested in the planning feature).
vi.mock("@/features/planning/ExportMenu", () => ({ ExportMenu: () => <button>Exporter</button> }));

import { SeasonSchedulesModal, seasonPlanningCount } from "./SeasonSchedulesModal";

const plan = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", calendarEntryId: null, ...over });

// A season with TWO versions of the main plan + one period overlay (2 versions).
const schedules = [
  plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00" }),
  plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
  plan({ id: "o1", name: "Vacances Toussaint", calendarEntryId: "p1", createdAt: "2026-07-03T10:00:00+00:00" }),
  plan({ id: "o2", name: "Vacances Toussaint", calendarEntryId: "p1", createdAt: "2026-07-04T10:00:00+00:00" }),
];

describe("SeasonSchedulesModal — plannings, not versions", () => {
  it("counts distinct plannings: 1 season plan + 1 overlay = 2 (not 4 versions)", () => {
    expect(seasonPlanningCount(schedules)).toBe(2);
  });

  it("lists one row per PLANNING (principal + overlay), each with view + export", () => {
    render(
      <MemoryRouter>
        <SeasonSchedulesModal schedules={schedules} baselineScheduleId="v2" onClose={vi.fn()} />
      </MemoryRouter>,
    );
    // Exactly two plannings, no per-version rows (V1/V2 not shown).
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
    expect(screen.getByText("Vacances Toussaint")).toBeInTheDocument();
    // Each row offers a consult (eye) + an export.
    expect(screen.getAllByRole("button", { name: /Consulter/ })).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: "Exporter" })).toHaveLength(2);
  });
});

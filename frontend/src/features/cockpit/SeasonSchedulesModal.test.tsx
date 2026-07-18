import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Schedule } from "@/features/planning/api";

const setSelectedScheduleId = vi.fn();
const jumpTo = vi.fn();
const startPeriodMode = vi.fn();
const exitPeriodMode = vi.fn();
const navigate = vi.fn();
const run = vi.fn();

vi.mock("@/features/planning/store", () => ({ usePlanningStore: (sel: (s: unknown) => unknown) => sel({ setSelectedScheduleId }) }));
vi.mock("@/features/wizard/store", () => ({ useWizardStore: { getState: () => ({ jumpTo, startPeriodMode, exitPeriodMode }) } }));
vi.mock("@/features/planning/queries", () => ({ useScheduleExport: () => ({ run, busy: null }) }));
vi.mock("@/features/auth/queries", () => ({ useMe: () => ({ data: { seasonPlan: { name: "Planning de la saison 2026-2027" } } }) }));
vi.mock("./queries", () => ({ useSchedulePlans: () => ({ data: [{ id: "p1", calendarEntryId: "entry-1", chosenScheduleId: null }, { id: "p2", calendarEntryId: "entry-2", chosenScheduleId: null }] }) }));
vi.mock("react-router-dom", async (orig) => ({ ...(await orig<typeof import("react-router-dom")>()), useNavigate: () => navigate }));

import { SeasonSchedulesModal } from "./SeasonSchedulesModal";
import { seasonPlanCounts } from "./seasonPlannings";

beforeEach(() => {
  setSelectedScheduleId.mockClear();
  jumpTo.mockClear();
  startPeriodMode.mockClear();
  exitPeriodMode.mockClear();
  navigate.mockClear();
  run.mockClear();
});

const plan = (over: Partial<Schedule>): Schedule => ({ id: "id", name: "Plan", status: "COMPLETED", score: null, createdAt: "2026-07-01T10:00:00+00:00", updatedAt: "", planType: "SEASON", schedulePlanId: "season-plan", ...over });

// A season with TWO versions of the main plan + one period overlay (2 versions).
const schedules = [
  plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00" }),
  plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
  plan({ id: "o1", name: "Vacances Toussaint", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-03T10:00:00+00:00" }),
  plan({ id: "o2", name: "Vacances Toussaint", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-04T10:00:00+00:00" }),
];

function open(list: Schedule[]) {
  return render(
    <MemoryRouter>
      <SeasonSchedulesModal schedules={list} onClose={vi.fn()} />
    </MemoryRouter>,
  );
}

describe("SeasonSchedulesModal — plannings, not versions", () => {
  it("counts distinct plannings: 1 season plan + 1 overlay = 2 (not 4 versions)", () => {
    expect(seasonPlanCounts(schedules)).toEqual({ total: 2, overlays: 1 });
  });

  it("counts OPEN periods too (a mid-generation planning is still a planning — founder 2026-07-18)", () => {
    // One finished period (p1) + one period (p2) still mid-first-generation → BOTH listed.
    const withInFlight = [...schedules, plan({ id: "o3", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p2", createdAt: "2026-07-05T10:00:00+00:00" })];
    expect(seasonPlanCounts(withInFlight)).toEqual({ total: 3, overlays: 2 });
  });

  it("lists one row per PLANNING (principal + overlay), each with view + export", () => {
    open(schedules);
    // Le socle porte le NOM de son plan (me.seasonPlan.name), pas un libellé générique.
    expect(screen.getByText("Planning de la saison 2026-2027")).toBeInTheDocument();
    expect(screen.getByText("Vacances Toussaint")).toBeInTheDocument();
    // Each row offers a consult (eye) + an export — icon-only (aria-label), no visible "Exporter" text.
    expect(screen.getAllByRole("button", { name: /^Consulter/ })).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: /^Exporter/ })).toHaveLength(2);
    expect(screen.queryByText("Exporter")).not.toBeInTheDocument();
  });

  it("an OPEN overlay (no finished version) is listed with « Reprendre » and no export", async () => {
    open([plan({ id: "o3", name: "Vacances Noël", status: "GENERATING", planType: "CLOSURE", schedulePlanId: "p1", createdAt: "2026-07-05T10:00:00+00:00" })]);
    expect(screen.getByText("Vacances Noël")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Exporter/ })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /^Reprendre/ }));
    expect(startPeriodMode).toHaveBeenCalledWith("entry-1");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("represents each planning by its latest FINISHED version, never a failed/in-flight one", () => {
    // v2 is FAILED and v3 GENERATING → the principal row must fall back to the finished v1.
    open([plan({ id: "v1", status: "COMPLETED", createdAt: "2026-07-01T10:00:00+00:00" }), plan({ id: "v2", status: "FAILED", createdAt: "2026-07-02T10:00:00+00:00" }), plan({ id: "v3", status: "GENERATING", createdAt: "2026-07-03T10:00:00+00:00" })]);
    expect(screen.getByText("Terminé")).toBeInTheDocument(); // v1's COMPLETED label, not FAILED/GENERATING
  });

  it("eye on the planning in force opens the planning page", async () => {
    open([plan({ id: "v1", status: "COMPLETED", isChosen: true })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("v1");
    expect(navigate).toHaveBeenCalledWith("/planning");
  });

  it("eye on an in-progress SEASON planning opens the wizard's generation step, resetting a stale period mode", async () => {
    open([plan({ id: "v1", status: "COMPLETED" })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    // Un mode période persisté générerait le plan de PÉRIODE — reset obligatoire.
    expect(exitPeriodMode).toHaveBeenCalled();
    expect(jumpTo).toHaveBeenCalledWith("generate");
    expect(navigate).toHaveBeenCalledWith("/wizard");
  });

  it("eye on a finished PERIOD overlay opens it on the planning page (never the wizard)", async () => {
    // A COMPLETED overlay is a finished period plan → consult it, not the wizard
    // (whose generate step renders the season plan, not the overlay).
    open([plan({ id: "o1", name: "Vacances Toussaint", status: "COMPLETED", planType: "CLOSURE", schedulePlanId: "p1" })]);
    await userEvent.click(screen.getByRole("button", { name: /^Consulter/ }));
    expect(setSelectedScheduleId).toHaveBeenCalledWith("o1");
    expect(navigate).toHaveBeenCalledWith("/planning");
    expect(jumpTo).not.toHaveBeenCalled();
  });

  it("export expands an inline format picker (PDF / Excel / PNG), no clipped dropdown", async () => {
    open([plan({ id: "v1", status: "COMPLETED" })]);
    expect(screen.queryByRole("menuitem")).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /^Exporter/ }));
    expect(screen.getByRole("menuitem", { name: "PDF" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Excel" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "PNG" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("menuitem", { name: "PDF" }));
    expect(run).toHaveBeenCalledWith("pdf", null);
  });
});

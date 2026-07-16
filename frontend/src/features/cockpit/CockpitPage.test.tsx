import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CockpitPage } from "./CockpitPage";
import { addDays, monthWindow, todayISO } from "./lib/date";
import { PUBLIC_HOLIDAY_HORIZON_DAYS } from "./RadarPanel";

let meData: { seasonPlan: { id: string; name: string; chosenScheduleId: string | null; hasFinishedVersion: boolean } } | null = null;

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: meData, isLoading: false }),
}));

vi.mock("@/features/planning/queries", () => ({
  useSchedules: () => ({ data: [{ id: "s1", name: "Planning A", status: "COMPLETED", score: 9011, createdAt: "", updatedAt: "", calendarEntryId: null, isChosen: true }] }),
  useReopenSchedule: () => ({ mutate: vi.fn(), isPending: false }),
}));

const publicHolidayWindows: [string, string][] = [];

vi.mock("./queries", () => ({
  useCalendarEntries: () => ({ data: [] }),
  useSchoolHolidays: () => ({ data: { zone: "A", items: [] } }),
  usePublicHolidays: (from: string, to: string) => {
    publicHolidayWindows.push([from, to]);
    return { data: { zone: "A", items: [] }, isLoading: false };
  },
  useCreateHolidayPeriod: () => ({ mutate: vi.fn(), isPending: false }),
  useEntryConflicts: () => ({ data: undefined }),
  useEntryConflictsList: (ids: string[]) => ids.map(() => ({ data: undefined })),
}));

function renderCockpit() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/"]}>
        <Routes>
          <Route path="/" element={<CockpitPage />} />
          <Route path="/planning" element={<div>PLANNING SCREEN</div>} />
          <Route path="/wizard" element={<div>WIZARD SCREEN</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("CockpitPage state machine", () => {
  beforeEach(() => {
    meData = null;
    publicHolidayWindows.length = 0;
  });

  it("state 1 — no main plan (baseline null) → redirects to the wizard", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: null, hasFinishedVersion: false } };
    renderCockpit();
    expect(screen.getByText("WIZARD SCREEN")).toBeInTheDocument();
  });

  it("state 2 — baseline exists but not validated → cockpit unlocked with a lock hint", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: null, hasFinishedVersion: true } };
    renderCockpit();
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
    expect(screen.getByText(/validez-le pour débloquer/i)).toBeInTheDocument();
    expect(screen.queryByText("WIZARD SCREEN")).not.toBeInTheDocument();
  });

  it("state 3 — validated → full cockpit, no lock hint", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true } };
    renderCockpit();
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
    expect(screen.getByText("À traiter")).toBeInTheDocument();
    expect(screen.queryByText(/validez-le pour débloquer/i)).not.toBeInTheDocument();
  });

  it("fetches public holidays on two explicit windows: visible month grid + radar horizon", () => {
    meData = { seasonPlan: { id: "p1", name: "Planning", chosenScheduleId: "s1", hasFinishedVersion: true } };
    renderCockpit();

    const now = new Date();
    const grid = monthWindow(now.getFullYear(), now.getMonth());
    const today = todayISO();
    expect(publicHolidayWindows).toEqual([
      [grid.from, grid.to],
      [today, addDays(today, PUBLIC_HOLIDAY_HORIZON_DAYS)],
    ]);
  });
});

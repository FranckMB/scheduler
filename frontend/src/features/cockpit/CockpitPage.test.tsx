import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CockpitPage } from "./CockpitPage";
import { addDays, monthWindow, todayISO } from "./lib/date";
import { PUBLIC_HOLIDAY_HORIZON_DAYS } from "./RadarPanel";

let meData: { socleValidatedAt: string | null; baselineScheduleId: string | null } | null = null;

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({ data: meData, isLoading: false }),
}));

vi.mock("@/features/planning/queries", () => ({
  useSchedules: () => ({ data: [{ id: "s1", name: "Planning A", status: "VALIDATED", score: 9011, createdAt: "", updatedAt: "", calendarEntryId: null }] }),
  useReopenSchedule: () => ({ mutate: vi.fn(), isPending: false }),
  useSetBaseline: () => ({ mutate: vi.fn(), isPending: false }),
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
}));

function renderCockpit() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/"]}>
        <Routes>
          <Route path="/" element={<CockpitPage />} />
          <Route path="/planning" element={<div>PLANNING SCREEN</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("CockpitPage sticky gate", () => {
  beforeEach(() => {
    meData = null;
    publicHolidayWindows.length = 0;
  });

  it("redirects to /planning when the socle is not validated", () => {
    meData = { socleValidatedAt: null, baselineScheduleId: "s1" };
    renderCockpit();
    expect(screen.getByText("PLANNING SCREEN")).toBeInTheDocument();
  });

  it("renders the 3 cockpit zones once the socle is validated", () => {
    meData = { socleValidatedAt: "2026-01-15T10:00:00Z", baselineScheduleId: "s1" };
    renderCockpit();
    expect(screen.getByText("Planning principal")).toBeInTheDocument();
    expect(screen.getByText("À traiter")).toBeInTheDocument();
    expect(screen.queryByText("PLANNING SCREEN")).not.toBeInTheDocument();
  });

  it("fetches public holidays on two explicit windows: visible month grid + radar horizon", () => {
    meData = { socleValidatedAt: "2026-01-15T10:00:00Z", baselineScheduleId: "s1" };
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

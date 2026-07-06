import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { MonthCalendar } from "./MonthCalendar";

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateCutoff: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteEntry: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [] }),
}));

const entry = (overrides: Partial<CalendarEntry>): CalendarEntry => ({
  id: "e1",
  kind: "event",
  title: "AG du club",
  startDate: "2026-05-12",
  endDate: "2026-05-12",
  isDisruptive: false,
  periodType: null,
  schoolHolidayId: null,
  status: "active",
  overlayScheduleId: null,
  createdBy: null,
  ...overrides,
});

const holidays: SchoolHoliday[] = [
  { id: "h1", label: "Pont de mai", holidayType: "printemps", startDate: "2026-05-14", endDate: "2026-05-17", schoolYear: "2025-2026" },
];

function renderMay(entries: CalendarEntry[] = [], hols: SchoolHoliday[] = [], publicHols: PublicHoliday[] = []) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      {/* May 2026 — month is 0-indexed */}
      <MonthCalendar year={2026} month={4} entries={entries} holidays={hols} publicHolidays={publicHols} onPrev={vi.fn()} onNext={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe("MonthCalendar — projection of the exception layer", () => {
  it("renders the month header and a full 6-week grid", () => {
    renderMay();
    expect(screen.getByText(/mai 2026/i)).toBeInTheDocument();
    // 42 day cells (Monday-first grid), May 1st 2026 is a Friday.
    expect(screen.getAllByRole("button", { name: /^Jour \d{4}-\d{2}-\d{2}$/ })).toHaveLength(42);
  });

  it("marks periods ⛔, disruptive events 🚫, plain events 🎉 and holidays 🏖 on their days", () => {
    renderMay(
      [
        entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé", startDate: "2026-05-04", endDate: "2026-05-05" }),
        entry({ id: "d1", isDisruptive: true, title: "Tournoi", startDate: "2026-05-20", endDate: "2026-05-20" }),
        entry({ id: "a1", title: "AG", startDate: "2026-05-26", endDate: "2026-05-26" }),
      ],
      holidays,
    );

    // A multi-day period marks every day of its window.
    expect(screen.getAllByTitle("Gym fermé")).toHaveLength(2);
    expect(screen.getAllByTitle("Gym fermé")[0]).toHaveTextContent("⛔");
    expect(screen.getByTitle("Tournoi")).toHaveTextContent("🚫");
    expect(screen.getByTitle("AG")).toHaveTextContent("🎉");
    // Holiday window 14→17 May = 4 beach markers.
    expect(screen.getAllByTitle("Vacances scolaires")).toHaveLength(4);
  });

  it("marks a cutoff 🛑 (distinct from other periods) on every day of its window", () => {
    renderMay([entry({ id: "cut1", kind: "period", periodType: "cutoff", title: "Coupure de Noël", startDate: "2026-05-11", endDate: "2026-05-12" })]);

    const markers = screen.getAllByTitle("Coupure de Noël");
    expect(markers).toHaveLength(2);
    expect(markers[0]).toHaveTextContent("🛑");
  });

  it("shows a public-holiday dot on its exact day", () => {
    renderMay([], [], [{ id: 1, date: "2026-05-01", label: "Fête du Travail", national: true }]);

    expect(screen.getByTitle("Férié — Fête du Travail")).toBeInTheDocument();
  });

  it("opens the day dialog on click with that day's entries", async () => {
    renderMay([entry({ id: "a1", title: "AG", startDate: "2026-05-26", endDate: "2026-05-26" })]);

    await userEvent.click(screen.getByRole("button", { name: "Jour 2026-05-26" }));

    // The DayDialog opened on that date and lists the day's entry.
    expect(screen.getByText(/26 mai 2026/)).toBeInTheDocument();
    expect(screen.getByText("AG")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Supprimer AG" })).toBeInTheDocument();
  });
});

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, SchoolHoliday } from "./api";
import { addDays, todayISO } from "./lib/date";
import { RadarPanel } from "./RadarPanel";

const createHolidayMutate = vi.fn();
let conflictsData: { conflicts: { dates: string[] }[] } | undefined;

vi.mock("./queries", () => ({
  useCreateHolidayPeriod: () => ({ mutate: createHolidayMutate, isPending: false }),
  useEntryConflicts: () => ({ data: conflictsData }),
}));

const FUTURE = "2999-01-05";
const FUTURE_END = "2999-01-18";

const holiday: SchoolHoliday = { id: "h1", label: "Vacances de Noël", holidayType: "noel", startDate: FUTURE, endDate: FUTURE_END, schoolYear: "2998-2999" };

const closure = (overrides: Partial<CalendarEntry>): CalendarEntry => ({
  id: "c1",
  kind: "period",
  title: "Gym Barros fermé",
  startDate: FUTURE,
  endDate: FUTURE_END,
  isDisruptive: false,
  periodType: "closure",
  schoolHolidayId: null,
  status: "active",
  overlayScheduleId: null,
  createdBy: null,
  ...overrides,
});

function renderRadar(props: Partial<Parameters<typeof RadarPanel>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <RadarPanel entries={[]} holidays={[]} publicHolidays={[]} zone="A" {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("RadarPanel", () => {
  beforeEach(() => {
    createHolidayMutate.mockReset();
    conflictsData = undefined;
  });

  it("asks for the school zone when unknown", () => {
    renderRadar({ zone: null });
    expect(screen.getByText("Zone scolaire à renseigner")).toBeInTheDocument();
  });

  it("does not flash the zone card while holidays are loading", () => {
    renderRadar({ zone: null, zoneLoading: true });
    expect(screen.queryByText("Zone scolaire à renseigner")).not.toBeInTheDocument();
  });

  it("offers to adapt an upcoming school holiday (never auto-applies)", async () => {
    createHolidayMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.({ id: "created" }));
    renderRadar({ holidays: [holiday] });

    expect(screen.getByText("Vacances de Noël")).toBeInTheDocument();
    expect(screen.getByText(/pas de plan/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    expect(createHolidayMutate).toHaveBeenCalledWith(
      { schoolHolidayId: "h1", label: "Vacances de Noël", startDate: FUTURE, endDate: FUTURE_END },
      expect.anything(),
    );
  });

  it("counts the sessions to replace on a closure without overlay", () => {
    conflictsData = { conflicts: [{ dates: [FUTURE, "2999-01-12"] }, { dates: ["2999-01-06"] }] };
    renderRadar({ entries: [closure({})] });

    expect(screen.getByText(/3 séances à replacer · plan secondaire absent/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Adapter" })).toBeInTheDocument();
  });

  it("switches to consult/adjust once the overlay exists", () => {
    renderRadar({ entries: [closure({ overlayScheduleId: "ov1" })] });

    expect(screen.getByText("Plan secondaire généré")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Voir le plan" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajuster" })).toBeInTheDocument();
  });

  it("shows the all-clear when there is nothing to handle", () => {
    renderRadar();
    expect(screen.getByText("Rien à l'horizon. Tout roule.")).toBeInTheDocument();
  });

  it("shows an upcoming cutoff as a plain reminder without any CTA", () => {
    renderRadar({ entries: [closure({ id: "cut1", periodType: "cutoff", title: "Coupure de Noël" })] });

    expect(screen.getByText("Coupure de Noël")).toBeInTheDocument();
    expect(screen.getByText(/aucun entraînement/)).toBeInTheDocument();
    // Reminder only: no plan to prepare for a cutoff (no Adapter / Voir le plan).
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Voir le plan" })).not.toBeInTheDocument();
  });

  it("reminds about public holidays within 30 days, ignores farther ones", () => {
    const today = todayISO();
    renderRadar({
      publicHolidays: [
        { id: 1, date: addDays(today, 10), label: "Férié proche", national: true },
        { id: 2, date: addDays(today, 60), label: "Férié lointain", national: true },
      ],
    });

    expect(screen.getByText("Férié proche")).toBeInTheDocument();
    expect(screen.getByText(/jour férié/)).toBeInTheDocument();
    expect(screen.queryByText("Férié lointain")).not.toBeInTheDocument();
  });
});

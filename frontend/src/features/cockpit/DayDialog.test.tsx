import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog } from "./DayDialog";

const deleteMutate = vi.fn();
const cutoffMutate = vi.fn();
const closureMutate = vi.fn();
// "Adapter" (create branch) uses mutateAsync so the wizard navigation survives a
// mid-POST modal dismiss — the mock resolves with the created period's id.
const holidayMutateAsync = vi.fn(() => Promise.resolve({ id: "created-hol" }));

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: closureMutate, isPending: false }),
  useCreateCutoff: () => ({ mutate: cutoffMutate, isPending: false }),
  useCreateHolidayPeriod: () => ({ mutateAsync: holidayMutateAsync, isPending: false }),
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }] }),
}));
// Freeze "today" so the fixed test date (2026-05-12) is not in the past (start ≥ today).
vi.mock("./lib/date", () => ({ todayISO: () => "2026-05-12" }));

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

function renderDialog(entries: CalendarEntry[], holidays: { holiday?: SchoolHoliday; publicHoliday?: PublicHoliday } = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DayDialog iso="2026-05-12" entries={entries} holiday={holidays.holiday} publicHoliday={holidays.publicHoliday} onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const schoolHoliday = (over: Partial<SchoolHoliday> = {}): SchoolHoliday => ({ id: "sh1", label: "Vacances de Noël", holidayType: "noel", startDate: "2026-05-10", endDate: "2026-05-20", schoolYear: "2025-2026", ...over });

describe("DayDialog — deletion is always confirmed", () => {
  beforeEach(() => {
    deleteMutate.mockReset();
    cutoffMutate.mockReset();
    closureMutate.mockReset();
    holidayMutateAsync.mockClear();
  });

  it("asks for confirmation before deleting, then deletes on confirm", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    // Nothing deleted yet — a confirmation dialog opened instead.
    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Supprimer « AG du club » \?/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteMutate).toHaveBeenCalledWith("e1", expect.anything());
  });

  it("cancel closes the confirmation without deleting", async () => {
    renderDialog([entry({})]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer AG du club" }));
    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.queryByText(/Supprimer « AG du club » \?/)).not.toBeInTheDocument();
  });

  it("warns that the generated overlay plan dies with a period", async () => {
    renderDialog([entry({ id: "p1", kind: "period", periodType: "closure", title: "Gym fermé", overlayScheduleId: "ov1" })]);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Gym fermé" }));

    expect(screen.getByText(/plan de période généré/i)).toBeInTheDocument();
  });

  it("keeps the custom period button disabled (deferred palier B/C)", () => {
    renderDialog([]);

    expect(screen.getByRole("button", { name: "Créer une période…" })).toBeDisabled();
  });

  it("creates a cutoff with the default title when left empty", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-12", endDate: "2026-05-12" }, expect.anything());
  });

  it("creates a cutoff with a custom title and end date", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Coupure de Noël");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure de Noël", startDate: "2026-05-12", endDate: "2026-05-18" }, expect.anything());
  });

  it("builds a structured '{venue} — {reason}' closure title, defaulting the reason to 'fermé'", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — fermé", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("puts the typed reason after the venue in the closure title", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A — Travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  it("does not repeat the venue when the typed reason already names it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Signaler une indisponibilité" }));
    await userEvent.selectOptions(screen.getByRole("combobox"), "v1");
    await userEvent.type(screen.getByPlaceholderText(/Intitulé \(optionnel/), "Gymnase A en travaux");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(closureMutate).toHaveBeenCalledWith(
      { title: "Gymnase A en travaux", startDate: "2026-05-12", endDate: "2026-05-12", venueId: "v1" },
      expect.anything(),
    );
  });

  // Lot B — item 2: the clicked day is only a DEFAULT start; both ends are editable.
  it("lets the start date be changed (clicked day is only the default)", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-15");
    const endInput = screen.getByLabelText("Jusqu'au");
    await userEvent.clear(endInput);
    await userEvent.type(endInput, "2026-05-18");
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-15", endDate: "2026-05-18" }, expect.anything());
  });

  // Moving the start past the end must bump the end so the window never inverts.
  it("clamps the end forward when the start is moved past it", async () => {
    renderDialog([]);

    await userEvent.click(screen.getByRole("button", { name: "Coupure (pas d'entraînement)" }));
    const startInput = screen.getByLabelText("Du");
    await userEvent.clear(startInput);
    await userEvent.type(startInput, "2026-05-20"); // later than the default end (2026-05-12)
    await userEvent.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(cutoffMutate).toHaveBeenCalledWith({ title: "Coupure", startDate: "2026-05-20", endDate: "2026-05-20" }, expect.anything());
  });
});

describe("DayDialog — holiday awareness (Lot B)", () => {
  beforeEach(() => holidayMutateAsync.mockClear());

  // item 1: a public holiday (jour férié) shows read-only info.
  it("shows the public-holiday info banner", () => {
    renderDialog([], { publicHoliday: { id: "ph1", date: "2026-05-12", label: "Ascension", national: true } });
    expect(screen.getByText("Jour férié")).toBeInTheDocument();
    expect(screen.getByText(/Ascension/)).toBeInTheDocument();
  });

  // item 1 + 3: a school holiday shows info AND the "Adapter" entry point.
  it("shows the school-holiday info + an « Adapter » action when no period exists yet", async () => {
    renderDialog([], { holiday: schoolHoliday() });
    expect(screen.getByText("Vacances")).toBeInTheDocument();
    expect(screen.getByText(/Vacances de Noël/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Adapter" }));
    // mutateAsync (no mutate-scoped options) so the wizard navigation survives a dismiss.
    expect(holidayMutateAsync).toHaveBeenCalledWith({ schoolHolidayId: "sh1", label: "Vacances de Noël", startDate: "2026-05-10", endDate: "2026-05-20" });
  });

  // item 3: once the holiday overlay is generated, offer "Voir le planning" instead.
  it("offers « Voir le planning » when the holiday's overlay is already generated", () => {
    const periodEntry = entry({ id: "p9", kind: "period", periodType: "holiday", schoolHolidayId: "sh1", startDate: "2026-05-10", endDate: "2026-05-20", overlayScheduleId: "ov9" });
    renderDialog([periodEntry], { holiday: schoolHoliday() });
    expect(screen.getByRole("button", { name: "Voir le planning" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });

  // The modal reuses the calendar's emoji markers (same look in both places).
  it("marks day entries with the same calendar emojis (⛔ closure, 🛑 cutoff)", () => {
    renderDialog([entry({ id: "c1", kind: "period", periodType: "closure", title: "Gym fermé" }), entry({ id: "c2", kind: "period", periodType: "cutoff", title: "Coupure de Noël" })]);
    expect(screen.getByText("⛔")).toBeInTheDocument();
    expect(screen.getByText("🛑")).toBeInTheDocument();
  });

  // Summer holidays: info only, never adaptable (off-season, no schedule to build).
  it("shows summer-holiday info but NO « Adapter » action", () => {
    renderDialog([], { holiday: schoolHoliday({ id: "sh-ete", label: "Vacances d'Été", holidayType: "ete" }) });
    expect(screen.getByText(/Vacances d'Été/)).toBeInTheDocument();
    expect(screen.getByText(/hors saison/i)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Adapter" })).not.toBeInTheDocument();
  });
});

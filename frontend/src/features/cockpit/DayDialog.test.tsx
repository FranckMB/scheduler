import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "./api";
import { DayDialog } from "./DayDialog";

const deleteMutate = vi.fn();
const cutoffMutate = vi.fn();
const closureMutate = vi.fn();

vi.mock("./queries", () => ({
  useCreateEvent: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateVenueClosure: () => ({ mutate: closureMutate, isPending: false }),
  useCreateCutoff: () => ({ mutate: cutoffMutate, isPending: false }),
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
}));
vi.mock("@/features/planning/queries", () => ({
  useVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true }] }),
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

function renderDialog(entries: CalendarEntry[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <DayDialog iso="2026-05-12" entries={entries} onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe("DayDialog — deletion is always confirmed", () => {
  beforeEach(() => {
    deleteMutate.mockReset();
    cutoffMutate.mockReset();
    closureMutate.mockReset();
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
});

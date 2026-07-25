import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";

import { CoachWishesModal } from "./CoachWishesModal";

// Saison couvrant les deux semaines des vacances de test (lun 2026-02-16 → dim 2026-03-01).
vi.mock("@/features/auth/queries", () => ({ useWorkingSeason: () => ({ startDate: "2025-09-01", endDate: "2026-06-30" }) }));
vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1" }, { id: "t2", name: "U13" }] }),
  useWizardCoaches: () => ({ data: [{ id: "c1", firstName: "Maxime", lastName: "Durand" }, { id: "c2", firstName: "Léa", lastName: "Roy" }] }),
  useWizardTeamCoaches: () => ({ data: [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }] }),
}));

const wishesState: { data: unknown[] } = { data: [] };
const createMut = vi.fn();
const updateMut = vi.fn();
const deleteMut = vi.fn();
vi.mock("./queries", () => ({
  useCoachWishes: () => ({ data: wishesState.data }),
  useCreateCoachWish: () => ({ mutate: createMut, isPending: false }),
  useUpdateCoachWish: () => ({ mutate: updateMut, isPending: false }),
  useDeleteCoachWish: () => ({ mutate: deleteMut, isPending: false }),
}));

const mother: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Toussaint",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
};

const wish = (over: Record<string, unknown>) => ({
  id: "w1",
  calendarEntryId: "e1",
  weekStart: "2026-02-16",
  teamId: "t1",
  coachId: "c1",
  slotsWanted: 2,
  unavailableDays: [],
  comment: null,
  done: false,
  ...over,
});

describe("CoachWishesModal", () => {
  beforeEach(() => {
    wishesState.data = [];
    createMut.mockClear();
    updateMut.mockClear();
    deleteMut.mockClear();
  });

  it("groupe les doléances par semaine quand aucun filtre de semaine", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    // Deux en-têtes de semaine (les deux semaines des vacances).
    expect(screen.getByText(/Semaine du 2026-02-16/)).toBeInTheDocument();
    expect(screen.getByText(/Semaine du 2026-02-23/)).toBeInTheDocument();
    expect(screen.getByText("SM1", { exact: false })).toBeInTheDocument();
  });

  it("ne montre qu'une semaine quand weekFilter est posé (vue wizard d'un plan de semaine)", () => {
    wishesState.data = [wish({ id: "w1", weekStart: "2026-02-16" }), wish({ id: "w2", teamId: "t2", weekStart: "2026-02-23" })];
    render(<CoachWishesModal mother={mother} weekFilter="2026-02-23" onClose={() => {}} />);
    expect(screen.queryByText(/Semaine du 2026-02-16/)).toBeNull();
    // La doléance de la semaine filtrée (U13) est là ; celle de l'autre semaine non.
    expect(screen.getByText("U13", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("SM1", { exact: false })).toBeNull();
  });

  it("cocher « traité » appelle update avec done inversé", async () => {
    wishesState.data = [wish({ id: "w1", done: false })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ done: true }) }));
  });

  it("cocher « traité » sur une doléance dé-attribuée préserve coachId null (pas de 422)", async () => {
    // Revue #10 C1 finding #1 : envoyer coachId:"" échouait le NotBlank et une doléance
    // dé-attribuée ne pouvait jamais être cochée. On préserve null.
    wishesState.data = [wish({ id: "w1", coachId: null, done: false })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await userEvent.click(screen.getByRole("checkbox", { name: /Traité/ }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null, done: true }) }));
  });

  it("une doléance dé-attribuée (coachId null) l'affiche explicitement", () => {
    wishesState.data = [wish({ id: "w1", coachId: null })];
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    expect(screen.getByText(/coach dé-attribué/)).toBeInTheDocument();
  });

  it("éditer une doléance ATTRIBUÉE ne peut pas la dé-attribuer (pas d'option coach vide)", async () => {
    // Revue #10 C1 round 2 finding #1 : autoriser le coach vide en édition (pour les
    // dé-attribuées) ne doit PAS laisser dé-attribuer une doléance attribuée.
    wishesState.data = [wish({ id: "w1", coachId: "c1", teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Pas d'option vide « Coach… » sur une doléance attribuée.
    const coachSelect = screen.getByLabelText("Coach") as HTMLSelectElement;
    expect(Array.from(coachSelect.options).some((o) => "" === o.value)).toBe(false);
    // Enregistrer garde le coach d'origine.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ body: expect.objectContaining({ coachId: "c1" }) }), expect.anything());
  });

  it("éditer une doléance dé-attribuée ne la réattribue PAS au coach MAIN", async () => {
    // Revue #10 C1 finding #2 : l'équipe t1 a un coach MAIN (c1) ; éditer une doléance
    // dé-attribuée retombait sur lui, corrompant l'auteur. On garde coachId null.
    wishesState.data = [wish({ id: "w1", coachId: null, teamId: "t1", weekStart: "2026-02-16" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "w1", body: expect.objectContaining({ coachId: null }) }), expect.anything());
  });

  it("le filtre par équipe masque les autres équipes", async () => {
    wishesState.data = [wish({ id: "w1", teamId: "t1" }), wish({ id: "w2", teamId: "t2" })];
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter={null} onClose={() => {}} />);
    // Ouvre le filtre Équipes, coche U13, referme le popover.
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    await user.click(screen.getByRole("button", { name: "U13" }));
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    // Popover fermé : les noms d'équipe ne vivent plus que dans les items filtrés.
    expect(screen.getByText("U13", { exact: false })).toBeInTheDocument();
    expect(screen.queryByText("SM1", { exact: false })).toBeNull();
  });

  it("le formulaire d'ajout soumet le payload avec la semaine figée quand weekFilter", async () => {
    const user = userEvent.setup();
    render(<CoachWishesModal mother={mother} weekFilter="2026-02-16" onClose={() => {}} />);
    await user.click(screen.getByRole("button", { name: /Ajouter/ }));
    // Équipe SM1 (défaut) → coach MAIN c1 pré-rempli ; on soumet directement.
    await user.click(screen.getByRole("button", { name: /Ajouter la doléance/ }));
    expect(createMut).toHaveBeenCalledWith(expect.objectContaining({ calendarEntryId: "e1", weekStart: "2026-02-16", teamId: "t1", coachId: "c1" }), expect.anything());
  });
});

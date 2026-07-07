import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const createMut = vi.fn();

vi.mock("../queries", () => ({
  useWizardConstraints: () => ({ data: [] }),
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", sportCategoryId: "cat", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }] }),
  useWizardTeamTags: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Jean", lastName: "Dupont" }] }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", isActive: true }] }),
  useVenueSlots: () => ({ data: [] }),
  useCreateConstraint: () => ({ mutate: createMut, isPending: false }),
  useDeleteConstraint: () => ({ mutate: vi.fn() }),
}));

import { ConstraintsStep } from "./ConstraintsStep";

/**
 * Freezes the UI OFFER side of the constraint matrix (audit P0.1): the rule
 * options and the emitted configs are locked to what
 * engine/tests/semantic/constraint_matrix.py declares as honored. Any change
 * here must update the matrix (and its generated engine test) first.
 */
describe("ConstraintsStep — constraint-matrix offer lock", () => {
  beforeEach(() => createMut.mockClear());

  it("offers exactly Obligatoire/Préféré/Verrouillé — BONUS is gone (ENG-12)", () => {
    renderWithProviders(<ConstraintsStep />);
    const rule = screen.getByLabelText("Règle");
    const options = Array.from(rule.querySelectorAll("option")).map((o) => o.textContent);
    expect(options).toEqual(["Préféré", "Obligatoire", "Verrouillé"]);
  });

  it("forces HARD on coach availability (no rule selector — the engine always enforces it)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Dispo coach" }));
    expect(screen.queryByLabelText("Règle")).not.toBeInTheDocument();
    expect(screen.getByText("Obligatoire")).toBeInTheDocument();

    // Pick coach + a day, add → the payload pins ruleType HARD.
    await user.selectOptions(screen.getByLabelText("Coach"), "co1");
    await user.click(screen.getByRole("button", { name: "Lun" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(createMut).toHaveBeenCalledOnce();
    expect(createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", config: { coachId: "co1", unavailableDays: [1] } });
  });

  it("DAY emits forbiddenDays (the matrix key) whatever the ruleType", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Mer" }));
    // default ruleType = PREFERRED (soft "avoid these days", ENG-10 fix engine-side)
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(createMut).toHaveBeenCalledOnce();
    expect(createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "PREFERRED", config: { forbiddenDays: [3] } });
  });

  it("FACILITY emits preferredVenueId or forbiddenVenueId (matrix keys)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", config: { preferredVenueId: "v1" } });
  });
});

import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Constraint } from "../api";

const h = vi.hoisted(() => ({ createMut: vi.fn(), updateMut: vi.fn(), list: [] as Constraint[] }));

vi.mock("../queries", () => ({
  useWizardConstraints: () => ({ data: h.list }),
  useWizardTeams: () => ({ data: [{ id: "t1", name: "SM1", sportCategoryId: "cat", priorityTierId: 3, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }] }),
  usePriorityTiers: () => ({ data: [{ id: 3, label: "B", name: "Moyenne", color: null }] }),
  useWizardTeamTags: () => ({ data: [] }),
  useWizardCoaches: () => ({ data: [{ id: "co1", firstName: "Jean", lastName: "Dupont" }] }),
  useWizardVenues: () => ({ data: [{ id: "v1", name: "Gymnase A", isActive: true }, { id: "v2", name: "Gymnase B", isActive: true }] }),
  useVenueSlots: () => ({ data: [] }),
  useCreateConstraint: () => ({ mutate: h.createMut, isPending: false }),
  useUpdateConstraint: () => ({ mutate: h.updateMut, isPending: false }),
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
  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [];
  });

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

    expect(h.createMut).toHaveBeenCalledOnce();
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "COACH_AVAILABILITY", ruleType: "HARD", config: { coachId: "co1", unavailableDays: [1] } });
  });

  it("DAY emits forbiddenDays (the matrix key) whatever the ruleType", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.click(screen.getByRole("button", { name: "Mer" }));
    // default ruleType = PREFERRED (soft "avoid these days", ENG-10 fix engine-side)
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "PREFERRED", config: { forbiddenDays: [3] } });
  });

  it("FACILITY emits preferredVenueId or forbiddenVenueId (matrix keys)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", config: { preferredVenueId: "v1" } });
  });

  it("FACILITY 'impose' emits a HARD forcedVenueId (matrix HONORED_HARD)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.selectOptions(screen.getByLabelText("Préférence"), "forced");
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v1");
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "FACILITY", ruleType: "HARD", config: { forcedVenueId: "v1" } });
  });

  it("DAY 'uniquement' emits HARD allowedDays (whitelist — only these days, ENG-16)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Type de jour"), "forced");
    await user.click(screen.getByRole("button", { name: "Ven" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut.mock.calls[0][0]).toMatchObject({ family: "DAY", ruleType: "HARD", config: { allowedDays: [5] } });
  });

  it("keeps the target after a create so several constraints can be added in a row (F5)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Jours" }));
    await user.selectOptions(screen.getByLabelText("Cible"), "t1");
    await user.click(screen.getByRole("button", { name: "Mer" }));
    await user.click(screen.getByRole("button", { name: "Ajouter la contrainte" }));

    expect(h.createMut).toHaveBeenCalledOnce();
    // The target survives the add (only the value inputs are cleared).
    expect(screen.getByLabelText("Cible")).toHaveValue("t1");
  });
});

/**
 * Editing an EXISTING constraint reuses the same form (PUT). The critical
 * guard: loading a forced-venue/day rule back into the form and saving must
 * NOT downgrade forcedVenueId→preferredVenueId (a silent §7.1 semantics break).
 */
describe("ConstraintsStep — edit an existing constraint", () => {
  const forcedFacility: Constraint = {
    id: "c-sm4",
    name: "SM1 · impose Gymnase A",
    scope: "TEAM",
    scopeTargetId: "t1",
    family: "FACILITY",
    ruleType: "HARD",
    config: { forcedVenueId: "v1" },
    isActive: true,
  };

  beforeEach(() => {
    h.createMut.mockClear();
    h.updateMut.mockClear();
    h.list = [forcedFacility];
  });

  it("round-trips a forced venue without downgrading it to preferred, and PUTs the same id", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    expect(screen.getByText("SM1 · impose Gymnase A")).toBeInTheDocument();

    // Enter edit mode → the form pre-fills from config.
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    expect(screen.getByLabelText("Préférence")).toHaveValue("forced");
    expect(screen.getByLabelText("Gymnase")).toHaveValue("v1");

    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    expect(h.createMut).not.toHaveBeenCalled();
    expect(h.updateMut).toHaveBeenCalledOnce();
    const arg = h.updateMut.mock.calls[0][0] as { id: string; body: Constraint };
    expect(arg.id).toBe("c-sm4");
    expect(arg.body.config).toHaveProperty("forcedVenueId", "v1");
    expect(arg.body.config).not.toHaveProperty("preferredVenueId");
    expect(arg.body.ruleType).toBe("HARD");
  });

  it("softening a forced venue to 'préfère' emits a PREFERRED rule, not the inherited HARD (F1)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    // Switch impose → préfère: the inherited HARD must NOT leak (HARD preferredVenueId
    // is still a forced venue engine-side — the opposite of what the user wants).
    await user.selectOptions(screen.getByLabelText("Préférence"), "preferred");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.ruleType).toBe("PREFERRED");
    expect(arg.body.config).toEqual({ preferredVenueId: "v1" });
  });

  it("persists an edited venue choice under the forced key", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ConstraintsStep />);

    await user.click(screen.getByRole("button", { name: "Gymnase" }));
    await user.click(screen.getByRole("button", { name: "Modifier" }));
    await user.selectOptions(screen.getByLabelText("Gymnase"), "v2");
    await user.click(screen.getByRole("button", { name: "Enregistrer la contrainte" }));

    const arg = h.updateMut.mock.calls[0][0] as { body: Constraint };
    expect(arg.body.config).toEqual({ forcedVenueId: "v2" });
  });
});

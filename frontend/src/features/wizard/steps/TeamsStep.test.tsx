import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team } from "../api";

const team: Team = {
  id: "t1", name: "SM3", sportCategoryId: "cat1", priorityTierId: 5, tierOrder: 0,
  gender: "M", level: "DEPARTEMENTAL", sessionsPerWeek: 1, isActive: true,
};

const createMut = vi.fn();
const updateMut = vi.fn();
const reorderMut = vi.fn();

vi.mock("../queries", () => ({
  useWizardTeams: () => ({ data: [team] }),
  useSportCategories: () => ({ data: [{ id: "cat1", name: "Senior", sortOrder: 0 }] }),
  usePriorityTiers: () => ({
    data: [
      { id: 1, label: "S", name: "Elite", color: null },
      { id: 5, label: "D", name: "Bonus", color: null },
    ],
  }),
  useCreateTeam: () => ({ mutate: createMut, isPending: false }),
  useUpdateTeam: () => ({ mutate: updateMut }),
  useDeleteTeam: () => ({ mutate: vi.fn() }),
  useReorderTeams: () => ({ mutate: reorderMut }),
}));

import { TeamsStep } from "./TeamsStep";

describe("TeamsStep", () => {
  beforeEach(() => {
    createMut.mockClear();
    updateMut.mockClear();
  });

  it("shows a play-level select and no redundant inner heading", () => {
    renderWithProviders(<TeamsStep />);
    // Play-level select exists (distinct from the rank/"Rang" select).
    expect(screen.getAllByLabelText("Niveau de jeu").length).toBeGreaterThan(0);
    expect(screen.getAllByLabelText("Rang").length).toBeGreaterThan(0);
    // Point 5: the sticky wizard header owns the title; no inner "Équipes" h2.
    expect(screen.queryByRole("heading", { name: "Équipes" })).toBeNull();
  });

  it("keeps the gender select (categories are ungendered now)", () => {
    renderWithProviders(<TeamsStep />);
    expect(screen.getAllByLabelText("Genre").length).toBeGreaterThan(0);
  });

  it("warns when a competitive team is ranked Bonus (D)", () => {
    renderWithProviders(<TeamsStep />);
    // team t1 = DEPARTEMENTAL (competitive) + tier 5 (D) → warning.
    expect(screen.getByText(/en compétition classée Bonus/i)).toBeInTheDocument();
  });

  it("no warning for a loisir team ranked Bonus", () => {
    team.level = "LOISIR_ADULTE";
    renderWithProviders(<TeamsStep />);
    expect(screen.queryByText(/en compétition classée Bonus/i)).toBeNull();
    team.level = "DEPARTEMENTAL"; // restore
  });

  it("sends the play level when changed on a row", async () => {
    const user = userEvent.setup();
    renderWithProviders(<TeamsStep />);
    const rowLevel = screen.getAllByLabelText("Niveau de jeu")[1]; // [0] = add form, [1] = row
    await user.selectOptions(rowLevel, "REGIONAL");
    expect(updateMut).toHaveBeenCalled();
    const body = updateMut.mock.calls[0][0].body;
    expect(body.level).toBe("REGIONAL");
  });
});

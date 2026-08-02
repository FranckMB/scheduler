import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, Team } from "./api";
import { ImportFbiDialog } from "./ImportFbiDialog";

const { analyzeFbiFixtures, importFbiFixtures } = vi.hoisted(() => ({
  analyzeFbiFixtures: vi.fn(() =>
    Promise.resolve({
      divisions: [
        { name: "DF2", fbiTeamLabel: null, rowCount: 22, teamId: null, competitionId: null },
        { name: "PNM", fbiTeamLabel: null, rowCount: 10, teamId: "team-1", competitionId: "comp-1" },
      ],
      totalRows: 34,
      exempted: 2,
      errors: [],
    }),
  ),
  importFbiFixtures: vi.fn(() =>
    Promise.resolve({
      message: "Import terminé.",
      created: 22,
      updated: 1,
      unchanged: 9,
      exempted: 2,
      errors: ["Ligne 4 : aucune équipe ne correspond au club « BC Test »."],
      warnings: [{ type: "RESCHEDULED", division: "PNM", externalRef: "101137", message: "PNM n°101137 : re-programmé du 28/11/2026 au 05/12/2026." }],
      unmappedDivisions: [],
    }),
  ),
}));

vi.mock("./api", () => ({ analyzeFbiFixtures, importFbiFixtures }));

const teams: Team[] = [
  { id: "team-1", name: "SM1", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-2", name: "SF3", sportCategoryId: "cat2", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const pickFile = async (user: ReturnType<typeof userEvent.setup>) => {
  const file = new File(["xlsx"], "fbi.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
  await user.upload(screen.getByLabelText("Fichier FBI"), file);
};

beforeEach(() => {
  analyzeFbiFixtures.mockClear();
  importFbiFixtures.mockClear();
});

describe("ImportFbiDialog", () => {
  it("disables Importer until a file is picked and analyzed", () => {
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Importer" })).toBeDisabled();
    expect(analyzeFbiFixtures).not.toHaveBeenCalled();
  });

  it("analyzes on file pick: known mappings shown as text, new ones as a select", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);

    expect(analyzeFbiFixtures).toHaveBeenCalledOnce();
    // The persisted PNM mapping is pre-filled (text, no select)…
    await waitFor(() => expect(screen.getByText("→ SM1")).toBeInTheDocument());
    // …and the unknown DF2 division offers the team picker.
    expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument();
    expect(screen.queryByLabelText("Équipe pour PNM")).not.toBeInTheDocument();
  });

  it("imports in ONE pass: file + the new mappings only, then shows the report", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument());
    await user.selectOptions(screen.getByLabelText("Équipe pour DF2"), "team-2");
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(importFbiFixtures).toHaveBeenCalledOnce();
    // Only DF2 rides along — PNM is already persisted server-side.
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2" }]);

    // The dialog stays open and surfaces the diff report + warnings + errors.
    await waitFor(() => expect(screen.getByText(/22 créés · 1 mis à jour · 9 inchangés/)).toBeInTheDocument());
    expect(screen.getByText(/PNM n°101137 : re-programmé/)).toBeInTheDocument();
    expect(screen.getByText(/Ligne 4 : aucune équipe ne correspond/)).toBeInTheDocument();
  });
});

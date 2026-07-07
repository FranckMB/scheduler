import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team } from "./api";
import { ImportFbiDialog } from "./ImportFbiDialog";

const { importFbiFixtures } = vi.hoisted(() => ({
  importFbiFixtures: vi.fn(() =>
    Promise.resolve({ message: "Import completed.", created: 2, skipped: 1, errors: ["Ligne 4 : aucune équipe ne correspond au club « BC Test »."] }),
  ),
}));

vi.mock("./api", () => ({ importFbiFixtures }));

const teams: Team[] = [
  { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null },
  { id: "team-2", name: "Seniors", sportCategoryId: "cat2", level: null, gender: null },
];

beforeEach(() => importFbiFixtures.mockClear());

describe("ImportFbiDialog", () => {
  it("disables Importer until a file is picked", () => {
    renderWithProviders(<ImportFbiDialog teams={teams} onClose={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Importer" })).toBeDisabled();
  });

  it("uploads for the selected team and shows the per-row report", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} onClose={vi.fn()} />);

    await user.selectOptions(screen.getByLabelText("Équipe"), "team-2");
    const file = new File(["xlsx"], "fbi.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
    await user.upload(screen.getByLabelText("Fichier FBI"), file);
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(importFbiFixtures).toHaveBeenCalledOnce();
    expect(importFbiFixtures).toHaveBeenCalledWith("team-2", expect.any(File));

    // The dialog stays open and surfaces created/skipped + row errors.
    await waitFor(() => expect(screen.getByText(/2 créés · 1 ignoré/)).toBeInTheDocument());
    expect(screen.getByText(/Ligne 4 : aucune équipe ne correspond/)).toBeInTheDocument();
  });
});

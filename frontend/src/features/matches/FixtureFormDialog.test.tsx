import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Competition, Team } from "./api";
import { FixtureFormDialog } from "./FixtureFormDialog";

const { mutate } = vi.hoisted(() => ({ mutate: vi.fn() }));

vi.mock("./queries", () => ({
  useCreateFixture: () => ({ mutate, isPending: false }),
}));

const teams: Team[] = [
  { id: "team-1", name: "U13", sportCategoryId: "cat", level: null, gender: null },
  { id: "team-2", name: "Seniors", sportCategoryId: "cat2", level: null, gender: null },
];
const competitions: Competition[] = [{ id: "comp-1", teamId: "team-1", name: "Championnat U13", competitionType: "CHAMPIONSHIP" }];

beforeEach(() => mutate.mockClear());

describe("FixtureFormDialog", () => {
  it("creates a friendly (no competition → competitionId null)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} competitions={[]} onClose={vi.fn()} />);

    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(mutate).toHaveBeenCalledOnce();
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-1", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: null });
  });

  it("keeps Créer disabled until the required fields are filled", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} competitions={[]} onClose={vi.fn()} />);

    expect(screen.getByRole("button", { name: "Créer" })).toBeDisabled();
    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    expect(screen.getByRole("button", { name: "Créer" })).toBeEnabled();
  });

  it("drops the previous team's competition when the team changes", async () => {
    const user = userEvent.setup();
    renderWithProviders(<FixtureFormDialog teams={teams} competitions={competitions} onClose={vi.fn()} />);

    // team-1 is selected by default → pick its competition, then switch to team-2.
    await user.selectOptions(screen.getByLabelText("Compétition"), "comp-1");
    await user.selectOptions(screen.getByLabelText("Équipe"), "team-2");
    await user.type(screen.getByLabelText("Date"), "2026-11-01");
    await user.type(screen.getByLabelText("Adversaire"), "Amis");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    // team-2 has no competition → the stale comp-1 must not be carried over.
    expect(mutate.mock.calls[0][0]).toEqual({ teamId: "team-2", matchDate: "2026-11-01", homeAway: "HOME", opponentLabel: "Amis", competitionId: null });
  });
});

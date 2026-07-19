import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DeletePlanningButton } from "./DeletePlanningButton";

const deleteMutate = vi.fn();
let planData: { id: string } | null | undefined = { id: "pl1" };
let schedulesData: { schedulePlanId: string | null }[] | undefined = [];

vi.mock("./queries", () => ({
  useDeleteEntry: () => ({ mutate: deleteMutate, isPending: false }),
  useSchedulePlanForEntry: (id: string | null) => ({ data: null !== id ? planData : undefined }),
}));
vi.mock("@/features/planning/queries", () => ({ useSchedules: () => ({ data: schedulesData }) }));

beforeEach(() => {
  deleteMutate.mockReset();
  planData = { id: "pl1" };
  schedulesData = [];
});

describe("DeletePlanningButton", () => {
  it("confirms BEFORE deleting (nothing removed on the first click)", async () => {
    render(<DeletePlanningButton calendarEntryId="e1" title="Vacances Toussaint" />);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vacances Toussaint" }));
    expect(deleteMutate).not.toHaveBeenCalled();
    expect(screen.getByText(/Supprimer « Vacances Toussaint » \?/)).toBeInTheDocument();
  });

  it("warns about the cascade when the plan carries generated versions", async () => {
    schedulesData = [{ schedulePlanId: "pl1" }]; // une version pend au plan
    render(<DeletePlanningButton calendarEntryId="e1" title="Gym fermé" />);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Gym fermé" }));
    expect(screen.getByText(/son plan et toutes ses versions/i)).toBeInTheDocument();
  });

  it("keeps a benign message when the plan carries no version", async () => {
    schedulesData = []; // plan vide
    render(<DeletePlanningButton calendarEntryId="e1" title="Vacances Noël" />);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vacances Noël" }));
    expect(screen.getByText(/sera retiré/i)).toBeInTheDocument();
    expect(screen.queryByText(/toutes ses versions/i)).not.toBeInTheDocument();
  });

  it("deletes the calendar entry and fires onDeleted on confirm", async () => {
    deleteMutate.mockImplementation((_id: string, opts?: { onSuccess?: () => void }) => opts?.onSuccess?.());
    const onDeleted = vi.fn();
    render(<DeletePlanningButton calendarEntryId="e1" title="Vacances Toussaint" onDeleted={onDeleted} />);

    await userEvent.click(screen.getByRole("button", { name: "Supprimer Vacances Toussaint" }));
    // Bouton « Supprimer » de la confirmation (le second, dans le dialogue).
    await userEvent.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteMutate).toHaveBeenCalledWith("e1", expect.anything());
    expect(onDeleted).toHaveBeenCalled();
  });
});

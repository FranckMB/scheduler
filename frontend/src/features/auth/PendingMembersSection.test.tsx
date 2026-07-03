import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const approveMut = vi.fn();
const rejectMut = vi.fn();
const pending = { data: { members: [{ id: "m1", firstName: "Ada", lastName: "Lovelace", email: "ada@club.fr" }] }, isLoading: false };

vi.mock("./queries", () => ({
  usePendingMembers: () => pending,
  useApproveMember: () => ({ mutate: approveMut, isPending: false }),
  useRejectMember: () => ({ mutate: rejectMut, isPending: false }),
}));

import { PendingMembersSection } from "./PendingMembersSection";

describe("PendingMembersSection", () => {
  beforeEach(() => {
    approveMut.mockClear();
    rejectMut.mockClear();
    pending.data = { members: [{ id: "m1", firstName: "Ada", lastName: "Lovelace", email: "ada@club.fr" }] };
  });

  it("approve/reject call the mutations with the member id", async () => {
    const user = userEvent.setup();
    render(<PendingMembersSection />);
    await user.click(screen.getByRole("button", { name: /Approuver/ }));
    expect(approveMut).toHaveBeenCalledWith("m1");
    await user.click(screen.getByRole("button", { name: /Refuser/ }));
    expect(rejectMut).toHaveBeenCalledWith("m1");
  });

  it("shows an empty state with no pending members", () => {
    pending.data = { members: [] };
    render(<PendingMembersSection />);
    expect(screen.getByText(/Aucune demande en attente/)).toBeInTheDocument();
  });
});

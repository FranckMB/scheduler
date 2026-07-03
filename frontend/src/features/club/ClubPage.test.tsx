import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const me = { data: { role: "admin", club: { name: "BC Test", accentColor: null, accentPalette: null, logoUrl: null } }, isLoading: false };

vi.mock("@/features/auth/queries", () => ({
  useMe: () => me,
  usePendingMembers: () => ({ data: { members: [] }, isLoading: false }),
  useApproveMember: () => ({ mutate: vi.fn(), isPending: false }),
  useRejectMember: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock("./queries", () => ({
  useUpdateAppearance: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useUploadLogo: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteLogo: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { ClubPage } from "./ClubPage";

describe("ClubPage", () => {
  beforeEach(() => {
    me.data = { role: "admin", club: { name: "BC Test", accentColor: null, accentPalette: null, logoUrl: null } };
  });

  it("shows both sections for an admin, Demandes open by default", () => {
    render(<ClubPage />);
    const demandes = screen.getByRole("button", { name: /Demandes/ });
    expect(demandes).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText(/Aucune demande en attente/)).toBeInTheDocument();
    // Visuel section present but collapsed.
    expect(screen.getByRole("button", { name: /Visuel/ })).toHaveAttribute("aria-expanded", "false");
  });

  it("hides the Demandes section for a non-admin", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /Demandes/ })).toBeNull();
    expect(screen.getByRole("button", { name: /Visuel/ })).toBeInTheDocument();
  });
});

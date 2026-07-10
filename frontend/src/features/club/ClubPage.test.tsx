import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "@/features/auth/api";

type ClubMock = (Partial<NonNullable<MeResponse["club"]>> & { name: string }) | null;
const me: { data: { role: string; club: ClubMock }; isLoading: boolean } = {
  data: { role: "admin", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } },
  isLoading: false,
};
const updateClubInfo = vi.fn();

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
  useUpdateClubInfo: () => ({ mutate: updateClubInfo, isPending: false }),
  useResetClub: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { ClubPage } from "./ClubPage";

describe("ClubPage", () => {
  beforeEach(() => {
    me.data = { role: "admin", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
  });

  it("shows both sections for an admin, Demandes open by default", () => {
    render(<ClubPage />);
    const demandes = screen.getByRole("button", { name: /Demandes/ });
    expect(demandes).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByText(/Aucune demande en attente/)).toBeInTheDocument();
    // Visuel section present but collapsed.
    expect(screen.getByRole("button", { name: /Visuel/ })).toHaveAttribute("aria-expanded", "false");
  });

  it("shows the FFBB contacts section with the 3 blocks", async () => {
    me.data = {
      role: "admin",
      club: {
        name: "BC Test",
        accentColor: null,
        accentColorDark: null,
        accentPalette: null,
        logoUrl: null,
        address: "5 rue X",
        postalCode: "69100",
        city: "Villeurbanne",
        contactEmail: "contact@bccl.fr",
        ffbbCommittee: { name: "Comité du Rhône", email: "cdrbb@basketrhone.com", address: null, postalCode: null, city: null, phone: null, logoUrl: null },
        ffbbLeague: { name: "Ligue AURA", email: null, address: null, postalCode: null, city: null, phone: null, logoUrl: null },
      },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Contacts FFBB/ }));
    expect(screen.getByText("Comité du Rhône")).toBeInTheDocument();
    expect(screen.getByText("Ligue AURA")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "contact@bccl.fr" })).toHaveAttribute("href", "mailto:contact@bccl.fr");
  });

  it("shows the FFBB club-info section for an admin and saves it", async () => {
    updateClubInfo.mockClear();
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    // Read-only identity + editable groups render.
    expect(screen.getByText("Code FFBB")).toBeInTheDocument();
    expect(screen.getByText("Correspondant")).toBeInTheDocument();
    // Editing a field then saving PATCHes only that field (partial update).
    await user.type(screen.getByLabelText("Comité"), "0069");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateClubInfo).toHaveBeenCalledOnce();
    expect(updateClubInfo).toHaveBeenCalledWith({ committeeCode: "0069" });
  });

  it("hides the club-info section for a non-admin", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /Informations du club/ })).toBeNull();
  });

  it("hides the Demandes section for a non-admin", () => {
    me.data = { role: "member", club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null } };
    render(<ClubPage />);
    expect(screen.queryByRole("button", { name: /Demandes/ })).toBeNull();
    expect(screen.getByRole("button", { name: /Visuel/ })).toBeInTheDocument();
  });
});

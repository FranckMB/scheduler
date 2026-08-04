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

const ffbbImport = vi.fn();

vi.mock("./queries", () => ({
  useUpdateAppearance: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useUploadLogo: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteLogo: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateClubInfo: () => ({ mutate: updateClubInfo, isPending: false }),
  useFfbbImport: () => ({ mutate: ffbbImport, isPending: false }),
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

  it("shows the FFBB contacts section with Comité and Ligue — NOT the club itself", async () => {
    // Décision fondateur 2026-08-04 : la section ne montre que la hiérarchie
    // AU-DESSUS du club — ses coordonnées vivent dans « Informations du club ».
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
        ffbbCommittee: { name: "Comité du Rhône", email: "cdrbb@basketrhone.com", address: null, postalCode: null, city: null, phone: null, logoUrl: null, website: "http://www.basketrhone.com" },
        ffbbLeague: { name: "Ligue AURA", email: null, address: null, postalCode: null, city: null, phone: null, logoUrl: null, website: null },
      },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Contacts FFBB/ }));
    expect(screen.getByText("Comité du Rhône")).toBeInTheDocument();
    expect(screen.getByText("Ligue AURA")).toBeInTheDocument();
    // Le site web du comité (nouvelle donnée urlSiteWeb) est un lien.
    expect(screen.getByRole("link", { name: "http://www.basketrhone.com/" })).toBeInTheDocument();
    // Plus de bloc « Club » : son email n'apparaît pas ici.
    expect(screen.queryByRole("link", { name: "contact@bccl.fr" })).toBeNull();
    expect(screen.queryByText(/^Club$/)).toBeNull();
  });

  it("club info: FFBB fields read-only, the rest editable, partial PATCH", async () => {
    updateClubInfo.mockClear();
    me.data = {
      role: "admin",
      club: { name: "BC Test", accentColor: null, accentColorDark: null, accentPalette: null, logoUrl: null, committeeCode: "0069", contactPhone: "0643720140" },
    };
    const user = userEvent.setup();
    render(<ClubPage />);
    await user.click(screen.getByRole("button", { name: /Informations du club/ }));
    // Identité + contact = LECTURE SEULE : la valeur s'affiche, aucun input ne la porte.
    expect(screen.getByText("0069")).toBeInTheDocument();
    expect(screen.getByText("0643720140")).toBeInTheDocument();
    expect(screen.queryByLabelText("Comité")).toBeNull();
    expect(screen.queryByDisplayValue("0643720140")).toBeNull();
    // Le geste de correction : le ré-import FFBB.
    await user.click(screen.getByRole("button", { name: "Actualiser depuis la FFBB" }));
    expect(ffbbImport).toHaveBeenCalledOnce();
    // Les champs que la FFBB ne connaît pas restent saisissables — PATCH partiel.
    // Trois blocs portent un « Nom » (correspondant, président, salle) : le premier
    // dans l'ordre du DOM est celui du correspondant.
    await user.type(screen.getAllByLabelText(/^Nom$/)[0], "Jean Dupont");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(updateClubInfo).toHaveBeenCalledOnce();
    expect(updateClubInfo).toHaveBeenCalledWith({ correspondentName: "Jean Dupont" });
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

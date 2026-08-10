import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const deleteMut = vi.fn();
const exportMut = vi.fn();
const requestEmailMut = vi.fn();
const cancelEmailMut = vi.fn();
const logoutFn = vi.fn();
let pendingEmail: string | null = null;

vi.mock("./queries", () => ({
  useUpdateProfile: () => ({ mutate: vi.fn(), isPending: false }),
  useRequestEmailChange: () => ({ mutate: requestEmailMut, isPending: false }),
  useCancelEmailChange: () => ({ mutate: cancelEmailMut, isPending: false }),
  useChangePassword: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutate: deleteMut, isPending: false }),
  useDownloadMyData: () => ({ mutate: exportMut, isPending: false }),
}));

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({
    data: { id: "u1", email: "flo@club.fr", pendingEmail, firstName: "Flo", lastName: "Journey", role: "admin", club: { name: "BCCL" } },
    isLoading: false,
  }),
  useLogout: () => logoutFn,
}));

import { ProfilePage } from "./ProfilePage";

describe("ProfilePage — zone de danger (RGPD)", () => {
  beforeEach(() => {
    deleteMut.mockClear();
    logoutFn.mockClear();
    requestEmailMut.mockClear();
    cancelEmailMut.mockClear();
    pendingEmail = null;
  });

  it("désarme la suppression tant que le mot de passe n'est pas saisi (ré-authentification)", () => {
    render(<ProfilePage />);
    expect(screen.getByRole("button", { name: /Supprimer définitivement mon compte/ })).toBeDisabled();
    expect(deleteMut).not.toHaveBeenCalled();
  });

  it("arme et appelle la mutation avec le mot de passe saisi", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    await user.type(screen.getByLabelText(/Confirmez avec votre mot de passe/), "Password123!");
    const button = screen.getByRole("button", { name: /Supprimer définitivement mon compte/ });
    expect(button).toBeEnabled();

    await user.click(button);
    expect(deleteMut).toHaveBeenCalledWith("Password123!", expect.anything());
  });

  it("expose l'export RGPD de mes données", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);
    await user.click(screen.getByRole("button", { name: /Exporter mes données/ }));
    expect(exportMut).toHaveBeenCalled();
  });

  it("annonce la conséquence : anonymisation immédiate + purge club à 30 jours", () => {
    render(<ProfilePage />);
    expect(screen.getByText(/irréversible/)).toBeInTheDocument();
    expect(screen.getByText(/30 jours/)).toBeInTheDocument();
    expect(screen.getByText(/fiche\s+publique FFBB/)).toBeInTheDocument();
  });

  it("P4-74 — changer l'e-mail DÉCLENCHE la demande de confirmation (n'écrit pas en direct)", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    const emailInput = screen.getByLabelText("E-mail");
    await user.clear(emailInput);
    await user.type(emailInput, "nouvelle@club.fr");

    const button = screen.getByRole("button", { name: /Envoyer un lien de confirmation/ });
    expect(button).toBeEnabled();
    await user.click(button);
    expect(requestEmailMut).toHaveBeenCalledWith("nouvelle@club.fr", expect.anything());

    // Le message dit clairement que l'adresse actuelle reste active.
    expect(screen.getByText(/adresse actuelle reste active/)).toBeInTheDocument();
  });

  it("P4-74 — affiche l'attente et permet d'annuler quand un pendingEmail existe", async () => {
    pendingEmail = "nouvelle@club.fr";
    const user = userEvent.setup();
    render(<ProfilePage />);

    expect(screen.getByText(/En attente de confirmation/)).toBeInTheDocument();
    expect(screen.getByText("nouvelle@club.fr")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Annuler/ }));
    expect(cancelEmailMut).toHaveBeenCalled();
  });
});

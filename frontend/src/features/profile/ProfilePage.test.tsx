import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

const deleteMut = vi.fn();
const logoutFn = vi.fn();

vi.mock("./queries", () => ({
  useUpdateProfile: () => ({ mutate: vi.fn(), isPending: false }),
  useChangePassword: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteAccount: () => ({ mutate: deleteMut, isPending: false }),
}));

vi.mock("@/features/auth/queries", () => ({
  useMe: () => ({
    data: { id: "u1", email: "flo@club.fr", firstName: "Flo", lastName: "Journey", role: "admin", club: { name: "BCCL" } },
    isLoading: false,
  }),
  useLogout: () => logoutFn,
}));

import { ProfilePage } from "./ProfilePage";

describe("ProfilePage — zone de danger (RGPD)", () => {
  beforeEach(() => {
    deleteMut.mockClear();
    logoutFn.mockClear();
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

  it("annonce la conséquence : anonymisation immédiate + purge club à 30 jours", () => {
    render(<ProfilePage />);
    expect(screen.getByText(/irréversible/)).toBeInTheDocument();
    expect(screen.getByText(/30 jours/)).toBeInTheDocument();
    expect(screen.getByText(/fiche\s+publique FFBB/)).toBeInTheDocument();
  });
});

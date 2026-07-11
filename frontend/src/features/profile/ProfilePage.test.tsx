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

  it("désarme la suppression tant que l'email exact n'est pas re-saisi", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    const button = screen.getByRole("button", { name: /Supprimer définitivement mon compte/ });
    expect(button).toBeDisabled();

    await user.type(screen.getByLabelText(/Confirmez en saisissant votre e-mail/), "autre@club.fr");
    expect(button).toBeDisabled();
    expect(deleteMut).not.toHaveBeenCalled();
  });

  it("arme et appelle la mutation avec l'email confirmé (insensible à la casse)", async () => {
    const user = userEvent.setup();
    render(<ProfilePage />);

    await user.type(screen.getByLabelText(/Confirmez en saisissant votre e-mail/), "FLO@club.fr");
    const button = screen.getByRole("button", { name: /Supprimer définitivement mon compte/ });
    expect(button).toBeEnabled();

    await user.click(button);
    expect(deleteMut).toHaveBeenCalledWith("flo@club.fr", expect.anything());
  });

  it("annonce la conséquence : anonymisation immédiate + purge club à 30 jours", () => {
    render(<ProfilePage />);
    expect(screen.getByText(/irréversible/)).toBeInTheDocument();
    expect(screen.getByText(/30 jours/)).toBeInTheDocument();
    expect(screen.getByText(/fiche\s+publique FFBB/)).toBeInTheDocument();
  });
});

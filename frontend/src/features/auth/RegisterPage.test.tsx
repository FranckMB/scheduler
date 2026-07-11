import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = { register: vi.fn() };

vi.mock("./queries", () => ({
  useRegister: () => ({ mutateAsync: h.register, isPending: false }),
}));

import { RegisterPage } from "./RegisterPage";

describe("RegisterPage", () => {
  it("renders the club registration fields including the ARA code", () => {
    renderWithProviders(<RegisterPage />);
    expect(screen.getByLabelText("Prénom")).toBeInTheDocument();
    expect(screen.getByLabelText("Nom")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Mot de passe")).toBeInTheDocument();
    expect(screen.getByLabelText(/code ara/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /créer le compte/i })).toBeInTheDocument();
  });

  // A3: register never authenticates — success shows a "check your email" state,
  // never a redirect into the app (the JWT only comes from the verification link).
  it("shows the check-your-email confirmation after a successful submit", async () => {
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);

    await user.type(screen.getByLabelText("Prénom"), "Mara");
    await user.type(screen.getByLabelText("Nom"), "Mb");
    await user.type(screen.getByLabelText("Email"), "new@club.fr");
    await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText(/code ara/i), "BCCL0123");
    await user.type(screen.getByLabelText(/nom du club/i), "Basket Club");
    // RGPD : le bouton reste désarmé tant que le consentement n'est pas coché.
    expect(screen.getByRole("button", { name: /créer le compte/i })).toBeDisabled();
    await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/email de confirmation/i)).toBeInTheDocument());
    expect(screen.getByText("new@club.fr")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /créer le compte/i })).not.toBeInTheDocument();
  });
});

import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

const h = { register: vi.fn() };

vi.mock("./queries", () => ({
  useRegister: () => ({ mutateAsync: h.register, isPending: false }),
}));

import { RegisterPage } from "./RegisterPage";

/** Traverse l'étape sport (basket présélectionné) pour atteindre le formulaire. */
async function gotoDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: /continuer/i }));
}

describe("RegisterPage", () => {
  it("lands on the sport step first, basketball preselected", () => {
    renderWithProviders(<RegisterPage />);
    expect(screen.getByRole("button", { name: /basketball/i })).toHaveAttribute("aria-pressed", "true");
    // Les autres sports sont annoncés mais désactivés (aucun multi-sport).
    expect(screen.getByRole("button", { name: /handball/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /continuer/i })).toBeInTheDocument();
  });

  it("renders the club registration fields (incl. ARA) after the sport step", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    expect(screen.getByLabelText("Prénom")).toBeInTheDocument();
    expect(screen.getByLabelText("Nom")).toBeInTheDocument();
    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Mot de passe")).toBeInTheDocument();
    expect(screen.getByLabelText("Confirmer le mot de passe")).toBeInTheDocument();
    expect(screen.getByLabelText(/code ara/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /créer le compte/i })).toBeInTheDocument();
  });

  it("validates the email on blur", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await user.type(screen.getByLabelText("Email"), "pas-un-email");
    await user.tab();
    expect(screen.getByText(/email invalide/i)).toBeInTheDocument();
  });

  it("blocks submit when the two passwords differ", async () => {
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);
    await user.type(screen.getByLabelText("Prénom"), "Mara");
    await user.type(screen.getByLabelText("Nom"), "Mb");
    await user.type(screen.getByLabelText("Email"), "new@club.fr");
    await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Sup3rStrongPwd?");
    await user.type(screen.getByLabelText(/code ara/i), "BCCL0123");
    await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));
    // Non-correspondance signalée sous le champ (NewPasswordFields) ; submit bloqué.
    expect(screen.getByText(/ne sont pas identiques/i)).toBeInTheDocument();
    expect(h.register).not.toHaveBeenCalled();
  });

  // A3: register never authenticates — success shows a "check your email" state,
  // never a redirect into the app (the JWT only comes from the verification link).
  it("shows the check-your-email confirmation after a successful submit", async () => {
    h.register.mockResolvedValueOnce({ status: "verification_pending" });
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />);
    await gotoDetails(user);

    await user.type(screen.getByLabelText("Prénom"), "Mara");
    await user.type(screen.getByLabelText("Nom"), "Mb");
    await user.type(screen.getByLabelText("Email"), "new@club.fr");
    await user.type(screen.getByLabelText("Mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Sup3rStrongPwd!");
    await user.type(screen.getByLabelText(/code ara/i), "BCCL0123");
    await user.type(screen.getByLabelText(/nom du club/i), "Basket Club");
    // RGPD : soumettre sans cocher affiche une erreur claire, puis cocher débloque.
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));
    expect(screen.getByText(/accepter les conditions/i)).toBeInTheDocument();
    await user.click(screen.getByRole("checkbox", { name: /j'accepte/i }));
    await user.click(screen.getByRole("button", { name: /créer le compte/i }));

    await waitFor(() => expect(screen.getByText(/email de confirmation/i)).toBeInTheDocument());
    expect(screen.getByText("new@club.fr")).toBeInTheDocument();
    // Le sport n'est pas envoyé (basket posé côté serveur) — payload sans `sport`/`confirm`.
    expect(h.register).toHaveBeenCalledWith(expect.not.objectContaining({ sport: expect.anything(), confirm: expect.anything() }));
  });
});

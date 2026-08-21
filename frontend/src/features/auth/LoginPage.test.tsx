import { screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";

import { markSessionExpired } from "@/shared/lib/sessionExpiredNotice";
import { renderWithProviders } from "@/test/utils";

import { LoginPage } from "./LoginPage";

describe("LoginPage", () => {
  it("renders the login form", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByLabelText("Mot de passe")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /se connecter/i })).toBeInTheDocument();
  });

  it("links to registration and password recovery", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.getByRole("link", { name: /créer un compte/i })).toHaveAttribute("href", "/register");
    expect(screen.getByRole("link", { name: /oublié/i })).toHaveAttribute("href", "/forgot-password");
  });
});

/**
 * P5-14 — la session expirée n'est plus une redirection MUETTE. Un 401 hors login
 * pose un marqueur one-shot (client.ts) ; LoginPage le lit ET le consomme au
 * montage et rend le bloc de réassurance « Fin du temps réglementaire » au-dessus
 * du formulaire. « Se reconnecter » EST le formulaire déjà présent — pas de page
 * séparée, pas de query param (une URL en favori ne doit jamais l'afficher à tort).
 */
describe("LoginPage — bloc « session expirée »", () => {
  afterEach(() => {
    window.sessionStorage.clear();
  });

  it("affiche le bloc quand le marqueur est présent", async () => {
    markSessionExpired();
    renderWithProviders(<LoginPage />);
    expect(await screen.findByText(/fin du temps réglementaire/i)).toBeInTheDocument();
    expect(screen.getByText(/votre session a expiré/i)).toBeInTheDocument();
  });

  it("n'affiche PAS le bloc sans marqueur", () => {
    renderWithProviders(<LoginPage />);
    expect(screen.queryByText(/fin du temps réglementaire/i)).toBeNull();
  });

  it("consomme le marqueur : absent au second montage (one-shot)", async () => {
    markSessionExpired();
    const first = renderWithProviders(<LoginPage />);
    expect(await screen.findByText(/fin du temps réglementaire/i)).toBeInTheDocument();
    first.unmount();

    renderWithProviders(<LoginPage />);
    expect(screen.queryByText(/fin du temps réglementaire/i)).toBeNull();
  });
});

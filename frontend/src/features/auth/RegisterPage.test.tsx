import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { renderWithProviders } from "@/test/utils";

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
});

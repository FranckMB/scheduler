import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";

import { NewPasswordFields } from "./new-password-fields";

function Harness() {
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  return <NewPasswordFields password={password} confirm={confirm} onPasswordChange={setPassword} onConfirmChange={setConfirm} />;
}

describe("NewPasswordFields", () => {
  it("greens each criterion live as the password satisfies it", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const pw = screen.getByLabelText("Mot de passe");

    await user.type(pw, "aaaaaaaaaaaa"); // 12 chars, no upper/special
    expect(screen.getByText("Au moins 12 caractères").closest("li")).toHaveTextContent("validé");
    expect(screen.getByText("Une majuscule").closest("li")).toHaveTextContent("manquant");

    await user.type(pw, "A!");
    expect(screen.getByText("Une majuscule").closest("li")).toHaveTextContent("validé");
    expect(screen.getByText("Un caractère spécial").closest("li")).toHaveTextContent("validé");
  });

  it("flags a mismatch once the confirmation is entered", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.type(screen.getByLabelText("Mot de passe"), "Password123!");
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Password123?");
    expect(screen.getByText(/ne sont pas identiques/i)).toBeInTheDocument();

    await user.clear(screen.getByLabelText("Confirmer le mot de passe"));
    await user.type(screen.getByLabelText("Confirmer le mot de passe"), "Password123!");
    expect(screen.queryByText(/ne sont pas identiques/i)).not.toBeInTheDocument();
  });
});

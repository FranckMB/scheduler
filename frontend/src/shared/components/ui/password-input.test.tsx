import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import { PasswordInput } from "./password-input";

describe("PasswordInput (eye toggle)", () => {
  it("hides the value by default and reveals it on toggle", async () => {
    const user = userEvent.setup();
    render(<PasswordInput aria-label="pw" defaultValue="secret" />);

    const field = screen.getByLabelText("pw") as HTMLInputElement;
    expect(field.type).toBe("password");

    await user.click(screen.getByRole("button", { name: "Afficher le mot de passe" }));
    expect(field.type).toBe("text");

    await user.click(screen.getByRole("button", { name: "Masquer le mot de passe" }));
    expect(field.type).toBe("password");
  });
});

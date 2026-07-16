import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { expectNoA11yViolations, renderWithProviders } from "@/test/utils";

import { completeAdminTotp, startAdminPassword } from "./api";
import { AdminLoginPage } from "./AdminLoginPage";
import { useAdminStore } from "./store";

vi.mock("./api", () => ({
  completeAdminTotp: vi.fn(),
  startAdminPassword: vi.fn(),
}));

const startPassword = vi.mocked(startAdminPassword);
const completeTotp = vi.mocked(completeAdminTotp);

describe("AdminLoginPage", () => {
  beforeEach(() => {
    startPassword.mockReset();
    completeTotp.mockReset();
    useAdminStore.getState().clear();
  });

  it("requires the password step before showing the TOTP challenge", async () => {
    startPassword.mockResolvedValue({ mfaRequired: true });
    const user = userEvent.setup();
    renderWithProviders(<AdminLoginPage />);

    await user.type(screen.getByLabelText("Email"), "ops@example.test");
    await user.type(screen.getByLabelText("Mot de passe"), "secret");
    await user.click(screen.getByRole("button", { name: "Continuer" }));

    expect(await screen.findByLabelText("Code TOTP")).toBeInTheDocument();
    expect(startPassword).toHaveBeenCalledWith({ email: "ops@example.test", password: "secret" });
  });

  it("stores the CSRF token after a valid TOTP code", async () => {
    startPassword.mockResolvedValue({ mfaRequired: true });
    completeTotp.mockResolvedValue({ authenticated: true, csrfToken: "csrf-123" });
    const user = userEvent.setup();
    renderWithProviders(<AdminLoginPage />);

    await user.type(screen.getByLabelText("Email"), "ops@example.test");
    await user.type(screen.getByLabelText("Mot de passe"), "secret");
    await user.click(screen.getByRole("button", { name: "Continuer" }));
    const code = await screen.findByLabelText("Code TOTP");
    await user.type(code, "123456");
    await user.click(screen.getByRole("button", { name: "Ouvrir la console" }));

    await waitFor(() => expect(useAdminStore.getState().csrfToken).toBe("csrf-123"));
    expect(completeTotp).toHaveBeenCalledWith("123456");
  });

  it("keeps the login structure accessible", async () => {
    await expectNoA11yViolations(<AdminLoginPage />);
  });
});

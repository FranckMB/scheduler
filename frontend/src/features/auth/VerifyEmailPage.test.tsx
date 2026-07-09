import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

const h = { verify: vi.fn(), navigate: vi.fn() };

vi.mock("./queries", () => ({
  useVerifyEmail: () => ({ mutateAsync: h.verify, isPending: false }),
}));
vi.mock("react-router-dom", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react-router-dom")>()),
  useNavigate: () => h.navigate,
}));

import { VerifyEmailPage } from "./VerifyEmailPage";

function renderAt(token: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/verify-email/${token}`]}>
        <Routes>
          <Route path="/verify-email/:token" element={<VerifyEmailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("VerifyEmailPage", () => {
  it("consumes the token on mount and routes into the app (active → home)", async () => {
    h.verify.mockReset();
    h.navigate.mockReset();
    h.verify.mockResolvedValueOnce({ token: "jwt", membershipStatus: "active", user: { id: "u1", email: "a@b.fr" } });

    renderAt("raw-token-123");

    await waitFor(() => expect(h.verify).toHaveBeenCalledWith("raw-token-123"));
    await waitFor(() => expect(h.navigate).toHaveBeenCalledWith("/", { replace: true }));
  });

  it("routes a pending membership to the waiting screen", async () => {
    h.verify.mockReset();
    h.navigate.mockReset();
    h.verify.mockResolvedValueOnce({ token: "jwt", membershipStatus: "pending", user: { id: "u1", email: "a@b.fr" } });

    renderAt("raw-token-456");

    await waitFor(() => expect(h.navigate).toHaveBeenCalledWith("/waiting", { replace: true }));
  });

  it("shows an error when the token is invalid/expired", async () => {
    h.verify.mockReset();
    h.navigate.mockReset();
    h.verify.mockRejectedValueOnce(new Error("bad token"));

    renderAt("expired");

    await waitFor(() => expect(screen.getByText(/expiré/i)).toBeInTheDocument());
    expect(h.navigate).not.toHaveBeenCalled();
  });
});

import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAuthStore } from "@/shared/stores/authStore";

import { AuthGuard } from "./AuthGuard";

const { meState, info } = vi.hoisted(() => ({
  meState: { data: undefined as unknown },
  info: vi.fn(),
}));

vi.mock("@/features/auth/queries", () => ({ useMe: () => ({ data: meState.data, isLoading: false, isError: false }) }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { info } }));

const activeClub = (onboardingCompleted: boolean) => ({ membershipStatus: "active", club: { onboardingCompleted } });

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route element={<AuthGuard />}>
          <Route path="/" element={<div>COCKPIT</div>} />
          <Route path="/profile" element={<div>PROFILE</div>} />
          <Route path="/matchs" element={<div>MATCHS</div>} />
        </Route>
        <Route path="/wizard" element={<div>WIZARD</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("AuthGuard — onboarding lock", () => {
  beforeEach(() => {
    info.mockClear();
    useAuthStore.setState({ token: "jwt" });
  });

  it("keeps the burger routes reachable while onboarding (profile)", () => {
    meState.data = activeClub(false);
    renderAt("/profile");
    expect(screen.getByText("PROFILE")).toBeInTheDocument();
  });

  it("redirects the cockpit home to the wizard with an ephemeral hint", () => {
    meState.data = activeClub(false);
    renderAt("/");
    expect(screen.getByText("WIZARD")).toBeInTheDocument();
    expect(info).toHaveBeenCalledWith("Lancez votre première génération d'abord.");
  });

  it("redirects other locked routes to the wizard silently (no cockpit hint)", () => {
    meState.data = activeClub(false);
    renderAt("/matchs");
    expect(screen.getByText("WIZARD")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
  });

  it("lets the cockpit render once onboarding is complete", () => {
    meState.data = activeClub(true);
    renderAt("/");
    expect(screen.getByText("COCKPIT")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
  });
});

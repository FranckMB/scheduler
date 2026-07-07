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
          <Route path="/club" element={<div>CLUB</div>} />
          <Route path="/matchs" element={<div>MATCHS</div>} />
        </Route>
        <Route path="/wizard" element={<div>WIZARD</div>} />
        <Route path="/waiting" element={<div>WAITING</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("AuthGuard — onboarding lock", () => {
  beforeEach(() => {
    info.mockClear();
    useAuthStore.setState({ token: "jwt" });
  });

  it("keeps the burger routes reachable while onboarding (profile + club)", () => {
    meState.data = activeClub(false);
    renderAt("/profile");
    expect(screen.getByText("PROFILE")).toBeInTheDocument();
  });

  it("keeps /club reachable while onboarding", () => {
    meState.data = activeClub(false);
    renderAt("/club");
    expect(screen.getByText("CLUB")).toBeInTheDocument();
  });

  it("does NOT fire the cockpit hint for a pending (not-yet-active) member", () => {
    meState.data = { membershipStatus: "pending", club: { onboardingCompleted: false } };
    renderAt("/");
    expect(screen.getByText("WAITING")).toBeInTheDocument();
    expect(info).not.toHaveBeenCalled();
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

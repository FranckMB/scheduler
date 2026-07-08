import { useEffect } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

// During onboarding — until the season has a main plan (baseline, set by the
// first generation) — the app is locked to the wizard, except the account-menu
// (burger) destinations, which stay reachable.
const ONBOARDING_ALLOWED = ["/wizard", "/profile", "/club"];

/**
 * Gate for authenticated routes:
 * - no token            -> /login
 * - token + loading     -> spinner
 * - token + active      -> render app
 * - token + pending/none-> /waiting
 * - token + auth error  -> /login (stale/invalid token)
 */
export function AuthGuard() {
  const token = useAuthStore((state) => state.token);
  const { data, isLoading, isError } = useMe();
  const location = useLocation();

  // First-time club: locked to the wizard until a main plan exists (baseline),
  // but the account-menu routes (profile, club) stay reachable. Landing on the
  // cockpit home gets an ephemeral hint on the redirect — only for an ACTIVE
  // member (pending users are sent to /waiting by the guard below).
  // Onboarding phase = no baseline yet (single source of truth; the legacy
  // club.onboardingCompleted flag is no longer read for routing).
  const membershipActive = "active" === data?.membershipStatus;
  const onboardingLocked = Boolean(data) && null === (data?.baselineScheduleId ?? null);
  const showCockpitHint = membershipActive && onboardingLocked && "/" === location.pathname;
  useEffect(() => {
    if (showCockpitHint) {
      toast.info("Lancez votre première génération d'abord.");
    }
  }, [showCockpitHint]);

  if (null === token) {
    return <Navigate to="/login" replace />;
  }
  if (isLoading) {
    return <FullPageSpinner />;
  }
  if (isError || !data) {
    return <Navigate to="/login" replace />;
  }
  if (data.membershipStatus !== "active") {
    return <Navigate to="/waiting" replace />;
  }
  if (onboardingLocked && !ONBOARDING_ALLOWED.includes(location.pathname)) {
    return <Navigate to="/wizard" replace />;
  }

  return <Outlet />;
}

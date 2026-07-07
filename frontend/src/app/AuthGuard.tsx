import { useEffect } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

// During onboarding (before the first generation) the app is locked to the
// wizard — except the account-menu (burger) destinations, which stay reachable.
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

  // First-time club: locked to the wizard until the first generation, but the
  // account-menu routes (profile, club) stay reachable. Reaching the cockpit
  // home explicitly (e.g. by URL) gets an ephemeral hint on the redirect.
  const onboardingLocked = Boolean(data?.club && !data.club.onboardingCompleted);
  const blockedFromCockpit = onboardingLocked && !ONBOARDING_ALLOWED.includes(location.pathname) && "/" === location.pathname;
  useEffect(() => {
    if (blockedFromCockpit) {
      toast.info("Lancez votre première génération d'abord.");
    }
  }, [blockedFromCockpit]);

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

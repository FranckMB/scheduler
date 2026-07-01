import { Navigate, Outlet, useLocation } from "react-router-dom";

import { useMe } from "@/features/auth/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { useAuthStore } from "@/shared/stores/authStore";

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
  // First-time club: guide through the wizard until the first generation.
  if (data.club && !data.club.onboardingCompleted && location.pathname !== "/wizard") {
    return <Navigate to="/wizard" replace />;
  }

  return <Outlet />;
}

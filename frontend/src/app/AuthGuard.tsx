import { Navigate, Outlet } from "react-router-dom";

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

  return <Outlet />;
}

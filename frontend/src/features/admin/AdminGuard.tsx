import { Navigate, Outlet, useLocation } from "react-router-dom";

import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { useAdminSession } from "./queries";

export function AdminGuard() {
  const location = useLocation();
  const session = useAdminSession();

  if (session.isLoading) {
    return <FullPageSpinner />;
  }
  if (session.isError || !session.data) {
    return <Navigate to="/admin/login" replace state={{ from: location.pathname }} />;
  }

  return <Outlet />;
}

import { createBrowserRouter, RouterProvider } from "react-router-dom";

import { AppLayout } from "@/app/AppLayout";
import { AuthGuard } from "@/app/AuthGuard";
import { ForgotPasswordPage } from "@/features/auth/ForgotPasswordPage";
import { LoginPage } from "@/features/auth/LoginPage";
import { PendingMembersPage } from "@/features/auth/PendingMembersPage";
import { RegisterPage } from "@/features/auth/RegisterPage";
import { ResetPasswordPage } from "@/features/auth/ResetPasswordPage";
import { WaitingApprovalPage } from "@/features/auth/WaitingApprovalPage";
import { DashboardHome } from "@/features/dashboard/DashboardHome";
import { ProfilePage } from "@/features/profile/ProfilePage";

export const router = createBrowserRouter([
  { path: "/login", element: <LoginPage /> },
  { path: "/register", element: <RegisterPage /> },
  { path: "/forgot-password", element: <ForgotPasswordPage /> },
  { path: "/reset-password/:token", element: <ResetPasswordPage /> },
  { path: "/waiting", element: <WaitingApprovalPage /> },
  {
    element: <AuthGuard />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: "/", element: <DashboardHome /> },
          { path: "/profile", element: <ProfilePage /> },
          { path: "/pending-members", element: <PendingMembersPage /> },
        ],
      },
    ],
  },
]);

export function AppRouter() {
  return <RouterProvider router={router} />;
}

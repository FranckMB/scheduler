import { createBrowserRouter, Navigate, RouterProvider } from "react-router-dom";

import { AppLayout } from "@/app/AppLayout";
import { AuthGuard } from "@/app/AuthGuard";
import { ForgotPasswordPage } from "@/features/auth/ForgotPasswordPage";
import { LoginPage } from "@/features/auth/LoginPage";
import { RegisterPage } from "@/features/auth/RegisterPage";
import { ResetPasswordPage } from "@/features/auth/ResetPasswordPage";
import { WaitingApprovalPage } from "@/features/auth/WaitingApprovalPage";
import { ClubPage } from "@/features/club/ClubPage";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { ProfilePage } from "@/features/profile/ProfilePage";
import { WizardPage } from "@/features/wizard/WizardLayout";

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
          { path: "/", element: <PlanningPage /> },
          { path: "/wizard", element: <WizardPage /> },
          { path: "/club", element: <ClubPage /> },
          { path: "/profile", element: <ProfilePage /> },
          // Unknown authed URL (e.g. the removed /pending-members) → home, not the raw error boundary.
          { path: "*", element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
]);

export function AppRouter() {
  return <RouterProvider router={router} />;
}

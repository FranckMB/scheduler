import { createBrowserRouter, Navigate, RouterProvider } from "react-router-dom";

import { AppLayout } from "@/app/AppLayout";
import { AuthGuard } from "@/app/AuthGuard";
import { AdminGuard } from "@/features/admin/AdminGuard";
import { AdminDashboardPage } from "@/features/admin/AdminDashboardPage";
import { AdminShell } from "@/features/admin/AdminShell";
import { AdminLoginPage } from "@/features/admin/AdminLoginPage";
import { ForgotPasswordPage } from "@/features/auth/ForgotPasswordPage";
import { LoginPage } from "@/features/auth/LoginPage";
import { RegisterPage } from "@/features/auth/RegisterPage";
import { ResetPasswordPage } from "@/features/auth/ResetPasswordPage";
import { VerifyEmailPage } from "@/features/auth/VerifyEmailPage";
import { WaitingApprovalPage } from "@/features/auth/WaitingApprovalPage";
import { PrivacyPage } from "@/features/legal/PrivacyPage";
import { ClubPage } from "@/features/club/ClubPage";
import { CockpitPage } from "@/features/cockpit/CockpitPage";
import { MatchesPage } from "@/features/matches/MatchesPage";
import { PlanningPage } from "@/features/planning/PlanningPage";
import { ProfilePage } from "@/features/profile/ProfilePage";
import { WizardPage } from "@/features/wizard/WizardLayout";

const router = createBrowserRouter([
  { path: "/login", element: <LoginPage /> },
  { path: "/admin/login", element: <AdminLoginPage /> },
  {
    path: "/admin",
    element: <AdminGuard />,
    children: [
      {
        element: <AdminShell />,
        children: [
          { index: true, element: <AdminDashboardPage /> },
          { path: "*", element: <Navigate to="/admin" replace /> },
        ],
      },
    ],
  },
  { path: "/register", element: <RegisterPage /> },
  { path: "/forgot-password", element: <ForgotPasswordPage /> },
  { path: "/reset-password/:token", element: <ResetPasswordPage /> },
  { path: "/verify-email/:token", element: <VerifyEmailPage /> },
  { path: "/waiting", element: <WaitingApprovalPage /> },
  { path: "/confidentialite", element: <PrivacyPage /> },
  {
    element: <AuthGuard />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { path: "/", element: <CockpitPage /> },
          { path: "/planning", element: <PlanningPage /> },
          { path: "/matchs", element: <MatchesPage /> },
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

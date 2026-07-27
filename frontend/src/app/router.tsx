import { createBrowserRouter, Navigate, RouterProvider } from "react-router";

import { AppLayout } from "@/app/AppLayout";
import { AuthGuard } from "@/app/AuthGuard";
import { LoginPage } from "@/features/auth/LoginPage";

/**
 * Découpage du bundle (P4-6) — un chunk unique de 834 kB partait à CHAQUE
 * première visite : console superadmin et wizard compris, y compris pour un
 * coach qui n'ouvre qu'une page publique de doléances.
 *
 * Reste EAGER ce qui sert au premier rendu du chemin d'entrée : `LoginPage`, le
 * garde d'auth et le shell applicatif. Tout le reste part en `lazy` de route —
 * react-router gère l'attente (pas de Suspense à câbler), et le chunk d'une
 * route jamais visitée n'est jamais téléchargé.
 *
 * Les gros gains : `/admin` (console superadmin — un gestionnaire de club ne
 * l'ouvre jamais), `/wizard` (la plus grosse feature), `/doleances/:token`
 * (page publique sans login : le coach n'a besoin de rien d'autre).
 */
const router = createBrowserRouter([
  { path: "/login", element: <LoginPage /> },
  {
    path: "/admin/login",
    lazy: async () => ({ Component: (await import("@/features/admin/AdminLoginPage")).AdminLoginPage }),
  },
  {
    path: "/admin",
    lazy: async () => ({ Component: (await import("@/features/admin/AdminGuard")).AdminGuard }),
    children: [
      {
        lazy: async () => ({ Component: (await import("@/features/admin/AdminShell")).AdminShell }),
        children: [
          {
            index: true,
            lazy: async () => ({ Component: (await import("@/features/admin/AdminDashboardPage")).AdminDashboardPage }),
          },
          { path: "*", element: <Navigate to="/admin" replace /> },
        ],
      },
    ],
  },
  {
    path: "/register",
    lazy: async () => ({ Component: (await import("@/features/auth/RegisterPage")).RegisterPage }),
  },
  {
    path: "/forgot-password",
    lazy: async () => ({ Component: (await import("@/features/auth/ForgotPasswordPage")).ForgotPasswordPage }),
  },
  {
    path: "/reset-password/:token",
    lazy: async () => ({ Component: (await import("@/features/auth/ResetPasswordPage")).ResetPasswordPage }),
  },
  {
    path: "/verify-email/:token",
    lazy: async () => ({ Component: (await import("@/features/auth/VerifyEmailPage")).VerifyEmailPage }),
  },
  {
    path: "/waiting",
    lazy: async () => ({ Component: (await import("@/features/auth/WaitingApprovalPage")).WaitingApprovalPage }),
  },
  {
    path: "/confidentialite",
    lazy: async () => ({ Component: (await import("@/features/legal/PrivacyPage")).PrivacyPage }),
  },
  // #10 C2 — page publique SANS login : le coach saisit ses disponibilités via son
  // lien personnel. Route plate, hors AuthGuard (aucune session requise).
  {
    path: "/doleances/:token",
    lazy: async () => ({ Component: (await import("@/features/coach-wishes/PublicWishPage")).PublicWishPage }),
  },
  {
    element: <AuthGuard />,
    children: [
      {
        element: <AppLayout />,
        children: [
          {
            path: "/",
            lazy: async () => ({ Component: (await import("@/features/cockpit/CockpitPage")).CockpitPage }),
          },
          {
            path: "/planning",
            lazy: async () => ({ Component: (await import("@/features/planning/PlanningPage")).PlanningPage }),
          },
          {
            path: "/matchs",
            lazy: async () => ({ Component: (await import("@/features/matches/MatchesPage")).MatchesPage }),
          },
          {
            path: "/wizard",
            lazy: async () => ({ Component: (await import("@/features/wizard/WizardLayout")).WizardPage }),
          },
          {
            path: "/club",
            lazy: async () => ({ Component: (await import("@/features/club/ClubPage")).ClubPage }),
          },
          {
            path: "/profile",
            lazy: async () => ({ Component: (await import("@/features/profile/ProfilePage")).ProfilePage }),
          },
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

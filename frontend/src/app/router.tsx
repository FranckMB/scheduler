import { createBrowserRouter, RouterProvider } from "react-router-dom";

import { App } from "@/App";

/**
 * Minimal router — foundation smoke route only. The real routes (login,
 * register, waiting-approval, profile, dashboard) + AuthGuard are added in the
 * auth step (§C) of tranche 1.
 */
export const router = createBrowserRouter([{ path: "/", element: <App /> }]);

export function AppRouter() {
  return <RouterProvider router={router} />;
}

import * as Sentry from "@sentry/react";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { AppRouter } from "@/app/router";
import { ErrorBoundary } from "@/app/ErrorBoundary";
import { Providers } from "@/app/providers";
import { readPersistedThemeMode } from "@/shared/stores/themeStore";
import "@/index.css";

// Sentry ERREURS uniquement (pas d'APM/replay — quota free tier préservé). DSN
// absent = init sautée, SDK inerte.
//
// ⚠ L'activer demande DEUX gestes, pas un (P4-65) : poser `VITE_SENTRY_DSN` au build ET
// autoriser l'hôte d'ingestion du DSN dans `connect-src` (`docker/frontend/csp.conf`).
// Le DSN seul initialise le SDK et la CSP jette chaque envoi EN SILENCE. Un garde de build
// refuse désormais cette combinaison (`tooling/sentryCspGuard.ts`). INF-01.
if (import.meta.env.VITE_SENTRY_DSN) {
  Sentry.init({ dsn: import.meta.env.VITE_SENTRY_DSN, environment: import.meta.env.MODE, tracesSampleRate: 0 });
}

// Apply the persisted theme class BEFORE React's first paint. Without this the
// tree renders in the light default, then useApplyTheme flips `.dark` in an
// effect — a flash of the wrong theme plus a `transition-colors` animation that
// briefly leaves surfaces at intermediate, sub-AA colours (A11Y-06). Uses the
// same predicate (=== "dark") + persisted-shape source as useApplyTheme, so the
// pre-paint class and the post-hydration class never disagree.
document.documentElement.classList.toggle("dark", "dark" === readPersistedThemeMode());

const container = document.getElementById("root");
if (!container) {
  throw new Error("Root element #root not found");
}

createRoot(container).render(
  <StrictMode>
    <ErrorBoundary>
      <Providers>
        <AppRouter />
      </Providers>
    </ErrorBoundary>
  </StrictMode>,
);

import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { AppRouter } from "@/app/router";
import { ErrorBoundary } from "@/app/ErrorBoundary";
import { Providers } from "@/app/providers";
import { readPersistedThemeMode } from "@/shared/stores/themeStore";
import "@/index.css";

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

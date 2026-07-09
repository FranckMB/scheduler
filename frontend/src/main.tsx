import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { AppRouter } from "@/app/router";
import { Providers } from "@/app/providers";
import "@/index.css";

// Apply the persisted theme class BEFORE React's first paint. Without this the
// tree renders in the light default, then useApplyTheme flips `.dark` in an
// effect — a flash of the wrong theme plus a `transition-colors` animation that
// briefly leaves surfaces at intermediate, sub-AA colours (A11Y-06). Mirrors the
// zustand-persist shape (`cs-theme` → state.mode); defaults to dark like the store.
try {
  const persisted = localStorage.getItem("cs-theme");
  const mode = persisted ? (JSON.parse(persisted)?.state?.mode ?? "dark") : "dark";
  document.documentElement.classList.toggle("dark", mode !== "light");
} catch {
  document.documentElement.classList.add("dark");
}

const container = document.getElementById("root");
if (!container) {
  throw new Error("Root element #root not found");
}

createRoot(container).render(
  <StrictMode>
    <Providers>
      <AppRouter />
    </Providers>
  </StrictMode>,
);

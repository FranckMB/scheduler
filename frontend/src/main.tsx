import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

import { AppRouter } from "@/app/router";
import { Providers } from "@/app/providers";
import "@/index.css";

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

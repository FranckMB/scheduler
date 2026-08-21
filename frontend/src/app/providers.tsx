import { QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

import { ActionVeil } from "@/app/ActionVeil";
import { Toaster } from "@/shared/components/ui/toaster";
import { useApplyTheme } from "@/shared/hooks/useApplyTheme";
import { queryClient } from "@/shared/lib/queryClient";

export function Providers({ children }: { children: ReactNode }) {
  useApplyTheme();
  return (
    <QueryClientProvider client={queryClient}>
      {/* Le voile bloquant enveloppe l'app (lot C PR-2). Le Toaster reste FRÈRE, hors du wrapper
          inert : les toasts d'erreur et l'avertissement de relâche doivent rester interactifs. */}
      <ActionVeil>{children}</ActionVeil>
      <Toaster />
    </QueryClientProvider>
  );
}

import { QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

import { useApplyTheme } from "@/shared/hooks/useApplyTheme";
import { queryClient } from "@/shared/lib/queryClient";

export function Providers({ children }: { children: ReactNode }) {
  useApplyTheme();
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

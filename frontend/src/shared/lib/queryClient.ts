import { MutationCache, QueryCache, QueryClient } from "@tanstack/react-query";
import { HTTPError } from "ky";

import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

/**
 * Surface every failure to the user (FRT-01/02). Mutations always toast on
 * error; queries toast only on a *first* load failure (a failed background
 * refetch that still has cached data to show stays silent).
 */
function report(error: unknown): void {
  // 401 is handled by the ky client (session cleared + redirect to /login).
  if (error instanceof HTTPError && error.response.status === 401) {
    return;
  }
  void errorMessage(error).then((message) => toast.error(message));
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000, refetchOnWindowFocus: false },
  },
  mutationCache: new MutationCache({
    onError: (error) => report(error),
  }),
  queryCache: new QueryCache({
    onError: (error, query) => {
      if (query.state.data !== undefined) {
        return; // stale data is still on screen — don't nag on a background refetch
      }
      report(error);
    },
  }),
});

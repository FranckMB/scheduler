import { useQuery } from "@tanstack/react-query";

import { getAdminSession } from "./api";
import { useAdminStore } from "./store";

export function useAdminSession() {
  return useQuery({
    queryKey: ["admin-session"],
    queryFn: async () => {
      const session = await getAdminSession();
      useAdminStore.getState().setSession({ id: session.id, email: session.email }, session.csrfToken);
      return session;
    },
    retry: false,
    staleTime: 5 * 60_000,
  });
}

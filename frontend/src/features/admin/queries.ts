import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getAdminClubs, getAdminHealth, getAdminJobs, getAdminOverview, getAdminSession, runAdminJob } from "./api";
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

export function useAdminOverview() {
  return useQuery({
    queryKey: ["admin-overview"],
    queryFn: getAdminOverview,
    refetchInterval: 60_000,
  });
}

export function useAdminHealth() {
  return useQuery({
    queryKey: ["admin-health"],
    queryFn: getAdminHealth,
    refetchInterval: 30_000,
  });
}

export function useAdminClubs(page: number, limit: number, query: string) {
  return useQuery({
    queryKey: ["admin-clubs", { page, limit, query }],
    queryFn: () => getAdminClubs(page, limit, query),
    placeholderData: (previous) => previous,
  });
}

export function useAdminJobs() {
  return useQuery({
    queryKey: ["admin-jobs"],
    queryFn: getAdminJobs,
    refetchInterval: 60_000,
  });
}

export function useRunAdminJob() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (key: string) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return runAdminJob(key, csrfToken);
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: ["admin-jobs"] }),
  });
}

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getAdminActions, getAdminAuditLog, getAdminClubs, getAdminFreshness, getAdminHealth, getAdminJobs, getAdminMessengerFailed, getAdminOverview, getAdminSession, getAdminSystemErrors, runAdminClubAction, runAdminJob } from "./api";
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

/** Data-freshness board — l'âge des référentiels bouge lentement : refetch 5 min. */
export function useAdminFreshness() {
  return useQuery({
    queryKey: ["admin-freshness"],
    queryFn: getAdminFreshness,
    refetchInterval: 5 * 60_000,
  });
}

/** SA4 — le catalogue fermé des actions support (stable : une seule lecture par session suffit). */
export function useAdminActions() {
  return useQuery({
    queryKey: ["admin-actions"],
    queryFn: getAdminActions,
    staleTime: 5 * 60_000,
  });
}

/** SA4 — exécute une action support sur un club (CSRF du store, comme useRunAdminJob). */
export function useRunAdminClubAction() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ clubId, key }: { clubId: string; key: string }) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return runAdminClubAction(clubId, key, csrfToken);
    },
    // Une action mute le club (quota, saison) : rafraîchir la liste ET l'overview.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-clubs"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-overview"] });
    },
  });
}

/** Journal d'audit super-admin — refetch 60s comme les jobs. */
export function useAdminAuditLog(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-audit-log", { page, limit }],
    queryFn: () => getAdminAuditLog(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

/** Messenger failed transport — refetch 60s. */
export function useAdminMessengerFailed(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-messenger-failed", { page, limit }],
    queryFn: () => getAdminMessengerFailed(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}

/** Erreurs système agrégées — refetch 60s. */
export function useAdminSystemErrors(page: number, limit: number) {
  return useQuery({
    queryKey: ["admin-system-errors", { page, limit }],
    queryFn: () => getAdminSystemErrors(page, limit),
    placeholderData: (previous) => previous,
    refetchInterval: 60_000,
  });
}


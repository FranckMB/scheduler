import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { activateAdminMembership, decideAdminClubRequest, getAdminActions, getAdminAuditLog, getAdminCapacity, getAdminClubRequests, getAdminClubs, getAdminFreshness, getAdminHealth, getAdminJobs, getAdminMessengerFailed, getAdminOverview, getAdminPendingMemberships, getAdminSession, getAdminSystemErrors, runAdminClubAction, runAdminJob } from "./api";
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

/** Capacité (P5-10) — la fenêtre est de 90 j : les agrégats bougent lentement, refetch 5 min. */
export function useAdminCapacity() {
  return useQuery({
    queryKey: ["admin-capacity"],
    queryFn: getAdminCapacity,
    refetchInterval: 5 * 60_000,
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
    mutationFn: ({ clubId, key, args }: { clubId: string; key: string; args?: Record<string, string> }) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      // Pas de body pour une action sans schéma : on garde l'appel à 3 arguments
      // (le backend refuse tout body sur une action sans schéma).
      return args ? runAdminClubAction(clubId, key, csrfToken, args) : runAdminClubAction(clubId, key, csrfToken);
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

/** P3-4 PR B — les demandes de création (pending + expirées : la console garde la main). */
export function useAdminClubRequests() {
  return useQuery({
    queryKey: ["admin-club-requests"],
    queryFn: getAdminClubRequests,
    refetchInterval: 60_000,
  });
}

export function useDecideAdminClubRequest() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, decision }: { id: string; decision: "approve" | "refuse" }) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return decideAdminClubRequest(id, decision, csrfToken);
    },
    // Approuver crée un club → la liste des demandes ET le parc bougent.
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ["admin-club-requests"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-clubs"] });
      void queryClient.invalidateQueries({ queryKey: ["admin-pending-memberships"] });
    },
  });
}

/** P3-4 PR B — les adhésions en attente, tous clubs. */
export function useAdminPendingMemberships() {
  return useQuery({
    queryKey: ["admin-pending-memberships"],
    queryFn: getAdminPendingMemberships,
    refetchInterval: 60_000,
  });
}

export function useActivateAdminMembership() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => {
      const csrfToken = useAdminStore.getState().csrfToken;
      if (!csrfToken) {
        return Promise.reject(new Error("Missing super-admin CSRF token."));
      }

      return activateAdminMembership(id, csrfToken);
    },
    onSettled: () => void queryClient.invalidateQueries({ queryKey: ["admin-pending-memberships"] }),
  });
}

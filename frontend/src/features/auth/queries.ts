import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useAuthStore } from "@/shared/stores/authStore";
import { useSeasonStore } from "@/shared/stores/seasonStore";

import * as authApi from "./api";

/** Current user + club + membership status (server source of truth). */
export function useMe() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  return useQuery({
    queryKey: ["me"],
    queryFn: authApi.getMe,
    enabled: isAuthenticated,
    retry: false,
    staleTime: 60_000,
  });
}

/**
 * The season the user is WORKING IN: the explicit selection (X-Season-Id,
 * seasonStore) first, else the calendar-current one. null while `me` loads.
 * Même dérivation que PlanningPage (workingSeason) — source partagée.
 */
export function useWorkingSeason(): authApi.MeSeason | null {
  const { data: me } = useMe();
  const selectedSeasonId = useSeasonStore((s) => s.selectedSeasonId);
  return (
    me?.seasons.find((sn) => sn.id === selectedSeasonId)
    ?? me?.seasons.find((sn) => sn.id === (me.currentSeasonId ?? ""))
    ?? me?.seasons.find((sn) => sn.isCurrent)
    ?? null
  );
}

/**
 * ADR-0002 inv. 12 : renommer UN plan — celui de la saison comme celui d'une période.
 * L'endpoint (`PUT /schedule_plans/{id}`) l'a toujours été ; seuls ses appelants
 * visaient le plan de saison en dur.
 *
 * Deux caches à rafraîchir, et les deux comptent : `me` porte le nom du plan de
 * SAISON (en-tête, liste des plannings), `calendar-entries` porte la collection des
 * plans de PÉRIODE. N'invalider que `me` laissait un plan de période renommé
 * afficher son ancien nom pendant 30 s (staleTime de `useSchedulePlans`).
 */
export function useRenamePlanning() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ planId, name }: { planId: string; name: string }) => authApi.renamePlanning(planId, name),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
    },
  });
}

export function useLogin() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.login,
    // SEC-16 : la réponse ne porte plus de jeton (cookie httpOnly). On ne marque
    // que « une session est ouverte » — indice d'UI, l'autorisation reste au serveur.
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/** Register no longer authenticates (A3): success just means "check your email".
 *  The token is issued by useVerifyEmail once the emailed link is followed. */
export function useRegister() {
  return useMutation({ mutationFn: authApi.register });
}

export function useVerifyEmail() {
  const setAuthenticated = useAuthStore((state) => state.setAuthenticated);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.verifyEmail,
    onSuccess: () => {
      setAuthenticated(true);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

/**
 * SEC-16 : la déconnexion passe désormais par le SERVEUR — lui seul peut effacer
 * un cookie httpOnly. L'état local est vidé quoi qu'il arrive (`finally`) : si le
 * réseau tombe, l'écran doit quand même se fermer, et le cookie expirera de
 * lui-même. L'inverse — garder l'UI ouverte parce que l'appel a échoué —
 * laisserait l'utilisateur croire qu'il est encore connecté.
 */
export function useLogout() {
  const clear = useAuthStore((state) => state.clear);
  const queryClient = useQueryClient();
  return async () => {
    try {
      await authApi.logout();
    } catch {
      // silencieux : voir plus haut
    } finally {
      clear();
      queryClient.clear();
    }
  };
}

export function useForgotPassword() {
  return useMutation({ mutationFn: authApi.forgotPassword });
}

export function useResetPassword() {
  return useMutation({ mutationFn: authApi.resetPassword });
}

export function usePendingMembers(enabled: boolean) {
  return useQuery({
    queryKey: ["memberships", "pending"],
    queryFn: authApi.getPendingMembers,
    enabled,
  });
}

export function useApproveMember() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.approveMember,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["memberships", "pending"] }),
  });
}

export function useRejectMember() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.rejectMember,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["memberships", "pending"] }),
  });
}

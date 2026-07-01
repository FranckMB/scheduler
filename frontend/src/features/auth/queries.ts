import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { useAuthStore } from "@/shared/stores/authStore";

import * as authApi from "./api";

/** Current user + club + membership status (server source of truth). */
export function useMe() {
  const token = useAuthStore((state) => state.token);
  return useQuery({
    queryKey: ["me"],
    queryFn: authApi.getMe,
    enabled: null !== token,
    retry: false,
    staleTime: 60_000,
  });
}

export function useLogin() {
  const setToken = useAuthStore((state) => state.setToken);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.login,
    onSuccess: (data) => {
      setToken(data.token);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

export function useRegister() {
  const setToken = useAuthStore((state) => state.setToken);
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: authApi.register,
    onSuccess: (data) => {
      setToken(data.token);
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

export function useLogout() {
  const clear = useAuthStore((state) => state.clear);
  const queryClient = useQueryClient();
  return () => {
    clear();
    queryClient.clear();
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

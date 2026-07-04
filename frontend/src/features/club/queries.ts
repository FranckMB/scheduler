import { useMutation, useQueryClient } from "@tanstack/react-query";

import { toast } from "@/shared/stores/toastStore";

import type { AppearancePayload } from "./api";
import * as clubApi from "./api";

/** Save the club accent; refetch /me so the theme re-applies live. */
export function useUpdateAppearance() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: AppearancePayload) => clubApi.updateAppearance(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

export function useUploadLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => clubApi.uploadLogo(file),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

export function useDeleteLogo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => clubApi.deleteLogo(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

/** Wipe all club data; invalidate every query so the emptied state reloads. */
export function useResetClub() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => clubApi.resetClub(),
    onSuccess: (result) => {
      const s = result.deleted > 1 ? "s" : "";
      toast.success(`Club réinitialisé (${result.deleted} élément${s} supprimé${s}).`);
      void queryClient.invalidateQueries();
    },
  });
}

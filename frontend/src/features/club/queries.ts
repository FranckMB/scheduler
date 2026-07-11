import { useMutation, useQueryClient } from "@tanstack/react-query";

import { toast } from "@/shared/stores/toastStore";

import type { AppearancePayload, ClubInfoPayload } from "./api";
import * as clubApi from "./api";

/** Save the club accent; refetch /me so the theme re-applies live. */
export function useUpdateAppearance() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: AppearancePayload) => clubApi.updateAppearance(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["me"] }),
  });
}

/** Save the FFBB club info; refetch /me so the club section re-renders. */
export function useUpdateClubInfo() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: ClubInfoPayload) => clubApi.updateClubInfo(body),
    onSuccess: () => {
      toast.success("Informations du club enregistrées.");
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
    onError: () => toast.error("L'enregistrement des informations du club a échoué."),
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

/** RGPD portabilité — export JSON du workspace du club (management). */
export function useDownloadClubExport() {
  return useMutation({
    mutationFn: () => clubApi.downloadClubExport(),
    onSuccess: () => toast.success("Export téléchargé."),
  });
}

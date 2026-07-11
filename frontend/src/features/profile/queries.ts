import { useMutation, useQueryClient } from "@tanstack/react-query";

import { toast } from "@/shared/stores/toastStore";

import type { ChangePasswordPayload, UpdateProfilePayload } from "./api";
import * as profileApi from "./api";

export function useUpdateProfile() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateProfilePayload) => profileApi.updateProfile(body),
    onSuccess: () => {
      toast.success("Profil mis à jour.");
      void queryClient.invalidateQueries({ queryKey: ["me"] });
    },
  });
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (body: ChangePasswordPayload) => profileApi.changePassword(body),
    onSuccess: () => toast.success("Mot de passe modifié."),
  });
}

export function useDeleteAccount() {
  return useMutation({
    mutationFn: (password: string) => profileApi.deleteAccount(password),
    // Pas de toast succès ici : l'appelant déconnecte immédiatement (le compte
    // n'existe plus) et affiche la conséquence club si elle s'applique.
  });
}

/** RGPD portabilité — export JSON de mes données de compte. */
export function useDownloadMyData() {
  return useMutation({
    mutationFn: () => profileApi.downloadMyDataExport(),
    onSuccess: () => toast.success("Export téléchargé."),
  });
}

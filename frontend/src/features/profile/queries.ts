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

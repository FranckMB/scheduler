import { useMutation, useQueryClient } from "@tanstack/react-query";

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

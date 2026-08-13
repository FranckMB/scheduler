import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getReleaseNotes, markReleaseNotesSeen } from "./api";

/** Le journal de nouveautés du membre (notes publiées + filigrane de lecture). */
export function useReleaseNotes() {
  return useQuery({
    queryKey: ["release-notes"],
    queryFn: getReleaseNotes,
  });
}

/**
 * Marquer le journal comme lu. Au succès on invalide `release-notes` : le
 * filigrane se déplace et la modale « quoi de neuf » se referme d'elle-même
 * (plus aucune note n'est postérieure au filigrane).
 */
export function useMarkReleaseNotesSeen() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => markReleaseNotesSeen(),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["release-notes"] }),
  });
}

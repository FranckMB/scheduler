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
    // JAMAIS de voile : le blocage à 0 ms de l'ActionVeil protège un geste PARTI D'UN CLIC en
    // mangeant le 2ᵉ clic — or ce POST part souvent TOUT SEUL (filigrane posé en silence pour un
    // nouvel inscrit, ~1,5 s après l'arrivée sur le wizard), pendant que l'utilisateur tape le
    // nom de sa première équipe. Sans l'exemption, ses frappes étaient avalées sans retour visuel
    // (voile invisible sous 250 ms) — flake e2e #684/#687/#689/#694, diagnostiqué au trace.
    // Le chemin « J'ai compris » n'a pas besoin du voile non plus : le bouton se désactive
    // lui-même (`disabled={markSeen.isPending}`) et le POST est idempotent.
    meta: { veil: false },
    mutationFn: () => markReleaseNotesSeen(),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["release-notes"] }),
  });
}

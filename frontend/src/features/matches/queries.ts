import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import type { CreateFixtureInput, Fixture, PlaceFixtureInput } from "./api";
import * as matchesApi from "./api";

export function useFixtures() {
  return useQuery({ queryKey: ["fixtures"], queryFn: matchesApi.getFixtures, staleTime: 30_000 });
}

export function useCompetitions() {
  return useQuery({ queryKey: ["competitions"], queryFn: matchesApi.getCompetitions, staleTime: 300_000 });
}

export function useLeagueWindows() {
  return useQuery({ queryKey: ["league-match-windows"], queryFn: matchesApi.getLeagueWindows, staleTime: 300_000 });
}

/** The conflict radar is recomputed server-side — keep it fresh (short stale). */
export function useConflicts() {
  return useQuery({ queryKey: ["fixtures", "conflicts"], queryFn: matchesApi.getConflicts, staleTime: 10_000 });
}

// Reference data (names + envelope axes). Long-lived within a session.
export function useTeams() {
  return useQuery({ queryKey: ["teams"], queryFn: matchesApi.getTeams, staleTime: 300_000 });
}

export function usePriorityTiers() {
  return useQuery({ queryKey: ["priority_tiers"], queryFn: matchesApi.getPriorityTiers, staleTime: 300_000 });
}

export function useVenues() {
  return useQuery({ queryKey: ["venues"], queryFn: matchesApi.getVenues, staleTime: 300_000 });
}

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: matchesApi.getCategories, staleTime: 300_000 });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: matchesApi.getCoaches, staleTime: 300_000 });
}

/** Any fixture write changes the radar → invalidate both. */
function invalidateFixtures(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["fixtures"] });
  // Un match créé ou supprimé change l'ENGAGEMENT de son équipe (elle est engagée dès
  // qu'elle porte un match), et `isEngaged` ne voyage que dans ["wizard","teams"] —
  // que rien d'autre n'invalide. Sans ça, l'écran des équipes garde jusqu'à 30 s des
  // lignes déverrouillées juste après l'import FBI, c'est-à-dire au moment PRÉCIS où
  // l'engagement naît : il offrirait « Supprimer » sur une équipe que le serveur
  // refuse — le geste qui finit en 409 que ce champ existe pour ne jamais proposer.
  void queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] });
}

export function useCreateFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateFixtureInput) => matchesApi.createFixture(input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Création du match impossible"),
  });
}

export function usePlaceFixture() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ fixture, input }: { fixture: Fixture; input: PlaceFixtureInput }) => matchesApi.placeFixture(fixture, input),
    onSuccess: () => invalidateFixtures(queryClient),
    onError: () => toast.error("Placement impossible"),
  });
}

// ── Capacity layer (P1-4 PR B) ───────────────────────────────────────────────

/** Match access windows of the club's venues — consumed by the placement panel,
 * the wizard gate exemption and the access editor. */
export function useVenueMatchWindows() {
  return useQuery({ queryKey: ["venue_match_windows"], queryFn: matchesApi.getVenueMatchWindows, staleTime: 300_000 });
}

export function useCreateVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createVenueMatchWindow,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["venue_match_windows"] }),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteVenueMatchWindow() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteVenueMatchWindow,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ["venue_match_windows"] }),
    onError: () => toast.error("Suppression de la fenêtre impossible"),
  });
}

export function useVenueUnavailabilities() {
  return useQuery({ queryKey: ["venue_unavailabilities"], queryFn: matchesApi.getVenueUnavailabilities, staleTime: 60_000 });
}

/** Any unavailability write moves both alert surfaces (impact card + radar). */
function invalidateUnavailabilities(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["venue_unavailabilities"] });
  void queryClient.invalidateQueries({ queryKey: ["venue-unavailability-impact"] });
  void queryClient.invalidateQueries({ queryKey: ["fixtures", "conflicts"] });
}

export function useCreateVenueUnavailability() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.createVenueUnavailability,
    onSuccess: () => invalidateUnavailabilities(queryClient),
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useDeleteVenueUnavailability() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: matchesApi.deleteVenueUnavailability,
    onSuccess: () => invalidateUnavailabilities(queryClient),
    onError: () => toast.error("Suppression de l'indisponibilité impossible"),
  });
}

export function useUnavailabilityImpact() {
  return useQuery({ queryKey: ["venue-unavailability-impact"], queryFn: matchesApi.getUnavailabilityImpact, staleTime: 60_000 });
}

/** Dry-run — writes nothing server-side, so no invalidation. */
export function useAnalyzeFbiFixtures() {
  return useMutation({
    mutationFn: (file: File) => matchesApi.analyzeFbiFixtures(file),
    // Surface the backend's actionable message (missing columns, bad format…),
    // not a fixed label — same pattern as cockpit/queries.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

export function useImportFbiFixtures() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ file, mappings }: { file: File; mappings: matchesApi.FbiMapping[] }) =>
      matchesApi.importFbiFixtures(file, mappings),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      // The import persists the new Division↔team mappings as competitions.
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
    },
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

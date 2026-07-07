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

export function useImportFbiFixtures() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ teamId, file }: { teamId: string; file: File }) => matchesApi.importFbiFixtures(teamId, file),
    onSuccess: () => {
      invalidateFixtures(queryClient);
      // The import may find-or-create competitions.
      void queryClient.invalidateQueries({ queryKey: ["competitions"] });
    },
    // Surface the backend's actionable message (missing columns, bad format…),
    // not a fixed label — same pattern as cockpit/queries.
    onError: (error) => void errorMessage(error).then((message) => toast.error(message)),
  });
}

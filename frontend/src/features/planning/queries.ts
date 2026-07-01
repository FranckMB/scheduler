import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import type { LockLevel, Schedule, SlotMovePatch } from "./api";
import * as planningApi from "./api";

const IN_FLIGHT: Schedule["status"][] = ["PENDING", "GENERATING"];

/**
 * List of the club's schedules. While any schedule is mid-generation, poll so the
 * grid reflects PENDING → GENERATING → COMPLETED without a Mercure subscriber.
 */
export function useSchedules() {
  return useQuery({
    queryKey: ["schedules"],
    queryFn: planningApi.listSchedules,
    staleTime: 30_000,
    refetchInterval: (query) => ((query.state.data ?? []).some((s) => IN_FLIGHT.includes(s.status)) ? 2500 : false),
  });
}

export function useSlots(scheduleId: string | null) {
  return useQuery({
    queryKey: ["slots", scheduleId],
    queryFn: () => planningApi.getSlots(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

export function useDiagnostics(scheduleId: string | null) {
  return useQuery({
    queryKey: ["diagnostics", scheduleId],
    queryFn: () => planningApi.getDiagnostics(scheduleId as string),
    enabled: null !== scheduleId,
    staleTime: 30_000,
  });
}

// Reference data (names + grouping). Long-lived — rarely changes within a session.
export function useTeams() {
  return useQuery({ queryKey: ["teams"], queryFn: planningApi.getTeams, staleTime: 300_000 });
}

export function useVenues() {
  return useQuery({ queryKey: ["venues"], queryFn: planningApi.getVenues, staleTime: 300_000 });
}

export function useCoaches() {
  return useQuery({ queryKey: ["coaches"], queryFn: planningApi.getCoaches, staleTime: 300_000 });
}

export function useCategories() {
  return useQuery({ queryKey: ["categories"], queryFn: planningApi.getCategories, staleTime: 300_000 });
}

export function useTeamCoaches() {
  return useQuery({ queryKey: ["team_coaches"], queryFn: planningApi.getTeamCoaches, staleTime: 300_000 });
}

// --- 2b: adjust + regenerate loop ---------------------------------------------

export function useLockSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, lockLevel }: { id: string; lockLevel: LockLevel }) => planningApi.lockSlot(id, lockLevel),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["slots"] }),
  });
}

export function useMoveSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: { id: string; patch: SlotMovePatch }) => planningApi.moveSlot(id, patch),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["slots"] }),
  });
}

export function useGenerate() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => planningApi.generateSchedule(scheduleId),
    // The controller flips the schedule to PENDING synchronously; refetch starts the poll.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

export function useValidateSchedule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) => planningApi.validateSchedule(scheduleId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["me"] });
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
    },
  });
}

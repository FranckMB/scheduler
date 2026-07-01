import { useQuery } from "@tanstack/react-query";

import * as planningApi from "./api";

/** List of the club's schedules (all seasons; the active season is the only one today). */
export function useSchedules() {
  return useQuery({ queryKey: ["schedules"], queryFn: planningApi.listSchedules, staleTime: 30_000 });
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

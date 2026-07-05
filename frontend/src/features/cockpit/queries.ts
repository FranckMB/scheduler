import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { createConstraint } from "@/features/wizard/api";

import * as cockpitApi from "./api";
import type { CreateClosurePayload, CreateEventPayload } from "./api";

export function useCalendarEntries(from: string, to: string) {
  return useQuery({
    queryKey: ["calendar-entries", from, to],
    queryFn: () => cockpitApi.getCalendarEntries(from, to),
    staleTime: 30_000,
  });
}

export function useCalendarEntry(id: string | null) {
  return useQuery({
    queryKey: ["calendar-entry", id],
    queryFn: () => cockpitApi.getCalendarEntry(id as string),
    enabled: null !== id,
    staleTime: 30_000,
  });
}

export function useSchoolHolidays() {
  return useQuery({
    queryKey: ["school-holidays"],
    queryFn: cockpitApi.getSchoolHolidays,
    staleTime: 3_600_000,
  });
}

export function useEntryConflicts(entryId: string | null) {
  return useQuery({
    queryKey: ["entry-conflicts", entryId],
    queryFn: () => cockpitApi.getEntryConflicts(entryId as string),
    enabled: null !== entryId,
    staleTime: 30_000,
  });
}

function invalidateEntries(queryClient: ReturnType<typeof useQueryClient>): void {
  void queryClient.invalidateQueries({ queryKey: ["calendar-entries"] });
}

export function useCreateEvent() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateEventPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "event",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
        isDisruptive: payload.isDisruptive,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/**
 * A venue closure is a period entry PLUS a dated FACILITY constraint that carries
 * the closed venue. Two calls: if the constraint fails, roll back the entry. If
 * the rollback ALSO fails, surface a distinct error so the orphan period (a ⛔
 * marker with no closed-venue constraint) is not hidden behind a generic failure.
 */
export function useCreateVenueClosure() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: CreateClosurePayload) => {
      const entry = await cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "closure",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      });
      try {
        await createConstraint({
          name: payload.title,
          scope: "FACILITY",
          scopeTargetId: payload.venueId,
          family: "FACILITY",
          ruleType: "HARD",
          config: { type: "venue_closed", startDate: payload.startDate, endDate: payload.endDate },
          calendarEntryId: entry.id,
        });
      } catch (error) {
        try {
          await cockpitApi.deleteCalendarEntry(entry.id);
        } catch {
          throw new Error("La salle n'a pas pu être bloquée et l'annulation a échoué — supprime la période à la main.");
        }
        throw error;
      }
      return entry;
    },
    onSuccess: () => invalidateEntries(queryClient),
  });
}

/** "Adapter" on a school holiday first materialises it as a period entry (holiday), then period mode adapts it. */
export function useCreateHolidayPeriod() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (holiday: { schoolHolidayId: string; label: string; startDate: string; endDate: string }) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "holiday",
        title: holiday.label,
        startDate: holiday.startDate,
        endDate: holiday.endDate,
        schoolHolidayId: holiday.schoolHolidayId,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

export function useDeleteEntry() {
  const queryClient = useQueryClient();
  return useMutation({
    // The backend cascades the entry's dated constraints on delete.
    mutationFn: (id: string) => cockpitApi.deleteCalendarEntry(id),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

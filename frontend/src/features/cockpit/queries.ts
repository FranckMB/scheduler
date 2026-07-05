import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import * as cockpitApi from "./api";
import type { CreateClosurePayload, CreateEventPayload } from "./api";

export function useCalendarEntries(from: string, to: string) {
  return useQuery({
    queryKey: ["calendar-entries", from, to],
    queryFn: () => cockpitApi.getCalendarEntries(from, to),
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
 * the closed venue. Two calls: if the constraint fails, roll back the entry so we
 * never leave a period with no closed-venue constraint.
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
        await cockpitApi.createConstraint({
          name: payload.title,
          scope: "FACILITY",
          scopeTargetId: payload.venueId,
          family: "FACILITY",
          ruleType: "HARD",
          config: { type: "venue_closed", startDate: payload.startDate, endDate: payload.endDate },
          calendarEntryId: entry.id,
        });
      } catch (error) {
        await cockpitApi.deleteCalendarEntry(entry.id).catch(() => undefined);
        throw error;
      }
      return entry;
    },
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

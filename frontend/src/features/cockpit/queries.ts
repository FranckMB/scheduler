import { useMutation, useQueries, useQuery, useQueryClient } from "@tanstack/react-query";

import { createConstraint } from "@/features/wizard/api";
import { errorMessage } from "@/shared/lib/errorMessage";
import { toast } from "@/shared/stores/toastStore";

import * as cockpitApi from "./api";
import type { CreateClosurePayload, CreateCutoffPayload, CreateEventPayload } from "./api";

export function useCalendarEntries(from: string, to: string) {
  return useQuery({
    queryKey: ["calendar-entries", from, to],
    queryFn: () => cockpitApi.getCalendarEntries(from, to),
    staleTime: 30_000,
  });
}

export function useCalendarEntry(id: string | null) {
  return useQuery({
    // Under the "calendar-entries" prefix so the shared invalidation (creation,
    // overlay generation, reopen) also refreshes the detail — a singular key
    // kept a stale overlayScheduleId for 30s after generating an overlay.
    queryKey: ["calendar-entries", "detail", id],
    queryFn: () => cockpitApi.getCalendarEntry(id as string),
    enabled: null !== id,
    staleTime: 30_000,
    // A 404 means the entry was deleted — the wizard exits period mode cleanly
    // (WizardPage effect) instead of the global net toasting a raw error.
    meta: { silent404: true },
  });
}

/**
 * Le plan d'une période (ADR-0002 lot C). Il naît avec le geste « ajuster cette
 * période », donc il est là dès que l'entrée existe — inutile d'attendre une
 * génération. Porte les réglages de la période (inv. 5), dont le garde de seed
 * `teamSelectionInitialized`.
 *
 * Le flag `teamSelectionInitialized` bascule côté SERVEUR au 1er override, sans
 * mutation directe sur le plan : aucune invalidation ne le rafraîchit (les mutations
 * d'override n'invalident que ["wizard", "team_period_overrides", …]). Ce qui protège
 * le seed d'un double déclenchement, c'est le garde `periodSeedWasClaimed` — pas cette
 * clé. Ne pas retirer ce garde en croyant qu'un refetch prend le relais.
 */
export function useSchedulePlanForEntry(calendarEntryId: string | null) {
  return useQuery({
    queryKey: ["calendar-entries", "plan", calendarEntryId],
    queryFn: () => cockpitApi.getSchedulePlanForEntry(calendarEntryId as string),
    enabled: null !== calendarEntryId,
    staleTime: 30_000,
  });
}

/**
 * School holidays. Without a window → the season default (radar, season-wide).
 * With a [from, to] → that window (the calendar's visible month, so summer and
 * any month outside the season are shown when browsed).
 */
export function useSchoolHolidays(from?: string, to?: string) {
  return useQuery({
    queryKey: ["school-holidays", from ?? null, to ?? null],
    queryFn: () => cockpitApi.getSchoolHolidays(from, to),
    staleTime: 3_600_000,
  });
}

export function usePublicHolidays(from: string, to: string) {
  return useQuery({
    queryKey: ["public-holidays", from, to],
    queryFn: () => cockpitApi.getPublicHolidays(from, to),
    staleTime: 3_600_000,
  });
}

/**
 * Les conflits de PLUSIEURS périodes d'un coup. Le radar est une liste « à traiter » :
 * il ne peut décider de masquer une fermeture sans impact qu'en connaissant l'impact,
 * or seul le serveur le sait. Même queryKey que useEntryConflicts → le cache dédoublonne,
 * la carte enfant ne refait aucune requête.
 */
export function useEntryConflictsList(entryIds: string[]) {
  return useQueries({
    queries: entryIds.map((entryId) => ({
      queryKey: ["entry-conflicts", entryId],
      queryFn: () => cockpitApi.getEntryConflicts(entryId),
      staleTime: 30_000,
    })),
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
    // Hook-level (unmount-safe): surfaces the tailored rollback message even if
    // the DayDialog was closed while the two-call sequence was in flight.
    onError: (error) => {
      if (error instanceof Error && !("response" in error)) {
        toast.error(error.message);
        return;
      }
      void errorMessage(error).then((message) => toast.error(message));
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

/** A cutoff means "no training on the window" — a bare period entry, no dated constraint, never an overlay. */
export function useCreateCutoff() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: CreateCutoffPayload) =>
      cockpitApi.createCalendarEntry({
        kind: "period",
        periodType: "cutoff",
        title: payload.title,
        startDate: payload.startDate,
        endDate: payload.endDate,
      }),
    onSuccess: () => invalidateEntries(queryClient),
  });
}

export function useDeleteEntry() {
  const queryClient = useQueryClient();
  return useMutation({
    // The backend cascades the entry's dated constraints AND its overlay
    // schedule on delete → schedules and conflicts must refresh too, or the
    // baseline banner keeps counting a ghost overlay ("Voir le plan" → 404).
    mutationFn: (id: string) => cockpitApi.deleteCalendarEntry(id),
    onSuccess: () => {
      invalidateEntries(queryClient);
      void queryClient.invalidateQueries({ queryKey: ["schedules"] });
      void queryClient.invalidateQueries({ queryKey: ["entry-conflicts"] });
    },
  });
}

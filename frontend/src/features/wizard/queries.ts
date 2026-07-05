import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import type { CoachPayload, ConstraintPayload, SlotPayload, TeamCoachRole, TeamPayload, VenuePayload } from "./api";
import * as wizardApi from "./api";
import type { Reservation } from "./store";

export function useWizardTeams() {
  return useQuery({ queryKey: ["wizard", "teams"], queryFn: wizardApi.listTeams, staleTime: 30_000 });
}

export function useSportCategories() {
  return useQuery({ queryKey: ["sport_categories"], queryFn: wizardApi.listSportCategories, staleTime: 300_000 });
}

export function usePriorityTiers() {
  return useQuery({ queryKey: ["priority_tiers"], queryFn: wizardApi.listPriorityTiers, staleTime: 300_000 });
}

// Per-entity save: every create/update/delete persists immediately, then the list
// is invalidated. "Suivant" only validates + navigates — nothing to flush.
export function useCreateTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: TeamPayload) => wizardApi.createTeam(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] }),
  });
}

export function useUpdateTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: TeamPayload }) => wizardApi.updateTeam(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] }),
  });
}

export function useDeleteTeam() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteTeam(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] }),
  });
}

/** Atomic bulk reorder for the sort UI (one transaction, no per-team version races). */
export function useReorderTeams() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (items: { id: string; priorityTierId: number; tierOrder: number }[]) => wizardApi.reorderTeams(items),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "teams"] }),
  });
}

// --- Venues + slots (W2) ---

export function useWizardVenues() {
  return useQuery({ queryKey: ["wizard", "venues"], queryFn: wizardApi.listVenues, staleTime: 30_000 });
}

export function useVenueSlots() {
  return useQuery({ queryKey: ["wizard", "venue_slots"], queryFn: wizardApi.listVenueSlots, staleTime: 30_000 });
}

export function useCreateVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: VenuePayload) => wizardApi.createVenue(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venues"] }),
  });
}

export function useUpdateVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: VenuePayload }) => wizardApi.updateVenue(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venues"] }),
  });
}

export function useDeleteVenue() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteVenue(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["wizard", "venues"] });
      void queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] });
    },
  });
}

export function useCreateSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: SlotPayload) => wizardApi.createSlot(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

export function useUpdateSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: SlotPayload }) => wizardApi.updateSlot(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

export function useDeleteSlot() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteSlot(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "venue_slots"] }),
  });
}

// --- Coaches + links (W3) ---

export function useWizardCoaches() {
  return useQuery({ queryKey: ["wizard", "coaches"], queryFn: wizardApi.listCoaches, staleTime: 30_000 });
}

export function useWizardTeamCoaches() {
  return useQuery({ queryKey: ["wizard", "team_coaches"], queryFn: wizardApi.listTeamCoaches, staleTime: 30_000 });
}

export function useWizardCoachPlayers() {
  return useQuery({ queryKey: ["wizard", "coach_players"], queryFn: wizardApi.listCoachPlayers, staleTime: 30_000 });
}

export function useCreateCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: CoachPayload) => wizardApi.createCoach(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coaches"] }),
  });
}

export function useUpdateCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: string; body: CoachPayload }) => wizardApi.updateCoach(id, body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coaches"] }),
  });
}

export function useDeleteCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteCoach(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["wizard", "coaches"] });
      void queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] });
      void queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] });
    },
  });
}

export function useCreateTeamCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: { teamId: string; coachId: string; role: TeamCoachRole }) => wizardApi.createTeamCoach(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] }),
  });
}

export function useDeleteTeamCoach() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteTeamCoach(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "team_coaches"] }),
  });
}

export function useCreateCoachPlayer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: { teamId: string; coachId: string; isActive: boolean }) => wizardApi.createCoachPlayer(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] }),
  });
}

export function useDeleteCoachPlayer() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteCoachPlayer(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "coach_players"] }),
  });
}

// --- Constraints (W4) ---

/** In period mode, list the period's dated constraints; else base-plan (permanent) constraints. */
export function useWizardConstraints(calendarEntryId?: string | null) {
  return useQuery({
    queryKey: ["wizard", "constraints", calendarEntryId ?? "base"],
    queryFn: () => wizardApi.listConstraints(calendarEntryId ? { calendarEntryId } : { permanent: "1" }),
    staleTime: 30_000,
  });
}

export function useWizardTeamTags() {
  return useQuery({ queryKey: ["wizard", "team_tags"], queryFn: wizardApi.listTeamTags, staleTime: 30_000 });
}

export function useCreateConstraint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: ConstraintPayload) => wizardApi.createConstraint(body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "constraints"] }),
  });
}

export function useDeleteConstraint() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => wizardApi.deleteConstraint(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["wizard", "constraints"] }),
  });
}

// --- Recap + generate (W5) ---

export function useConstraintValidation(enabled: boolean, calendarEntryId?: string | null) {
  return useQuery({
    queryKey: ["wizard", "constraint_validation", calendarEntryId ?? "base"],
    queryFn: () => wizardApi.validateConstraints(calendarEntryId ?? undefined),
    enabled,
    staleTime: 0,
  });
}

/** Poll a schedule's status while it is queued/generating; stops once terminal. */
export function useScheduleStatus(id: string | null) {
  return useQuery({
    queryKey: ["wizard", "schedule_status", id],
    queryFn: () => wizardApi.getSchedule(id ?? ""),
    enabled: null !== id,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return "PENDING" === status || "GENERATING" === status ? 2500 : false;
    },
  });
}

/**
 * Create a fresh schedule, pin the wizard's reserved slots as HARD locks, then
 * queue its generation; resolves to the schedule id.
 */
export function useLaunchGeneration() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      name,
      reservations,
      calendarEntryId,
      existingScheduleId,
    }: {
      name: string;
      reservations: Reservation[];
      calendarEntryId?: string;
      existingScheduleId?: string;
    }) => {
      // Period mode reuses the entry's existing overlay schedule (regenerate);
      // otherwise create a fresh schedule (base plan, or first overlay).
      const scheduleId = existingScheduleId ?? (await wizardApi.createSchedule(name, calendarEntryId)).id;
      for (const r of reservations) {
        await wizardApi.createSlotTemplate({
          scheduleId,
          teamId: r.teamId,
          venueId: r.venueId,
          dayOfWeek: r.dayOfWeek,
          startTime: r.startTime,
          durationMinutes: r.durationMinutes,
          lockLevel: "HARD",
        });
      }
      await wizardApi.generateSchedule(scheduleId);
      return scheduleId;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

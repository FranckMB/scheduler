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

export function useWizardConstraints() {
  return useQuery({ queryKey: ["wizard", "constraints"], queryFn: wizardApi.listConstraints, staleTime: 30_000 });
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

export function useConstraintValidation(enabled: boolean) {
  return useQuery({ queryKey: ["wizard", "constraint_validation"], queryFn: wizardApi.validateConstraints, enabled, staleTime: 0 });
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
    mutationFn: async ({ name, reservations }: { name: string; reservations: Reservation[] }) => {
      const schedule = await wizardApi.createSchedule(name);
      for (const r of reservations) {
        await wizardApi.createSlotTemplate({
          scheduleId: schedule.id,
          teamId: r.teamId,
          venueId: r.venueId,
          dayOfWeek: r.dayOfWeek,
          startTime: r.startTime,
          durationMinutes: r.durationMinutes,
          lockLevel: "HARD",
        });
      }
      await wizardApi.generateSchedule(schedule.id);
      return schedule.id;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["schedules"] }),
  });
}

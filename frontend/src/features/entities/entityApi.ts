import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'

interface HydraCollection<T> {
  'hydra:member': T[]
}

export interface BackendEntity {
  id: string
  name?: string
  firstName?: string
  lastName?: string
  priorityTierId?: number
  [key: string]: unknown
}

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface Venue {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  name: string
  isExternal: boolean
  color: string | null
  latitude: string | null
  longitude: string | null
  source: string
  externalRef: string | null
  isActive: boolean
  parentVenueId: string | null
}

export interface Coach {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  firstName: string
  lastName: string
  email: string | null
  phone: string | null
  maxDaysOverride: number | null
  maxDaysOverrideConfirmed: boolean
  acceptableLateMinutes: number | null
  isActive: boolean
  parentCoachId: string | null
}

export interface TeamConstraint {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  teamId: string
  type: string
  dayOfWeek: number | null
  startTime: string | null
  endTime: string | null
  venueId: string | null
  reason: string | null
  createdBy: string | null
  sourceOccurrenceId: string | null
  severity: string | null
}

export interface VenueConstraint {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  venueId: string
  constraintType: string
  constraintValue: string
}

export interface CoachUnavailability {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  coachId: string
  dayOfWeek: number
  startTime: string | null
  endTime: string | null
}

export interface Team {
  id: string
  name: string
  priorityTierId: number
  sessionsPerWeek: number
  minSessionsOverride: number | null
  isActive: boolean
  sportCategoryId: string
  gender: string | null
  matchDay: number | null
  version: number
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

async function fetchCollection<T>(resource: string): Promise<T[]> {
  const json = await apiClient.get(resource).json<T[] | HydraCollection<T>>()
  return Array.isArray(json) ? json : json['hydra:member'] ?? []
}

function useCollection<T>(queryKey: string, resource: string) {
  return useQuery({
    queryKey: [queryKey],
    queryFn: async () => fetchCollection<T>(resource),
  })
}

/* ------------------------------------------------------------------ */
/*  Read                                                               */
/* ------------------------------------------------------------------ */

export function useTeams() {
  return useCollection<Team>('teams', 'teams?isActive=true')
}

export function useVenues() {
  return useCollection<Venue>('venues', 'venues')
}

export function useCoaches() {
  return useCollection<Coach>('coaches', 'coaches')
}

export function useTeamConstraints() {
  return useCollection<TeamConstraint>('team-constraints', 'team_constraints')
}

export function useVenueConstraints() {
  return useCollection<VenueConstraint>('venue-constraints', 'venue_constraints')
}

export function useCoachUnavailabilities() {
  return useCollection<CoachUnavailability>('coach-unavailabilities', 'coach_unavailabilities')
}

/* ------------------------------------------------------------------ */
/*  Create                                                             */
/* ------------------------------------------------------------------ */

export function useCreateVenue() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<Venue, 'id' | 'version' | 'createdAt' | 'updatedAt'>) => {
      return apiClient.post('venues', { json: data }).json<Venue>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venues'] })
    },
  })
}

export function useCreateCoach() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<Coach, 'id' | 'version' | 'createdAt' | 'updatedAt'>) => {
      return apiClient.post('coaches', { json: data }).json<Coach>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coaches'] })
    },
  })
}

export function useCreateTeamConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<TeamConstraint, 'id' | 'version' | 'createdAt' | 'updatedAt'>) => {
      return apiClient.post('team_constraints', { json: data }).json<TeamConstraint>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team-constraints'] })
    },
  })
}

export function useCreateVenueConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<VenueConstraint, 'id' | 'version' | 'createdAt' | 'updatedAt'>) => {
      return apiClient.post('venue_constraints', { json: data }).json<VenueConstraint>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venue-constraints'] })
    },
  })
}

export function useCreateCoachUnavailability() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<CoachUnavailability, 'id' | 'version' | 'createdAt' | 'updatedAt'>) => {
      return apiClient.post('coach_unavailabilities', { json: data }).json<CoachUnavailability>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coach-unavailabilities'] })
    },
  })
}

export function useCreateTeam() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: Omit<Team, 'id' | 'version'>) => {
      return apiClient.post('teams', { json: data }).json<Team>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })
}

/* ------------------------------------------------------------------ */
/*  Update                                                             */
/* ------------------------------------------------------------------ */

export function useUpdateVenue() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<Venue> }) => {
      return apiClient.put(`venues/${id}`, { json: data }).json<Venue>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venues'] })
    },
  })
}

export function useUpdateCoach() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<Coach> }) => {
      return apiClient.put(`coaches/${id}`, { json: data }).json<Coach>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coaches'] })
    },
  })
}

export function useUpdateTeamConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<TeamConstraint> }) => {
      return apiClient.put(`team_constraints/${id}`, { json: data }).json<TeamConstraint>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team-constraints'] })
    },
  })
}

export function useUpdateVenueConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<VenueConstraint> }) => {
      return apiClient.put(`venue_constraints/${id}`, { json: data }).json<VenueConstraint>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venue-constraints'] })
    },
  })
}

export function useUpdateCoachUnavailability() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<CoachUnavailability> }) => {
      return apiClient.put(`coach_unavailabilities/${id}`, { json: data }).json<CoachUnavailability>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coach-unavailabilities'] })
    },
  })
}

export function useUpdateTeam() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: Partial<Team> }) => {
      return apiClient.put(`teams/${id}`, { json: data }).json<Team>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })
}

/* ------------------------------------------------------------------ */
/*  Delete                                                             */
/* ------------------------------------------------------------------ */

export function useDeleteVenue() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`venues/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venues'] })
    },
  })
}

export function useDeleteCoach() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`coaches/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coaches'] })
    },
  })
}

export function useDeleteTeamConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`team_constraints/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team-constraints'] })
    },
  })
}

export function useDeleteVenueConstraint() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`venue_constraints/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['venue-constraints'] })
    },
  })
}

export function useDeleteCoachUnavailability() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`coach_unavailabilities/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coach-unavailabilities'] })
    },
  })
}

export function useDeleteTeam() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`teams/${id}`)
      return id
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })
}

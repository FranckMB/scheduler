import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import type { Team, PriorityTier, UpdateTeamPayload } from './types'

interface ApiPlatformCollection<T> {
  'hydra:member': T[]
  'hydra:totalItems': number
}

export function useTeams() {
  return useQuery({
    queryKey: ['teams'],
    queryFn: async () => {
      const json = await apiClient
        .get('teams?isActive=true')
        .json<ApiPlatformCollection<Team>>()
      return json['hydra:member']
    },
  })
}

export function usePriorityTiers() {
  return useQuery({
    queryKey: ['priority-tiers'],
    queryFn: async () => {
      const json = await apiClient
        .get('priority_tiers')
        .json<ApiPlatformCollection<PriorityTier>>()
      return json['hydra:member']
    },
  })
}

export function useUpdateTeamTier() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, data }: { id: string; data: UpdateTeamPayload }) => {
      return apiClient.put(`teams/${id}`, { json: data }).json<Team>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })
}

export function useUpdateTeamMinSessions() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ id, minSessionsOverride }: { id: string; minSessionsOverride: number | null }) => {
      return apiClient
        .put(`teams/${id}`, { json: { minSessionsOverride } })
        .json<Team>()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['teams'] })
    },
  })
}

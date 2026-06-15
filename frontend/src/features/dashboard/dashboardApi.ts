import { useQuery } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'

interface HydraCollection<T> {
  'hydra:member': T[]
}

export interface ScheduleSummary {
  id: string
  name?: string
  status?: string
  score?: number | null
}

export function useLatestSchedule() {
  return useQuery({
    queryKey: ['latest-schedule'],
    queryFn: async () => {
      const json = await apiClient
        .get('schedules?order[updatedAt]=desc&itemsPerPage=1')
        .json<ScheduleSummary[] | HydraCollection<ScheduleSummary>>()

      const items = Array.isArray(json) ? json : json['hydra:member'] ?? []
      return items[0] ?? null
    },
  })
}

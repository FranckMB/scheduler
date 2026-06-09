import { useQuery, useMutation } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import { queryClient } from '@/shared/lib/queryClient'
import type { Schedule, ScheduleSlot, ApiCollection } from '@/features/schedule/types'

const SCHEDULE_QUERY_KEY = (id: string) => ['schedule', id] as const
const SLOTS_QUERY_KEY = (scheduleId: string) => ['schedule-slots', scheduleId] as const

export function useSchedule(id: string) {
  return useQuery({
    queryKey: SCHEDULE_QUERY_KEY(id),
    queryFn: async () => {
      const json = await apiClient.get(`schedules/${id}`).json<Schedule>()
      return json
    },
    enabled: !!id,
  })
}

export function useScheduleSlots(scheduleId: string) {
  return useQuery({
    queryKey: SLOTS_QUERY_KEY(scheduleId),
    queryFn: async () => {
      const json = await apiClient
        .get(`schedule_slot_templates`, {
          searchParams: { schedule: scheduleId },
        })
        .json<ApiCollection<ScheduleSlot>>()
      return json['hydra:member']
    },
    enabled: !!scheduleId,
  })
}

export function useExportPdf() {
  return useMutation({
    mutationFn: async (scheduleId: string) => {
      await apiClient.post(`schedules/${scheduleId}/export-pdf`).json()
    },
    onSuccess: (_, scheduleId) => {
      queryClient.invalidateQueries({ queryKey: SCHEDULE_QUERY_KEY(scheduleId) })
    },
  })
}

export function invalidateScheduleQueries(scheduleId: string) {
  queryClient.invalidateQueries({ queryKey: SCHEDULE_QUERY_KEY(scheduleId) })
  queryClient.invalidateQueries({ queryKey: SLOTS_QUERY_KEY(scheduleId) })
}

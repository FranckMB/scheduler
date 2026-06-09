import { useQuery } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import type { ScheduleDiagnostic } from '@/features/schedule/types/diagnostic'

const DIAGNOSTICS_QUERY_KEY = 'schedule-diagnostics'

export function useScheduleDiagnostics(scheduleId: string) {
  return useQuery({
    queryKey: [DIAGNOSTICS_QUERY_KEY, scheduleId],
    queryFn: async () => {
      const data = await apiClient
        .get('schedule_diagnostics', { searchParams: { schedule: scheduleId } })
        .json<ScheduleDiagnostic[]>()
      return data
    },
    enabled: !!scheduleId,
  })
}

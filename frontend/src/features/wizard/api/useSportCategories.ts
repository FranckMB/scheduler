import { useQuery } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'

export interface SportCategory {
  id: string
  name: string
}

interface ApiPlatformCollection<T> {
  'hydra:member': T[]
  'hydra:totalItems': number
}

const SPORT_CATEGORIES_QUERY_KEY = ['sport-categories'] as const

export function useSportCategories() {
  return useQuery({
    queryKey: SPORT_CATEGORIES_QUERY_KEY,
    queryFn: async () => {
      const json = await apiClient
        .get('sport_categories')
        .json<ApiPlatformCollection<SportCategory>>()

      return json['hydra:member']
    },
  })
}

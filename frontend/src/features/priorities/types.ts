export interface PriorityTier {
  id: number
  label: string
  name: string
  color: string
  orToolsWeight: number
  defaultMinSessions: number
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

export interface UpdateTeamPayload {
  priorityTierId?: number
  minSessionsOverride?: number | null
}

export const TIER_COLORS: Record<string, string> = {
  S: 'bg-rose-900/40 text-rose-300 border-rose-700',
  A: 'bg-orange-900/40 text-orange-300 border-orange-700',
  B: 'bg-yellow-900/40 text-yellow-300 border-yellow-700',
  C: 'bg-green-900/40 text-green-300 border-green-700',
  D: 'bg-blue-900/40 text-blue-300 border-blue-700',
}

export const TIER_BADGE_COLORS: Record<string, string> = {
  S: 'bg-rose-500',
  A: 'bg-orange-500',
  B: 'bg-yellow-500',
  C: 'bg-green-500',
  D: 'bg-blue-500',
}

export const TIER_ORDER = ['S', 'A', 'B', 'C', 'D'] as const

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
  S: 'bg-rose-100 text-rose-800 border-rose-300',
  A: 'bg-orange-100 text-orange-800 border-orange-300',
  B: 'bg-yellow-100 text-yellow-800 border-yellow-300',
  C: 'bg-green-100 text-green-800 border-green-300',
  D: 'bg-blue-100 text-blue-800 border-blue-300',
}

export const TIER_BADGE_COLORS: Record<string, string> = {
  S: 'bg-rose-500',
  A: 'bg-orange-500',
  B: 'bg-yellow-500',
  C: 'bg-green-500',
  D: 'bg-blue-500',
}

export const TIER_ORDER = ['S', 'A', 'B', 'C', 'D'] as const

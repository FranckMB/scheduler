export interface Schedule {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  name: string
  status: string
  score: number | null
  solverSeed: number
  snapshotHash: string | null
  snapshotData: Record<string, unknown>
  solverVersion: string | null
  constraintVersion: string | null
  scoreFormulaVersion: string | null
  solverTimeoutSeconds: number | null
  solverNbVariables: number | null
  solverNbConstraints: number | null
  solverNbConflicts: number | null
  solverWallTimeMs: number | null
  pdfExportStatus: string | null
  pdfExportUrl: string | null
}

export interface ScheduleSlot {
  id: string
  version: number
  createdAt: string
  updatedAt: string
  scheduleId: string
  teamId: string
  venueId: string
  coachId: string | null
  dayOfWeek: number
  startTime: string
  durationMinutes: number
  lockLevel: 'NONE' | 'SOFT' | 'HARD'
  temporaryLock: boolean
  temporaryLockFor: string | null
  temporaryMinSessionsOverride: number | null
  pendingConstraintSuggestion: Record<string, unknown> | null
}

export interface ApiCollection<T> {
  'hydra:member': T[]
  'hydra:totalItems': number
  'hydra:view'?: {
    '@id'?: string
    '@type'?: string
    'hydra:first'?: string
    'hydra:last'?: string
    'hydra:next'?: string
    'hydra:previous'?: string
  }
}

export type LockLevel = ScheduleSlot['lockLevel']

export const LOCK_LEVEL_CONFIG: Record<LockLevel, { label: string; color: string; bgColor: string }> = {
  NONE: { label: 'NONE', color: 'text-neutral-500', bgColor: 'bg-neutral-400' },
  SOFT: { label: 'SOFT', color: 'text-warning-600', bgColor: 'bg-warning-500' },
  HARD: { label: 'HARD', color: 'text-error-600', bgColor: 'bg-error-500' },
}

export const DAY_NAMES = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']

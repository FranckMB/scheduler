export type DiagnosticSeverity = 'error' | 'warning' | 'info'

export type DiagnosticType =
  | 'unplaced'
  | 'soft_lock_moved'
  | 'coach_overload'
  | 'conflict'

export interface ScheduleDiagnostic {
  id: string
  type: DiagnosticType
  severity: DiagnosticSeverity
  message: string
  teamId?: string
  coachId?: string
  venueId?: string
  suggestions: string[]
}

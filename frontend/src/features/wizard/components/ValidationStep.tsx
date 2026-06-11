import { useMemo } from 'react'
import { useWizardStore, DAY_LABELS, generateSlotsFromRanges, type DayKey } from '@/features/wizard/wizardStore'

interface ValidationIssue {
  severity: 'error' | 'warning' | 'info'
  message: string
  detail?: string
}

export default function ValidationStep() {
  const { data } = useWizardStore()

  const issues = useMemo<ValidationIssue[]>(() => {
    const result: ValidationIssue[] = []

    // Check venues
    for (const venue of data.venues) {
      if (!venue.name.trim()) {
        result.push({
          severity: 'error',
          message: `Salle sans nom`,
          detail: 'Chaque salle doit avoir un nom pour la generation.',
        })
      }
      const availableSlots = generateSlotsFromRanges(venue.availabilityRanges).length
      if (availableSlots === 0) {
        result.push({
          severity: 'error',
          message: `Aucune plage disponible pour "${venue.name || 'Salle sans nom'}"`,
          detail: 'Le moteur a besoin d\'au moins une plage horaire disponible par salle.',
        })
      }
      if (availableSlots < 10) {
        result.push({
          severity: 'warning',
          message: `Peu de creneaux pour "${venue.name || 'Salle'}" (${availableSlots})`,
          detail: 'Un nombre limite de creneaux peut reduire les options de planification.',
        })
      }
    }

    // Check teams
    if (data.teams.length === 0) {
      result.push({
        severity: 'error',
        message: 'Aucune equipe configuree',
        detail: 'Ajoutez au moins une equipe avant de generer le planning.',
      })
    }

    for (const team of data.teams) {
      if (!team.name.trim()) {
        result.push({
          severity: 'error',
          message: 'Equipe sans nom',
          detail: 'Chaque equipe doit avoir un nom.',
        })
      }
      if (team.sessions_count < 1) {
        result.push({
          severity: 'error',
          message: `"${team.name || 'Equipe'}" a 0 sessions`,
          detail: 'Chaque equipe doit avoir au moins 1 session par semaine.',
        })
      }
    }

    const teamsWithoutCoach = data.teams.filter(
      (team) => !data.coaches.some((coach) => coach.teamIds.includes(team.id))
    )
    if (teamsWithoutCoach.length > 0) {
      result.push({
        severity: 'warning',
        message: 'Equipes sans coach assigne',
        detail: teamsWithoutCoach.map((team) => team.name || 'Equipe sans nom').join(', '),
      })
    }

    // Check constraint coherence
    for (const constraint of data.constraints) {
      if (!constraint.teamId) {
        result.push({
          severity: 'error',
          message: 'Contrainte equipe sans cible',
          detail: 'Chaque contrainte equipe doit cibler une equipe.',
        })
      }

      // Check for conflicting constraints on same team
      if (constraint.teamId) {
        const teamConstraints = data.constraints.filter(
          (c) => c.teamId === constraint.teamId && c.id !== constraint.id
        )
        for (const other of teamConstraints) {
          if (
            constraint.day === other.day &&
            constraint.startHour != null &&
            constraint.endHour != null &&
            other.startHour != null &&
            other.endHour != null
          ) {
            // Check time overlap
            const overlaps =
              constraint.startHour < other.endHour && other.startHour < constraint.endHour
            if (overlaps) {
              const team = data.teams.find((t) => t.id === constraint.teamId)
              if (
                constraint.type === 'fixed' &&
                other.type === 'fixed'
              ) {
                result.push({
                  severity: 'error',
                  message: `Conflit de contraintes fixes pour "${team?.name || 'Equipe'}"`,
                  detail: `Deux contraintes fixes se chevauchent le ${DAY_LABELS[constraint.day as DayKey]}.`,
                })
              } else if (
                constraint.type === 'fixed' &&
                other.type === 'forbidden'
              ) {
                result.push({
                  severity: 'error',
                  message: `Contrainte contradictoire pour "${team?.name || 'Equipe'}"`,
                  detail: `Un creneau fixe et exclu se chevauchent le ${DAY_LABELS[constraint.day as DayKey]}.`,
                })
              }
            }
          }
        }
      }
    }

    for (const constraint of data.coachConstraints) {
      if (!constraint.coachId) {
        result.push({
          severity: 'error',
          message: 'Contrainte coach sans cible',
          detail: 'Chaque contrainte coach doit cibler un coach.',
        })
      }
    }

    // Check coach-team assignments
    for (const coach of data.coaches) {
      if (coach.teamIds.length === 0 && coach.name.trim()) {
        result.push({
          severity: 'info',
          message: `"${coach.name}" n'est assigne a aucune equipe`,
          detail: 'Ce coach ne sera pas pris en compte dans la generation.',
        })
      }
    }

    // Check preferred slots reference valid venues
    for (const ps of data.preferredSlots) {
      if (ps.venueId && !data.venues.find((v) => v.id === ps.venueId)) {
        result.push({
          severity: 'warning',
          message: 'Creneau prefere avec salle invalide',
          detail: 'La salle referencee n\'existe plus.',
        })
      }
    }

    // Summary stats
    if (result.length === 0) {
      result.push({
        severity: 'info',
        message: 'Aucun probleme detecte',
        detail: 'La configuration semble coherente. Vous pouvez proceder a la generation.',
      })
    }

    return result
  }, [data])

  const errorCount = issues.filter((i) => i.severity === 'error').length
  const warningCount = issues.filter((i) => i.severity === 'warning').length
  const infoCount = issues.filter((i) => i.severity === 'info').length

  const SEVERITY_STYLES = {
    error: 'bg-error-50 border-error-200 text-error-700',
    warning: 'bg-warning-50 border-warning-200 text-warning-700',
    info: 'bg-info-50 border-info-200 text-info-700',
  }

  const SEVERITY_ICONS = {
    error: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    ),
    warning: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
    ),
    info: (
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    ),
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-neutral-900">Validation</h2>
        <p className="text-sm text-neutral-500">
          Verification de la coherence des contraintes et des donnees
        </p>
      </div>

      {/* Summary badges */}
      <div className="flex gap-4">
        {errorCount > 0 && (
          <span className="rounded-full bg-error-100 px-3 py-1 text-sm font-medium text-error-700">
            {errorCount} erreur{errorCount > 1 ? 's' : ''}
          </span>
        )}
        {warningCount > 0 && (
          <span className="rounded-full bg-warning-100 px-3 py-1 text-sm font-medium text-warning-700">
            {warningCount} avertissement{warningCount > 1 ? 's' : ''}
          </span>
        )}
        {infoCount > 0 && (
          <span className="rounded-full bg-info-100 px-3 py-1 text-sm font-medium text-info-700">
            {infoCount} info{infoCount > 1 ? 's' : ''}
          </span>
        )}
      </div>

      {/* Issues list */}
      <div className="space-y-2">
        {issues.map((issue, index) => (
          <div
            key={index}
            className={`flex items-start gap-3 rounded-lg border p-3 ${SEVERITY_STYLES[issue.severity]}`}
          >
            <span className="shrink-0 mt-0.5">{SEVERITY_ICONS[issue.severity]}</span>
            <div>
              <p className="text-sm font-medium">{issue.message}</p>
              {issue.detail && (
                <p className="mt-1 text-xs opacity-80">{issue.detail}</p>
              )}
            </div>
          </div>
        ))}
      </div>

      {/* Stats summary */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">Resume des donnees</h3>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="rounded-md bg-neutral-50 p-3 text-center">
            <p className="text-2xl font-bold text-primary-600">{data.venues.length}</p>
            <p className="text-xs text-neutral-500">Salle{data.venues.length > 1 ? 's' : ''}</p>
          </div>
          <div className="rounded-md bg-neutral-50 p-3 text-center">
            <p className="text-2xl font-bold text-primary-600">{data.teams.length}</p>
            <p className="text-xs text-neutral-500">Equipe{data.teams.length > 1 ? 's' : ''}</p>
          </div>
          <div className="rounded-md bg-neutral-50 p-3 text-center">
            <p className="text-2xl font-bold text-primary-600">{data.coaches.length}</p>
            <p className="text-xs text-neutral-500">Coach{data.coaches.length > 1 ? 's' : ''}</p>
          </div>
          <div className="rounded-md bg-neutral-50 p-3 text-center">
            <p className="text-2xl font-bold text-primary-600">{data.constraints.length + data.coachConstraints.length}</p>
            <p className="text-xs text-neutral-500">Contrainte{data.constraints.length + data.coachConstraints.length > 1 ? 's' : ''}</p>
          </div>
        </div>
      </div>
    </div>
  )
}

import { Link } from 'react-router-dom'
import type { ScheduleDiagnostic, DiagnosticSeverity } from '@/features/schedule/types/diagnostic'

interface DiagnosticsPanelProps {
  diagnostics: ScheduleDiagnostic[]
}

const SEVERITY_CONFIG: Record<
  DiagnosticSeverity,
  { bg: string; border: string; badge: string; icon: React.ReactNode; label: string }
> = {
  error: {
    bg: 'bg-error-50',
    border: 'border-error-500',
    badge: 'bg-error-500 text-white',
    label: 'Erreur',
    icon: (
      <svg className="h-5 w-5 text-error-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 9v2m0 4h.01M12 3l9.5 16.5H2.5L12 3z"
        />
      </svg>
    ),
  },
  warning: {
    bg: 'bg-warning-50',
    border: 'border-warning-500',
    badge: 'bg-warning-500 text-white',
    label: 'Attention',
    icon: (
      <svg className="h-5 w-5 text-warning-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M12 9v2m0 4h.01M12 3l9.5 16.5H2.5L12 3z"
        />
      </svg>
    ),
  },
  info: {
    bg: 'bg-info-50',
    border: 'border-info-500',
    badge: 'bg-info-500 text-white',
    label: 'Information',
    icon: (
      <svg className="h-5 w-5 text-info-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"
        />
      </svg>
    ),
  },
}

const TYPE_LABELS: Record<ScheduleDiagnostic['type'], string> = {
  unplaced: 'Équipe non placée',
  soft_lock_moved: 'Créneau préféré déplacé',
  coach_overload: 'Surcharge entraîneur',
  conflict: 'Conflit de contrainte',
}

function groupBySeverity(
  diagnostics: ScheduleDiagnostic[]
): Record<DiagnosticSeverity, ScheduleDiagnostic[]> {
  return diagnostics.reduce(
    (acc, diag) => {
      acc[diag.severity].push(diag)
      return acc
    },
    { error: [], warning: [], info: [] } as Record<DiagnosticSeverity, ScheduleDiagnostic[]>
  )
}

function EntityLink({
  type,
  id,
}: {
  type: 'team' | 'coach' | 'venue'
  id: string
}) {
  const labels: Record<string, string> = {
    team: 'Équipe',
    coach: 'Entraîneur',
    venue: 'Lieu',
  }
  const routes: Record<string, string> = {
    team: `/teams/${id}`,
    coach: `/coaches/${id}`,
    venue: `/venues/${id}`,
  }

  return (
    <Link
      to={routes[type]}
      className="inline-flex items-center gap-1 rounded-md bg-white px-2 py-1 text-xs font-medium text-primary-600 ring-1 ring-inset ring-primary-200 hover:bg-primary-50"
    >
      {labels[type]} #{id.slice(0, 8)}
      <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
      </svg>
    </Link>
  )
}

function DiagnosticCard({ diagnostic }: { diagnostic: ScheduleDiagnostic }) {
  const config = SEVERITY_CONFIG[diagnostic.severity]

  return (
    <div
      className={`rounded-lg border-l-4 ${config.border} ${config.bg} p-4 shadow-sm`}
      role="article"
      aria-label={`Diagnostic: ${TYPE_LABELS[diagnostic.type]}`}
    >
      <div className="flex items-start gap-3">
        <div className="flex-shrink-0">{config.icon}</div>
        <div className="flex-1 space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${config.badge}`}>
              {config.label}
            </span>
            <span className="text-sm font-medium text-neutral-700">
              {TYPE_LABELS[diagnostic.type]}
            </span>
          </div>

          <p className="text-sm text-neutral-800">{diagnostic.message}</p>

          {(diagnostic.teamId || diagnostic.coachId || diagnostic.venueId) && (
            <div className="flex flex-wrap gap-2">
              {diagnostic.teamId && <EntityLink type="team" id={diagnostic.teamId} />}
              {diagnostic.coachId && <EntityLink type="coach" id={diagnostic.coachId} />}
              {diagnostic.venueId && <EntityLink type="venue" id={diagnostic.venueId} />}
            </div>
          )}

          {diagnostic.suggestions.length > 0 && (
            <div className="mt-2">
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                Suggestions
              </p>
              <ul className="space-y-1">
                {diagnostic.suggestions.map((suggestion, index) => (
                  <li key={index} className="flex items-start gap-2 text-sm text-neutral-700">
                    <svg
                      className="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        strokeWidth={2}
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                      />
                    </svg>
                    {suggestion}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export function DiagnosticsPanel({ diagnostics }: DiagnosticsPanelProps) {
  if (diagnostics.length === 0) {
    return (
      <div className="rounded-lg bg-white p-8 text-center shadow-sm">
        <svg
          className="mx-auto h-12 w-12 text-success-500"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <h3 className="mt-3 text-lg font-medium text-neutral-900">Aucun diagnostic</h3>
        <p className="mt-1 text-sm text-neutral-500">
          L&apos;emploi du temps ne présente aucun problème détecté.
        </p>
      </div>
    )
  }

  const grouped = groupBySeverity(diagnostics)
  const severityOrder: DiagnosticSeverity[] = ['error', 'warning', 'info']

  return (
    <div className="space-y-6">
      {/* Summary bar */}
      <div className="flex flex-wrap gap-3">
        {severityOrder.map((severity) => {
          const count = grouped[severity].length
          if (count === 0) return null
          const config = SEVERITY_CONFIG[severity]
          return (
            <div
              key={severity}
              className={`flex items-center gap-2 rounded-lg ${config.bg} px-4 py-2`}
            >
              {config.icon}
              <span className="text-sm font-medium text-neutral-700">
                {count} {config.label.toLowerCase()}
                {count > 1 ? 's' : ''}
              </span>
            </div>
          )
        })}
      </div>

      {/* Grouped diagnostics */}
      {severityOrder.map((severity) => {
        const items = grouped[severity]
        if (items.length === 0) return null
        const config = SEVERITY_CONFIG[severity]

        return (
          <section key={severity} aria-label={config.label}>
            <h3 className="mb-3 flex items-center gap-2 text-lg font-semibold text-neutral-900">
              {config.icon}
              {config.label}s ({items.length})
            </h3>
            <div className="space-y-3">
              {items.map((diagnostic) => (
                <DiagnosticCard key={diagnostic.id} diagnostic={diagnostic} />
              ))}
            </div>
          </section>
        )
      })}
    </div>
  )
}

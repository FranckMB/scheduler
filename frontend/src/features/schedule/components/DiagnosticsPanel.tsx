import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { ScheduleDiagnostic, DiagnosticSeverity } from '@/features/schedule/types/diagnostic'

interface DiagnosticsPanelProps {
  diagnostics: ScheduleDiagnostic[]
}

const SEVERITY_CONFIG: Record<
  DiagnosticSeverity,
  {
    bg: string
    border: string
    badge: string
    icon: ReactNode
    label: string
    pluralLabel: string
  }
> = {
  error: {
    bg: 'bg-error-900/40',
    border: 'border-error-500',
    badge: 'bg-error-500 text-white',
    label: 'Erreur',
    pluralLabel: 'Erreurs',
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
    bg: 'bg-warning-900/40',
    border: 'border-warning-500',
    badge: 'bg-warning-500 text-white',
    label: 'Avertissement',
    pluralLabel: 'Avertissements',
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
    bg: 'bg-info-900/40',
    border: 'border-info-500',
    badge: 'bg-info-500 text-white',
    label: 'Information',
    pluralLabel: 'Informations',
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

type SuggestionLink = {
  id: string
  type: 'team' | 'coach' | 'venue'
}

type SuggestionItem = {
  label: string
  links: SuggestionLink[]
}

const DIAGNOSTIC_COPY: Record<
  ScheduleDiagnostic['type'],
  {
    summary: string
    suggestions: (diagnostic: ScheduleDiagnostic) => SuggestionItem[]
  }
> = {
  unplaced: {
    summary: 'Aucun créneau compatible n’a été trouvé pour cette équipe.',
    suggestions: (diagnostic) => [
      {
        label: 'Ouvrir la fiche de l’équipe pour alléger certaines contraintes.',
        links: diagnostic.teamId ? ([{ type: 'team', id: diagnostic.teamId }] as SuggestionLink[]) : [],
      },
      {
        label: 'Vérifier les disponibilités des lieux concernés.',
        links: diagnostic.venueId ? ([{ type: 'venue', id: diagnostic.venueId }] as SuggestionLink[]) : [],
      },
    ],
  },
  soft_lock_moved: {
    summary: 'Le créneau préféré a été déplacé pour améliorer l’équilibre global du planning.',
    suggestions: (diagnostic) => [
      {
        label: 'Vérifier le nouveau créneau proposé.',
        links: [
          ...(diagnostic.teamId ? ([{ type: 'team', id: diagnostic.teamId }] as SuggestionLink[]) : []),
          ...(diagnostic.venueId ? ([{ type: 'venue', id: diagnostic.venueId }] as SuggestionLink[]) : []),
        ],
      },
    ],
  },
  coach_overload: {
    summary: 'L’entraîneur est trop sollicité sur la période analysée.',
    suggestions: (diagnostic) => [
      {
        label: 'Répartir une partie des séances sur une autre ressource.',
        links: diagnostic.coachId ? ([{ type: 'coach', id: diagnostic.coachId }] as SuggestionLink[]) : [],
      },
    ],
  },
  conflict: {
    summary: 'Deux contraintes entrent en conflit sur le même créneau.',
    suggestions: (diagnostic) => [
      {
        label: 'Déplacer l’une des séances concernées.',
        links: [
          ...(diagnostic.teamId ? ([{ type: 'team', id: diagnostic.teamId }] as SuggestionLink[]) : []),
          ...(diagnostic.coachId ? ([{ type: 'coach', id: diagnostic.coachId }] as SuggestionLink[]) : []),
          ...(diagnostic.venueId ? ([{ type: 'venue', id: diagnostic.venueId }] as SuggestionLink[]) : []),
        ],
      },
    ],
  },
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
      className="inline-flex items-center gap-1 rounded-md bg-neutral-700 px-2 py-1 text-xs font-medium text-primary-400 ring-1 ring-inset ring-primary-700 hover:bg-neutral-600"
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
  const copy = DIAGNOSTIC_COPY[diagnostic.type]
  const suggestions = copy.suggestions(diagnostic)

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
              <span className="text-sm font-medium text-neutral-200">
              {TYPE_LABELS[diagnostic.type]}
            </span>
          </div>

          <p className="text-sm text-neutral-200">{copy.summary}</p>

          <div className="mt-2 space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wide text-neutral-400">
              Actions recommandées
            </p>
            <ul className="space-y-2">
              {suggestions.map((suggestion, index) => (
                <li key={index} className="space-y-2 rounded-md bg-neutral-700/70 px-3 py-2">
                  <p className="flex items-start gap-2 text-sm text-neutral-200">
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
                    <span>{suggestion.label}</span>
                  </p>

                  {suggestion.links.length > 0 && (
                    <div className="flex flex-wrap gap-2 pl-6">
                      {suggestion.links.map((link) => (
                        <EntityLink key={`${link.type}-${link.id}`} type={link.type} id={link.id} />
                      ))}
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  )
}

export function DiagnosticsPanel({ diagnostics }: DiagnosticsPanelProps) {
  if (diagnostics.length === 0) {
    return (
      <div className="rounded-lg bg-neutral-800 p-8 text-center shadow-sm">
        <svg
          className="mx-auto h-12 w-12 text-success-400"
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
        <h3 className="mt-3 text-lg font-medium text-white">Aucun diagnostic</h3>
        <p className="mt-1 text-sm text-neutral-400">
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
            <span className="text-sm font-medium text-neutral-200">
                {count} {count > 1 ? config.pluralLabel.toLowerCase() : config.label.toLowerCase()}
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
            <h3 className="mb-3 flex items-center gap-2 text-lg font-semibold text-white">
              {config.icon}
              {config.pluralLabel} ({items.length})
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

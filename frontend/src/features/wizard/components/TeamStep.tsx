import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  type DayKey,
  type TeamData,
  type ConstraintType,
} from '@/features/wizard/wizardStore'

const CONSTRAINT_LABELS: Record<ConstraintType, string> = {
  fixed: 'Fixe (HARD)',
  forbidden: 'Exclu',
  preferred: 'Préféré (SOFT)',
}

const CONSTRAINT_COLORS: Record<ConstraintType, string> = {
  fixed: 'bg-error-50 text-error-700 border-error-200',
  forbidden: 'bg-warning-50 text-warning-700 border-warning-200',
  preferred: 'bg-success-50 text-success-700 border-success-200',
}

export default function TeamStep() {
  const { data, addTeam, updateTeam, removeTeam, addTeamConstraint, removeTeamConstraint, updateTeamConstraint, autoSave } =
    useWizardStore()
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const triggerSave = useCallback(() => {
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    saveTimerRef.current = setTimeout(() => {
      autoSave()
    }, 500)
  }, [autoSave])

  useEffect(() => {
    triggerSave()
    return () => {
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    }
  }, [data.teams, triggerSave])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Équipes</h2>
          <p className="text-sm text-neutral-500">
            Ajoutez les équipes et leurs contraintes d'horaire
          </p>
        </div>
        <button
          type="button"
          onClick={addTeam}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
          + Ajouter une équipe
        </button>
      </div>

      {data.teams.length === 0 && (
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Aucune équipe ajoutée. Cliquez sur le bouton ci-dessus pour commencer.</p>
        </div>
      )}

      {data.teams.map((team, index) => (
        <TeamCard
          key={team.id}
          team={team}
          index={index}
          onUpdate={(updates) => updateTeam(team.id, updates)}
          onRemove={() => removeTeam(team.id)}
          onAddConstraint={() => addTeamConstraint(team.id)}
          onRemoveConstraint={(constraintId) => removeTeamConstraint(team.id, constraintId)}
          onUpdateConstraint={(constraintId, updates) =>
            updateTeamConstraint(team.id, constraintId, updates)
          }
        />
      ))}
    </div>
  )
}

interface TeamCardProps {
  team: TeamData
  index: number
  onUpdate: (updates: Partial<TeamData>) => void
  onRemove: () => void
  onAddConstraint: () => void
  onRemoveConstraint: (constraintId: string) => void
  onUpdateConstraint: (constraintId: string, updates: Partial<TeamData['constraints'][0]>) => void
}

function TeamCard({
  team,
  index,
  onUpdate,
  onRemove,
  onAddConstraint,
  onRemoveConstraint,
  onUpdateConstraint,
}: TeamCardProps) {
  const [expanded, setExpanded] = useState(false)

  return (
    <div className="rounded-lg border border-neutral-200 bg-white shadow-sm">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
        <div className="flex items-center gap-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">
            {index + 1}
          </span>
          <span className="font-medium text-neutral-900">
            {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
          </span>
          {team.playerCount > 0 && (
            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
              {team.playerCount} joueur{team.playerCount > 1 ? 's' : ''}
            </span>
          )}
          {team.level && (
            <span className="rounded-full bg-info-50 px-2 py-0.5 text-xs text-info-600">
              {team.level}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="text-sm text-primary-600 hover:text-primary-700"
          >
            {expanded ? 'Réduire' : 'Détails'}
          </button>
          <button
            type="button"
            onClick={onRemove}
            className="rounded p-1 text-error-500 hover:bg-error-50"
            aria-label="Remove team"
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>
      </div>

      {/* Form */}
      <div className="px-4 py-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Nom de l'équipe</label>
            <input
              type="text"
              value={team.name}
              onChange={(e) => onUpdate({ name: e.target.value })}
              placeholder="Ex: U15 Elite"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Nombre de joueurs</label>
            <input
              type="number"
              min={1}
              value={team.playerCount || ''}
              onChange={(e) => onUpdate({ playerCount: parseInt(e.target.value, 10) || 0 })}
              placeholder="0"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Niveau</label>
            <input
              type="text"
              value={team.level}
              onChange={(e) => onUpdate({ level: e.target.value })}
              placeholder="Ex: Départemental, Régional"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
        </div>
      </div>

      {/* Constraints */}
      {expanded && (
        <div className="border-t border-neutral-100 px-4 py-3">
          <div className="mb-3 flex items-center justify-between">
            <h4 className="text-sm font-semibold text-neutral-700">Contraintes d'horaire</h4>
            <button
              type="button"
              onClick={onAddConstraint}
              className="rounded bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-200"
            >
              + Ajouter
            </button>
          </div>

          {team.constraints.length === 0 && (
            <p className="text-xs text-neutral-400">Aucune contrainte</p>
          )}

          <div className="space-y-2">
            {team.constraints.map((constraint) => (
              <div
                key={constraint.id}
                className={`flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 ${CONSTRAINT_COLORS[constraint.type]}`}
              >
                <select
                  value={constraint.type}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, { type: e.target.value as ConstraintType })
                  }
                  className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
                >
                  {Object.entries(CONSTRAINT_LABELS).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
                </select>
                <select
                  value={constraint.day || 'mon'}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, { day: e.target.value as DayKey })
                  }
                  className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
                >
                  {DAYS.map((d) => (
                    <option key={d} value={d}>
                      {DAY_LABELS[d]}
                    </option>
                  ))}
                </select>
                <span className="text-xs opacity-60">de</span>
                <input
                  type="number"
                  min={0}
                  max={23}
                  value={constraint.startHour ?? 18}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, {
                      startHour: parseInt(e.target.value, 10),
                    })
                  }
                  className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
                />
                <span className="text-xs opacity-60">h</span>
                <input
                  type="number"
                  min={0}
                  max={59}
                  step={15}
                  value={constraint.startMinute ?? 0}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, {
                      startMinute: parseInt(e.target.value, 10),
                    })
                  }
                  className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
                />
                <span className="text-xs opacity-60">à</span>
                <input
                  type="number"
                  min={0}
                  max={23}
                  value={constraint.endHour ?? 20}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, {
                      endHour: parseInt(e.target.value, 10),
                    })
                  }
                  className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
                />
                <span className="text-xs opacity-60">h</span>
                <input
                  type="number"
                  min={0}
                  max={59}
                  step={15}
                  value={constraint.endMinute ?? 0}
                  onChange={(e) =>
                    onUpdateConstraint(constraint.id, {
                      endMinute: parseInt(e.target.value, 10),
                    })
                  }
                  className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
                />
                <button
                  type="button"
                  onClick={() => onRemoveConstraint(constraint.id)}
                  className="ml-auto opacity-60 hover:opacity-100"
                  aria-label="Remove constraint"
                >
                  ×
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

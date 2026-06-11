import { useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  type CoachConstraint,
  type ConstraintType,
  type DayKey,
  type PreferredSlotSeverity,
  type TeamConstraint,
} from '@/features/wizard/wizardStore'

const CONSTRAINT_LABELS: Record<ConstraintType, string> = {
  fixed: 'Fixe',
  forbidden: 'Exclu',
  preferred: 'Prefere',
}

const SEVERITIES: PreferredSlotSeverity[] = ['Obligatoire', 'Fortement préféré', 'Préféré', 'Flexible']

export default function ConstraintStep() {
  const {
    data,
    addConstraint,
    removeConstraint,
    updateConstraint,
    addCoachConstraint,
    removeCoachConstraint,
    updateCoachConstraint,
    autoSave,
  } = useWizardStore()
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
  }, [data.constraints, data.coachConstraints, triggerSave])

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-neutral-900">Contraintes</h2>
        <p className="text-sm text-neutral-500">
          Centralisez les indisponibilites coachs et les contraintes equipes
        </p>
      </div>

      <section className="rounded-lg border border-neutral-200 bg-white p-4">
        <div className="mb-4 flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-neutral-800">Coach Constraints</h3>
            <p className="text-xs text-neutral-500">Indisponibilites recurrentes et preference de salle</p>
          </div>
          <button
            type="button"
            onClick={addCoachConstraint}
            className="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700"
          >
            + Ajouter
          </button>
        </div>
        {data.coachConstraints.length === 0 ? (
          <p className="rounded-md bg-neutral-50 p-3 text-sm text-neutral-400">Aucune contrainte coach.</p>
        ) : (
          <div className="space-y-2">
            {data.coachConstraints.map((constraint) => (
              <CoachConstraintRow
                key={constraint.id}
                constraint={constraint}
                coaches={data.coaches}
                venues={data.venues}
                onUpdate={(updates) => updateCoachConstraint(constraint.id, updates)}
                onRemove={() => removeCoachConstraint(constraint.id)}
              />
            ))}
          </div>
        )}
      </section>

      <section className="rounded-lg border border-neutral-200 bg-white p-4">
        <div className="mb-4 flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-neutral-800">Team Constraints</h3>
            <p className="text-xs text-neutral-500">Creneaux preferes, salle preferee et severite</p>
          </div>
          <button
            type="button"
            onClick={addConstraint}
            className="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700"
          >
            + Ajouter
          </button>
        </div>
        {data.constraints.length === 0 ? (
          <p className="rounded-md bg-neutral-50 p-3 text-sm text-neutral-400">Aucune contrainte equipe.</p>
        ) : (
          <div className="space-y-2">
            {data.constraints.map((constraint) => (
              <TeamConstraintRow
                key={constraint.id}
                constraint={constraint}
                teams={data.teams}
                venues={data.venues}
                onUpdate={(updates) => updateConstraint(constraint.id, updates)}
                onRemove={() => removeConstraint(constraint.id)}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}

interface CoachConstraintRowProps {
  constraint: CoachConstraint
  coaches: { id: string; name: string }[]
  venues: { id: string; name: string }[]
  onUpdate: (updates: Partial<CoachConstraint>) => void
  onRemove: () => void
}

function CoachConstraintRow({ constraint, coaches, venues, onUpdate, onRemove }: CoachConstraintRowProps) {
  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-warning-700">
      <select
        value={constraint.coachId || ''}
        onChange={(e) => onUpdate({ coachId: e.target.value || undefined })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        <option value="">-- Coach --</option>
        {coaches.map((coach) => (
          <option key={coach.id} value={coach.id}>
            {coach.name || 'Sans nom'}
          </option>
        ))}
      </select>
      <TimeRangeFields constraint={constraint} onUpdate={onUpdate} />
      <select
        value={constraint.venueId || ''}
        onChange={(e) => onUpdate({ venueId: e.target.value || undefined })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        <option value="">Preference de salle</option>
        {venues.map((venue) => (
          <option key={venue.id} value={venue.id}>
            {venue.name || 'Sans nom'}
          </option>
        ))}
      </select>
      <button type="button" onClick={onRemove} className="ml-auto opacity-60 hover:opacity-100" aria-label="Remove coach constraint">
        x
      </button>
    </div>
  )
}

interface TeamConstraintRowProps {
  constraint: TeamConstraint
  teams: { id: string; name: string }[]
  venues: { id: string; name: string }[]
  onUpdate: (updates: Partial<TeamConstraint>) => void
  onRemove: () => void
}

function TeamConstraintRow({ constraint, teams, venues, onUpdate, onRemove }: TeamConstraintRowProps) {
  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-success-200 bg-success-50 px-3 py-2 text-success-700">
      <select
        value={constraint.teamId || ''}
        onChange={(e) => onUpdate({ teamId: e.target.value || undefined })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        <option value="">-- Equipe --</option>
        {teams.map((team) => (
          <option key={team.id} value={team.id}>
            {team.name || 'Sans nom'}
          </option>
        ))}
      </select>
      <select
        value={constraint.type}
        onChange={(e) => onUpdate({ type: e.target.value as ConstraintType })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        {Object.entries(CONSTRAINT_LABELS).map(([key, label]) => (
          <option key={key} value={key}>
            {label}
          </option>
        ))}
      </select>
      <TimeRangeFields constraint={constraint} onUpdate={onUpdate} />
      <select
        value={constraint.venueId || ''}
        onChange={(e) => onUpdate({ venueId: e.target.value || undefined })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        <option value="">Salle preferee</option>
        {venues.map((venue) => (
          <option key={venue.id} value={venue.id}>
            {venue.name || 'Sans nom'}
          </option>
        ))}
      </select>
      <select
        value={constraint.severity || 'Flexible'}
        onChange={(e) => onUpdate({ severity: e.target.value as PreferredSlotSeverity })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        {SEVERITIES.map((severity) => (
          <option key={severity} value={severity}>
            {severity}
          </option>
        ))}
      </select>
      <button type="button" onClick={onRemove} className="ml-auto opacity-60 hover:opacity-100" aria-label="Remove team constraint">
        x
      </button>
    </div>
  )
}

interface TimeRangeConstraint {
  day?: DayKey
  startHour?: number
  startMinute?: number
  endHour?: number
  endMinute?: number
}

interface TimeRangeFieldsProps<T extends TimeRangeConstraint> {
  constraint: T
  onUpdate: (updates: Partial<T>) => void
}

function TimeRangeFields<T extends TimeRangeConstraint>({ constraint, onUpdate }: TimeRangeFieldsProps<T>) {
  return (
    <>
      <select
        value={constraint.day || 'mon'}
        onChange={(e) => onUpdate({ day: e.target.value as DayKey } as Partial<T>)}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        {DAYS.map((day) => (
          <option key={day} value={day}>
            {DAY_LABELS[day]}
          </option>
        ))}
      </select>
      <span className="text-xs opacity-60">de</span>
      <input
        type="number"
        min={0}
        max={23}
        value={constraint.startHour ?? 18}
        onChange={(e) => onUpdate({ startHour: parseInt(e.target.value, 10) } as Partial<T>)}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">h</span>
      <input
        type="number"
        min={0}
        max={59}
        step={15}
        value={constraint.startMinute ?? 0}
        onChange={(e) => onUpdate({ startMinute: parseInt(e.target.value, 10) } as Partial<T>)}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">a</span>
      <input
        type="number"
        min={0}
        max={23}
        value={constraint.endHour ?? 20}
        onChange={(e) => onUpdate({ endHour: parseInt(e.target.value, 10) } as Partial<T>)}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">h</span>
      <input
        type="number"
        min={0}
        max={59}
        step={15}
        value={constraint.endMinute ?? 0}
        onChange={(e) => onUpdate({ endMinute: parseInt(e.target.value, 10) } as Partial<T>)}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
    </>
  )
}

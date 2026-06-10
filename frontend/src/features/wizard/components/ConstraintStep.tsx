import { useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  type DayKey,
  type ConstraintType,
  type TeamConstraint,
} from '@/features/wizard/wizardStore'

const CONSTRAINT_LABELS: Record<ConstraintType, string> = {
  fixed: 'Fixe (HARD)',
  forbidden: 'Exclu',
  preferred: 'Prefere (SOFT)',
}

const CONSTRAINT_COLORS: Record<ConstraintType, string> = {
  fixed: 'bg-error-50 text-error-700 border-error-200',
  forbidden: 'bg-warning-50 text-warning-700 border-warning-200',
  preferred: 'bg-success-50 text-success-700 border-success-200',
}

export default function ConstraintStep() {
  const { data, addConstraint, removeConstraint, updateConstraint, autoSave } = useWizardStore()
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
  }, [data.constraints, triggerSave])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Contraintes</h2>
          <p className="text-sm text-neutral-500">
            Ajoutez des contraintes pour les equipes et les coachs
          </p>
        </div>
        <button
          type="button"
          onClick={addConstraint}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
          + Ajouter une contrainte
        </button>
      </div>

      {data.constraints.length === 0 && (
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Aucune contrainte. Cliquez sur le bouton ci-dessus pour commencer.</p>
        </div>
      )}

      <div className="space-y-2">
        {data.constraints.map((constraint) => (
          <ConstraintRow
            key={constraint.id}
            constraint={constraint}
            teams={data.teams}
            coaches={data.coaches}
            venues={data.venues}
            onUpdate={(updates) => updateConstraint(constraint.id, updates)}
            onRemove={() => removeConstraint(constraint.id)}
          />
        ))}
      </div>
    </div>
  )
}

interface ConstraintRowProps {
  constraint: TeamConstraint
  teams: { id: string; name: string }[]
  coaches: { id: string; name: string }[]
  venues: { id: string; name: string }[]
  onUpdate: (updates: Partial<TeamConstraint>) => void
  onRemove: () => void
}

function ConstraintRow({
  constraint,
  teams,
  coaches,
  venues,
  onUpdate,
  onRemove,
}: ConstraintRowProps) {
  const colorClass = CONSTRAINT_COLORS[constraint.type]

  return (
    <div className={`flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 ${colorClass}`}>
      {/* Target: team or coach */}
      <div className="flex gap-2">
        <select
          value={constraint.teamId || ''}
          onChange={(e) => {
            const teamId = e.target.value || undefined
            onUpdate({ teamId, coachId: teamId ? undefined : constraint.coachId })
          }}
          className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
        >
          <option value="">-- Equipe --</option>
          {teams.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name || 'Sans nom'}
            </option>
          ))}
        </select>

        <span className="flex items-center text-xs opacity-60">ou</span>

        <select
          value={constraint.coachId || ''}
          onChange={(e) => {
            const coachId = e.target.value || undefined
            onUpdate({ coachId, teamId: coachId ? undefined : constraint.teamId })
          }}
          className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
        >
          <option value="">-- Coach --</option>
          {coaches.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name || 'Sans nom'}
            </option>
          ))}
        </select>
      </div>

      {/* Type */}
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

      {/* Day */}
      <select
        value={constraint.day || 'mon'}
        onChange={(e) => onUpdate({ day: e.target.value as DayKey })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        {DAYS.map((d) => (
          <option key={d} value={d}>
            {DAY_LABELS[d]}
          </option>
        ))}
      </select>

      {/* Time range */}
      <span className="text-xs opacity-60">de</span>
      <input
        type="number"
        min={0}
        max={23}
        value={constraint.startHour ?? 18}
        onChange={(e) => onUpdate({ startHour: parseInt(e.target.value, 10) })}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">h</span>
      <input
        type="number"
        min={0}
        max={59}
        step={15}
        value={constraint.startMinute ?? 0}
        onChange={(e) => onUpdate({ startMinute: parseInt(e.target.value, 10) })}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">a</span>
      <input
        type="number"
        min={0}
        max={23}
        value={constraint.endHour ?? 20}
        onChange={(e) => onUpdate({ endHour: parseInt(e.target.value, 10) })}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />
      <span className="text-xs opacity-60">h</span>
      <input
        type="number"
        min={0}
        max={59}
        step={15}
        value={constraint.endMinute ?? 0}
        onChange={(e) => onUpdate({ endMinute: parseInt(e.target.value, 10) })}
        className="w-14 rounded border border-current/20 bg-white px-2 py-1 text-sm"
      />

      {/* Venue (optional) */}
      <select
        value={constraint.venueId || ''}
        onChange={(e) => onUpdate({ venueId: e.target.value || undefined })}
        className="rounded border border-current/20 bg-white px-2 py-1 text-sm"
      >
        <option value="">Toutes salles</option>
        {venues.map((v) => (
          <option key={v.id} value={v.id}>
            {v.name || 'Sans nom'}
          </option>
        ))}
      </select>

      {/* Remove */}
      <button
        type="button"
        onClick={onRemove}
        className="ml-auto opacity-60 hover:opacity-100"
        aria-label="Remove constraint"
      >
        x
      </button>
    </div>
  )
}

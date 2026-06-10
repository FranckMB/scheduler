import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  type PreferredSlot,
  type DayKey,
} from '@/features/wizard/wizardStore'

const HOURS = Array.from({ length: 16 }, (_, i) => i + 7) // 7h to 22h
const MINUTES = [0, 15, 30, 45]

export default function PreferredSlotStep() {
  const { data, addPreferredSlot, updatePreferredSlot, removePreferredSlot, autoSave } =
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
  }, [data.preferredSlots, triggerSave])

  if (data.teams.length === 0) {
    return (
      <div className="space-y-6">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Creneaux preferes</h2>
          <p className="text-sm text-neutral-500">
            Selectionnez les creneaux preferes pour chaque equipe
          </p>
        </div>
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Ajoutez d&apos;abord des equipes (etape 2) avant de definir des creneaux preferes.</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Creneaux preferes</h2>
          <p className="text-sm text-neutral-500">
            Pour chaque equipe, selectionnez un jour, une heure et une salle preferes
          </p>
        </div>
      </div>

      {data.teams.map((team) => (
        <TeamPreferredSlots
          key={team.id}
          team={team}
          preferredSlots={data.preferredSlots.filter((ps) => ps.teamId === team.id)}
          venues={data.venues}
          onAdd={() => addPreferredSlot(team.id)}
          onUpdate={(id, updates) => updatePreferredSlot(id, updates)}
          onRemove={(id) => removePreferredSlot(id)}
        />
      ))}
    </div>
  )
}

interface TeamPreferredSlotsProps {
  team: { id: string; name: string }
  preferredSlots: PreferredSlot[]
  venues: { id: string; name: string }[]
  onAdd: () => void
  onUpdate: (id: string, updates: Partial<PreferredSlot>) => void
  onRemove: (id: string) => void
}

function TeamPreferredSlots({
  team,
  preferredSlots,
  venues,
  onAdd,
  onUpdate,
  onRemove,
}: TeamPreferredSlotsProps) {
  const [expanded, setExpanded] = useState(false)

  return (
    <div className="rounded-lg border border-neutral-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
        <div className="flex items-center gap-3">
          <span className="font-medium text-neutral-900">
            {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
          </span>
          {preferredSlots.length > 0 && (
            <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-600">
              {preferredSlots.length} creneau{preferredSlots.length > 1 ? 'x' : ''}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onAdd}
            className="rounded bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-200"
          >
            + Ajouter
          </button>
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="text-sm text-primary-600 hover:text-primary-700"
          >
            {expanded ? 'Reduire' : 'Details'}
          </button>
        </div>
      </div>

      {expanded && preferredSlots.length > 0 && (
        <div className="border-t border-neutral-100 px-4 py-3">
          <div className="space-y-2">
            {preferredSlots.map((slot) => (
              <div
                key={slot.id}
                className="flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2"
              >
                <select
                  value={slot.day}
                  onChange={(e) => onUpdate(slot.id, { day: e.target.value as DayKey })}
                  className="rounded border border-neutral-300 px-2 py-1 text-sm"
                >
                  {DAYS.map((d) => (
                    <option key={d} value={d}>
                      {DAY_LABELS[d]}
                    </option>
                  ))}
                </select>

                <select
                  value={slot.hour}
                  onChange={(e) => onUpdate(slot.id, { hour: parseInt(e.target.value, 10) })}
                  className="rounded border border-neutral-300 px-2 py-1 text-sm"
                >
                  {HOURS.map((h) => (
                    <option key={h} value={h}>
                      {h.toString().padStart(2, '0')}h
                    </option>
                  ))}
                </select>

                <select
                  value={slot.minute}
                  onChange={(e) => onUpdate(slot.id, { minute: parseInt(e.target.value, 10) })}
                  className="rounded border border-neutral-300 px-2 py-1 text-sm"
                >
                  {MINUTES.map((m) => (
                    <option key={m} value={m}>
                      {m.toString().padStart(2, '0')}
                    </option>
                  ))}
                </select>

                <select
                  value={slot.venueId}
                  onChange={(e) => onUpdate(slot.id, { venueId: e.target.value })}
                  className="rounded border border-neutral-300 px-2 py-1 text-sm"
                >
                  <option value="">-- Salle --</option>
                  {venues.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.name || 'Sans nom'}
                    </option>
                  ))}
                </select>

                <button
                  type="button"
                  onClick={() => onRemove(slot.id)}
                  className="ml-auto text-error-500 hover:text-error-700"
                  aria-label="Remove preferred slot"
                >
                  x
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  START_HOUR,
  END_HOUR,
  generateSlotKey,
  type DayKey,
} from '@/features/wizard/wizardStore'

export default function VenueStep() {
  const { data, updateVenueSlot, addClosure, removeClosure, autoSave } = useWizardStore()
  const [closureDate, setClosureDate] = useState('')
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Debounced auto-save
  const triggerSave = useCallback(() => {
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    saveTimerRef.current = setTimeout(() => {
      autoSave()
    }, 500)
  }, [autoSave])

  // Auto-save on data change
  useEffect(() => {
    triggerSave()
    return () => {
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
    }
  }, [data.venues.slots, data.venues.closures, triggerSave])

  const handleSlotClick = (day: DayKey, hour: number, minute: number) => {
    const key = generateSlotKey(day, hour, minute)
    updateVenueSlot(key, !data.venues.slots[key])
  }

  const handleAddClosure = () => {
    if (closureDate) {
      addClosure(closureDate)
      setClosureDate('')
    }
  }

  const availableCount = Object.values(data.venues.slots).filter(Boolean).length

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Créneaux des salles</h2>
          <p className="text-sm text-neutral-500">
            Cliquez sur les créneaux pour les rendre disponibles • {availableCount} créneau
            {availableCount > 1 ? 'x' : ''} sélectionné
            {availableCount > 1 ? 's' : ''}
          </p>
        </div>
      </div>

      {/* Time grid */}
      <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white">
        <table className="w-full border-collapse text-xs">
          <thead>
            <tr className="bg-neutral-50">
              <th className="sticky left-0 z-10 w-20 border-b border-r border-neutral-200 bg-neutral-50 px-2 py-2 text-left font-medium text-neutral-600">
                Heure
              </th>
              {DAYS.map((day) => (
                <th
                  key={day}
                  className="min-w-24 border-b border-neutral-200 px-1 py-2 text-center font-semibold text-neutral-700"
                >
                  {DAY_LABELS[day]}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Array.from({ length: END_HOUR - START_HOUR }, (_, i) => START_HOUR + i).map(
              (hour) =>
                [0, 15, 30, 45].map((minute) => {
                  const timeLabel = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`
                  return (
                    <tr key={`${hour}-${minute}`} className="hover:bg-neutral-50">
                      <td className="sticky left-0 z-10 border-b border-r border-neutral-100 bg-white px-2 py-0.5 font-mono text-neutral-500">
                        {minute === 0 ? timeLabel : ''}
                      </td>
                      {DAYS.map((day) => {
                        const key = generateSlotKey(day, hour, minute)
                        const isAvailable = data.venues.slots[key]
                        return (
                          <td key={key} className="border-b border-neutral-100 p-0.5">
                            <button
                              type="button"
                              onClick={() => handleSlotClick(day, hour, minute)}
                              className={`h-6 w-full rounded transition-colors ${
                                isAvailable
                                  ? 'bg-primary-500 hover:bg-primary-600'
                                  : 'bg-neutral-100 hover:bg-neutral-200'
                              }`}
                              title={`${DAY_LABELS[day]} ${timeLabel}${isAvailable ? ' (disponible)' : ''}`}
                              aria-label={`${DAY_LABELS[day]} ${timeLabel}${isAvailable ? ' disponible' : ' indisponible'}`}
                            />
                          </td>
                        )
                      })}
                    </tr>
                  )
                })
            )}
          </tbody>
        </table>
      </div>

      {/* Closures */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">Jours de fermeture</h3>
        <div className="flex gap-2">
          <input
            type="date"
            value={closureDate}
            onChange={(e) => setClosureDate(e.target.value)}
            className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
          />
          <button
            type="button"
            onClick={handleAddClosure}
            disabled={!closureDate}
            className="rounded-md bg-neutral-100 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-200 disabled:opacity-50"
          >
            Ajouter
          </button>
        </div>
        {data.venues.closures.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {data.venues.closures.map((date) => (
              <span
                key={date}
                className="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-600"
              >
                {date}
                <button
                  type="button"
                  onClick={() => removeClosure(date)}
                  className="ml-1 text-warning-400 hover:text-warning-700"
                  aria-label={`Remove closure ${date}`}
                >
                  ×
                </button>
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

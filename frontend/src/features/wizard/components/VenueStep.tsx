import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
  generateSlotsFromRanges,
  type DayKey,
  type TimeRange,
  type VenueData,
} from '@/features/wizard/wizardStore'

export default function VenueStep() {
  const {
    data,
    addVenue,
    updateVenue,
    removeVenue,
    addVenueRange,
    updateVenueRange,
    removeVenueRange,
    addClosure,
    removeClosure,
    autoSave,
  } = useWizardStore()
  const [closureDates, setClosureDates] = useState<Record<string, string>>({})
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
  }, [data.venues, triggerSave])

  const handleAddClosure = (venueId: string) => {
    const date = closureDates[venueId]
    if (date) {
      addClosure(venueId, date)
      setClosureDates((prev) => ({ ...prev, [venueId]: '' }))
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Salles</h2>
          <p className="text-sm text-neutral-500">
            Configurez les salles avec des plages horaires par jour
          </p>
        </div>
        <button
          type="button"
          onClick={addVenue}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
          + Ajouter une salle
        </button>
      </div>

      {data.venues.map((venue, index) => (
        <VenueCard
          key={venue.id}
          venue={venue}
          index={index}
          closureDate={closureDates[venue.id] || ''}
          onClosureDateChange={(date) =>
            setClosureDates((prev) => ({ ...prev, [venue.id]: date }))
          }
          onUpdate={(updates) => updateVenue(venue.id, updates)}
          onRemove={() => removeVenue(venue.id)}
          onAddRange={(day) => addVenueRange(venue.id, day)}
          onUpdateRange={(day, rangeId, updates) => updateVenueRange(venue.id, day, rangeId, updates)}
          onRemoveRange={(day, rangeId) => removeVenueRange(venue.id, day, rangeId)}
          onAddClosure={() => handleAddClosure(venue.id)}
          onRemoveClosure={(date) => removeClosure(venue.id, date)}
          canRemove={data.venues.length > 1}
        />
      ))}
    </div>
  )
}

interface VenueCardProps {
  venue: VenueData
  index: number
  closureDate: string
  onUpdate: (updates: Partial<VenueData>) => void
  onRemove: () => void
  onAddRange: (day: DayKey) => void
  onUpdateRange: (day: DayKey, rangeId: string, updates: Partial<TimeRange>) => void
  onRemoveRange: (day: DayKey, rangeId: string) => void
  onAddClosure: () => void
  onRemoveClosure: (date: string) => void
  onClosureDateChange: (date: string) => void
  canRemove: boolean
}

function VenueCard({
  venue,
  index,
  closureDate,
  onUpdate,
  onRemove,
  onAddRange,
  onUpdateRange,
  onRemoveRange,
  onAddClosure,
  onRemoveClosure,
  onClosureDateChange,
  canRemove,
}: VenueCardProps) {
  const [expanded, setExpanded] = useState(false)
  const availableCount = generateSlotsFromRanges(venue.availabilityRanges).length
  const rangeCount = DAYS.reduce((count, day) => count + venue.availabilityRanges[day].length, 0)

  return (
    <div className="rounded-lg border border-neutral-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
        <div className="flex items-center gap-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">
            {index + 1}
          </span>
          <span className="font-medium text-neutral-900">
            {venue.name || <span className="text-neutral-400 italic">Sans nom</span>}
          </span>
          <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
            {rangeCount} plage{rangeCount > 1 ? 's' : ''}
          </span>
          <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-600">
            {availableCount} creneau{availableCount > 1 ? 'x' : ''} de 15 min
          </span>
          {venue.can_split && (
            <span className="rounded-full bg-info-50 px-2 py-0.5 text-xs text-info-600">
              Split possible
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="text-sm text-primary-600 hover:text-primary-700"
          >
            {expanded ? 'Reduire' : 'Details'}
          </button>
          {canRemove && (
            <button
              type="button"
              onClick={onRemove}
              className="rounded p-1 text-error-500 hover:bg-error-50"
              aria-label="Remove venue"
            >
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                />
              </svg>
            </button>
          )}
        </div>
      </div>

      <div className="px-4 py-3">
        <div className="flex items-center gap-4">
          <div className="flex-1">
            <label className="mb-1 block text-xs font-medium text-neutral-600">Nom de la salle</label>
            <input
              type="text"
              value={venue.name}
              onChange={(e) => onUpdate({ name: e.target.value })}
              placeholder="Ex: Gymnase Principal"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
          <label className="flex items-center gap-2 pt-5">
            <input
              type="checkbox"
              checked={venue.can_split}
              onChange={(e) => onUpdate({ can_split: e.target.checked })}
              className="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-neutral-700">Split possible</span>
          </label>
        </div>
      </div>

      {expanded && (
        <div className="border-t border-neutral-100 px-4 py-3">
          <h4 className="mb-3 text-sm font-semibold text-neutral-700">
            Plages de disponibilite
          </h4>
          <div className="grid gap-3 md:grid-cols-2">
            {DAYS.map((day) => (
              <div key={day} className="rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                <div className="mb-2 flex items-center justify-between">
                  <h5 className="text-sm font-semibold text-neutral-700">{DAY_LABELS[day]}</h5>
                  <button
                    type="button"
                    onClick={() => onAddRange(day)}
                    className="rounded bg-white px-2 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-100"
                  >
                    + Plage
                  </button>
                </div>
                {venue.availabilityRanges[day].length === 0 ? (
                  <p className="text-xs text-neutral-400">Aucune disponibilite</p>
                ) : (
                  <div className="space-y-2">
                    {venue.availabilityRanges[day].map((range) => (
                      <div key={range.id} className="flex items-center gap-2">
                        <input
                          type="time"
                          step={900}
                          value={range.start}
                          onChange={(e) => onUpdateRange(day, range.id, { start: e.target.value })}
                          className="rounded border border-neutral-300 px-2 py-1 text-sm"
                        />
                        <span className="text-xs text-neutral-500">a</span>
                        <input
                          type="time"
                          step={900}
                          value={range.end}
                          onChange={(e) => onUpdateRange(day, range.id, { end: e.target.value })}
                          className="rounded border border-neutral-300 px-2 py-1 text-sm"
                        />
                        <button
                          type="button"
                          onClick={() => onRemoveRange(day, range.id)}
                          className="ml-auto text-error-500 hover:text-error-700"
                          aria-label="Remove availability range"
                        >
                          x
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>

          <div className="mt-4">
            <h5 className="mb-2 text-xs font-medium text-neutral-600">Jours de fermeture</h5>
            <div className="flex gap-2">
              <input
                type="date"
                value={closureDate}
                onChange={(e) => onClosureDateChange(e.target.value)}
                className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
              />
              <button
                type="button"
                onClick={onAddClosure}
                disabled={!closureDate}
                className="rounded-md bg-neutral-100 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-200 disabled:opacity-50"
              >
                Ajouter
              </button>
            </div>
            {venue.closures.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-2">
                {venue.closures.map((date) => (
                  <span
                    key={date}
                    className="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-600"
                  >
                    {date}
                    <button
                      type="button"
                      onClick={() => onRemoveClosure(date)}
                      className="ml-1 text-warning-400 hover:text-warning-700"
                      aria-label={`Remove closure ${date}`}
                    >
                      x
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

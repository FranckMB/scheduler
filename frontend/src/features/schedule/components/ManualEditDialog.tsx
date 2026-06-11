import { Fragment, useState } from 'react'
import type { ScheduleSlot } from '@/features/schedule/types'
import { DAY_NAMES, LOCK_LEVEL_CONFIG } from '@/features/schedule/types'
import {
  useManualEditConstraint,
  useManualEditLock,
  useManualEditOneTime,
} from '@/features/schedule/useSchedule'

interface ManualEditDialogProps {
  slot: ScheduleSlot
  originalSlot: ScheduleSlot
  newDayOfWeek: number
  newStartTime: string
  newVenueId: string
  onClose: () => void
  onSuccess: () => void
}

type ActionType = 'constraint' | 'lock' | 'one-time' | null

function formatTime(isoTime: string): string {
  if (isoTime.includes('T')) {
    const date = new Date(isoTime)
    return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  }
  const parts = isoTime.split(':')
  return `${parts[0]}:${parts[1]}`
}

function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h > 0 && m > 0) return `${h}h${m.toString().padStart(2, '0')}`
  if (h > 0) return `${h}h`
  return `${m}min`
}

export default function ManualEditDialog({
  slot,
  originalSlot,
  newDayOfWeek,
  newStartTime,
  newVenueId,
  onClose,
  onSuccess,
}: ManualEditDialogProps) {
  const [selectedAction, setSelectedAction] = useState<ActionType>(null)
  const [lockLevel, setLockLevel] = useState<'SOFT' | 'HARD'>('SOFT')
  const [constraintType, setConstraintType] = useState<string>('day_time')
  const [error, setError] = useState<string | null>(null)

  const constraintMutation = useManualEditConstraint()
  const lockMutation = useManualEditLock()
  const oneTimeMutation = useManualEditOneTime()

  const dayChanged = originalSlot.dayOfWeek !== newDayOfWeek
  const venueChanged = originalSlot.venueId !== newVenueId
  const timeChanged = originalSlot.startTime !== newStartTime

  const isMutating =
    constraintMutation.isPending || lockMutation.isPending || oneTimeMutation.isPending

  const handleAction = async () => {
    setError(null)

    try {
      if (selectedAction === 'constraint') {
        await constraintMutation.mutateAsync({
          slotId: slot.id,
          type: constraintType,
          reason: `Manual edit: ${dayChanged ? 'day change' : ''}${venueChanged ? 'venue change' : ''}`,
        })
      } else if (selectedAction === 'lock') {
        await lockMutation.mutateAsync({
          slotId: slot.id,
          lockLevel,
        })
        // Also apply the one-time move if day/venue/time changed
        if (dayChanged || venueChanged || timeChanged) {
          await oneTimeMutation.mutateAsync({
            slotId: slot.id,
            data: {
              dayOfWeek: newDayOfWeek,
              startTime: newStartTime,
              venueId: newVenueId,
            },
          })
        }
      } else if (selectedAction === 'one-time') {
        await oneTimeMutation.mutateAsync({
          slotId: slot.id,
          data: {
            dayOfWeek: newDayOfWeek,
            startTime: newStartTime,
            venueId: newVenueId,
          },
        })
      }
      onSuccess()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Une erreur est survenue')
    }
  }

  const lockConfig = LOCK_LEVEL_CONFIG[slot.lockLevel]

  return (
    <Fragment>
      {/* Backdrop */}
      <div
        className="fixed inset-0 z-40 bg-black/50 transition-opacity"
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Modal */}
      <div
        className="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Manual edit dialog"
      >
        <div className="w-full max-w-lg rounded-xl bg-neutral-800 shadow-lg">
          {/* Header */}
          <div className="flex items-center justify-between border-b border-neutral-700 px-6 py-4">
            <h2 className="text-lg font-semibold text-white">
              Modifier le créneau
            </h2>
            <button
              type="button"
              className="rounded-md p-1 text-neutral-500 hover:bg-neutral-700 hover:text-neutral-300"
              onClick={onClose}
              aria-label="Close"
            >
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Body */}
          <div className="px-6 py-4">
            {/* Current lock indicator */}
            <div className="mb-4 flex items-center gap-2 rounded-lg bg-neutral-700 px-3 py-2">
              <span className={`h-2.5 w-2.5 rounded-full ${lockConfig.bgColor}`} />
              <span className={`text-sm font-medium ${lockConfig.color}`}>
                {lockConfig.label}
              </span>
              <span className="text-sm text-neutral-400">
                — {DAY_NAMES[originalSlot.dayOfWeek]} {formatTime(originalSlot.startTime)} · {formatDuration(originalSlot.durationMinutes)}
              </span>
            </div>

            {/* Changes summary */}
            <div className="mb-4 space-y-2">
              <h3 className="text-sm font-medium text-neutral-200">Modifications détectées</h3>
              <div className="rounded-md bg-neutral-700 p-3 text-sm">
                <div className="space-y-1">
                  {dayChanged && (
                    <div className="flex items-center gap-2">
                      <span className="text-neutral-500">Jour :</span>
                      <span className="text-error-600 line-through">{DAY_NAMES[originalSlot.dayOfWeek]}</span>
                      <span className="text-neutral-400">→</span>
                      <span className="text-success-600 font-medium">{DAY_NAMES[newDayOfWeek]}</span>
                    </div>
                  )}
                  {venueChanged && (
                    <div className="flex items-center gap-2">
                      <span className="text-neutral-500">Salle :</span>
                      <span className="text-error-600 line-through">{originalSlot.venueId.slice(0, 8)}</span>
                      <span className="text-neutral-400">→</span>
                      <span className="text-success-600 font-medium">{newVenueId.slice(0, 8)}</span>
                    </div>
                  )}
                  {timeChanged && (
                    <div className="flex items-center gap-2">
                      <span className="text-neutral-500">Horaire :</span>
                      <span className="text-error-600 line-through">{formatTime(originalSlot.startTime)}</span>
                      <span className="text-neutral-400">→</span>
                      <span className="text-success-600 font-medium">{formatTime(newStartTime)}</span>
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* Action selection */}
            <div className="mb-4 space-y-2">
              <h3 className="text-sm font-medium text-neutral-200">Comment appliquer ce changement ?</h3>

              {/* Option 1: Permanent constraint */}
              <button
                type="button"
                className={`w-full rounded-lg border-2 px-4 py-3 text-left transition-colors ${
                  selectedAction === 'constraint'
                    ? 'border-primary-500 bg-primary-900/30'
                    : 'border-neutral-700 hover:border-neutral-600 hover:bg-neutral-700'
                }`}
                onClick={() => setSelectedAction('constraint')}
              >
                <div className="flex items-center gap-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-900/50">
                    <svg className="h-4 w-4 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-sm font-semibold text-white">
                      Créer contrainte permanente
                    </div>
                    <div className="text-xs text-neutral-400">
                      Ce créneau deviendra une règle pour toutes les générations futures
                    </div>
                  </div>
                </div>
              </button>

              {/* Option 2: Lock */}
              <button
                type="button"
                className={`w-full rounded-lg border-2 px-4 py-3 text-left transition-colors ${
                  selectedAction === 'lock'
                    ? 'border-warning-500 bg-warning-900/30'
                    : 'border-neutral-700 hover:border-neutral-600 hover:bg-neutral-700'
                }`}
                onClick={() => setSelectedAction('lock')}
              >
                <div className="flex items-center gap-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-warning-900/50">
                    <svg className="h-4 w-4 text-warning-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-sm font-semibold text-white">
                      Verrouiller
                    </div>
                    <div className="text-xs text-neutral-400">
                      Bloquer ce créneau pour la prochaine génération
                    </div>
                  </div>
                </div>
              </button>

              {/* Option 3: One-time */}
              <button
                type="button"
                className={`w-full rounded-lg border-2 px-4 py-3 text-left transition-colors ${
                  selectedAction === 'one-time'
                    ? 'border-success-500 bg-success-900/30'
                    : 'border-neutral-700 hover:border-neutral-600 hover:bg-neutral-700'
                }`}
                onClick={() => setSelectedAction('one-time')}
              >
                <div className="flex items-center gap-3">
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-success-900/50">
                    <svg className="h-4 w-4 text-success-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-sm font-semibold text-white">
                      Juste ponctuel
                    </div>
                    <div className="text-xs text-neutral-400">
                      Modifier uniquement ce créneau, sans impact futur
                    </div>
                  </div>
                </div>
              </button>
            </div>

            {/* Sub-options based on selection */}
            {selectedAction === 'constraint' && (
              <div className="mb-4 rounded-lg border border-primary-700 bg-primary-900/30 p-3">
                <label className="mb-1 block text-xs font-medium text-primary-300">
                  Type de contrainte
                </label>
                <select
                  className="w-full rounded-md border border-primary-700 bg-neutral-700 px-3 py-2 text-sm text-neutral-100 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                  value={constraintType}
                  onChange={(e) => setConstraintType(e.target.value)}
                >
                  <option value="day_time">Jour + Horaire fixe</option>
                  <option value="day_only">Jour fixe (horaire flexible)</option>
                  <option value="venue_only">Salle fixe (jour flexible)</option>
                </select>
              </div>
            )}

            {selectedAction === 'lock' && (
              <div className="mb-4 rounded-lg border border-warning-700 bg-warning-900/30 p-3">
                <label className="mb-1 block text-xs font-medium text-warning-300">
                  Niveau de verrouillage
                </label>
                <div className="flex gap-2">
                  <button
                    type="button"
                    className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      lockLevel === 'SOFT'
                        ? 'bg-warning-500 text-white'
                        : 'bg-neutral-700 text-warning-300 hover:bg-neutral-600'
                    }`}
                    onClick={() => setLockLevel('SOFT')}
                  >
                    SOFT — Pénalité 10k
                  </button>
                  <button
                    type="button"
                    className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      lockLevel === 'HARD'
                        ? 'bg-error-500 text-white'
                        : 'bg-neutral-700 text-error-300 hover:bg-neutral-600'
                    }`}
                    onClick={() => setLockLevel('HARD')}
                  >
                    HARD — Intouchable
                  </button>
                </div>
              </div>
            )}

            {/* Error display */}
            {error && (
              <div className="mb-4 rounded-lg bg-error-900/40 px-3 py-2 text-sm text-error-400">
                {error}
              </div>
            )}
          </div>

          {/* Footer */}
          <div className="flex items-center justify-between border-t border-neutral-700 px-6 py-3">
            <button
              type="button"
              className="rounded-md px-4 py-2 text-sm font-medium text-neutral-300 hover:bg-neutral-700"
              onClick={onClose}
              disabled={isMutating}
            >
              Annuler
            </button>
            <button
              type="button"
              className={`rounded-md px-4 py-2 text-sm font-medium text-white transition-colors ${
                selectedAction === 'constraint'
                  ? 'bg-primary-600 hover:bg-primary-700'
                  : selectedAction === 'lock'
                    ? 'bg-warning-600 hover:bg-warning-700'
                    : selectedAction === 'one-time'
                      ? 'bg-success-600 hover:bg-success-700'
                      : 'bg-neutral-600 cursor-not-allowed'
              }`}
              onClick={handleAction}
              disabled={!selectedAction || isMutating}
            >
              {isMutating ? (
                <span className="flex items-center gap-2">
                  <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                  En cours...
                </span>
              ) : (
                'Appliquer'
              )}
            </button>
          </div>
        </div>
      </div>
    </Fragment>
  )
}

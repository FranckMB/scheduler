import { Fragment } from 'react'
import type { ScheduleSlot } from '@/features/schedule/types'
import { LOCK_LEVEL_CONFIG, DAY_NAMES } from '@/features/schedule/types'

interface SlotDetailModalProps {
  slot: ScheduleSlot | null
  teamName?: string
  venueName?: string
  coachName?: string
  onClose: () => void
}

function formatTime(isoTime: string): string {
  const date = new Date(isoTime)
  return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h > 0 && m > 0) return `${h}h${m.toString().padStart(2, '0')}`
  if (h > 0) return `${h}h`
  return `${m}min`
}

export default function SlotDetailModal({
  slot,
  teamName = 'Unknown',
  venueName = 'Unknown',
  coachName,
  onClose,
}: SlotDetailModalProps) {
  if (!slot) return null

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
        aria-label="Slot details"
      >
        <div className="w-full max-w-lg rounded-xl bg-white shadow-lg">
          {/* Header */}
          <div className="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-neutral-900">Slot Details</h2>
            <button
              type="button"
              className="rounded-md p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600"
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
            {/* Team & Venue */}
            <div className="mb-4 grid grid-cols-2 gap-4">
              <div>
                <dt className="text-sm font-medium text-neutral-500">Team</dt>
                <dd className="mt-1 text-base font-semibold text-neutral-900">{teamName}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-neutral-500">Venue</dt>
                <dd className="mt-1 text-base font-semibold text-neutral-900">{venueName}</dd>
              </div>
            </div>

            {/* Coach */}
            {coachName && (
              <div className="mb-4">
                <dt className="text-sm font-medium text-neutral-500">Coach</dt>
                <dd className="mt-1 text-base text-neutral-900">{coachName}</dd>
              </div>
            )}

            {/* Day & Time */}
            <div className="mb-4 grid grid-cols-2 gap-4">
              <div>
                <dt className="text-sm font-medium text-neutral-500">Day</dt>
                <dd className="mt-1 text-base text-neutral-900">{DAY_NAMES[slot.dayOfWeek]}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-neutral-500">Time</dt>
                <dd className="mt-1 text-base text-neutral-900">
                  {formatTime(slot.startTime)} — {formatDuration(slot.durationMinutes)}
                </dd>
              </div>
            </div>

            {/* Duration */}
            <div className="mb-4">
              <dt className="text-sm font-medium text-neutral-500">Duration</dt>
              <dd className="mt-1 text-base text-neutral-900">{formatDuration(slot.durationMinutes)}</dd>
            </div>

            {/* Lock Level */}
            <div className="mb-4">
              <dt className="text-sm font-medium text-neutral-500">Lock Level</dt>
              <dd className="mt-1 flex items-center gap-2">
                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium ${lockConfig.color}`}>
                  <span className={`h-2 w-2 rounded-full ${lockConfig.bgColor}`} />
                  {lockConfig.label}
                </span>
                {slot.temporaryLock && (
                  <span className="inline-flex items-center gap-1 rounded-md bg-info-50 px-2 py-0.5 text-xs text-info-600">
                    <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    Temporary
                  </span>
                )}
              </dd>
            </div>

            {/* Constraints */}
            {slot.pendingConstraintSuggestion && (
              <div>
                <dt className="text-sm font-medium text-neutral-500">Pending Constraints</dt>
                <dd className="mt-1 rounded-md bg-neutral-50 p-3 font-mono text-sm text-neutral-700">
                  {JSON.stringify(slot.pendingConstraintSuggestion, null, 2)}
                </dd>
              </div>
            )}
          </div>

          {/* Footer */}
          <div className="flex justify-end border-t border-neutral-200 px-6 py-3">
            <button
              type="button"
              className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
              onClick={onClose}
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Fragment>
  )
}

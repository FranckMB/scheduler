import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import FullCalendar from '@fullcalendar/react'
import type { EventDropArg, EventContentArg, EventApi } from '@fullcalendar/core'
import timeGridPlugin from '@fullcalendar/timegrid'
import dayGridPlugin from '@fullcalendar/daygrid'
import listPlugin from '@fullcalendar/list'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'
import { useLatestSchedule } from './dashboardApi'
import { useScheduleSlots, useManualEditOneTime, invalidateScheduleQueries } from '@/features/schedule/useSchedule'
import type { ScheduleSlot } from '@/features/schedule/types'
import { DAY_NAMES } from '@/features/schedule/types'
import { useAuthStore } from '@/features/auth/authStore'
import { queryClient } from '@/shared/lib/queryClient'

const EVENT_COLORS = [
  '#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6',
  '#ec4899', '#06b6d4', '#f97316', '#14b8a6', '#6366f1',
]

function hashToColor(id: string): string {
  let hash = 0
  for (let i = 0; i < id.length; i++) {
    hash = id.charCodeAt(i) + ((hash << 5) - hash)
  }
  return EVENT_COLORS[Math.abs(hash) % EVENT_COLORS.length]
}

function formatTime(isoTime: string): string {
  const date = new Date(isoTime)
  return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function dateToDayOfWeek(date: Date): number {
  return date.getDay()
}

function dateToTimeString(date: Date): string {
  const h = date.getHours().toString().padStart(2, '0')
  const m = date.getMinutes().toString().padStart(2, '0')
  return `${h}:${m}`
}

const renderEventContent = (eventInfo: EventContentArg) => {
  const slot = eventInfo.event.extendedProps.slot as ScheduleSlot
  return (
    <div
      className="fc-event-inner-custom"
      style={{
        display: 'flex',
        flexDirection: 'column',
        padding: '2px 4px',
        fontSize: '11px',
        lineHeight: '1.3',
        overflow: 'hidden',
      }}
    >
      <div style={{ fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
        Team {slot.teamId.slice(0, 8)}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '4px', marginTop: '1px' }}>
        <span
          style={{
            display: 'inline-block',
            width: '8px',
            height: '8px',
            borderRadius: '50%',
            backgroundColor:
              slot.lockLevel === 'HARD'
                ? '#dc2626'
                : slot.lockLevel === 'SOFT'
                  ? '#f59e0b'
                  : '#9ca3af',
          }}
        />
        <span style={{ fontSize: '9px', opacity: 0.9 }}>
          {formatTime(slot.startTime)} · {slot.durationMinutes}min
        </span>
      </div>
    </div>
  )
}

// Mercure subscription hook — adapted from ScheduleViewPage
function useMercureSubscription(clubId: string | null, scheduleId: string, onEvent: () => void) {
  const eventSourceRef = useRef<EventSource | null>(null)

  useEffect(() => {
    if (!clubId || !scheduleId) return
    if (typeof EventSource === 'undefined') return

    const topic = `club:${clubId}:schedule:${scheduleId}`
    const url = `/.well-known/mercure?topic=${encodeURIComponent(topic)}`

    const es = new EventSource(url)
    eventSourceRef.current = es

    es.onmessage = () => {
      onEvent()
    }

    es.onerror = () => {
      // EventSource auto-reconnects; silent retry
      console.debug('Mercure connection error, will retry')
    }

    return () => {
      es.close()
      eventSourceRef.current = null
    }
  }, [clubId, scheduleId, onEvent])
}

function Toast({ message, onClose }: { message: string; onClose: () => void }) {
  useEffect(() => {
    const timer = setTimeout(onClose, 4000)
    return () => clearTimeout(timer)
  }, [onClose])

  return (
    <div className="fixed bottom-6 right-6 z-50 animate-slide-up rounded-lg bg-success-700 px-4 py-3 text-sm font-medium text-white shadow-xl">
      {message}
    </div>
  )
}

interface PendingEdit {
  slot: ScheduleSlot
  newDayOfWeek: number
  newStartTime: string
  newDurationMinutes: number
  revert: () => void
}

type CalendarEventResizeInfo = { event: EventApi; revert: () => void }

export default function DashboardPage() {
  const club = useAuthStore((s) => s.club)
  const { data: latestSchedule, isLoading: scheduleLoading, error: scheduleError } = useLatestSchedule()
  const scheduleId = latestSchedule?.id ?? ''
  const { data: slots, isLoading: slotsLoading, error: slotsError } = useScheduleSlots(scheduleId)
  const oneTimeMutation = useManualEditOneTime()

  const [pendingEdit, setPendingEdit] = useState<PendingEdit | null>(null)
  const [apiError, setApiError] = useState<string | null>(null)
  const [toast, setToast] = useState<string | null>(null)

  const isLoading = scheduleLoading || slotsLoading
  const error = scheduleError || slotsError

  const handleRefetch = useCallback(() => {
    if (scheduleId) invalidateScheduleQueries(scheduleId)
    queryClient.invalidateQueries({ queryKey: ['latest-schedule'] })
    setToast('Planning mis à jour')
  }, [scheduleId])

  useMercureSubscription(club?.id || null, scheduleId, handleRefetch)

  const calendarEvents = useMemo(() => {
    if (!slots) return []

    const today = new Date()
    const dayOfWeek = today.getDay()
    const mondayOffset = dayOfWeek === 0 ? 1 : 1 - dayOfWeek
    const monday = new Date(today)
    monday.setDate(today.getDate() + mondayOffset)
    monday.setHours(0, 0, 0, 0)

    return slots.map((slot: ScheduleSlot) => {
      const daysFromMonday = slot.dayOfWeek === 0 ? 6 : slot.dayOfWeek - 1
      const eventDate = new Date(monday)
      eventDate.setDate(monday.getDate() + daysFromMonday)

      const timeStr = slot.startTime
      let hours: number
      let minutes: number
      if (timeStr.includes('T')) {
        const d = new Date(timeStr)
        hours = d.getHours()
        minutes = d.getMinutes()
      } else {
        const parts = timeStr.split(':')
        hours = parseInt(parts[0], 10)
        minutes = parseInt(parts[1], 10)
      }

      const start = new Date(eventDate)
      start.setHours(hours, minutes, 0, 0)

      const end = new Date(start)
      end.setMinutes(start.getMinutes() + slot.durationMinutes)

      const color = hashToColor(slot.teamId)

      return {
        id: slot.id,
        title: `${DAY_NAMES[slot.dayOfWeek]} — Slot`,
        start: start.toISOString(),
        end: end.toISOString(),
        backgroundColor: color,
        borderColor: color,
        textColor: '#ffffff',
        extendedProps: { slot, color },
      }
    })
  }, [slots])

  const handleEventDrop = useCallback((info: EventDropArg) => {
    const slot = info.event.extendedProps.slot as ScheduleSlot
    const newStart = info.event.start
    if (!newStart) return

    const newDayOfWeek = dateToDayOfWeek(newStart)
    const newStartTime = dateToTimeString(newStart)

    setApiError(null)
    setPendingEdit({
      slot,
      newDayOfWeek,
      newStartTime,
      newDurationMinutes: slot.durationMinutes,
      revert: info.revert,
    })
  }, [])

  const handleEventResize = useCallback((info: CalendarEventResizeInfo) => {
    const slot = info.event.extendedProps.slot as ScheduleSlot
    const newEnd = info.event.end
    const newStart = info.event.start
    if (!newEnd || !newStart) return

    const newDuration = Math.round((newEnd.getTime() - newStart.getTime()) / 60000)
    const newDayOfWeek = dateToDayOfWeek(newStart)
    const newStartTime = dateToTimeString(newStart)

    setApiError(null)
    setPendingEdit({
      slot,
      newDayOfWeek,
      newStartTime,
      newDurationMinutes: newDuration,
      revert: info.revert,
    })
  }, [])

  const handleConfirmEdit = async () => {
    if (!pendingEdit) return

    try {
      await oneTimeMutation.mutateAsync({
        slotId: pendingEdit.slot.id,
        data: {
          dayOfWeek: pendingEdit.newDayOfWeek,
          startTime: pendingEdit.newStartTime,
          durationMinutes: pendingEdit.newDurationMinutes,
        },
      })
      setPendingEdit(null)
    } catch (err) {
      setApiError(err instanceof Error ? err.message : 'Une erreur est survenue lors de la sauvegarde')
      pendingEdit.revert()
    }
  }

  const handleCancelEdit = () => {
    if (pendingEdit) {
      pendingEdit.revert()
    }
    setPendingEdit(null)
    setApiError(null)
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold text-fg-primary">Dashboard</h2>
          <p className="mt-1 text-sm text-fg-muted">Vue calendrier branchée sur les données backend.</p>
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            className="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 hover:shadow-lg"
          >
            Générer
          </button>
          <Link
            to="/entities"
            className="rounded-lg bg-surface-hover px-4 py-2 text-sm font-medium text-fg-primary ring-1 ring-border-subtle transition hover:bg-surface-hover/80"
          >
            Éditer les entités
          </Link>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1.4fr_0.6fr]">
        <section className="glass rounded-xl p-4 shadow-lg">
          <div className="mb-3 flex items-center justify-between">
            <div>
              <h3 className="text-lg font-semibold text-fg-primary">Semaine type</h3>
              <p className="text-sm text-fg-muted">Vue Lundi-Samedi pour la planification.</p>
            </div>
            <span className="text-xs uppercase tracking-wide text-fg-muted">Week view</span>
          </div>

          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <LoadingSpinner size="lg" />
            </div>
          ) : error ? (
            <div className="rounded-lg border border-error-700 bg-error-900/30 p-4 text-sm text-error-300">
              Erreur lors du chargement du calendrier.
            </div>
          ) : !latestSchedule ? (
            <div className="rounded-lg border border-dashed border-border-subtle p-6 text-center text-sm text-fg-muted">
              Aucun planning disponible. Créez un planning pour voir le calendrier.
            </div>
          ) : (
            <div className="rounded-lg border border-border-subtle bg-bg-deep/50 p-2">
              <FullCalendar
                {...({
                  plugins: [timeGridPlugin, dayGridPlugin, listPlugin],
                  initialView: 'timeGridWeek',
                  headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridWeek,timeGridDay,listWeek',
                  },
                  buttonText: {
                    today: 'Aujourd\'hui',
                    week: 'Semaine',
                    day: 'Jour',
                    list: 'Liste',
                  },
                  slotMinTime: '06:00:00',
                  slotMaxTime: '22:00:00',
                  slotDuration: '00:30:00',
                  allDaySlot: false,
                  weekends: true,
                  events: calendarEvents,
                  height: 'auto',
                  editable: true,
                  eventDurationEditable: true,
                  eventStartEditable: true,
                  eventDrop: handleEventDrop,
                  eventResize: handleEventResize,
                  selectable: false,
                  dayHeaderFormat: { weekday: 'long', day: 'numeric', month: 'short' },
                  slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
                  locale: 'fr',
                  firstDay: 1,
                  eventContent: renderEventContent,
                })}
              />
            </div>
          )}
        </section>

        <aside className="space-y-4">
          <div className="glass rounded-xl p-4 shadow-lg">
            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-fg-muted">Dernier planning</h3>
            {scheduleLoading ? (
              <div className="flex items-center justify-center py-8">
                <LoadingSpinner size="md" />
              </div>
            ) : latestSchedule ? (
              <div className="space-y-2 text-sm">
                <div className="font-semibold text-fg-primary">{latestSchedule.name ?? latestSchedule.id}</div>
                <div className="text-fg-muted">Statut: {latestSchedule.status ?? 'unknown'}</div>
                <div className="text-fg-muted">Score: {latestSchedule.score ?? '—'}</div>
                <Link
                  to={`/schedules/${latestSchedule.id}`}
                  className="inline-flex rounded-md bg-surface-hover px-3 py-2 text-sm font-medium text-fg-primary ring-1 ring-border-subtle transition hover:bg-surface-hover/80"
                >
                  Ouvrir le planning
                </Link>
              </div>
            ) : (
              <p className="text-sm text-fg-muted">Aucun planning disponible.</p>
            )}
          </div>

          <div className="glass rounded-xl p-4 shadow-lg">
            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-fg-muted">Actions rapides</h3>
            <div className="space-y-2 text-sm text-neutral-300">
              <Link
                to="/wizard"
                className="block rounded-md px-3 py-2 transition hover:bg-surface-hover hover:text-fg-primary"
              >
                Retour au wizard
              </Link>
              <Link
                to="/entities"
                className="block rounded-md px-3 py-2 transition hover:bg-surface-hover hover:text-fg-primary"
              >
                Gérer les entités
              </Link>
            </div>
          </div>
        </aside>
      </div>

      {/* Confirmation Modal for Drag-Drop / Resize */}
      {pendingEdit && (
        <>
          <div
            className="fixed inset-0 z-40 bg-black/50 transition-opacity"
            onClick={handleCancelEdit}
            aria-hidden="true"
          />
          <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Confirm schedule change"
          >
            <div className="w-full max-w-md rounded-xl bg-neutral-800 shadow-lg">
              {/* Header */}
              <div className="flex items-center justify-between border-b border-neutral-700 px-6 py-4">
                <h2 className="text-lg font-semibold text-white">Confirmer le déplacement</h2>
                <button
                  type="button"
                  className="rounded-md p-1 text-neutral-500 hover:bg-neutral-700 hover:text-neutral-300"
                  onClick={handleCancelEdit}
                  aria-label="Close"
                >
                  <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              {/* Body */}
              <div className="px-6 py-4">
                {/* Slot identifier */}
                <div className="mb-4 flex items-center gap-2 rounded-lg bg-neutral-700 px-3 py-2">
                  <span className="text-sm font-medium text-neutral-200">
                    Team {pendingEdit.slot.teamId.slice(0, 8)}
                  </span>
                </div>

                {/* Changes summary */}
                <div className="mb-4 space-y-2">
                  <h3 className="text-sm font-medium text-neutral-200">Modifications</h3>
                  <div className="rounded-md bg-neutral-700 p-3 text-sm">
                    <div className="space-y-1">
                      {pendingEdit.slot.dayOfWeek !== pendingEdit.newDayOfWeek && (
                        <div className="flex items-center gap-2">
                          <span className="text-neutral-500">Jour :</span>
                          <span className="text-error-400 line-through">{DAY_NAMES[pendingEdit.slot.dayOfWeek]}</span>
                          <span className="text-neutral-400">→</span>
                          <span className="text-success-400 font-medium">{DAY_NAMES[pendingEdit.newDayOfWeek]}</span>
                        </div>
                      )}
                      {pendingEdit.slot.startTime !== pendingEdit.newStartTime && (
                        <div className="flex items-center gap-2">
                          <span className="text-neutral-500">Horaire :</span>
                          <span className="text-error-400 line-through">{formatTime(pendingEdit.slot.startTime)}</span>
                          <span className="text-neutral-400">→</span>
                          <span className="text-success-400 font-medium">{formatTime(pendingEdit.newStartTime)}</span>
                        </div>
                      )}
                      {pendingEdit.slot.durationMinutes !== pendingEdit.newDurationMinutes && (
                        <div className="flex items-center gap-2">
                          <span className="text-neutral-500">Durée :</span>
                          <span className="text-error-400 line-through">{pendingEdit.slot.durationMinutes}min</span>
                          <span className="text-neutral-400">→</span>
                          <span className="text-success-400 font-medium">{pendingEdit.newDurationMinutes}min</span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>

                {/* Error display */}
                {apiError && (
                  <div className="mb-4 rounded-lg bg-error-900/40 px-3 py-2 text-sm text-error-400">
                    {apiError}
                  </div>
                )}
              </div>

              {/* Footer */}
              <div className="flex items-center justify-between border-t border-neutral-700 px-6 py-3">
                <button
                  type="button"
                  className="rounded-md px-4 py-2 text-sm font-medium text-neutral-300 hover:bg-neutral-700"
                  onClick={handleCancelEdit}
                  disabled={oneTimeMutation.isPending}
                >
                  Annuler
                </button>
                <button
                  type="button"
                  className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50"
                  onClick={handleConfirmEdit}
                  disabled={oneTimeMutation.isPending}
                >
                  {oneTimeMutation.isPending ? (
                    <span className="flex items-center gap-2">
                      <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                      </svg>
                      En cours...
                    </span>
                  ) : (
                    'Confirmer'
                  )}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  )
}

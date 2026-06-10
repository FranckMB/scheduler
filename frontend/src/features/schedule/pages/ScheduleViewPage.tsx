import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useParams } from 'react-router-dom'
import FullCalendar from '@fullcalendar/react'
import timeGridPlugin from '@fullcalendar/timegrid'
import dayGridPlugin from '@fullcalendar/daygrid'
import listPlugin from '@fullcalendar/list'
import type { EventClickArg, EventContentArg, EventDropArg, EventResizeArg } from '@fullcalendar/core'
import { useAuthStore } from '@/features/auth/authStore'
import { useSchedule, useScheduleSlots, invalidateScheduleQueries, useManualEditLock, useManualEditOneTime } from '@/features/schedule/useSchedule'
import { ExportPdfButton } from '@/features/schedule/components/ExportPdfButton'
import SlotDetailModal from '@/features/schedule/SlotDetailModal'
import ManualEditDialog from '@/features/schedule/components/ManualEditDialog'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'
import type { ScheduleSlot, LockLevel } from '@/features/schedule/types'
import { LOCK_LEVEL_CONFIG, DAY_NAMES } from '@/features/schedule/types'

// Color palette for team/venue coloring
const EVENT_COLORS = [
  '#3b82f6', // primary-500
  '#22c55e', // success-500
  '#f59e0b', // warning-500
  '#ef4444', // error-500
  '#8b5cf6', // violet
  '#ec4899', // pink
  '#06b6d4', // cyan
  '#f97316', // orange
  '#14b8a6', // teal
  '#6366f1', // indigo
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

// Mercure subscription hook
function useMercureSubscription(clubId: string | null, scheduleId: string, onEvent: () => void) {
  const eventSourceRef = useRef<EventSource | null>(null)

  useEffect(() => {
    if (!clubId || !scheduleId) return

    const mercureUrl = import.meta.env.VITE_MERCURE_URL || 'http://localhost:3000/.well-known/mercure'
    const topic = `club:${clubId}:schedule:${scheduleId}`
    const url = `${mercureUrl}?topic=${encodeURIComponent(topic)}`

    const es = new EventSource(url)
    eventSourceRef.current = es

    es.onmessage = () => {
      onEvent()
    }

    es.onerror = () => {
      // EventSource auto-reconnects; just log
      console.debug('Mercure connection error, will retry')
    }

    return () => {
      es.close()
      eventSourceRef.current = null
    }
  }, [clubId, scheduleId, onEvent])
}

export default function ScheduleViewPage() {
  const { id } = useParams<{ id: string }>()
  const club = useAuthStore((s) => s.club)
  const [selectedSlot, setSelectedSlot] = useState<ScheduleSlot | null>(null)
  const calendarRef = useRef<FullCalendar>(null)

  // Manual edit drag state
  const [editDialogSlot, setEditDialogSlot] = useState<{
    slot: ScheduleSlot
    originalSlot: ScheduleSlot
    newDayOfWeek: number
    newStartTime: string
    newVenueId: string
  } | null>(null)

  const lockMutation = useManualEditLock()
  const oneTimeMutation = useManualEditOneTime()

  const { data: schedule, isLoading: scheduleLoading } = useSchedule(id || '')
  const { data: slots, isLoading: slotsLoading } = useScheduleSlots(id || '')

  const handleRefetch = useCallback(() => {
    if (id) invalidateScheduleQueries(id)
  }, [id])

  // Mercure subscription
  useMercureSubscription(club?.id || null, id || '', handleRefetch)

  // Map slots to FullCalendar events
  const calendarEvents = useMemo(() => {
    if (!slots) return []

    // Build a reference week date (use next Monday as anchor)
    const today = new Date()
    const dayOfWeek = today.getDay() // 0=Sun, 1=Mon, ...
    const mondayOffset = dayOfWeek === 0 ? 1 : 1 - dayOfWeek
    const monday = new Date(today)
    monday.setDate(today.getDate() + mondayOffset)
    monday.setHours(0, 0, 0, 0)

    return slots.map((slot: ScheduleSlot) => {
      // dayOfWeek: 0=Dimanche, 1=Lundi, ... 6=Samedi
      // Convert to days from Monday: Lundi=1 -> 0, Mardi=2 -> 1, ..., Dimanche=0 -> 6
      const daysFromMonday = slot.dayOfWeek === 0 ? 6 : slot.dayOfWeek - 1

      const eventDate = new Date(monday)
      eventDate.setDate(monday.getDate() + daysFromMonday)

      // Parse startTime (HH:MM:SS or ISO format)
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
        extendedProps: {
          slot,
          color,
        },
      }
    })
  }, [slots])

  // Event click handler
  const handleEventClick = useCallback((info: EventClickArg) => {
    const slot = info.event.extendedProps.slot as ScheduleSlot
    setSelectedSlot(slot)
  }, [])

  // Helper: convert a JS Date to dayOfWeek (0=Dimanche, 1=Lundi, ... 6=Samedi)
  function dateToDayOfWeek(date: Date): number {
    return date.getDay()
  }

  // Helper: convert a JS Date to HH:MM:SS string
  function dateToTimeString(date: Date): string {
    const h = date.getHours().toString().padStart(2, '0')
    const m = date.getMinutes().toString().padStart(2, '0')
    const s = date.getSeconds().toString().padStart(2, '0')
    return `${h}:${m}:${s}`
  }

  // Helper: compute time difference in minutes between two ISO time strings
  function timeDiffMinutes(original: string, updated: string): number {
    const base = new Date()
    const d1 = new Date(base.toDateString() + ' ' + original)
    const d2 = new Date(base.toDateString() + ' ' + updated)
    return Math.abs((d2.getTime() - d1.getTime()) / 60000)
  }

  // Drag drop handler — decides silent lock vs dialog
  const handleEventDrop = useCallback((info: EventDropArg) => {
    const slot = info.event.extendedProps.slot as ScheduleSlot
    const newStart = info.event.start
    if (!newStart) return

    const newDayOfWeek = dateToDayOfWeek(newStart)
    const newStartTime = slot.startTime.includes('T')
      ? dateToTimeString(newStart)
      : dateToTimeString(newStart)

    const dayChanged = slot.dayOfWeek !== newDayOfWeek
    const timeDiff = timeDiffMinutes(slot.startTime, newStartTime)

    if (!dayChanged && timeDiff <= 30) {
      // Silent SOFT lock + one-time update
      lockMutation.mutate({ slotId: slot.id, lockLevel: 'SOFT' })
      oneTimeMutation.mutate({
        slotId: slot.id,
        data: { startTime: newStartTime },
      })
    } else {
      // Show dialog for day change or large time shift
      setEditDialogSlot({
        slot,
        originalSlot: slot,
        newDayOfWeek,
        newStartTime,
        newVenueId: slot.venueId,
      })
    }
  }, [lockMutation, oneTimeMutation])

  // Resize handler — duration change
  const handleEventResize = useCallback((info: EventResizeArg) => {
    const slot = info.event.extendedProps.slot as ScheduleSlot
    const newEnd = info.event.end
    if (!newEnd) return

    const newDuration = Math.round((newEnd.getTime() - info.event.start!.getTime()) / 60000)
    const durationDiff = Math.abs(newDuration - slot.durationMinutes)

    if (durationDiff <= 30) {
      // Silent SOFT lock + one-time update
      lockMutation.mutate({ slotId: slot.id, lockLevel: 'SOFT' })
      oneTimeMutation.mutate({
        slotId: slot.id,
        data: { durationMinutes: newDuration },
      })
    } else {
      // Show dialog for large duration change
      const newStart = info.event.start!
      setEditDialogSlot({
        slot,
        originalSlot: slot,
        newDayOfWeek: dateToDayOfWeek(newStart),
        newStartTime: dateToTimeString(newStart),
        newVenueId: slot.venueId,
      })
    }
  }, [lockMutation, oneTimeMutation])

  // Custom event content renderer
  const renderEventContent = useCallback((eventInfo: EventContentArg) => {
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
  }, [])

  if (!id) {
    return (
      <div className="mx-auto max-w-4xl">
        <div className="rounded-lg bg-white p-6 shadow-sm">
          <p className="text-error-600">Aucun identifiant de planning trouvé.</p>
        </div>
      </div>
    )
  }

  if (scheduleLoading || slotsLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-7xl">
      {/* Header */}
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold text-neutral-900">
            {schedule?.name || `Planning #${id}`}
          </h2>
          {schedule && (
            <p className="mt-1 text-sm text-neutral-500">
              Status: <span className="font-medium text-neutral-700">{schedule.status}</span>
              {schedule.score !== null && (
                <span className="ml-3">
                  Score: <span className="font-medium text-neutral-700">{schedule.score}</span>
                </span>
              )}
            </p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <ExportPdfButton scheduleId={id} />
        </div>
      </div>

      {/* Legend */}
      <div className="mb-3 flex flex-wrap items-center gap-4 rounded-lg bg-white px-4 py-2 shadow-sm">
        <span className="text-sm font-medium text-neutral-600">Lock levels:</span>
        {(['NONE', 'SOFT', 'HARD'] as LockLevel[]).map((level) => {
          const config = LOCK_LEVEL_CONFIG[level]
          return (
            <span key={level} className="inline-flex items-center gap-1.5 text-sm">
              <span className={`h-2.5 w-2.5 rounded-full ${config.bgColor}`} />
              <span className={config.color}>{config.label}</span>
            </span>
          )
        })}
        <span className="ml-auto text-xs text-neutral-400">
          Drag to reschedule · Click for details
        </span>
      </div>

      {/* Calendar */}
      <div className="rounded-lg bg-white p-4 shadow-sm">
        <FullCalendar
          ref={calendarRef}
          plugins={[timeGridPlugin, dayGridPlugin, listPlugin]}
          initialView="timeGridWeek"
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: 'timeGridWeek,timeGridDay,listWeek',
          }}
          buttonText={{
            today: 'Today',
            week: 'Week',
            day: 'Day',
            list: 'List',
          }}
          slotMinTime="06:00:00"
          slotMaxTime="22:00:00"
          slotDuration="00:30:00"
          allDaySlot={false}
          weekends={true}
          events={calendarEvents}
          eventClick={handleEventClick}
          eventContent={renderEventContent}
          height="auto"
          editable={true}
          eventDurationEditable={true}
          eventStartEditable={true}
          eventDrop={handleEventDrop}
          eventResize={handleEventResize}
          selectable={false}
          dayHeaderFormat={{ weekday: 'long', day: 'numeric', month: 'short' }}
          slotLabelFormat={{ hour: '2-digit', minute: '2-digit', hour12: false }}
          locale="fr"
          firstDay={1}
        />
      </div>

      {/* Slot Detail Modal */}
      <SlotDetailModal
        slot={selectedSlot}
        onClose={() => setSelectedSlot(null)}
      />

      {/* Manual Edit Dialog */}
      {editDialogSlot && (
        <ManualEditDialog
          slot={editDialogSlot.slot}
          originalSlot={editDialogSlot.originalSlot}
          newDayOfWeek={editDialogSlot.newDayOfWeek}
          newStartTime={editDialogSlot.newStartTime}
          newVenueId={editDialogSlot.newVenueId}
          onClose={() => setEditDialogSlot(null)}
          onSuccess={() => {
            setEditDialogSlot(null)
            if (id) invalidateScheduleQueries(id)
          }}
        />
      )}
    </div>
  )
}

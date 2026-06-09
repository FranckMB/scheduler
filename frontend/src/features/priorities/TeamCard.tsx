import { useSortable } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { useState, useCallback, useRef } from 'react'
import { useUpdateTeamMinSessions } from './priorityApi'
import type { Team } from './types'

interface TeamCardProps {
  team: Team
}

export function TeamCard({ team }: TeamCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: team.id,
  })

  const { mutate: updateMinSessions } = useUpdateTeamMinSessions()
  const [editingSessions, setEditingSessions] = useState(false)
  const [draftValue, setDraftValue] = useState(team.minSessionsOverride ?? '')
  const inputRef = useRef<HTMLInputElement>(null)

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  const handleSessionsBlur = useCallback(() => {
    setEditingSessions(false)
    const val = draftValue === '' ? null : Number(draftValue)
    if (val !== team.minSessionsOverride && (val === null || (Number.isFinite(val) && val >= 0))) {
      updateMinSessions({ id: team.id, minSessionsOverride: val })
    } else {
      setDraftValue(team.minSessionsOverride ?? '')
    }
  }, [draftValue, team.minSessionsOverride, team.id, updateMinSessions])

  const handleSessionsKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter') {
        handleSessionsBlur()
      } else if (e.key === 'Escape') {
        setEditingSessions(false)
        setDraftValue(team.minSessionsOverride ?? '')
      }
    },
    [handleSessionsBlur, team.minSessionsOverride]
  )

  const startEditing = () => {
    setEditingSessions(true)
    setDraftValue(team.minSessionsOverride ?? '')
    setTimeout(() => inputRef.current?.focus(), 0)
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm hover:shadow-md transition-shadow cursor-grab active:cursor-grabbing"
      {...attributes}
      {...listeners}
    >
      <div className="flex items-start justify-between gap-2">
        <h4 className="font-semibold text-neutral-900 text-sm leading-tight">{team.name}</h4>
        {team.gender && (
          <span className="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
            {team.gender}
          </span>
        )}
      </div>

      <div className="mt-2 flex items-center justify-between">
        <span className="text-xs text-neutral-500">
          {team.sessionsPerWeek} session{team.sessionsPerWeek !== 1 ? 's' : ''}/wk
        </span>

        <div className="flex items-center gap-1">
          <span className="text-xs text-neutral-400">min:</span>
          {editingSessions ? (
            <input
              ref={inputRef}
              type="number"
              min={0}
              value={draftValue}
              onChange={(e) => setDraftValue(e.target.value === '' ? '' : Number(e.target.value))}
              onBlur={handleSessionsBlur}
              onKeyDown={handleSessionsKeyDown}
              className="w-10 rounded border border-neutral-300 px-1 py-0.5 text-xs text-center focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
              onClick={(e) => e.stopPropagation()}
              onMouseDown={(e) => e.stopPropagation()}
            />
          ) : (
            <button
              type="button"
              onClick={startEditing}
              className="w-10 rounded border border-dashed border-neutral-300 px-1 py-0.5 text-xs text-center text-neutral-600 hover:border-primary-400 hover:text-primary-600 transition-colors"
            >
              {team.minSessionsOverride ?? '—'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

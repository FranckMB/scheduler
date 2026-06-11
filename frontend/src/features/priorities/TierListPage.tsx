import { useMemo, useState, useCallback, useRef } from 'react'
import {
  DndContext,
  type DragEndEvent,
  DragOverlay,
  type DragStartEvent,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import { useTeams, usePriorityTiers, useUpdateTeamTier } from './priorityApi'
import { TierColumn } from './TierColumn'
import { TeamCard } from './TeamCard'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'
import { ErrorBoundary } from '@/shared/components/ErrorBoundary'
import type { Team } from './types'

function TierListContent() {
  const { data: teams, isLoading: teamsLoading, error: teamsError } = useTeams()
  const { data: tiers, isLoading: tiersLoading, error: tiersError } = usePriorityTiers()
  const { mutate: updateTeamTier } = useUpdateTeamTier()

  const [activeTeam, setActiveTeam] = useState<Team | null>(null)
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    })
  )

  const teamsByTier = useMemo(() => {
    if (!teams || !tiers) return new Map<number, Team[]>()
    const map = new Map<number, Team[]>()
    for (const tier of tiers) {
      map.set(tier.id, [])
    }
    for (const team of teams) {
      const list = map.get(team.priorityTierId)
      if (list) {
        list.push(team)
      }
    }
    return map
  }, [teams, tiers])

  const handleDragStart = useCallback((event: DragStartEvent) => {
    const { active } = event
    const team = teams?.find((t) => t.id === active.id)
    if (team) {
      setActiveTeam(team)
    }
  }, [teams])

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event
      setActiveTeam(null)

      if (!over) return

      const teamId = active.id as string
      const overId = over.id as string

      // Extract tier ID from droppable id (format: "tier-{id}")
      const tierIdMatch = overId.match(/^tier-(\d+)$/)
      if (!tierIdMatch) return

      const newTierId = Number(tierIdMatch[1])
      const team = teams?.find((t) => t.id === teamId)
      if (!team || team.priorityTierId === newTierId) return

      // Debounced save
      if (saveTimerRef.current) {
        clearTimeout(saveTimerRef.current)
      }
      saveTimerRef.current = setTimeout(() => {
        updateTeamTier({ id: teamId, data: { priorityTierId: newTierId } })
      }, 300)
    },
    [teams, updateTeamTier]
  )

  if (teamsLoading || tiersLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (teamsError || tiersError) {
    return (
      <div className="glass rounded-xl border border-error-700/50 bg-error-900/40 p-6 text-center">
        <p className="text-error-300 font-medium">Failed to load data</p>
        <p className="text-error-400 text-sm mt-1">
          {(teamsError ?? tiersError)?.message}
        </p>
      </div>
    )
  }

  if (!tiers || !teams) {
    return (
      <div className="glass rounded-xl border border-border-subtle p-6 text-center text-fg-muted">
        No data available
      </div>
    )
  }

  return (
    <DndContext sensors={sensors} onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        {tiers.map((tier) => (
          <TierColumn key={tier.id} tier={tier} teams={teamsByTier.get(tier.id) ?? []} />
        ))}
      </div>

      <DragOverlay>
        {activeTeam ? (
          <div className="glass-strong rounded-xl border border-primary-600/50 p-3 shadow-lg rotate-2">
            <TeamCard team={activeTeam} />
          </div>
        ) : null}
      </DragOverlay>
    </DndContext>
  )
}

export default function TierListPage() {
  return (
    <div className="mx-auto max-w-7xl">
      <div className="mb-6">
        <h2 className="text-2xl font-bold text-fg-primary">Priority Tiers</h2>
        <p className="mt-1 text-sm text-fg-muted">
          Drag and drop teams between tiers to adjust scheduling priority. Changes auto-save.
        </p>
      </div>

      <ErrorBoundary>
        <TierListContent />
      </ErrorBoundary>
    </div>
  )
}

import { useDroppable } from '@dnd-kit/core'
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { TeamCard } from './TeamCard'
import { TIER_COLORS, TIER_BADGE_COLORS } from './types'
import type { Team, PriorityTier } from './types'

interface TierColumnProps {
  tier: PriorityTier
  teams: Team[]
}

export function TierColumn({ tier, teams }: TierColumnProps) {
  const { setNodeRef } = useDroppable({
    id: `tier-${tier.id}`,
  })

  const colorClass = TIER_COLORS[tier.label] ?? 'bg-neutral-800 text-neutral-200 border-neutral-600'
  const badgeClass = TIER_BADGE_COLORS[tier.label] ?? 'bg-neutral-500'

  return (
    <div className="glass flex flex-col rounded-xl border border-border-subtle overflow-hidden">
      {/* Column header */}
      <div className={`flex items-center gap-2 border-b border-border-subtle px-4 py-3 ${colorClass}`}>
        <span className={`flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold text-white ${badgeClass}`}>
          {tier.label}
        </span>
        <span className="font-semibold text-sm">{tier.name}</span>
        <span className="ml-auto text-xs opacity-70">w:{tier.orToolsWeight}</span>
        <span className="rounded-full bg-white/60 px-2 py-0.5 text-xs font-medium">
          {teams.length}
        </span>
      </div>

      {/* Droppable area */}
      <div ref={setNodeRef} className="flex flex-1 flex-col gap-2 p-3 min-h-[120px]">
        <SortableContext items={teams.map((t) => t.id)} strategy={verticalListSortingStrategy}>
          {teams.map((team) => (
            <TeamCard key={team.id} team={team} />
          ))}
        </SortableContext>

        {teams.length === 0 && (
          <div className="flex h-24 items-center justify-center rounded-lg border-2 border-dashed border-border-subtle text-sm text-fg-disabled">
            Drop teams here
          </div>
        )}
      </div>
    </div>
  )
}

import { useState, useCallback } from 'react'
import {
  DndContext,
  type DragEndEvent,
  DragOverlay,
  type DragStartEvent,
  PointerSensor,
  useSensor,
  useSensors,
  useDroppable,
} from '@dnd-kit/core'
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import {
  useWizardStore,
  TIER_ORDER,
  type TierLabel,
  type TeamData,
} from '@/features/wizard/wizardStore'

const TIER_COLORS: Record<TierLabel, string> = {
  S: 'bg-rose-100 text-rose-800 border-rose-300',
  A: 'bg-orange-100 text-orange-800 border-orange-300',
  B: 'bg-yellow-100 text-yellow-800 border-yellow-300',
  C: 'bg-green-100 text-green-800 border-green-300',
  D: 'bg-blue-100 text-blue-800 border-blue-300',
}

const TIER_BADGE_COLORS: Record<TierLabel, string> = {
  S: 'bg-rose-500',
  A: 'bg-orange-500',
  B: 'bg-yellow-500',
  C: 'bg-green-500',
  D: 'bg-blue-500',
}

const TIER_DESCRIPTIONS: Record<TierLabel, string> = {
  S: 'Priorite absolue',
  A: 'Haute priorite',
  B: 'Priorite moyenne',
  C: 'Priorite basse',
  D: 'Dernier recours',
}

export default function TierListStep() {
  const { data, setTeamTier } = useWizardStore()
  const [activeTeam, setActiveTeam] = useState<TeamData | null>(null)

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    })
  )

  const teamsByTier = useCallback(() => {
    const map = new Map<TierLabel, TeamData[]>()
    for (const tier of TIER_ORDER) {
      map.set(tier, [])
    }
    for (const team of data.teams) {
      const list = map.get(team.tier)
      if (list) {
        list.push(team)
      }
    }
    return map
  }, [data.teams])

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const { active } = event
      const team = data.teams.find((t) => t.id === active.id)
      if (team) {
        setActiveTeam(team)
      }
    },
    [data.teams]
  )

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event
      setActiveTeam(null)

      if (!over) return

      const teamId = active.id as string
      const overId = over.id as string

      // Extract tier from droppable id (format: "tier-{label}")
      const tierMatch = overId.match(/^tier-(S|A|B|C|D)$/)
      if (!tierMatch) return

      const newTier = tierMatch[1] as TierLabel
      const team = data.teams.find((t) => t.id === teamId)
      if (!team || team.tier === newTier) return

      setTeamTier(teamId, newTier)
    },
    [data.teams, setTeamTier]
  )

  if (data.teams.length === 0) {
    return (
      <div className="space-y-6">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Tier List</h2>
          <p className="text-sm text-neutral-500">
            Classez les equipes par priorite de planification
          </p>
        </div>
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Ajoutez d&apos;abord des equipes (etape 2) avant de les classer.</p>
        </div>
      </div>
    )
  }

  const tierMap = teamsByTier()

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-neutral-900">Tier List</h2>
        <p className="text-sm text-neutral-500">
          Glissez-deposez les equipes entre les tiers pour ajuster leur priorite
        </p>
      </div>

      <DndContext sensors={sensors} onDragStart={handleDragStart} onDragEnd={handleDragEnd}>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          {TIER_ORDER.map((tier) => (
            <TierColumn
              key={tier}
              tier={tier}
              teams={tierMap.get(tier) ?? []}
            />
          ))}
        </div>

        <DragOverlay>
          {activeTeam ? (
            <div className="rounded-lg border border-primary-300 bg-white p-3 shadow-lg rotate-2">
              <TeamCard team={activeTeam} />
            </div>
          ) : null}
        </DragOverlay>
      </DndContext>
    </div>
  )
}

interface TierColumnProps {
  tier: TierLabel
  teams: TeamData[]
}

function TierColumn({ tier, teams }: TierColumnProps) {
  const { setNodeRef } = useDroppable({
    id: `tier-${tier}`,
  })

  const colorClass = TIER_COLORS[tier]
  const badgeClass = TIER_BADGE_COLORS[tier]

  return (
    <div className="flex flex-col rounded-xl border border-neutral-200 bg-neutral-50 overflow-hidden">
      <div className={`flex items-center gap-2 border-b border-neutral-200 px-4 py-3 ${colorClass}`}>
        <span className={`flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold text-white ${badgeClass}`}>
          {tier}
        </span>
        <div className="flex flex-col">
          <span className="font-semibold text-sm">{TIER_DESCRIPTIONS[tier]}</span>
          <span className="text-xs opacity-70">{teams.length} equipe{teams.length > 1 ? 's' : ''}</span>
        </div>
      </div>

      <div ref={setNodeRef} className="flex flex-1 flex-col gap-2 p-3 min-h-[120px]">
        <SortableContext items={teams.map((t) => t.id)} strategy={verticalListSortingStrategy}>
          {teams.map((team) => (
            <TeamCard key={team.id} team={team} />
          ))}
        </SortableContext>

        {teams.length === 0 && (
          <div className="flex h-24 items-center justify-center rounded-lg border-2 border-dashed border-neutral-300 text-sm text-neutral-400">
            Deposer ici
          </div>
        )}
      </div>
    </div>
  )
}

interface TeamCardProps {
  team: TeamData
}

function TeamCard({ team }: TeamCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: team.id,
  })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
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
        <h4 className="font-semibold text-neutral-900 text-sm leading-tight">
          {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
        </h4>
        {team.gender && (
          <span className="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
            {team.gender}
          </span>
        )}
      </div>

      <div className="mt-2 flex items-center gap-2 text-xs text-neutral-500">
        {team.level && <span>{team.level}</span>}
        {team.size > 0 && <span>{team.size} joueurs</span>}
        {team.is_competition && (
          <span className="rounded bg-rose-50 px-1.5 py-0.5 text-rose-600">Competition</span>
        )}
      </div>
    </div>
  )
}

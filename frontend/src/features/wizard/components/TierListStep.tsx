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
  S: 'bg-rose-900/40 text-rose-300 border-rose-700',
  A: 'bg-orange-900/40 text-orange-300 border-orange-700',
  B: 'bg-yellow-900/40 text-yellow-300 border-yellow-700',
  C: 'bg-green-900/40 text-green-300 border-green-700',
  D: 'bg-blue-900/40 text-blue-300 border-blue-700',
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
          <h2 className="text-xl font-bold text-fg-primary">Tier List</h2>
          <p className="text-sm text-fg-muted">
            Classez les equipes par priorite de planification
          </p>
        </div>
        <div className="glass rounded-lg border-2 border-dashed border-border-subtle p-8 text-center">
          <p className="text-fg-muted">Ajoutez d&apos;abord des equipes (etape 2) avant de les classer.</p>
        </div>
      </div>
    )
  }

  const tierMap = teamsByTier()

  return (
    <div className="space-y-6">
      <div>
          <h2 className="text-xl font-bold text-fg-primary">Tier List</h2>
          <p className="text-sm text-fg-muted">
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
            <div className="glass-strong rounded-lg border border-primary-600/50 p-3 shadow-lg rotate-2">
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
    <div className="glass flex flex-col rounded-xl border border-border-subtle overflow-hidden">
      <div className={`flex items-center gap-2 border-b border-border-subtle px-4 py-3 ${colorClass}`}>
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
          <div className="flex h-24 items-center justify-center rounded-lg border-2 border-dashed border-border-subtle text-sm text-fg-disabled">
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

  const coaches = useWizardStore((s) => s.data.coaches)
  const firstCoach = coaches.find((c) => c.teamIds.includes(team.id))

  const genderLabel = team.gender === 'M' ? 'Masculin' : team.gender === 'F' ? 'Féminin' : ''

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="glass rounded-lg border border-border-subtle p-3 shadow-sm hover:shadow-md transition-shadow cursor-grab active:cursor-grabbing"
      {...attributes}
      {...listeners}
    >
      <h4 className="font-semibold text-fg-primary text-sm leading-tight">
        {team.name || <span className="text-fg-disabled italic">Sans nom</span>}
      </h4>

      <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-muted">
        {genderLabel && <span>{genderLabel}</span>}
        {team.level && <span>{team.level}</span>}
        <span>{firstCoach ? firstCoach.name : 'Aucun coach'}</span>
      </div>
    </div>
  )
}

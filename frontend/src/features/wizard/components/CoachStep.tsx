import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  type CoachData,
} from '@/features/wizard/wizardStore'

export default function CoachStep() {
  const { data, addCoach, updateCoach, removeCoach, autoSave } = useWizardStore()
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
  }, [data.coaches, triggerSave])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Coachs</h2>
          <p className="text-sm text-neutral-500">
            Ajoutez les coachs, assignez-les aux equipes et indiquez s'ils jouent aussi
          </p>
        </div>
        <button
          type="button"
          onClick={addCoach}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
          + Ajouter un coach
        </button>
      </div>

      {data.coaches.length === 0 && (
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Aucun coach ajoute. Cliquez sur le bouton ci-dessus pour commencer.</p>
        </div>
      )}

      {data.coaches.map((coach, index) => (
        <CoachCard
          key={coach.id}
          coach={coach}
          index={index}
          teams={data.teams}
          onUpdate={(updates) => updateCoach(coach.id, updates)}
          onRemove={() => removeCoach(coach.id)}
        />
      ))}
    </div>
  )
}

interface CoachCardProps {
  coach: CoachData
  index: number
  teams: { id: string; name: string }[]
  onUpdate: (updates: Partial<CoachData>) => void
  onRemove: () => void
}

function CoachCard({ coach, index, teams, onUpdate, onRemove }: CoachCardProps) {
  const [expanded, setExpanded] = useState(false)

  const toggleTeamAssignment = (teamId: string) => {
    if (coach.teamIds.includes(teamId)) {
      onUpdate({ teamIds: coach.teamIds.filter((tid) => tid !== teamId) })
    } else {
      onUpdate({ teamIds: [...coach.teamIds, teamId] })
    }
  }

  const updateIsPlayer = (checked: boolean) => {
    onUpdate({ is_player: checked, player_team_id: checked ? coach.player_team_id : '' })
  }

  return (
    <div className="rounded-lg border border-neutral-200 bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
        <div className="flex items-center gap-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">
            {index + 1}
          </span>
          <span className="font-medium text-neutral-900">
            {coach.name || <span className="text-neutral-400 italic">Sans nom</span>}
          </span>
          {coach.is_player && (
            <span className="rounded-full bg-success-50 px-2 py-0.5 text-xs text-success-600">
              Joueur
            </span>
          )}
          {coach.teamIds.length > 0 && (
            <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
              {coach.teamIds.length} equipe{coach.teamIds.length > 1 ? 's' : ''}
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
          <button
            type="button"
            onClick={onRemove}
            className="rounded p-1 text-error-500 hover:bg-error-50"
            aria-label="Remove coach"
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
        </div>
      </div>

      <div className="px-4 py-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Nom</label>
            <input
              type="text"
              value={coach.name}
              onChange={(e) => onUpdate({ name: e.target.value })}
              placeholder="Nom du coach"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
          <div className="flex items-end gap-3">
            <label className="flex items-center gap-2 pb-2">
              <input
                type="checkbox"
                checked={coach.is_player}
                onChange={(e) => updateIsPlayer(e.target.checked)}
                className="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              />
              <span className="text-sm text-neutral-700">Est joueur</span>
            </label>
            {coach.is_player && (
              <div className="flex-1">
                <label className="mb-1 block text-xs font-medium text-neutral-600">Equipe joueur</label>
                <select
                  value={coach.player_team_id}
                  onChange={(e) => onUpdate({ player_team_id: e.target.value })}
                  className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
                >
                  <option value="">-- Selectionner --</option>
                  {teams.map((team) => (
                    <option key={team.id} value={team.id}>
                      {team.name || 'Sans nom'}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
        </div>
      </div>

      {expanded && (
        <div className="border-t border-neutral-100 px-4 py-3">
          <h4 className="mb-2 text-sm font-semibold text-neutral-700">Assigner aux equipes</h4>
          {teams.length === 0 ? (
            <p className="text-xs text-neutral-400">Aucune equipe disponible. Ajoutez d&apos;abord des equipes.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {teams.map((team) => {
                const isAssigned = coach.teamIds.includes(team.id)
                return (
                  <button
                    key={team.id}
                    type="button"
                    onClick={() => toggleTeamAssignment(team.id)}
                    className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                      isAssigned
                        ? 'bg-primary-600 text-white'
                        : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'
                    }`}
                  >
                    {team.name || 'Sans nom'}
                  </button>
                )
              })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

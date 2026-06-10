import { useState, useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  type TeamData,
} from '@/features/wizard/wizardStore'

const LEVEL_OPTIONS = ['Regional', 'Depart', 'Loisir', 'National', 'Elite']

export default function TeamStep() {
  const { data, addTeam, updateTeam, removeTeam, autoSave } = useWizardStore()
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
  }, [data.teams, triggerSave])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-bold text-neutral-900">Equipes</h2>
          <p className="text-sm text-neutral-500">
            Ajoutez les equipes avec leur niveau, genre et effectif
          </p>
        </div>
        <button
          type="button"
          onClick={addTeam}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
          + Ajouter une equipe
        </button>
      </div>

      {data.teams.length === 0 && (
        <div className="rounded-lg border-2 border-dashed border-neutral-200 bg-neutral-50 p-8 text-center">
          <p className="text-neutral-500">Aucune equipe ajoutee. Cliquez sur le bouton ci-dessus pour commencer.</p>
        </div>
      )}

      {data.teams.map((team, index) => (
        <TeamCard
          key={team.id}
          team={team}
          index={index}
          onUpdate={(updates) => updateTeam(team.id, updates)}
          onRemove={() => removeTeam(team.id)}
        />
      ))}
    </div>
  )
}

interface TeamCardProps {
  team: TeamData
  index: number
  onUpdate: (updates: Partial<TeamData>) => void
  onRemove: () => void
}

function TeamCard({ team, index, onUpdate, onRemove }: TeamCardProps) {
  const [expanded, setExpanded] = useState(false)

  return (
    <div className="rounded-lg border border-neutral-200 bg-white shadow-sm">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
        <div className="flex items-center gap-3">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100 text-sm font-bold text-primary-700">
            {index + 1}
          </span>
          <span className="font-medium text-neutral-900">
            {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
          </span>
          {team.gender && (
            <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
              {team.gender === 'M' ? 'Masculin' : 'Feminin'}
            </span>
          )}
          {team.level && (
            <span className="rounded-full bg-info-50 px-2 py-0.5 text-xs text-info-600">
              {team.level}
            </span>
          )}
          {team.is_competition && (
            <span className="rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-600">
              Competition
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
            aria-label="Remove team"
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

      {/* Form */}
      <div className="px-4 py-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Nom de l&apos;equipe</label>
            <input
              type="text"
              value={team.name}
              onChange={(e) => onUpdate({ name: e.target.value })}
              placeholder="Ex: U15 Elite"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Niveau</label>
            <select
              value={team.level}
              onChange={(e) => onUpdate({ level: e.target.value })}
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            >
              <option value="">-- Selectionner --</option>
              {LEVEL_OPTIONS.map((level) => (
                <option key={level} value={level}>
                  {level}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Genre</label>
            <select
              value={team.gender}
              onChange={(e) => onUpdate({ gender: e.target.value as TeamData['gender'] })}
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            >
              <option value="">-- Selectionner --</option>
              <option value="M">Masculin</option>
              <option value="F">Feminin</option>
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Effectif</label>
            <input
              type="number"
              min={0}
              value={team.size || ''}
              onChange={(e) => onUpdate({ size: parseInt(e.target.value, 10) || 0 })}
              placeholder="0"
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            />
          </div>
        </div>
      </div>

      {/* Expanded details */}
      {expanded && (
        <div className="border-t border-neutral-100 px-4 py-3">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={team.is_competition}
                onChange={(e) => onUpdate({ is_competition: e.target.checked })}
                className="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              />
              <span className="text-sm text-neutral-700">Competition</span>
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={team.is_junior}
                onChange={(e) => onUpdate({ is_junior: e.target.checked })}
                className="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              />
              <span className="text-sm text-neutral-700">Jeunes (Junior)</span>
            </label>
            <div>
              <label className="mb-1 block text-xs font-medium text-neutral-600">Sessions par semaine</label>
              <input
                type="number"
                min={1}
                max={7}
                value={team.sessions_count}
                onChange={(e) =>
                  onUpdate({ sessions_count: Math.max(1, parseInt(e.target.value, 10) || 1) })
                }
                className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

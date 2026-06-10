import { useCallback, useEffect, useRef } from 'react'
import {
  useWizardStore,
  type FilterLevel,
  type FilterJunior,
  type Gender,
} from '@/features/wizard/wizardStore'

const LEVEL_OPTIONS: { value: FilterLevel; label: string }[] = [
  { value: 'all', label: 'Tous les niveaux' },
  { value: 'Regional', label: 'Regional' },
  { value: 'Depart', label: 'Departemental' },
  { value: 'Loisir', label: 'Loisir' },
  { value: 'National', label: 'National' },
  { value: 'Elite', label: 'Elite' },
]

export default function FilterStep() {
  const { data, setFilter, autoSave } = useWizardStore()
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
  }, [data.filters, triggerSave])

  const { filters, teams } = data

  // Apply filters to teams
  const filteredTeams = teams.filter((team) => {
    if (filters.gender !== 'all' && team.gender !== filters.gender) return false
    if (filters.level !== 'all' && team.level !== filters.level) return false
    if (filters.is_junior !== 'all') {
      const teamIsJunior = team.is_junior ? 'junior' : 'senior'
      if (teamIsJunior !== filters.is_junior) return false
    }
    return true
  })

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-neutral-900">Filtres</h2>
        <p className="text-sm text-neutral-500">
          Filtrez les equipes par genre, niveau et categorie
        </p>
      </div>

      {/* Filter controls */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-4 text-sm font-semibold text-neutral-700">Filtres actifs</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {/* Gender filter */}
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Genre</label>
            <div className="flex gap-2">
              {([['all', 'Tous'], ['M', 'Masculin'], ['F', 'Feminin']] as [Gender | 'all', string][]).map(
                ([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setFilter({ gender: value })}
                    className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      filters.gender === value
                        ? 'bg-primary-600 text-white'
                        : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'
                    }`}
                  >
                    {label}
                  </button>
                )
              )}
            </div>
          </div>

          {/* Level filter */}
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Niveau</label>
            <select
              value={filters.level}
              onChange={(e) => setFilter({ level: e.target.value as FilterLevel })}
              className="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
            >
              {LEVEL_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </div>

          {/* Junior/Senior filter */}
          <div>
            <label className="mb-1 block text-xs font-medium text-neutral-600">Categorie</label>
            <div className="flex gap-2">
              {([['all', 'Tous'], ['junior', 'Jeunes'], ['senior', 'Seniors']] as [FilterJunior, string][]).map(
                ([value, label]) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setFilter({ is_junior: value })}
                    className={`flex-1 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                      filters.is_junior === value
                        ? 'bg-primary-600 text-white'
                        : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'
                    }`}
                  >
                    {label}
                  </button>
                )
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Filtered teams preview */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">
          Resultats : {filteredTeams.length} equipe{filteredTeams.length > 1 ? 's' : ''}
          {filteredTeams.length !== teams.length && ` sur ${teams.length}`}
        </h3>

        {filteredTeams.length === 0 ? (
          <p className="text-sm text-neutral-400">Aucune equipe ne correspond aux filtres.</p>
        ) : (
          <div className="space-y-2">
            {filteredTeams.map((team) => (
              <div
                key={team.id}
                className="flex items-center justify-between rounded-md bg-neutral-50 px-3 py-2"
              >
                <div className="flex items-center gap-3">
                  <span className="font-medium text-neutral-900 text-sm">
                    {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
                  </span>
                  {team.gender && (
                    <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
                      {team.gender === 'M' ? 'M' : 'F'}
                    </span>
                  )}
                  {team.level && (
                    <span className="rounded-full bg-info-50 px-2 py-0.5 text-xs text-info-600">
                      {team.level}
                    </span>
                  )}
                  {team.is_junior ? (
                    <span className="rounded-full bg-purple-50 px-2 py-0.5 text-xs text-purple-600">
                      Jeunes
                    </span>
                  ) : (
                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-500">
                      Seniors
                    </span>
                  )}
                </div>
                <span className="text-xs text-neutral-400">
                  Tier {team.tier}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

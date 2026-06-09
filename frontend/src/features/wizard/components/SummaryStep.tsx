import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  useWizardStore,
  DAYS,
  DAY_LABELS,
} from '@/features/wizard/wizardStore'
import { apiClient } from '@/shared/api/client'

export default function SummaryStep() {
  const { data, resetWizard } = useWizardStore()
  const navigate = useNavigate()
  const [isGenerating, setIsGenerating] = useState(false)
  const [generateError, setGenerateError] = useState<string | null>(null)
  const [generateSuccess, setGenerateSuccess] = useState(false)

  const availableSlots = Object.entries(data.venues.slots)
    .filter(([, v]) => v)
    .map(([key]) => {
      const [day, hour, minute] = key.split('-')
      return { day: day as (typeof DAYS)[number], hour: parseInt(hour, 10), minute: parseInt(minute, 10) }
    })

  // Group slots by day for summary
  const slotsByDay = DAYS.reduce<Record<string, typeof availableSlots>>((acc, day) => {
    acc[day] = availableSlots.filter((s) => s.day === day)
    return acc
  }, {})

  const handleGenerate = useCallback(async () => {
    setIsGenerating(true)
    setGenerateError(null)
    setGenerateSuccess(false)

    try {
      // 1. Create the schedule first
      const schedule = await apiClient
        .post('schedules', { json: { name: 'Planning' } })
        .json<{ id: string }>()
      const scheduleId = schedule.id

      // 2. Generate the schedule with wizard data
      await apiClient.post(`schedules/${scheduleId}/generate`, {
        json: {
          venues: {
            slots: availableSlots,
            closures: data.venues.closures,
          },
          coaches: data.coaches,
          teams: data.teams,
        },
      })

      setGenerateSuccess(true)

      // 3. Redirect to the schedule view
      navigate(`/schedules/${scheduleId}`)
    } catch (err) {
      setGenerateError(err instanceof Error ? err.message : 'Échec de la génération')
    } finally {
      setIsGenerating(false)
    }
  }, [availableSlots, data.venues.closures, data.coaches, data.teams, navigate])

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-neutral-900">Résumé de la configuration</h2>
        <p className="text-sm text-neutral-500">
          Vérifiez les données avant de générer le planning
        </p>
      </div>

      {/* Venues summary */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">
          Salles — {availableSlots.length} créneau{availableSlots.length > 1 ? 'x' : ''} disponible
          {availableSlots.length > 1 ? 's' : ''}
        </h3>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {DAYS.map((day) => (
            <div key={day} className="rounded-md bg-neutral-50 p-2">
              <p className="mb-1 text-xs font-semibold text-neutral-600">{DAY_LABELS[day]}</p>
              <p className="text-lg font-bold text-primary-600">
                {slotsByDay[day]?.length || 0}
              </p>
              <p className="text-xs text-neutral-400">créneaux</p>
            </div>
          ))}
        </div>
        {data.venues.closures.length > 0 && (
          <div className="mt-3">
            <p className="text-xs font-medium text-neutral-600">Fermetures :</p>
            <div className="mt-1 flex flex-wrap gap-1">
              {data.venues.closures.map((date) => (
                <span
                  key={date}
                  className="rounded bg-warning-50 px-2 py-0.5 text-xs text-warning-600"
                >
                  {date}
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Coaches summary */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">
          Coaches — {data.coaches.length} coach{data.coaches.length > 1 ? 's' : ''}
        </h3>
        {data.coaches.length === 0 ? (
          <p className="text-sm text-neutral-400">Aucun coach configuré</p>
        ) : (
          <div className="space-y-2">
            {data.coaches.map((coach) => (
              <div key={coach.id} className="flex items-center justify-between rounded-md bg-neutral-50 px-3 py-2">
                <div>
                  <p className="text-sm font-medium text-neutral-900">
                    {coach.name || <span className="text-neutral-400 italic">Sans nom</span>}
                  </p>
                  {(coach.email || coach.phone) && (
                    <p className="text-xs text-neutral-500">
                      {[coach.email, coach.phone].filter(Boolean).join(' • ')}
                    </p>
                  )}
                </div>
                {coach.unavailabilities.length > 0 && (
                  <span className="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-600">
                    {coach.unavailabilities.length} indisponibilité
                    {coach.unavailabilities.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Teams summary */}
      <div className="rounded-lg border border-neutral-200 bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold text-neutral-700">
          Équipes — {data.teams.length} équipe{data.teams.length > 1 ? 's' : ''}
        </h3>
        {data.teams.length === 0 ? (
          <p className="text-sm text-neutral-400">Aucune équipe configurée</p>
        ) : (
          <div className="space-y-2">
            {data.teams.map((team) => (
              <div key={team.id} className="rounded-md bg-neutral-50 px-3 py-2">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-neutral-900">
                      {team.name || <span className="text-neutral-400 italic">Sans nom</span>}
                    </p>
                    <p className="text-xs text-neutral-500">
                      {team.playerCount} joueur{team.playerCount > 1 ? 's' : ''}
                      {team.level ? ` • ${team.level}` : ''}
                    </p>
                  </div>
                  {team.constraints.length > 0 && (
                    <span className="rounded-full bg-info-50 px-2 py-0.5 text-xs text-info-600">
                      {team.constraints.length} contrainte{team.constraints.length > 1 ? 's' : ''}
                    </span>
                  )}
                </div>
                {team.constraints.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {team.constraints.map((c) => (
                      <span
                        key={c.id}
                        className={`rounded px-1.5 py-0.5 text-xs ${
                          c.type === 'fixed'
                            ? 'bg-error-50 text-error-600'
                            : c.type === 'forbidden'
                              ? 'bg-warning-50 text-warning-600'
                              : 'bg-success-50 text-success-600'
                        }`}
                      >
                        {c.type === 'fixed' ? 'Fixe' : c.type === 'forbidden' ? 'Exclu' : 'Préféré'}{' '}
                        {c.day ? DAY_LABELS[c.day] : ''}{' '}
                        {c.startHour != null ? `${c.startHour}h-${c.endHour}h` : ''}
                      </span>
                    ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Generate button */}
      <div className="flex flex-col items-center gap-3 rounded-lg border-2 border-primary-200 bg-primary-50 p-6">
        <h3 className="text-lg font-bold text-primary-900">Générer le planning</h3>
        <p className="text-sm text-primary-700">
          Le moteur de planification va créer un planning optimal basé sur vos données.
        </p>

        {generateError && (
          <div className="w-full rounded-md bg-error-50 p-3 text-sm text-error-600" role="alert">
            {generateError}
          </div>
        )}

        {generateSuccess && (
          <div className="w-full rounded-md bg-success-50 p-3 text-sm text-success-600" role="alert">
            Planning généré avec succès !
          </div>
        )}

        <button
          type="button"
          onClick={handleGenerate}
          disabled={isGenerating}
          className="rounded-lg bg-primary-600 px-8 py-3 text-base font-bold text-white shadow-md hover:bg-primary-700 disabled:opacity-50"
        >
          {isGenerating ? (
            <span className="flex items-center gap-2">
              <svg className="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Génération en cours...
            </span>
          ) : (
            'Générer le planning'
          )}
        </button>

        <button
          type="button"
          onClick={resetWizard}
          className="text-sm text-neutral-500 hover:text-neutral-700"
        >
          Recommencer depuis le début
        </button>
      </div>
    </div>
  )
}

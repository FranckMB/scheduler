import { useEffect, useRef, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '@/features/auth/authStore'
import {
  useWizardStore,
  DAY_LABELS,
  generateSlotsFromRanges,
} from '@/features/wizard/wizardStore'
import { apiClient } from '@/shared/api/client'

export default function SummaryStep() {
  const { data, resetWizard } = useWizardStore()
  const setHasGenerated = useAuthStore((state) => state.setHasGenerated)
  const navigate = useNavigate()
  const [isGenerating, setIsGenerating] = useState(false)
  const [showPreview, setShowPreview] = useState(false)
  const [showTransition, setShowTransition] = useState(false)
  const [generateError, setGenerateError] = useState<string | null>(null)
  const [generateSuccess, setGenerateSuccess] = useState(false)
  const redirectTimeoutRef = useRef<number | null>(null)

  useEffect(
    () => () => {
      if (redirectTimeoutRef.current !== null) {
        window.clearTimeout(redirectTimeoutRef.current)
      }
    },
    []
  )

  const handleGenerate = useCallback(async () => {
    setIsGenerating(true)
    setGenerateError(null)
    setGenerateSuccess(false)
    setShowTransition(false)

    try {
      // 1. Create the schedule first
      const schedule = await apiClient
        .post('schedules', { json: { name: 'Planning', status: 'draft' } })
        .json<{ id: string }>()
      const scheduleId = schedule.id

      // 2. Build payload from wizard data
      const venuePayloads = data.venues.map((venue) => {
        const slots = generateSlotsFromRanges(venue.availabilityRanges)
        return {
          name: venue.name,
          slots,
          closures: venue.closures,
          can_split: venue.can_split,
        }
      })

      const teamPayloads = data.teams.map((team) => ({
        name: team.name,
        level: team.level,
        gender: team.gender,
        is_competition: team.is_competition,
        size: team.size,
        sessions_count: team.sessions_count,
        tier: team.tier,
        is_junior: team.is_junior,
      }))

      const coachPayloads = data.coaches.map((coach) => ({
        firstName: coach.name,
        lastName: '',
        email: null,
        phone: null,
        unavailabilities: data.coachConstraints.filter((constraint) => constraint.coachId === coach.id),
      }))

      // Preferred slots as type=preferred constraints
      const preferredConstraintPayloads = data.preferredSlots.map((ps) => ({
        team_id: ps.teamId,
        type: 'preferred' as const,
        day: ps.day,
        start_hour: ps.hour,
        start_minute: ps.minute,
        venue_id: ps.venueId,
        severity: ps.severity,
      }))

      const explicitConstraintPayloads = data.constraints.map((c) => ({
        team_id: c.teamId,
        type: c.type,
        day: c.day,
        start_hour: c.startHour,
        start_minute: c.startMinute,
        end_hour: c.endHour,
        end_minute: c.endMinute,
        venue_id: c.venueId,
        severity: c.severity,
      }))

      // 3. Generate the schedule with wizard data
      await apiClient.post(`schedules/${scheduleId}/generate`, {
        json: {
          venues: venuePayloads,
          teams: teamPayloads,
          coaches: coachPayloads,
          constraints: [...preferredConstraintPayloads, ...explicitConstraintPayloads],
        },
      })

      setHasGenerated(true)
      setGenerateSuccess(true)
      setShowPreview(false)
      setShowTransition(true)

      redirectTimeoutRef.current = window.setTimeout(() => {
        navigate('/dashboard')
      }, 900)
    } catch (err) {
      setGenerateError(err instanceof Error ? err.message : 'Echec de la generation')
    } finally {
      setIsGenerating(false)
    }
  }, [data, navigate, setHasGenerated])

  const generationSummary = {
    venues: data.venues.length,
    teams: data.teams.length,
    coaches: data.coaches.length,
    constraints: data.constraints.length + data.coachConstraints.length + data.preferredSlots.length,
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-bold text-fg-primary">Resume et Generation</h2>
        <p className="text-sm text-fg-muted">
          Verifiez toutes les donnees avant de lancer la generation
        </p>
      </div>

      {/* Venues summary */}
      <div className="glass rounded-lg border border-border-subtle p-4">
        <h3 className="mb-3 text-sm font-semibold text-fg-primary">
          Salles ({data.venues.length})
        </h3>
        <div className="space-y-2">
          {data.venues.map((venue) => {
            const availableCount = generateSlotsFromRanges(venue.availabilityRanges).length
            const rangeCount = Object.values(venue.availabilityRanges).reduce((count, ranges) => count + ranges.length, 0)
            return (
              <div key={venue.id} className="glass flex items-center justify-between rounded-md bg-bg-elevated px-3 py-2">
                <div>
                  <p className="text-sm font-medium text-fg-primary">
                    {venue.name || <span className="text-fg-disabled italic">Sans nom</span>}
                  </p>
                  <p className="text-xs text-fg-muted">
                    {rangeCount} plage{rangeCount > 1 ? 's' : ''}, {availableCount} creneau{availableCount > 1 ? 'x' : ''} de 15 min
                    {venue.can_split && ' - Split possible'}
                  </p>
                </div>
                {venue.closures.length > 0 && (
                  <span className="rounded-full bg-warning-900/40 px-2 py-0.5 text-xs text-warning-300">
                    {venue.closures.length} fermeture{venue.closures.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {/* Teams summary */}
      <div className="glass rounded-lg border border-border-subtle p-4">
        <h3 className="mb-3 text-sm font-semibold text-fg-primary">
          Equipes ({data.teams.length})
        </h3>
        {data.teams.length === 0 ? (
          <p className="text-sm text-fg-disabled">Aucune equipe configuree</p>
        ) : (
          <div className="space-y-2">
            {data.teams.map((team) => (
              <div key={team.id} className="glass rounded-md bg-bg-elevated px-3 py-2">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-fg-primary">
                      {team.name || <span className="text-fg-disabled italic">Sans nom</span>}
                    </p>
                    <p className="text-xs text-fg-muted">
                      {team.level && `${team.level} - `}
                      {team.gender === 'M' ? 'Masculin' : team.gender === 'F' ? 'Feminin' : ''}
                      {team.size > 0 && ` - ${team.size} joueurs`}
                      {team.is_competition && ' - Competition'}
                      {team.is_junior ? ' - Jeunes' : ' - Seniors'}
                    </p>
                  </div>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium text-white ${
                    team.tier === 'S' ? 'bg-rose-500' :
                    team.tier === 'A' ? 'bg-orange-500' :
                    team.tier === 'B' ? 'bg-yellow-500' :
                    team.tier === 'C' ? 'bg-green-500' :
                    'bg-blue-500'
                  }`}>
                    Tier {team.tier}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Coaches summary */}
      <div className="glass rounded-lg border border-border-subtle p-4">
        <h3 className="mb-3 text-sm font-semibold text-fg-primary">
          Coachs ({data.coaches.length})
        </h3>
        {data.coaches.length === 0 ? (
          <p className="text-sm text-fg-disabled">Aucun coach configure</p>
        ) : (
          <div className="space-y-2">
            {data.coaches.map((coach) => (
              <div key={coach.id} className="glass flex items-center justify-between rounded-md bg-bg-elevated px-3 py-2">
                <div>
                  <p className="text-sm font-medium text-fg-primary">
                    {coach.name || <span className="text-fg-disabled italic">Sans nom</span>}
                  </p>
                  <p className="text-xs text-fg-muted">
                    {coach.is_player ? 'Joueur' : 'Coach uniquement'}
                    {coach.player_team_id && ` - Equipe joueur: ${data.teams.find((team) => team.id === coach.player_team_id)?.name || 'Sans nom'}`}
                  </p>
                </div>
                {coach.teamIds.length > 0 && (
                  <span className="rounded-full bg-neutral-700 px-2 py-0.5 text-xs text-neutral-300">
                    {coach.teamIds.length} equipe{coach.teamIds.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Constraints summary */}
      <div className="glass rounded-lg border border-border-subtle p-4">
        <h3 className="mb-3 text-sm font-semibold text-fg-primary">
          Contraintes ({data.constraints.length + data.coachConstraints.length + data.preferredSlots.length})
        </h3>
        {data.constraints.length === 0 && data.coachConstraints.length === 0 && data.preferredSlots.length === 0 ? (
          <p className="text-sm text-fg-disabled">Aucune contrainte</p>
        ) : (
          <div className="space-y-2">
            {data.preferredSlots.map((ps) => {
              const team = data.teams.find((t) => t.id === ps.teamId)
              return (
                <div key={ps.id} className="rounded-md bg-success-900/30 px-3 py-2">
                  <p className="text-sm font-medium text-success-300">
                    Prefere: {team?.name || 'Equipe'} - {DAY_LABELS[ps.day]} {ps.hour}h{ps.minute.toString().padStart(2, '0')}
                    {' '}({ps.severity})
                  </p>
                </div>
              )
            })}
            {data.coachConstraints.map((c) => {
              const coach = c.coachId ? data.coaches.find((ch) => ch.id === c.coachId) : null
              return (
                <div key={c.id} className="rounded-md bg-warning-900/30 px-3 py-2 text-warning-300">
                  <p className="text-sm font-medium">
                    Coach: {coach?.name || 'Sans cible'}
                    {c.day && ` - ${DAY_LABELS[c.day]}`}
                    {c.startHour != null && ` ${c.startHour}h-${c.endHour}h`}
                  </p>
                </div>
              )
            })}
            {data.constraints.map((c) => {
              const team = c.teamId ? data.teams.find((t) => t.id === c.teamId) : null
              return (
                <div
                  key={c.id}
                    className={`rounded-md px-3 py-2 ${
                    c.type === 'fixed' ? 'bg-error-900/30 text-error-300' :
                    c.type === 'forbidden' ? 'bg-warning-900/30 text-warning-300' :
                    'bg-success-900/30 text-success-300'
                  }`}
                >
                  <p className="text-sm font-medium">
                    {c.type === 'fixed' ? 'Fixe' : c.type === 'forbidden' ? 'Exclu' : 'Prefere'}:
                    {' '}{team?.name || 'Sans cible'}
                    {c.day && ` - ${DAY_LABELS[c.day]}`}
                    {c.startHour != null && ` ${c.startHour}h-${c.endHour}h`}
                  </p>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* Generate button */}
      <div className="glass rounded-lg border-2 border-primary-700/50 bg-primary-900/20 p-6">
        <h3 className="text-lg font-bold text-primary-300">Generer le planning</h3>
        <p className="text-sm text-fg-muted">
          Le moteur de planification va creer un planning optimal base sur vos donnees.
        </p>

        {generateError && (
          <div className="w-full rounded-md bg-error-900/40 p-3 text-sm text-error-400" role="alert">
            {generateError}
          </div>
        )}

        {generateSuccess && (
          <div className="w-full rounded-md bg-success-900/40 p-3 text-sm text-success-400" role="alert">
            Planning genere avec succes !
          </div>
        )}

        <button
          type="button"
          onClick={() => setShowPreview(true)}
          disabled={isGenerating || showTransition}
          className="rounded-lg bg-primary-600 px-8 py-3 text-base font-bold text-white shadow-md transition hover:bg-primary-700 hover:shadow-lg disabled:opacity-50"
        >
          {isGenerating ? (
            <span className="flex items-center gap-2">
              <svg className="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              Generation en cours...
            </span>
          ) : showTransition ? (
            'Redirection...'
          ) : (
            'Generer le planning'
          )}
        </button>

        <button
          type="button"
          onClick={resetWizard}
          disabled={isGenerating || showTransition}
          className="text-sm text-fg-muted transition hover:text-fg-primary"
        >
          Recommencer depuis le debut
        </button>
      </div>

      {showPreview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className="w-full max-w-lg rounded-2xl border border-border-subtle bg-bg-deep p-6 shadow-2xl">
            <h3 className="text-xl font-semibold text-fg-primary">Prévisualisation de la génération</h3>
            <p className="mt-2 text-sm text-fg-muted">
              Vérifiez les éléments qui vont être envoyés au moteur avant de lancer le calcul.
            </p>

            <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-lg bg-bg-elevated p-3">
                <p className="text-fg-muted">Salles</p>
                <p className="mt-1 text-lg font-semibold text-fg-primary">{generationSummary.venues}</p>
              </div>
              <div className="rounded-lg bg-bg-elevated p-3">
                <p className="text-fg-muted">Équipes</p>
                <p className="mt-1 text-lg font-semibold text-fg-primary">{generationSummary.teams}</p>
              </div>
              <div className="rounded-lg bg-bg-elevated p-3">
                <p className="text-fg-muted">Coachs</p>
                <p className="mt-1 text-lg font-semibold text-fg-primary">{generationSummary.coaches}</p>
              </div>
              <div className="rounded-lg bg-bg-elevated p-3">
                <p className="text-fg-muted">Contraintes</p>
                <p className="mt-1 text-lg font-semibold text-fg-primary">{generationSummary.constraints}</p>
              </div>
            </div>

            <div className="mt-5 max-h-40 overflow-auto rounded-lg border border-border-subtle bg-surface p-3 text-sm text-fg-muted">
              <p>{data.venues.map((venue) => venue.name || 'Sans nom').join(' · ') || 'Aucune salle'}</p>
              <p className="mt-2">{data.teams.map((team) => team.name || 'Sans nom').join(' · ') || 'Aucune equipe'}</p>
            </div>

            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={() => setShowPreview(false)}
                disabled={isGenerating}
                className="rounded-md border border-border-subtle bg-surface px-4 py-2 text-sm font-medium text-fg-primary"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={handleGenerate}
                disabled={isGenerating}
                className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
              >
                {isGenerating ? 'Génération...' : 'Lancer la génération'}
              </button>
            </div>
          </div>
        </div>
      )}

      {showTransition && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
          <div className="rounded-2xl border border-border-subtle bg-bg-deep px-8 py-6 text-center shadow-2xl">
            <div className="mx-auto mb-4 h-12 w-12 animate-pulse rounded-full bg-primary-500/30" />
            <h3 className="text-xl font-semibold text-fg-primary">Planning généré</h3>
            <p className="mt-2 text-sm text-fg-muted">Ouverture du tableau de bord…</p>
          </div>
        </div>
      )}
    </div>
  )
}

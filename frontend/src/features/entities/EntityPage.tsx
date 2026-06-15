import { useState } from 'react'
import { usePriorityTiers, useUpdateTeamTier } from '@/features/priorities/priorityApi'
import { useLatestSchedule } from '@/features/dashboard/dashboardApi'
import { apiClient } from '@/shared/api/client'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'
import RegenerationWarningModal from './RegenerationWarningModal'
import DiffModal from './DiffModal'
import {
  useTeams,
  useCoaches,
  useCoachUnavailabilities,
  useDeleteCoach,
  useDeleteCoachUnavailability,
  useDeleteTeam,
  useDeleteTeamConstraint,
  useDeleteVenue,
  useDeleteVenueConstraint,
  useTeamConstraints,
  useVenueConstraints,
  useVenues,
  useUpdateVenue,
  useUpdateCoach,
  useUpdateTeam,
  useUpdateTeamConstraint,
  useUpdateVenueConstraint,
  useUpdateCoachUnavailability,
  useCreateVenue,
  useCreateCoach,
  useCreateTeam,
  useCreateTeamConstraint,
  useCreateVenueConstraint,
  useCreateCoachUnavailability,
  type Venue,
  type Coach,
  type Team,
  type TeamConstraint,
  type VenueConstraint,
  type CoachUnavailability,
} from './entityApi'

type SectionKey =
  | 'Salles'
  | 'Contraintes salles'
  | 'Équipes'
  | 'Contraintes équipes'
  | 'Coachs'
  | 'Contraintes coachs'
  | 'Tier list'

interface EditableRowProps<T extends { id: string }> {
  entity: T
  label: string
  onSave: (id: string, data: Record<string, unknown>) => void
  onDelete: (id: string) => void
  isSaving: boolean
  extra?: React.ReactNode
  onStartEdit?: () => void
}

function EditableRow<T extends { id: string }>({
  entity,
  label,
  onSave,
  onDelete,
  isSaving,
  extra,
  onStartEdit,
}: EditableRowProps<T>) {
  const [isEditing, setIsEditing] = useState(false)
  const [draft, setDraft] = useState(label)

  const handleSave = () => {
    if (draft.trim() && draft.trim() !== label) {
      onSave(entity.id, { name: draft.trim() })
    }
    setIsEditing(false)
  }

  return (
    <div className="flex items-center justify-between rounded-lg border border-border-subtle bg-bg-deep/50 px-4 py-3">
      <div className="flex-1 min-w-0">
        {isEditing ? (
          <div className="flex items-center gap-2">
            <input
              type="text"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleSave()
                if (e.key === 'Escape') {
                  setDraft(label)
                  setIsEditing(false)
                }
              }}
              className="rounded-md border border-border-subtle bg-surface px-2 py-1 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
              autoFocus
            />
            <button
              type="button"
              onClick={handleSave}
              disabled={isSaving}
              className="rounded-md bg-accent px-2 py-1 text-xs font-medium text-white hover:bg-accent/90 disabled:opacity-50"
            >
              {isSaving ? '…' : 'Save'}
            </button>
            <button
              type="button"
              onClick={() => {
                setDraft(label)
                setIsEditing(false)
              }}
              className="rounded-md border border-border-subtle bg-surface px-2 py-1 text-xs font-medium text-fg-primary"
            >
              Cancel
            </button>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => {
              onStartEdit?.()
              setIsEditing(true)
            }}
            className="text-left hover:underline"
          >
            <div className="font-medium text-fg-primary">{label}</div>
            <div className="text-xs text-fg-muted">ID: {entity.id}</div>
          </button>
        )}
      </div>
      <div className="flex items-center gap-2 ml-3">
        {extra}
        <button
          type="button"
          onClick={() => onDelete(entity.id)}
          className="rounded-md border border-border-subtle bg-surface px-3 py-1 text-xs font-medium text-fg-primary hover:bg-surface-hover"
        >
          Supprimer
        </button>
      </div>
    </div>
  )
}

export default function EntityPage() {
  const [openSection, setOpenSection] = useState<SectionKey>('Salles')

  const teamsQuery = useTeams()
  const tiersQuery = usePriorityTiers()
  const updateTeamTier = useUpdateTeamTier()

  const { data: latestSchedule } = useLatestSchedule()
  const isScheduleValidated = latestSchedule?.status === 'validated' || latestSchedule?.status === 'done'

  const [showWarningModal, setShowWarningModal] = useState(false)
  const [showDiffModal, setShowDiffModal] = useState(false)
  const [pendingEdit, setPendingEdit] = useState<{
    apply: () => Promise<void>
    changes: Array<{ entityType: string; entityId: string; entityName: string; before: string; after: string }>
  } | null>(null)
  const [isRegenerating, setIsRegenerating] = useState(false)
  const [originalValues, setOriginalValues] = useState<Record<string, string>>({})

  const venuesQuery = useVenues()
  const coachesQuery = useCoaches()
  const teamConstraintsQuery = useTeamConstraints()
  const venueConstraintsQuery = useVenueConstraints()
  const coachUnavailabilitiesQuery = useCoachUnavailabilities()

  const updateVenue = useUpdateVenue()
  const updateCoach = useUpdateCoach()
  const updateTeam = useUpdateTeam()
  const updateTeamConstraint = useUpdateTeamConstraint()
  const updateVenueConstraint = useUpdateVenueConstraint()
  const updateCoachUnavailability = useUpdateCoachUnavailability()

  const deleteVenue = useDeleteVenue()
  const deleteCoach = useDeleteCoach()
  const deleteTeam = useDeleteTeam()
  const deleteTeamConstraint = useDeleteTeamConstraint()
  const deleteVenueConstraint = useDeleteVenueConstraint()
  const deleteCoachUnavailability = useDeleteCoachUnavailability()

  const createVenue = useCreateVenue()
  const createCoach = useCreateCoach()
  const createTeam = useCreateTeam()
  const createTeamConstraint = useCreateTeamConstraint()
  const createVenueConstraint = useCreateVenueConstraint()
  const createCoachUnavailability = useCreateCoachUnavailability()

  const [newNames, setNewNames] = useState<Record<SectionKey, string>>({
    Salles: '',
    'Contraintes salles': '',
    Équipes: '',
    'Contraintes équipes': '',
    Coachs: '',
    'Contraintes coachs': '',
    'Tier list': '',
  })

  const triggerRegeneration = async () => {
    if (!latestSchedule?.id) return
    await apiClient.post(`schedules/${latestSchedule.id}/generate`, { json: {} })
  }

  const queueEdit = (apply: () => Promise<void>, changes: Array<{
    entityType: string
    entityId: string
    entityName: string
    before: string
    after: string
  }>) => {
    if (!isScheduleValidated) {
      void apply()
      return
    }
    setPendingEdit({ apply, changes })
    setShowWarningModal(true)
  }

  const handleWarningConfirm = () => {
    setShowWarningModal(false)
    setShowDiffModal(true)
  }

  const handleWarningCancel = () => {
    setShowWarningModal(false)
    setPendingEdit(null)
  }

  const handleDiffConfirm = async () => {
    if (!pendingEdit) return
    setIsRegenerating(true)
    try {
      await pendingEdit.apply()
      await triggerRegeneration()
    } finally {
      setIsRegenerating(false)
      setShowDiffModal(false)
      setPendingEdit(null)
    }
  }

  const handleDiffCancel = () => {
    setShowDiffModal(false)
    setPendingEdit(null)
  }

  const loading =
    teamsQuery.isLoading ||
    tiersQuery.isLoading ||
    venuesQuery.isLoading ||
    coachesQuery.isLoading ||
    teamConstraintsQuery.isLoading ||
    venueConstraintsQuery.isLoading ||
    coachUnavailabilitiesQuery.isLoading

  if (loading) {
    return (
      <div className="flex min-h-[24rem] items-center justify-center">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  const sections = [
    { label: 'Salles' as SectionKey, count: venuesQuery.data?.length ?? 0 },
    { label: 'Contraintes salles' as SectionKey, count: venueConstraintsQuery.data?.length ?? 0 },
    { label: 'Équipes' as SectionKey, count: teamsQuery.data?.length ?? 0 },
    { label: 'Contraintes équipes' as SectionKey, count: teamConstraintsQuery.data?.length ?? 0 },
    { label: 'Coachs' as SectionKey, count: coachesQuery.data?.length ?? 0 },
    { label: 'Contraintes coachs' as SectionKey, count: coachUnavailabilitiesQuery.data?.length ?? 0 },
    { label: 'Tier list' as SectionKey, count: tiersQuery.data?.length ?? 0 },
  ]

  const isMutating =
    updateVenue.isPending ||
    updateCoach.isPending ||
    updateTeam.isPending ||
    updateTeamConstraint.isPending ||
    updateVenueConstraint.isPending ||
    updateCoachUnavailability.isPending ||
    createVenue.isPending ||
    createCoach.isPending ||
    createTeam.isPending ||
    createTeamConstraint.isPending ||
    createVenueConstraint.isPending ||
    createCoachUnavailability.isPending

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-fg-primary">Entités</h1>
          <p className="mt-1 text-sm text-fg-muted">CRUD backend branché sur les ressources du club.</p>
        </div>

        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            ['Salles', venuesQuery.data?.length ?? 0],
            ['Équipes', teamsQuery.data?.length ?? 0],
            ['Coachs', coachesQuery.data?.length ?? 0],
            ['Tiers', tiersQuery.data?.length ?? 0],
          ].map(([label, value]) => (
            <div key={label as string} className="glass rounded-lg border border-border-subtle px-4 py-3">
              <div className="text-xs uppercase tracking-wide text-fg-muted">{label}</div>
              <div className="mt-1 text-sm font-semibold text-fg-primary">{value as number}</div>
            </div>
          ))}
        </div>
      </div>

      {isMutating && (
        <div className="flex items-center gap-2 text-sm text-fg-muted">
          <LoadingSpinner size="sm" />
          <span>Sauvegarde en cours…</span>
        </div>
      )}

      <div className="space-y-3">
        {sections.map((section) => (
          <section key={section.label} className="glass rounded-lg border border-border-subtle shadow-sm overflow-hidden">
            <button
              type="button"
              onClick={() => setOpenSection(section.label)}
              className="flex w-full items-center justify-between px-5 py-4 text-left transition hover:bg-surface-hover"
            >
              <span className="text-base font-semibold text-fg-primary">{section.label}</span>
              <span className="text-sm text-fg-muted">{section.count}</span>
            </button>

            {openSection === section.label && (
              <div className="border-t border-border-subtle px-5 py-5">
                <div className="space-y-3">
                  {section.label === 'Équipes' && teamsQuery.data && tiersQuery.data ? (
                    <>
                      {teamsQuery.data.map((team) => (
                        <EditableRow
                          key={team.id}
                          entity={team}
                          label={team.name}
                          onSave={(id, data) => {
                            const before = team.name
                            const after = (data as { name?: string }).name ?? before
                            queueEdit(
                              async () => { await updateTeam.mutateAsync({ id, data: data as Partial<Team> }) },
                              [{ entityType: 'Équipe', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteTeam.mutateAsync(id) },
                              [{ entityType: 'Équipe', entityId: id, entityName: team.name, before: team.name, after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateTeam.isPending}
                          extra={
                            <select
                              value={String(team.priorityTierId)}
                              onChange={(event) => {
                                const priorityTierId = Number(event.target.value)
                                if (!Number.isNaN(priorityTierId)) {
                                  const tierLabel = tiersQuery.data.find((t) => t.id === priorityTierId)?.label ?? String(priorityTierId)
                                  const currentTier = tiersQuery.data.find((t) => t.id === team.priorityTierId)?.label ?? String(team.priorityTierId)
                                  queueEdit(
                                    async () => { await updateTeamTier.mutateAsync({ id: team.id, data: { priorityTierId } }) },
                                    [{ entityType: 'Équipe', entityId: team.id, entityName: team.name, before: `Tier: ${currentTier}`, after: `Tier: ${tierLabel}` }]
                                  )
                                }
                              }}
                              className="rounded-md border border-border-subtle bg-surface px-2 py-1 text-xs text-fg-primary"
                            >
                              {tiersQuery.data.map((tier) => (
                                <option key={tier.id} value={tier.id}>
                                  {tier.label}
                                </option>
                              ))}
                            </select>
                          }
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Nouvelle équipe…"
                          value={newNames['Équipes']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, Équipes: e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Équipes'].trim()) {
                              createTeam.mutate({
                                name: newNames['Équipes'].trim(),
                                priorityTierId: tiersQuery.data[0]?.id ?? 1,
                                sessionsPerWeek: 1,
                                minSessionsOverride: null,
                                isActive: true,
                                sportCategoryId: '',
                                gender: null,
                                matchDay: null,
                              })
                              setNewNames((prev) => ({ ...prev, Équipes: '' }))
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Équipes'].trim()) {
                              createTeam.mutate({
                                name: newNames['Équipes'].trim(),
                                priorityTierId: tiersQuery.data[0]?.id ?? 1,
                                sessionsPerWeek: 1,
                                minSessionsOverride: null,
                                isActive: true,
                                sportCategoryId: '',
                                gender: null,
                                matchDay: null,
                              })
                              setNewNames((prev) => ({ ...prev, Équipes: '' }))
                            }
                          }}
                          disabled={!newNames['Équipes'].trim() || createTeam.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : section.label === 'Salles' && venuesQuery.data ? (
                    <>
                      {venuesQuery.data.map((venue) => (
                        <EditableRow
                          key={venue.id}
                          entity={venue}
                          label={venue.name}
                          onStartEdit={() => setOriginalValues((prev) => ({ ...prev, [`venue-${venue.id}`]: venue.name }))}
                          onSave={(id, data) => {
                            const before = originalValues[`venue-${id}`] ?? venue.name
                            const after = (data as { name?: string }).name ?? before
                            queueEdit(
                              async () => { await updateVenue.mutateAsync({ id, data: data as Partial<Venue> }) },
                              [{ entityType: 'Salle', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteVenue.mutateAsync(id) },
                              [{ entityType: 'Salle', entityId: id, entityName: venue.name, before: venue.name, after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateVenue.isPending}
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Nouvelle salle…"
                          value={newNames['Salles']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, Salles: e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Salles'].trim()) {
                              createVenue.mutate({
                                name: newNames['Salles'].trim(),
                                isExternal: false,
                                color: null,
                                latitude: null,
                                longitude: null,
                                source: 'manual',
                                externalRef: null,
                                isActive: true,
                                parentVenueId: null,
                              })
                              setNewNames((prev) => ({ ...prev, Salles: '' }))
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Salles'].trim()) {
                              createVenue.mutate({
                                name: newNames['Salles'].trim(),
                                isExternal: false,
                                color: null,
                                latitude: null,
                                longitude: null,
                                source: 'manual',
                                externalRef: null,
                                isActive: true,
                                parentVenueId: null,
                              })
                              setNewNames((prev) => ({ ...prev, Salles: '' }))
                            }
                          }}
                          disabled={!newNames['Salles'].trim() || createVenue.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : section.label === 'Contraintes salles' && venueConstraintsQuery.data ? (
                    <>
                      {venueConstraintsQuery.data.map((constraint) => (
                        <EditableRow
                          key={constraint.id}
                          entity={constraint}
                          label={`${constraint.constraintType} = ${constraint.constraintValue}`}
                          onStartEdit={() => setOriginalValues((prev) => ({ ...prev, [`venue-constraint-${constraint.id}`]: `${constraint.constraintType} = ${constraint.constraintValue}` }))}
                          onSave={(id, data) => {
                            const before = originalValues[`venue-constraint-${id}`] ?? `${constraint.constraintType} = ${constraint.constraintValue}`
                            const constraintType = (data as { constraintType?: string }).constraintType ?? constraint.constraintType
                            const constraintValue = (data as { constraintValue?: string }).constraintValue ?? constraint.constraintValue
                            const after = `${constraintType} = ${constraintValue}`
                            queueEdit(
                              async () => { await updateVenueConstraint.mutateAsync({ id, data: data as Partial<VenueConstraint> }) },
                              [{ entityType: 'Contrainte salle', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteVenueConstraint.mutateAsync(id) },
                              [{ entityType: 'Contrainte salle', entityId: id, entityName: `${constraint.constraintType} = ${constraint.constraintValue}`, before: `${constraint.constraintType} = ${constraint.constraintValue}`, after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateVenueConstraint.isPending}
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Nouvelle contrainte (type=valeur)…"
                          value={newNames['Contraintes salles']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, 'Contraintes salles': e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Contraintes salles'].trim()) {
                              const [constraintType, ...rest] = newNames['Contraintes salles'].trim().split('=')
                              createVenueConstraint.mutate({
                                venueId: venuesQuery.data?.[0]?.id ?? '',
                                constraintType: constraintType.trim(),
                                constraintValue: rest.join('=').trim() || '',
                              })
                              setNewNames((prev) => ({ ...prev, 'Contraintes salles': '' }))
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Contraintes salles'].trim()) {
                              const [constraintType, ...rest] = newNames['Contraintes salles'].trim().split('=')
                              createVenueConstraint.mutate({
                                venueId: venuesQuery.data?.[0]?.id ?? '',
                                constraintType: constraintType.trim(),
                                constraintValue: rest.join('=').trim() || '',
                              })
                              setNewNames((prev) => ({ ...prev, 'Contraintes salles': '' }))
                            }
                          }}
                          disabled={!newNames['Contraintes salles'].trim() || createVenueConstraint.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : section.label === 'Contraintes équipes' && teamConstraintsQuery.data ? (
                    <>
                      {teamConstraintsQuery.data.map((constraint) => (
                        <EditableRow
                          key={constraint.id}
                          entity={constraint}
                          label={`${constraint.type}${constraint.reason ? ` — ${constraint.reason}` : ''}`}
                          onStartEdit={() => setOriginalValues((prev) => ({ ...prev, [`team-constraint-${constraint.id}`]: `${constraint.type}${constraint.reason ? ` — ${constraint.reason}` : ''}` }))}
                          onSave={(id, data) => {
                            const before = originalValues[`team-constraint-${id}`] ?? `${constraint.type}${constraint.reason ? ` — ${constraint.reason}` : ''}`
                            const type = (data as { type?: string }).type ?? constraint.type
                            const reason = (data as { reason?: string }).reason ?? constraint.reason
                            const after = `${type}${reason ? ` — ${reason}` : ''}`
                            queueEdit(
                              async () => { await updateTeamConstraint.mutateAsync({ id, data: data as Partial<TeamConstraint> }) },
                              [{ entityType: 'Contrainte équipe', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteTeamConstraint.mutateAsync(id) },
                              [{ entityType: 'Contrainte équipe', entityId: id, entityName: `${constraint.type}${constraint.reason ? ` — ${constraint.reason}` : ''}`, before: `${constraint.type}${constraint.reason ? ` — ${constraint.reason}` : ''}`, after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateTeamConstraint.isPending}
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Nouvelle contrainte…"
                          value={newNames['Contraintes équipes']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, 'Contraintes équipes': e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Contraintes équipes'].trim()) {
                              createTeamConstraint.mutate({
                                teamId: teamsQuery.data?.[0]?.id ?? '',
                                type: 'preferred',
                                dayOfWeek: null,
                                startTime: null,
                                endTime: null,
                                venueId: null,
                                reason: newNames['Contraintes équipes'].trim(),
                                createdBy: null,
                                sourceOccurrenceId: null,
                                severity: null,
                              })
                              setNewNames((prev) => ({ ...prev, 'Contraintes équipes': '' }))
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Contraintes équipes'].trim()) {
                              createTeamConstraint.mutate({
                                teamId: teamsQuery.data?.[0]?.id ?? '',
                                type: 'preferred',
                                dayOfWeek: null,
                                startTime: null,
                                endTime: null,
                                venueId: null,
                                reason: newNames['Contraintes équipes'].trim(),
                                createdBy: null,
                                sourceOccurrenceId: null,
                                severity: null,
                              })
                              setNewNames((prev) => ({ ...prev, 'Contraintes équipes': '' }))
                            }
                          }}
                          disabled={!newNames['Contraintes équipes'].trim() || createTeamConstraint.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : section.label === 'Coachs' && coachesQuery.data ? (
                    <>
                      {coachesQuery.data.map((coach) => (
                        <EditableRow
                          key={coach.id}
                          entity={coach}
                          label={`${coach.firstName} ${coach.lastName}`.trim()}
                          onStartEdit={() => setOriginalValues((prev) => ({ ...prev, [`coach-${coach.id}`]: `${coach.firstName} ${coach.lastName}`.trim() }))}
                          onSave={(id, data) => {
                            const before = originalValues[`coach-${id}`] ?? `${coach.firstName} ${coach.lastName}`.trim()
                            const firstName = (data as { firstName?: string }).firstName ?? coach.firstName
                            const lastName = (data as { lastName?: string }).lastName ?? coach.lastName
                            const after = `${firstName} ${lastName}`.trim()
                            queueEdit(
                              async () => { await updateCoach.mutateAsync({ id, data: data as Partial<Coach> }) },
                              [{ entityType: 'Coach', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteCoach.mutateAsync(id) },
                              [{ entityType: 'Coach', entityId: id, entityName: `${coach.firstName} ${coach.lastName}`.trim(), before: `${coach.firstName} ${coach.lastName}`.trim(), after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateCoach.isPending}
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Prénom Nom…"
                          value={newNames['Coachs']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, Coachs: e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Coachs'].trim()) {
                              const [firstName, ...rest] = newNames['Coachs'].trim().split(' ')
                              createCoach.mutate({
                                firstName: firstName.trim(),
                                lastName: rest.join(' ').trim() || '',
                                email: null,
                                phone: null,
                                maxDaysOverride: null,
                                maxDaysOverrideConfirmed: false,
                                acceptableLateMinutes: null,
                                isActive: true,
                                parentCoachId: null,
                              })
                              setNewNames((prev) => ({ ...prev, Coachs: '' }))
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Coachs'].trim()) {
                              const [firstName, ...rest] = newNames['Coachs'].trim().split(' ')
                              createCoach.mutate({
                                firstName: firstName.trim(),
                                lastName: rest.join(' ').trim() || '',
                                email: null,
                                phone: null,
                                maxDaysOverride: null,
                                maxDaysOverrideConfirmed: false,
                                acceptableLateMinutes: null,
                                isActive: true,
                                parentCoachId: null,
                              })
                              setNewNames((prev) => ({ ...prev, Coachs: '' }))
                            }
                          }}
                          disabled={!newNames['Coachs'].trim() || createCoach.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : section.label === 'Contraintes coachs' && coachUnavailabilitiesQuery.data ? (
                    <>
                      {coachUnavailabilitiesQuery.data.map((constraint) => (
                        <EditableRow
                          key={constraint.id}
                          entity={constraint}
                          label={`J${constraint.dayOfWeek}`}
                          onStartEdit={() => setOriginalValues((prev) => ({ ...prev, [`coach-unavail-${constraint.id}`]: `J${constraint.dayOfWeek}` }))}
                          onSave={(id, data) => {
                            const before = originalValues[`coach-unavail-${id}`] ?? `J${constraint.dayOfWeek}`
                            const dayOfWeek = (data as { dayOfWeek?: number }).dayOfWeek ?? constraint.dayOfWeek
                            const after = `J${dayOfWeek}`
                            queueEdit(
                              async () => { await updateCoachUnavailability.mutateAsync({ id, data: data as Partial<CoachUnavailability> }) },
                              [{ entityType: 'Contrainte coach', entityId: id, entityName: before, before, after }]
                            )
                          }}
                          onDelete={(id) => {
                            queueEdit(
                              async () => { await deleteCoachUnavailability.mutateAsync(id) },
                              [{ entityType: 'Contrainte coach', entityId: id, entityName: `J${constraint.dayOfWeek}`, before: `J${constraint.dayOfWeek}`, after: '(supprimé)' }]
                            )
                          }}
                          isSaving={updateCoachUnavailability.isPending}
                        />
                      ))}
                      <div className="flex items-center gap-2 pt-2">
                        <input
                          type="text"
                          placeholder="Jour (1-7)…"
                          value={newNames['Contraintes coachs']}
                          onChange={(e) => setNewNames((prev) => ({ ...prev, 'Contraintes coachs': e.target.value }))}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter' && newNames['Contraintes coachs'].trim()) {
                              const day = Number(newNames['Contraintes coachs'].trim())
                              if (day >= 1 && day <= 7) {
                                createCoachUnavailability.mutate({
                                  coachId: coachesQuery.data?.[0]?.id ?? '',
                                  dayOfWeek: day,
                                  startTime: null,
                                  endTime: null,
                                })
                                setNewNames((prev) => ({ ...prev, 'Contraintes coachs': '' }))
                              }
                            }
                          }}
                          className="flex-1 rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <button
                          type="button"
                          onClick={() => {
                            if (newNames['Contraintes coachs'].trim()) {
                              const day = Number(newNames['Contraintes coachs'].trim())
                              if (day >= 1 && day <= 7) {
                                createCoachUnavailability.mutate({
                                  coachId: coachesQuery.data?.[0]?.id ?? '',
                                  dayOfWeek: day,
                                  startTime: null,
                                  endTime: null,
                                })
                                setNewNames((prev) => ({ ...prev, 'Contraintes coachs': '' }))
                              }
                            }
                          }}
                          disabled={!newNames['Contraintes coachs'].trim() || createCoachUnavailability.isPending}
                          className="rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-50"
                        >
                          Ajouter
                        </button>
                      </div>
                    </>
                  ) : (
                    <div className="rounded-lg border border-dashed border-border-subtle px-4 py-6 text-sm text-fg-muted">
                      {tiersQuery.data?.length ?? 0} tiers chargés depuis le backend.
                    </div>
                  )}
                </div>
              </div>
            )}
          </section>
        ))}
      </div>

      {showWarningModal && pendingEdit && (
        <RegenerationWarningModal
          onConfirm={handleWarningConfirm}
          onCancel={handleWarningCancel}
          isPending={isRegenerating}
        />
      )}

      {showDiffModal && pendingEdit && (
        <DiffModal
          changes={pendingEdit.changes}
          onConfirm={handleDiffConfirm}
          onCancel={handleDiffCancel}
          isRegenerating={isRegenerating}
        />
      )}
    </div>
  )
}

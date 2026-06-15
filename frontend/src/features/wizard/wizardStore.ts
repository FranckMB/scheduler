import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { apiClient } from '@/shared/api/client'

// ─── Types ───────────────────────────────────────────────────────────────────

export type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat'
export type TierLabel = 'S' | 'A' | 'B' | 'C' | 'D'
export type Gender = 'M' | 'F' | ''
export type ConstraintType = 'fixed' | 'forbidden' | 'preferred'
export type VenueConstraintType = 'gender_restriction' | 'level_preference'
export type PreferredSlotSeverity = 'Obligatoire' | 'Fortement préféré' | 'Préféré' | 'Flexible'

export const TIER_ORDER: TierLabel[] = ['S', 'A', 'B', 'C', 'D']

export interface TimeSlot {
  day: DayKey
  hour: number
  minute: number
}

export interface TimeRange {
  id: string
  start: string
  end: string
}

export interface VenueData {
  id: string
  name: string
  availabilityRanges: Record<DayKey, TimeRange[]>
  closures: string[] // date strings "YYYY-MM-DD"
  can_split: boolean
}

export interface CoachData {
  id: string
  name: string
  teamIds: string[] // assigned team IDs
  is_player: boolean
  player_team_id: string
}

export interface TeamData {
  id: string
  name: string
  level: string // Regional/Depart/Loisir/National/Elite (legacy, kept for backward compat)
  sportCategoryId: string | null
  gender: Gender
  is_competition: boolean
  size: number
  sessions_count: number
  tier: TierLabel
  is_junior: boolean
}

export interface PreferredSlot {
  id: string
  teamId: string
  day: DayKey
  hour: number
  minute: number
  venueId: string
  severity: PreferredSlotSeverity
}

export interface TeamConstraint {
  id: string
  teamId?: string
  type: ConstraintType
  day?: DayKey
  startHour?: number
  startMinute?: number
  endHour?: number
  endMinute?: number
  venueId?: string
  severity?: PreferredSlotSeverity
}

export interface CoachConstraint {
  id: string
  coachId?: string
  day?: DayKey
  startHour?: number
  startMinute?: number
  endHour?: number
  endMinute?: number
  venueId?: string
}

export interface VenueConstraint {
  id: string
  venueId: string
  constraintType: VenueConstraintType
  constraintValue: string
}

export interface WizardData {
  venues: VenueData[]
  teams: TeamData[]
  preferredSlots: PreferredSlot[]
  coaches: CoachData[]
  constraints: TeamConstraint[]
  coachConstraints: CoachConstraint[]
  venueConstraints: VenueConstraint[]
}

export type WizardStep = 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8

interface WizardState {
  currentStep: WizardStep
  data: WizardData
  isSaving: boolean
  saveError: string | null
  validationErrors: Record<number, string[]>

  // Navigation
  setCurrentStep: (step: WizardStep) => void
  nextStep: () => boolean
  prevStep: () => void

  // Venue actions
  addVenue: () => void
  updateVenue: (id: string, updates: Partial<VenueData>) => void
  removeVenue: (id: string) => void
  addVenueRange: (venueId: string, day: DayKey) => void
  updateVenueRange: (venueId: string, day: DayKey, rangeId: string, updates: Partial<TimeRange>) => void
  removeVenueRange: (venueId: string, day: DayKey, rangeId: string) => void
  addClosure: (venueId: string, date: string) => void
  removeClosure: (venueId: string, date: string) => void

  // Team actions
  addTeam: () => void
  updateTeam: (id: string, updates: Partial<TeamData>) => void
  removeTeam: (id: string) => void

  // Preferred slot actions
  addPreferredSlot: (teamId: string) => void
  updatePreferredSlot: (id: string, updates: Partial<PreferredSlot>) => void
  removePreferredSlot: (id: string) => void

  // Tier actions
  setTeamTier: (teamId: string, tier: TierLabel) => void

  // Coach actions
  addCoach: () => void
  updateCoach: (id: string, updates: Partial<CoachData>) => void
  removeCoach: (id: string) => void
  assignCoachToTeam: (coachId: string, teamId: string) => void
  unassignCoachFromTeam: (coachId: string, teamId: string) => void

  // Constraint actions
  addConstraint: () => void
  removeConstraint: (id: string) => void
  updateConstraint: (id: string, updates: Partial<TeamConstraint>) => void
  addCoachConstraint: () => void
  removeCoachConstraint: (id: string) => void
  updateCoachConstraint: (id: string, updates: Partial<CoachConstraint>) => void

  addVenueConstraint: () => void
  updateVenueConstraint: (id: string, updates: Partial<VenueConstraint>) => void
  removeVenueConstraint: (id: string) => void

  // General
  autoSave: () => Promise<void>
  validateStep: (step: WizardStep) => string[]
  resetWizard: () => void
  clearSaveError: () => void
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

const DAYS: DayKey[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat']
const DAY_LABELS: Record<DayKey, string> = {
  mon: 'Lun',
  tue: 'Mar',
  wed: 'Mer',
  thu: 'Jeu',
  fri: 'Ven',
  sat: 'Sam',
}

const LEVEL_OPTIONS = ['Regional', 'Depart', 'Loisir', 'National', 'Elite'] as const

const START_HOUR = 7
const END_HOUR = 23
const DEFAULT_RANGE_START = '18:00'
const DEFAULT_RANGE_END = '20:00'

function generateSlotKey(day: DayKey, hour: number, minute: number): string {
  return `${day}-${hour}-${minute}`
}

function createEmptyAvailabilityRanges(): Record<DayKey, TimeRange[]> {
  return DAYS.reduce<Record<DayKey, TimeRange[]>>((ranges, day) => {
    ranges[day] = []
    return ranges
  }, { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [] })
}

function timeToMinutes(time: string): number {
  const [hour = '0', minute = '0'] = time.split(':')
  return parseInt(hour, 10) * 60 + parseInt(minute, 10)
}

function minutesToSlot(minutes: number): Omit<TimeSlot, 'day'> {
  return {
    hour: Math.floor(minutes / 60),
    minute: minutes % 60,
  }
}

function generateSlotsFromRanges(availabilityRanges: Record<DayKey, TimeRange[]>): TimeSlot[] {
  const slots: TimeSlot[] = []
  for (const day of DAYS) {
    for (const range of availabilityRanges[day]) {
      const start = timeToMinutes(range.start)
      const end = timeToMinutes(range.end)
      for (let current = start; current < end; current += 15) {
        const slot = minutesToSlot(current)
        slots.push({ day, ...slot })
      }
    }
  }
  return slots
}

function generateId(): string {
  return `id_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`
}

function createEmptyVenueData(): VenueData {
  return {
    id: generateId(),
    name: '',
    availabilityRanges: createEmptyAvailabilityRanges(),
    closures: [],
    can_split: false,
  }
}

function createEmptyTeam(): TeamData {
  return {
    id: generateId(),
    name: '',
    level: '',
    sportCategoryId: null,
    gender: '',
    is_competition: true,
    size: 0,
    sessions_count: 2,
    tier: 'C',
    is_junior: false,
  }
}

function createEmptyCoach(): CoachData {
  return {
    id: generateId(),
    name: '',
    teamIds: [],
    is_player: false,
    player_team_id: '',
  }
}

function createEmptyConstraint(): TeamConstraint {
  return {
    id: generateId(),
    type: 'fixed',
    day: 'mon',
    startHour: 18,
    startMinute: 0,
    endHour: 20,
    endMinute: 0,
    severity: 'Flexible',
  }
}

function createEmptyCoachConstraint(): CoachConstraint {
  return {
    id: generateId(),
    day: 'mon',
    startHour: 9,
    startMinute: 0,
    endHour: 12,
    endMinute: 0,
  }
}

function createEmptyVenueConstraint(venueId = ''): VenueConstraint {
  return {
    id: generateId(),
    venueId,
    constraintType: 'gender_restriction',
    constraintValue: 'M',
  }
}

function createEmptyPreferredSlot(teamId: string): PreferredSlot {
  return {
    id: generateId(),
    teamId,
    day: 'mon',
    hour: 18,
    minute: 0,
    venueId: '',
    severity: 'Flexible',
  }
}

function createInitialWizardData(): WizardData {
  return {
    venues: [createEmptyVenueData()],
    teams: [],
    preferredSlots: [],
    coaches: [],
    constraints: [],
    coachConstraints: [],
    venueConstraints: [],
  }
}

function createInitialWizardState(): Pick<WizardState, 'currentStep' | 'data' | 'isSaving' | 'saveError' | 'validationErrors'> {
  return {
    currentStep: 0,
    data: createInitialWizardData(),
    isSaving: false,
    saveError: null,
    validationErrors: {},
  }
}

function isValidWizardState(persistedState: unknown): persistedState is Partial<WizardState> {
  if (!persistedState || typeof persistedState !== 'object') {
    return false
  }

  const state = persistedState as { currentStep?: unknown; data?: unknown }
  const currentStep = state.currentStep
  const data = state.data as
      | {
          venues?: unknown
          teams?: unknown
          coaches?: unknown
          constraints?: unknown
          coachConstraints?: unknown
          venueConstraints?: unknown
          preferredSlots?: unknown
        }
    | undefined

  return (
    typeof currentStep === 'number' &&
    Number.isInteger(currentStep) &&
    currentStep >= 0 &&
    currentStep <= 8 &&
    !!data &&
    Array.isArray(data.venues) &&
    Array.isArray(data.teams) &&
    Array.isArray(data.coaches) &&
    Array.isArray(data.constraints) &&
    Array.isArray(data.coachConstraints) &&
    (data.venueConstraints === undefined || Array.isArray(data.venueConstraints)) &&
    Array.isArray(data.preferredSlots)
  )
}

// ─── Store ───────────────────────────────────────────────────────────────────

export const useWizardStore = create<WizardState>()(
  persist(
    (set, get) => ({
      ...createInitialWizardState(),

      setCurrentStep: (step) => set({ currentStep: step }),

      nextStep: () => {
        const { currentStep, validateStep } = get()
        const errors = validateStep(currentStep)
        if (errors.length > 0) {
          set((state) => ({
            validationErrors: { ...state.validationErrors, [currentStep]: errors },
          }))
          return false
        }
        set((state) => ({
          currentStep: Math.min(state.currentStep + 1, 8) as WizardStep,
          validationErrors: { ...state.validationErrors, [state.currentStep]: [] },
          saveError: null,
        }))
        return true
      },

      prevStep: () =>
        set((state) => ({
          currentStep: Math.max(state.currentStep - 1, 0) as WizardStep,
          saveError: null,
        })),

      // ── Venue actions ──
      addVenue: () =>
        set((state) => ({
          data: { ...state.data, venues: [...state.data.venues, createEmptyVenueData()] },
        })),

      updateVenue: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) => (v.id === id ? { ...v, ...updates } : v)),
          },
        })),

      removeVenue: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.filter((v) => v.id !== id),
            venueConstraints: state.data.venueConstraints.filter((constraint) => constraint.venueId !== id),
          },
        })),

      addVenueRange: (venueId, day) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId
                ? {
                    ...v,
                    availabilityRanges: {
                      ...v.availabilityRanges,
                      [day]: [
                        ...v.availabilityRanges[day],
                        { id: generateId(), start: DEFAULT_RANGE_START, end: DEFAULT_RANGE_END },
                      ],
                    },
                  }
                : v
            ),
          },
        })),

      updateVenueRange: (venueId, day, rangeId, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId
                ? {
                    ...v,
                    availabilityRanges: {
                      ...v.availabilityRanges,
                      [day]: v.availabilityRanges[day].map((range) =>
                        range.id === rangeId ? { ...range, ...updates } : range
                      ),
                    },
                  }
                : v
            ),
          },
        })),

      removeVenueRange: (venueId, day, rangeId) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId
                ? {
                    ...v,
                    availabilityRanges: {
                      ...v.availabilityRanges,
                      [day]: v.availabilityRanges[day].filter((range) => range.id !== rangeId),
                    },
                  }
                : v
            ),
          },
        })),

      addClosure: (venueId, date) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId ? { ...v, closures: [...v.closures, date] } : v
            ),
          },
        })),

      removeClosure: (venueId, date) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId ? { ...v, closures: v.closures.filter((d) => d !== date) } : v
            ),
          },
        })),

      // ── Team actions ──
      addTeam: () =>
        set((state) => ({
          data: { ...state.data, teams: [...state.data.teams, createEmptyTeam()] },
        })),

      updateTeam: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) => (t.id === id ? { ...t, ...updates } : t)),
          },
        })),

      removeTeam: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.filter((t) => t.id !== id),
            preferredSlots: state.data.preferredSlots.filter((ps) => ps.teamId !== id),
            constraints: state.data.constraints.filter((c) => c.teamId !== id),
          },
        })),

      // ── Preferred slot actions ──
      addPreferredSlot: (teamId) =>
        set((state) => ({
          data: {
            ...state.data,
            preferredSlots: [...state.data.preferredSlots, createEmptyPreferredSlot(teamId)],
          },
        })),

      updatePreferredSlot: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            preferredSlots: state.data.preferredSlots.map((ps) =>
              ps.id === id ? { ...ps, ...updates } : ps
            ),
          },
        })),

      removePreferredSlot: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            preferredSlots: state.data.preferredSlots.filter((ps) => ps.id !== id),
          },
        })),

      // ── Tier actions ──
      setTeamTier: (teamId, tier) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) => (t.id === teamId ? { ...t, tier } : t)),
          },
        })),

      // ── Coach actions ──
      addCoach: () =>
        set((state) => ({
          data: { ...state.data, coaches: [...state.data.coaches, createEmptyCoach()] },
        })),

      updateCoach: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) => (c.id === id ? { ...c, ...updates } : c)),
          },
        })),

      removeCoach: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.filter((c) => c.id !== id),
            coachConstraints: state.data.coachConstraints.filter((c) => c.coachId !== id),
          },
        })),

      assignCoachToTeam: (coachId, teamId) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) =>
              c.id === coachId && !c.teamIds.includes(teamId)
                ? { ...c, teamIds: [...c.teamIds, teamId] }
                : c
            ),
          },
        })),

      unassignCoachFromTeam: (coachId, teamId) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) =>
              c.id === coachId
                ? { ...c, teamIds: c.teamIds.filter((tid) => tid !== teamId) }
                : c
            ),
          },
        })),

      // ── Constraint actions ──
      addConstraint: () =>
        set((state) => ({
          data: {
            ...state.data,
            constraints: [...state.data.constraints, createEmptyConstraint()],
          },
        })),

      removeConstraint: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            constraints: state.data.constraints.filter((c) => c.id !== id),
          },
        })),

      updateConstraint: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            constraints: state.data.constraints.map((c) =>
              c.id === id ? { ...c, ...updates } : c
            ),
          },
        })),

      addCoachConstraint: () =>
        set((state) => ({
          data: {
            ...state.data,
            coachConstraints: [...state.data.coachConstraints, createEmptyCoachConstraint()],
          },
        })),

      removeCoachConstraint: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            coachConstraints: state.data.coachConstraints.filter((c) => c.id !== id),
          },
        })),

      updateCoachConstraint: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            coachConstraints: state.data.coachConstraints.map((c) =>
              c.id === id ? { ...c, ...updates } : c
            ),
          },
        })),

      addVenueConstraint: () =>
        set((state) => ({
          data: {
            ...state.data,
            venueConstraints: [
              ...state.data.venueConstraints,
              createEmptyVenueConstraint(state.data.venues[0]?.id || ''),
            ],
          },
        })),

      updateVenueConstraint: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            venueConstraints: state.data.venueConstraints.map((constraint) =>
              constraint.id === id ? { ...constraint, ...updates } : constraint
            ),
          },
        })),

      removeVenueConstraint: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            venueConstraints: state.data.venueConstraints.filter((constraint) => constraint.id !== id),
          },
        })),

      // ── Auto-save ──
      autoSave: async () => {
        const { data } = get()
        set({ isSaving: true, saveError: null })
        try {
          // Save venues
          for (const venue of data.venues) {
            const venueSlots = generateSlotsFromRanges(venue.availabilityRanges)

            await apiClient.post('venues', {
              json: {
                name: venue.name,
                slots: venueSlots,
                closures: venue.closures,
                can_split: venue.can_split,
                source: 'manual',
              },
            })
          }

          for (const constraint of data.venueConstraints) {
            await apiClient.post('venue_constraints', {
              json: {
                venueId: constraint.venueId,
                constraintType: constraint.constraintType,
                constraintValue: constraint.constraintValue,
              },
            })
          }

          // Save coaches
          for (const coach of data.coaches) {
            await apiClient.post('coaches', {
              json: {
                firstName: coach.name,
                lastName: '',
                email: null,
                phone: null,
              },
            })
          }

          // Save teams
          for (const team of data.teams) {
            const teamResult = await apiClient.post('teams', {
              json: {
                name: team.name,
                sportCategoryId: team.sportCategoryId,
                gender: team.gender,
                is_competition: team.is_competition,
                size: team.size,
                sessions_count: team.sessions_count,
                tier: team.tier,
                is_junior: team.is_junior,
              },
            })

            const teamId = (teamResult as { id?: string }).id || team.id

            // Save preferred slots as type=preferred constraints
            for (const ps of data.preferredSlots.filter((p) => p.teamId === team.id)) {
              await apiClient.post('team_constraints', {
                json: {
                  team_id: teamId,
                  type: 'preferred',
                  day: ps.day,
                  start_hour: ps.hour,
                  start_minute: ps.minute,
                  venue_id: ps.venueId,
                  severity: ps.severity,
                },
              })
            }
          }

          // Save explicit constraints
          for (const constraint of data.constraints) {
            await apiClient.post('team_constraints', {
              json: {
                team_id: constraint.teamId,
                type: constraint.type,
                day: constraint.day,
                start_hour: constraint.startHour,
                start_minute: constraint.startMinute,
                end_hour: constraint.endHour,
                end_minute: constraint.endMinute,
                venue_id: constraint.venueId,
                severity: constraint.severity,
              },
            })
          }

            for (const constraint of data.coachConstraints) {
              await apiClient.post('coach_unavailabilities', {
                json: {
                  coach_id: constraint.coachId,
                day: constraint.day,
                start_hour: constraint.startHour,
                start_minute: constraint.startMinute,
                end_hour: constraint.endHour,
                end_minute: constraint.endMinute,
                  venue_id: constraint.venueId,
                },
              })
            }

          } catch (err) {
            set({
              saveError: err instanceof Error ? err.message : 'Save failed',
            })
        } finally {
          set({ isSaving: false })
        }
      },

      // ── Validation ──
      validateStep: (step) => {
        const { data } = get()
        const errors: string[] = []

        switch (step) {
          case 0: {
            // Venues: at least one venue with name and one available slot
            if (data.venues.length === 0) {
              errors.push('Ajoutez au moins une salle')
            }
            for (const venue of data.venues) {
              if (!venue.name.trim()) {
                errors.push('Chaque salle doit avoir un nom')
                break
              }
              const hasAvailable = generateSlotsFromRanges(venue.availabilityRanges).length > 0
              if (!hasAvailable) {
                errors.push(`Ajoutez au moins une plage horaire pour "${venue.name}"`)
              }
            }
            break
          }
          case 1: {
            for (const constraint of data.venueConstraints) {
              if (!constraint.venueId) {
                errors.push('Chaque contrainte de salle doit cibler une salle')
                break
              }

              if (!constraint.constraintValue.trim()) {
                errors.push('Chaque contrainte de salle doit avoir une valeur')
                break
              }
            }
            break
          }
          case 2: {
            // Teams: at least one team with name
            if (data.teams.length === 0) {
              errors.push('Ajoutez au moins une equipe')
            }
            for (const team of data.teams) {
              if (!team.name.trim()) {
                errors.push('Chaque equipe doit avoir un nom')
                break
              }
            }
            break
          }
          case 3: {
            // Team constraints: validate coherence
            for (const constraint of data.constraints) {
              if (!constraint.teamId) {
                errors.push('Chaque contrainte equipe doit cibler une equipe')
                break
              }
            }
            break
          }
          case 4: {
            // Coaches: if coaches exist, each must have a name
            for (const coach of data.coaches) {
              if (!coach.name.trim()) {
                errors.push('Chaque coach doit avoir un nom')
                break
              }
            }
            break
          }
          case 5: {
            // Coach constraints: validate coherence
            for (const constraint of data.coachConstraints) {
              if (!constraint.coachId) {
                errors.push('Chaque contrainte coach doit cibler un coach')
                break
              }
            }
            break
          }
          case 6: {
            // Tier list: all teams should have a tier (default is C)
            break
          }
          case 7: {
            // Validation: no required validation
            break
          }
          case 8: {
            // Summary: no validation needed
            break
          }
        }

        return errors
      },

      resetWizard: () =>
        set(createInitialWizardState()),

      clearSaveError: () =>
        set({ saveError: null }),
    }),
    {
      name: 'wizard-storage',
      version: 2,
      migrate: (persistedState: unknown, version: number) => {
        void version

        if (!isValidWizardState(persistedState)) {
          return createInitialWizardState() as WizardState
        }

        const state = persistedState as Partial<WizardState> & { data?: Partial<WizardData> }

        return {
          ...createInitialWizardState(),
          ...state,
          data: {
            ...createInitialWizardData(),
            ...state.data,
            venueConstraints: Array.isArray(state.data?.venueConstraints) ? state.data.venueConstraints : [],
          },
        } as WizardState
      },
    }
  )
)

// ─── Exports ─────────────────────────────────────────────────────────────────

export { DAYS, DAY_LABELS, START_HOUR, END_HOUR, generateSlotKey, generateSlotsFromRanges, LEVEL_OPTIONS }

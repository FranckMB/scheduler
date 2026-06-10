import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { apiClient } from '@/shared/api/client'

// ─── Types ───────────────────────────────────────────────────────────────────

export type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat'
export type TierLabel = 'S' | 'A' | 'B' | 'C' | 'D'
export type Gender = 'M' | 'F' | ''
export type ConstraintType = 'fixed' | 'forbidden' | 'preferred'
export type FilterLevel = 'all' | 'Regional' | 'Depart' | 'Loisir' | 'National' | 'Elite'
export type FilterJunior = 'all' | 'junior' | 'senior'

export const TIER_ORDER: TierLabel[] = ['S', 'A', 'B', 'C', 'D']

export interface TimeSlot {
  day: DayKey
  hour: number
  minute: number
}

export interface VenueData {
  id: string
  name: string
  slots: Record<string, boolean> // key: "day-hour-minute" → available
  closures: string[] // date strings "YYYY-MM-DD"
  can_split: boolean
}

export interface CoachUnavailability {
  id: string
  day: DayKey
  startHour: number
  startMinute: number
  endHour: number
  endMinute: number
}

export interface CoachData {
  id: string
  name: string
  email: string
  phone: string
  teamIds: string[] // assigned team IDs
  is_player: boolean
  unavailabilities: CoachUnavailability[]
}

export interface TeamData {
  id: string
  name: string
  level: string // Regional/Depart/Loisir/National/Elite
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
}

export interface TeamConstraint {
  id: string
  teamId?: string
  coachId?: string
  type: ConstraintType
  day?: DayKey
  startHour?: number
  startMinute?: number
  endHour?: number
  endMinute?: number
  venueId?: string
}

export interface TeamFilters {
  gender: Gender | 'all'
  level: FilterLevel
  is_junior: FilterJunior
}

export interface WizardData {
  venues: VenueData[]
  teams: TeamData[]
  preferredSlots: PreferredSlot[]
  coaches: CoachData[]
  constraints: TeamConstraint[]
  filters: TeamFilters
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
  updateVenueSlot: (venueId: string, key: string, available: boolean) => void
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

  // Filter actions
  setFilter: (updates: Partial<TeamFilters>) => void

  // Coach actions
  addCoach: () => void
  updateCoach: (id: string, updates: Partial<CoachData>) => void
  removeCoach: (id: string) => void
  addCoachUnavailability: (coachId: string) => void
  removeCoachUnavailability: (coachId: string, unavailId: string) => void
  assignCoachToTeam: (coachId: string, teamId: string) => void
  unassignCoachFromTeam: (coachId: string, teamId: string) => void

  // Constraint actions
  addConstraint: () => void
  removeConstraint: (id: string) => void
  updateConstraint: (id: string, updates: Partial<TeamConstraint>) => void

  // General
  autoSave: () => Promise<void>
  validateStep: (step: WizardStep) => string[]
  resetWizard: () => void
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

function generateSlotKey(day: DayKey, hour: number, minute: number): string {
  return `${day}-${hour}-${minute}`
}

function generateId(): string {
  return `id_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`
}

function createEmptyVenueData(): VenueData {
  const slots: Record<string, boolean> = {}
  for (const day of DAYS) {
    for (let h = START_HOUR; h < END_HOUR; h++) {
      for (const m of [0, 15, 30, 45]) {
        slots[generateSlotKey(day, h, m)] = false
      }
    }
  }
  return {
    id: generateId(),
    name: '',
    slots,
    closures: [],
    can_split: false,
  }
}

function createEmptyTeam(): TeamData {
  return {
    id: generateId(),
    name: '',
    level: '',
    gender: '',
    is_competition: false,
    size: 0,
    sessions_count: 1,
    tier: 'C',
    is_junior: false,
  }
}

function createEmptyCoach(): CoachData {
  return {
    id: generateId(),
    name: '',
    email: '',
    phone: '',
    teamIds: [],
    is_player: false,
    unavailabilities: [],
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
  }
}

// ─── Store ───────────────────────────────────────────────────────────────────

export const useWizardStore = create<WizardState>()(
  persist(
    (set, get) => ({
      currentStep: 0,
      data: {
        venues: [createEmptyVenueData()],
        teams: [],
        preferredSlots: [],
        coaches: [],
        constraints: [],
        filters: { gender: 'all', level: 'all', is_junior: 'all' },
      },
      isSaving: false,
      saveError: null,
      validationErrors: {},

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
        }))
        return true
      },

      prevStep: () =>
        set((state) => ({
          currentStep: Math.max(state.currentStep - 1, 0) as WizardStep,
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
          },
        })),

      updateVenueSlot: (venueId, key, available) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: state.data.venues.map((v) =>
              v.id === venueId
                ? { ...v, slots: { ...v.slots, [key]: available } }
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

      // ── Filter actions ──
      setFilter: (updates) =>
        set((state) => ({
          data: {
            ...state.data,
            filters: { ...state.data.filters, ...updates },
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
            constraints: state.data.constraints.filter((c) => c.coachId !== id),
          },
        })),

      addCoachUnavailability: (coachId) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) =>
              c.id === coachId
                ? {
                    ...c,
                    unavailabilities: [
                      ...c.unavailabilities,
                      {
                        id: generateId(),
                        day: 'mon' as DayKey,
                        startHour: 9,
                        startMinute: 0,
                        endHour: 12,
                        endMinute: 0,
                      },
                    ],
                  }
                : c
            ),
          },
        })),

      removeCoachUnavailability: (coachId, unavailId) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) =>
              c.id === coachId
                ? {
                    ...c,
                    unavailabilities: c.unavailabilities.filter((u) => u.id !== unavailId),
                  }
                : c
            ),
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

      // ── Auto-save ──
      autoSave: async () => {
        const { data } = get()
        set({ isSaving: true, saveError: null })
        try {
          // Save venues
          for (const venue of data.venues) {
            const venueSlots = Object.entries(venue.slots)
              .filter(([, available]) => available)
              .map(([key]) => {
                const [day, hour, minute] = key.split('-')
                return {
                  day,
                  hour: parseInt(hour, 10),
                  minute: parseInt(minute, 10),
                }
              })

            await apiClient.post('venues', {
              json: {
                name: venue.name,
                slots: venueSlots,
                closures: venue.closures,
                can_split: venue.can_split,
              },
            })
          }

          // Save coaches
          for (const coach of data.coaches) {
            await apiClient.post('coaches', {
              json: {
                name: coach.name,
                email: coach.email,
                phone: coach.phone,
                team_ids: coach.teamIds,
                is_player: coach.is_player,
                unavailabilities: coach.unavailabilities,
              },
            })
          }

          // Save teams
          for (const team of data.teams) {
            const teamResult = await apiClient.post('teams', {
              json: {
                name: team.name,
                level: team.level,
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
                },
              })
            }
          }

          // Save explicit constraints
          for (const constraint of data.constraints) {
            await apiClient.post('team_constraints', {
              json: {
                team_id: constraint.teamId,
                coach_id: constraint.coachId,
                type: constraint.type,
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
              const hasAvailable = Object.values(venue.slots).some((v) => v)
              if (!hasAvailable) {
                errors.push(`Sélectionnez au moins un créneau pour "${venue.name}"`)
              }
            }
            break
          }
          case 1: {
            // Teams: at least one team with name
            if (data.teams.length === 0) {
              errors.push('Ajoutez au moins une équipe')
            }
            for (const team of data.teams) {
              if (!team.name.trim()) {
                errors.push('Chaque équipe doit avoir un nom')
                break
              }
            }
            break
          }
          case 2: {
            // Preferred slots: no required validation
            break
          }
          case 3: {
            // Tier list: all teams should have a tier (default is C)
            break
          }
          case 4: {
            // Filters: no required validation
            break
          }
          case 5: {
            // Coaches: if coaches exist, each must have a name
            for (const coach of data.coaches) {
              if (!coach.name.trim()) {
                errors.push('Chaque coach doit avoir un nom')
                break
              }
            }
            break
          }
          case 6: {
            // Constraints: validate coherence
            for (const constraint of data.constraints) {
              if (!constraint.teamId && !constraint.coachId) {
                errors.push('Chaque contrainte doit cibler une équipe ou un coach')
                break
              }
            }
            break
          }
          case 7:
            // Validation: no required validation
            break
          case 8:
            // Summary: no validation needed
            break
        }

        return errors
      },

      resetWizard: () =>
        set({
          currentStep: 0,
          data: {
            venues: [createEmptyVenueData()],
            teams: [],
            preferredSlots: [],
            coaches: [],
            constraints: [],
            filters: { gender: 'all', level: 'all', is_junior: 'all' },
          },
          isSaving: false,
          saveError: null,
          validationErrors: {},
        }),
    }),
    {
      name: 'wizard-storage',
    }
  )
)

// ─── Exports ─────────────────────────────────────────────────────────────────

export { DAYS, DAY_LABELS, START_HOUR, END_HOUR, generateSlotKey, LEVEL_OPTIONS }

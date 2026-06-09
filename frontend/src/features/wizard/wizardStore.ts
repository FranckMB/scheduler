import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { apiClient } from '@/shared/api/client'

// ─── Types ───────────────────────────────────────────────────────────────────

export type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat'

export interface TimeSlot {
  day: DayKey
  hour: number
  minute: number
}

export interface VenueSlot extends TimeSlot {
  available: boolean
}

export interface VenueData {
  slots: Record<string, boolean> // key: "day-hour-minute" → available
  closures: string[] // date strings "YYYY-MM-DD"
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
  unavailabilities: CoachUnavailability[]
}

export type ConstraintType = 'fixed' | 'forbidden' | 'preferred'

export interface TeamConstraint {
  id: string
  type: ConstraintType
  day?: DayKey
  startHour?: number
  startMinute?: number
  endHour?: number
  endMinute?: number
  venueId?: string
}

export interface TeamData {
  id: string
  name: string
  playerCount: number
  level: string
  constraints: TeamConstraint[]
}

export interface WizardData {
  venues: VenueData
  coaches: CoachData[]
  teams: TeamData[]
}

export type WizardStep = 0 | 1 | 2 | 3

interface WizardState {
  currentStep: WizardStep
  data: WizardData
  isSaving: boolean
  saveError: string | null
  validationErrors: Record<number, string[]>

  // Actions
  setCurrentStep: (step: WizardStep) => void
  nextStep: () => boolean
  prevStep: () => void
  updateVenueSlot: (key: string, available: boolean) => void
  addClosure: (date: string) => void
  removeClosure: (date: string) => void
  addCoach: () => void
  updateCoach: (id: string, updates: Partial<CoachData>) => void
  removeCoach: (id: string) => void
  addCoachUnavailability: (coachId: string) => void
  removeCoachUnavailability: (coachId: string, unavailId: string) => void
  addTeam: () => void
  updateTeam: (id: string, updates: Partial<TeamData>) => void
  removeTeam: (id: string) => void
  addTeamConstraint: (teamId: string) => void
  removeTeamConstraint: (teamId: string, constraintId: string) => void
  updateTeamConstraint: (
    teamId: string,
    constraintId: string,
    updates: Partial<TeamConstraint>
  ) => void
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

const START_HOUR = 7
const END_HOUR = 23

function generateSlotKey(day: DayKey, hour: number, minute: number): string {
  return `${day}-${hour}-${minute}`
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
  return { slots, closures: [] }
}

function generateId(): string {
  return `id_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`
}

function createEmptyCoach(): CoachData {
  return {
    id: generateId(),
    name: '',
    email: '',
    phone: '',
    unavailabilities: [],
  }
}

function createEmptyTeam(): TeamData {
  return {
    id: generateId(),
    name: '',
    playerCount: 0,
    level: '',
    constraints: [],
  }
}

// ─── Store ───────────────────────────────────────────────────────────────────

export const useWizardStore = create<WizardState>()(
  persist(
    (set, get) => ({
      currentStep: 0,
      data: {
        venues: createEmptyVenueData(),
        coaches: [],
        teams: [],
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
          currentStep: Math.min(state.currentStep + 1, 3) as WizardStep,
          validationErrors: { ...state.validationErrors, [state.currentStep]: [] },
        }))
        return true
      },

      prevStep: () =>
        set((state) => ({
          currentStep: Math.max(state.currentStep - 1, 0) as WizardStep,
        })),

      updateVenueSlot: (key, available) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: {
              ...state.data.venues,
              slots: { ...state.data.venues.slots, [key]: available },
            },
          },
        })),

      addClosure: (date) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: {
              ...state.data.venues,
              closures: [...state.data.venues.closures, date],
            },
          },
        })),

      removeClosure: (date) =>
        set((state) => ({
          data: {
            ...state.data,
            venues: {
              ...state.data.venues,
              closures: state.data.venues.closures.filter((d) => d !== date),
            },
          },
        })),

      addCoach: () =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: [...state.data.coaches, createEmptyCoach()],
          },
        })),

      updateCoach: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.map((c) =>
              c.id === id ? { ...c, ...updates } : c
            ),
          },
        })),

      removeCoach: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            coaches: state.data.coaches.filter((c) => c.id !== id),
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
                    unavailabilities: c.unavailabilities.filter(
                      (u) => u.id !== unavailId
                    ),
                  }
                : c
            ),
          },
        })),

      addTeam: () =>
        set((state) => ({
          data: {
            ...state.data,
            teams: [...state.data.teams, createEmptyTeam()],
          },
        })),

      updateTeam: (id, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) =>
              t.id === id ? { ...t, ...updates } : t
            ),
          },
        })),

      removeTeam: (id) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.filter((t) => t.id !== id),
          },
        })),

      addTeamConstraint: (teamId) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) =>
              t.id === teamId
                ? {
                    ...t,
                    constraints: [
                      ...t.constraints,
                      {
                        id: generateId(),
                        type: 'fixed' as ConstraintType,
                        day: 'mon' as DayKey,
                        startHour: 18,
                        startMinute: 0,
                        endHour: 20,
                        endMinute: 0,
                      },
                    ],
                  }
                : t
            ),
          },
        })),

      removeTeamConstraint: (teamId, constraintId) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) =>
              t.id === teamId
                ? {
                    ...t,
                    constraints: t.constraints.filter((c) => c.id !== constraintId),
                  }
                : t
            ),
          },
        })),

      updateTeamConstraint: (teamId, constraintId, updates) =>
        set((state) => ({
          data: {
            ...state.data,
            teams: state.data.teams.map((t) =>
              t.id === teamId
                ? {
                    ...t,
                    constraints: t.constraints.map((c) =>
                      c.id === constraintId ? { ...c, ...updates } : c
                    ),
                  }
                : t
            ),
          },
        })),

      autoSave: async () => {
        const { data } = get()
        set({ isSaving: true, saveError: null })
        try {
          // Save venues
          const venueSlots = Object.entries(data.venues.slots)
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
            json: { slots: venueSlots, closures: data.venues.closures },
          })

          // Save coaches
          for (const coach of data.coaches) {
            await apiClient.post('coaches', {
              json: {
                name: coach.name,
                email: coach.email,
                phone: coach.phone,
                unavailabilities: coach.unavailabilities,
              },
            })
          }

          // Save teams and constraints
          for (const team of data.teams) {
            const teamResult = await apiClient.post('teams', {
              json: {
                name: team.name,
                player_count: team.playerCount,
                level: team.level,
              },
            })

            const teamId = (teamResult as { id?: string }).id

            for (const constraint of team.constraints) {
              await apiClient.post('team_constraints', {
                json: {
                  team_id: teamId || team.id,
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
          }
        } catch (err) {
          set({
            saveError: err instanceof Error ? err.message : 'Save failed',
          })
        } finally {
          set({ isSaving: false })
        }
      },

      validateStep: (step) => {
        const { data } = get()
        const errors: string[] = []

        switch (step) {
          case 0: {
            // Venues: at least one slot must be available
            const hasAvailable = Object.values(data.venues.slots).some(
              (v) => v
            )
            if (!hasAvailable) {
              errors.push('Sélectionnez au moins un créneau disponible')
            }
            break
          }
          case 1: {
            // Coaches: at least one coach with name
            if (data.coaches.length === 0) {
              errors.push('Ajoutez au moins un coach')
            }
            for (const coach of data.coaches) {
              if (!coach.name.trim()) {
                errors.push('Chaque coach doit avoir un nom')
                break
              }
            }
            break
          }
          case 2: {
            // Teams: at least one team with name
            if (data.teams.length === 0) {
              errors.push('Ajoutez au moins une équipe')
            }
            for (const team of data.teams) {
              if (!team.name.trim()) {
                errors.push('Chaque équipe doit avoir un nom')
                break
              }
              if (team.playerCount <= 0) {
                errors.push('Chaque équipe doit avoir au moins 1 joueur')
                break
              }
            }
            break
          }
          case 3:
            // Summary: no validation needed
            break
        }

        return errors
      },

      resetWizard: () =>
        set({
          currentStep: 0,
          data: {
            venues: createEmptyVenueData(),
            coaches: [],
            teams: [],
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

export { DAYS, DAY_LABELS, START_HOUR, END_HOUR, generateSlotKey }

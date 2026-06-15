import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'
import type { ComponentType } from 'react'

import type { WizardData } from '@/features/wizard/wizardStore'

const { getJsonMock, postMock } = vi.hoisted(() => ({
  getJsonMock: vi.fn(),
  postMock: vi.fn(),
}))

vi.mock('@/shared/api/client', () => ({
  apiClient: {
    get: vi.fn(() => ({ json: getJsonMock })),
    post: postMock,
  },
}))

vi.mock('@/features/wizard/api/useSportCategories', () => ({
  useSportCategories: () => ({ data: [], isLoading: false }),
}))

const createMockWizardState = (data: WizardData) => ({
  data,
  autoSave: vi.fn().mockResolvedValue(undefined),
})

let TeamStepComponent: ComponentType | null = null
let wizardStoreModule: typeof import('@/features/wizard/wizardStore') | null = null

function renderTeamStep() {
  if (!TeamStepComponent) {
    throw new Error('TeamStep component not loaded')
  }

  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
      mutations: {
        retry: 0,
      },
    },
  })

  const Page = TeamStepComponent
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <Page />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

function createWizardData(): WizardData {
  return {
    venues: [],
    teams: [],
    preferredSlots: [],
    coaches: [],
    constraints: [],
    coachConstraints: [],
    venueConstraints: [],
  }
}

beforeEach(async () => {
  vi.resetModules()
  localStorage.clear()
  getJsonMock.mockReset()
  postMock.mockReset()
  getJsonMock.mockResolvedValue({ 'hydra:member': [], 'hydra:totalItems': 0 })
  postMock.mockResolvedValue({})
  wizardStoreModule = await import('@/features/wizard/wizardStore')
  TeamStepComponent = (await import('./TeamStep')).default
  wizardStoreModule.useWizardStore.setState(createMockWizardState(createWizardData()))
})

afterEach(() => {
  cleanup()
})

describe('TeamStep', () => {
  it('defaults new teams to competition mode', async () => {
    renderTeamStep()

    fireEvent.click(screen.getByRole('button', { name: '+ Ajouter une equipe' }))
    fireEvent.click(screen.getByRole('button', { name: 'Details' }))

    await waitFor(() => {
      expect(wizardStoreModule?.useWizardStore.getState().data.teams).toHaveLength(1)
    })

    const competitionCheckbox = screen.getByRole('checkbox', { name: 'Competition' })

    expect(competitionCheckbox).toBeChecked()
    expect(wizardStoreModule?.useWizardStore.getState().data.teams[0].is_competition).toBe(true)
  })
})

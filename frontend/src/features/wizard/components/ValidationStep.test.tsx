import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import ValidationStep from './ValidationStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

function renderValidationStep() {
  return render(
    <MemoryRouter>
      <ValidationStep />
    </MemoryRouter>
  )
}

function createWizardData(): WizardData {
  return {
    venues: [
      {
        id: 'venue-1',
        name: 'Gymnase Principal',
        availabilityRanges: {
          mon: [{ id: 'range-1', start: '18:00', end: '20:30' }],
          tue: [],
          wed: [],
          thu: [],
          fri: [],
          sat: [],
        },
        closures: [],
        can_split: false,
      },
    ],
    teams: [{ id: 'team-1', name: 'U15 A', level: 'Regional', gender: 'M', is_competition: true, size: 12, sessions_count: 2, tier: 'C', is_junior: false }],
    preferredSlots: [],
    coaches: [],
    constraints: [],
    coachConstraints: [],
  }
}

beforeEach(() => {
  localStorage.clear()
  useWizardStore.setState({
    data: createWizardData(),
    autoSave: vi.fn().mockResolvedValue(undefined),
  } as any)
})

afterEach(() => {
  cleanup()
})

describe('ValidationStep', () => {
  it('warns when teams have no assigned coach', () => {
    renderValidationStep()

    expect(screen.getByText('Equipes sans coach assigne')).toBeInTheDocument()
    expect(screen.getByText('U15 A')).toBeInTheDocument()
    expect(screen.getByText('1 avertissement')).toBeInTheDocument()
  })
})

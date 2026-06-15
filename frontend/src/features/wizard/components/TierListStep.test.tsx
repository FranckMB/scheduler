import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'

import TierListStep from './TierListStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

function createWizardData(): WizardData {
  return {
    venues: [],
    teams: [
      {
        id: 'team-1',
        name: 'U15 Elite',
        level: 'Regional',
        sportCategoryId: null,
        gender: 'M',
        is_competition: true,
        size: 12,
        sessions_count: 2,
        tier: 'C',
        is_junior: false,
      },
    ],
    preferredSlots: [],
    coaches: [
      {
        id: 'coach-1',
        name: 'Coach Alpha',
        teamIds: ['team-1'],
        is_player: false,
        player_team_id: '',
      },
    ],
    constraints: [],
    coachConstraints: [],
    venueConstraints: [],
  }
}

beforeEach(() => {
  localStorage.clear()
  useWizardStore.setState({
    currentStep: 0,
    data: createWizardData(),
    isSaving: false,
    saveError: null,
    validationErrors: {},
  })
})

afterEach(() => {
  cleanup()
})

describe('TierListStep', () => {
  it('renders simplified team cards', () => {
    render(<TierListStep />)

    expect(screen.getByText('U15 Elite')).toBeInTheDocument()
    expect(screen.getByText('Masculin')).toBeInTheDocument()
    expect(screen.getByText('Regional')).toBeInTheDocument()
    expect(screen.getByText('Coach Alpha')).toBeInTheDocument()
    expect(screen.queryByText(/Competition/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/joueurs?/i)).not.toBeInTheDocument()
  })
})

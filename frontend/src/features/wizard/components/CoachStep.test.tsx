import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import CoachStep from './CoachStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

const createMockWizardState = (data: WizardData) => ({
  data,
  autoSave: vi.fn().mockResolvedValue(undefined),
})

function renderCoachStep() {
  return render(
    <MemoryRouter>
      <CoachStep />
    </MemoryRouter>
  )
}

function createWizardData(): WizardData {
  return {
    venues: [],
    teams: [
      { id: 'team-1', name: 'U15 A', level: 'Regional', sportCategoryId: null, gender: 'M', is_competition: true, size: 12, sessions_count: 2, tier: 'C', is_junior: false },
      { id: 'team-2', name: 'U18 A', level: 'Depart', sportCategoryId: null, gender: 'F', is_competition: true, size: 14, sessions_count: 2, tier: 'B', is_junior: false },
    ],
    preferredSlots: [],
    coaches: [
      { id: 'coach-1', name: 'Coach Martin', teamIds: [], is_player: false, player_team_id: '' },
    ],
    constraints: [],
    coachConstraints: [],
    venueConstraints: [],
  }
}

beforeEach(() => {
  localStorage.clear()
  useWizardStore.setState(createMockWizardState(createWizardData()))
})

afterEach(() => {
  cleanup()
})

describe('CoachStep', () => {
  it('handles player status and team assignment', { timeout: 10000 }, () => {
    renderCoachStep()

    fireEvent.click(screen.getByRole('button', { name: 'Details' }))

    fireEvent.click(screen.getByRole('checkbox', { name: 'Est joueur' }))
    const playerTeamLabel = screen.getByText('Equipe joueur')
    const playerTeamSelect = playerTeamLabel.parentElement?.querySelector('select') as HTMLSelectElement
    expect(playerTeamSelect).toBeInTheDocument()
    fireEvent.change(playerTeamSelect, { target: { value: 'team-2' } })

    const assignmentSection = screen.getByText('Assigner aux equipes').closest('div') as HTMLElement
    fireEvent.click(within(assignmentSection).getByRole('button', { name: 'U15 A' }))

    expect(useWizardStore.getState().data.coaches[0]).toMatchObject({
      is_player: true,
      player_team_id: 'team-2',
      teamIds: ['team-1'],
    })
    expect(screen.getByRole('checkbox', { name: 'Est joueur' })).toBeChecked()
    expect(playerTeamSelect).toHaveValue('team-2')
  })
})

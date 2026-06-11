import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import ConstraintStep from './ConstraintStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

function renderConstraintStep() {
  return render(
    <MemoryRouter>
      <ConstraintStep />
    </MemoryRouter>
  )
}

function createWizardData(): WizardData {
  return {
    venues: [{ id: 'venue-1', name: 'Gymnase Principal', availabilityRanges: { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [] }, closures: [], can_split: false }],
    teams: [{ id: 'team-1', name: 'U15 A', level: 'Regional', gender: 'M', is_competition: true, size: 12, sessions_count: 2, tier: 'C', is_junior: false }],
    preferredSlots: [],
    coaches: [{ id: 'coach-1', name: 'Coach Martin', teamIds: [], is_player: false, player_team_id: '' }],
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

describe('ConstraintStep', () => {
  it('creates coach and team constraint rows', () => {
    renderConstraintStep()

    const sections = screen.getAllByRole('button', { name: '+ Ajouter' })
    fireEvent.click(sections[0])
    fireEvent.click(sections[1])

    const coachSection = screen.getByText('Coach Constraints').closest('section') as HTMLElement
    const teamSection = screen.getByText('Team Constraints').closest('section') as HTMLElement

    fireEvent.change(within(coachSection).getByDisplayValue('-- Coach --'), {
      target: { value: 'coach-1' },
    })
    fireEvent.change(within(coachSection).getByDisplayValue('Preference de salle'), {
      target: { value: 'venue-1' },
    })

    fireEvent.change(within(teamSection).getByDisplayValue('-- Equipe --'), {
      target: { value: 'team-1' },
    })
    fireEvent.change(within(teamSection).getByDisplayValue('Flexible'), {
      target: { value: 'Fortement préféré' },
    })

    expect(useWizardStore.getState().data.coachConstraints[0]).toMatchObject({
      coachId: 'coach-1',
      venueId: 'venue-1',
    })
    expect(useWizardStore.getState().data.constraints[0]).toMatchObject({
      teamId: 'team-1',
      severity: 'Fortement préféré',
    })
  })
})

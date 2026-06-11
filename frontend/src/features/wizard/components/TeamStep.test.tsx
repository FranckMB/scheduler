import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import TeamStep from './TeamStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

function renderTeamStep() {
  return render(
    <MemoryRouter>
      <TeamStep />
    </MemoryRouter>
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

describe('TeamStep', () => {
  it('defaults new teams to competition mode', () => {
    renderTeamStep()

    fireEvent.click(screen.getByRole('button', { name: '+ Ajouter une equipe' }))
    fireEvent.click(screen.getByRole('button', { name: 'Details' }))

    const competitionCheckbox = screen.getByRole('checkbox', { name: 'Competition' })

    expect(competitionCheckbox).toBeChecked()
    expect(useWizardStore.getState().data.teams[0].is_competition).toBe(true)
  })
})

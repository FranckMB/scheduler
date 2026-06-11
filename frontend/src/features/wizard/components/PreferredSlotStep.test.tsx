import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import PreferredSlotStep from './PreferredSlotStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

function renderPreferredSlotStep() {
  return render(
    <MemoryRouter>
      <PreferredSlotStep />
    </MemoryRouter>
  )
}

function createWizardData(): WizardData {
  return {
    venues: [{ id: 'venue-1', name: 'Gymnase Principal', availabilityRanges: { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [] }, closures: [], can_split: false }],
    teams: [{ id: 'team-1', name: 'U15 A', level: 'Regional', gender: 'M', is_competition: true, size: 12, sessions_count: 2, tier: 'C', is_junior: false }],
    preferredSlots: [
      {
        id: 'slot-1',
        teamId: 'team-1',
        day: 'mon',
        hour: 18,
        minute: 0,
        venueId: 'venue-1',
        severity: 'Flexible',
      },
    ],
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

describe('PreferredSlotStep', () => {
  it('updates the preferred-slot severity dropdown', () => {
    renderPreferredSlotStep()

    fireEvent.click(screen.getByRole('button', { name: 'Details' }))

    const severitySelect = screen.getByDisplayValue('Flexible')
    fireEvent.change(severitySelect, { target: { value: 'Préféré' } })

    expect(useWizardStore.getState().data.preferredSlots[0].severity).toBe('Préféré')
    expect(screen.getByDisplayValue('Préféré')).toBeInTheDocument()
  })
})

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'

import VenueStep from './VenueStep'
import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

const createMockWizardState = (data: WizardData) => ({
  data,
  autoSave: vi.fn().mockResolvedValue(undefined),
})

function renderVenueStep() {
  return render(
    <MemoryRouter>
      <VenueStep />
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
          mon: [],
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
    teams: [],
    preferredSlots: [],
    coaches: [],
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

describe('VenueStep', () => {
  it('manages time ranges and the split flag', { timeout: 10000 }, () => {
    renderVenueStep()

    fireEvent.click(screen.getByRole('button', { name: 'Details' }))

    const splitCheckbox = screen.getByRole('checkbox', { name: /Split possible/i })
    expect(splitCheckbox).not.toBeChecked()

    fireEvent.click(splitCheckbox)

    expect(useWizardStore.getState().data.venues[0].can_split).toBe(true)

    fireEvent.click(screen.getAllByRole('button', { name: '+ Plage' })[0])

    const [startInput] = screen.getAllByDisplayValue('18:00') as HTMLInputElement[]
    const [endInput] = screen.getAllByDisplayValue('20:00') as HTMLInputElement[]

    expect(startInput).toBeInTheDocument()
    expect(endInput).toBeInTheDocument()

    fireEvent.change(startInput, { target: { value: '19:00' } })
    fireEvent.change(endInput, { target: { value: '21:00' } })

    expect(useWizardStore.getState().data.venues[0].availabilityRanges.mon[0]).toMatchObject({
      start: '19:00',
      end: '21:00',
    })
  })
})

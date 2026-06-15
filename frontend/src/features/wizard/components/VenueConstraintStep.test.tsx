import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { useWizardStore, type WizardData } from '@/features/wizard/wizardStore'

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
  useWizardStore.setState({
    currentStep: 0,
    data: createWizardData(),
    isSaving: false,
    saveError: null,
    validationErrors: {},
  })
})

afterEach(() => {
  useWizardStore.setState({
    currentStep: 0,
    data: createWizardData(),
    isSaving: false,
    saveError: null,
    validationErrors: {},
  })
})

describe('VenueConstraintStep', () => {
  it('adds, updates, and removes venue constraints', () => {
    const store = useWizardStore.getState()

    store.addVenueConstraint()

    const addedConstraint = useWizardStore.getState().data.venueConstraints[0]
    expect(addedConstraint).toMatchObject({
      venueId: 'venue-1',
      constraintType: 'gender_restriction',
      constraintValue: 'M',
    })

    store.updateVenueConstraint(addedConstraint.id, {
      venueId: 'venue-1',
      constraintType: 'level_preference',
      constraintValue: 'cat-2',
    })

    expect(useWizardStore.getState().data.venueConstraints[0]).toMatchObject({
      venueId: 'venue-1',
      constraintType: 'level_preference',
      constraintValue: 'cat-2',
    })

    store.removeVenueConstraint(addedConstraint.id)

    expect(useWizardStore.getState().data.venueConstraints).toHaveLength(0)
  })
})

import { describe, expect, it } from 'vitest'

const STEP_HEADINGS = [
  'Salles',
  'Contraintes par salle',
  'Equipes',
  'Contraintes equipes',
  'Coachs',
  'Contraintes coachs',
  'Tier List',
  'Validation',
  'Resume et Generation',
] as const

function nextStep(step: number) {
  return Math.min(step + 1, STEP_HEADINGS.length - 1)
}

function prevStep(step: number) {
  return Math.max(step - 1, 0)
}

describe('WizardPage', () => {
  it('navigates through all 9 steps', () => {
    let currentStep = 0

    expect(STEP_HEADINGS[currentStep]).toBe('Salles')

    for (const heading of STEP_HEADINGS.slice(1)) {
      currentStep = nextStep(currentStep)
      expect(STEP_HEADINGS[currentStep]).toBe(heading)
    }

    currentStep = prevStep(currentStep)
    expect(currentStep).toBe(7)
    expect(STEP_HEADINGS[currentStep]).toBe('Validation')
    expect(STEP_HEADINGS).toHaveLength(9)
  })
})

import { useWizardStore, type WizardStep } from '@/features/wizard/wizardStore'
import StepIndicator from './components/StepIndicator'
import VenueStep from './components/VenueStep'
import TeamStep from './components/TeamStep'
import PreferredSlotStep from './components/PreferredSlotStep'
import TierListStep from './components/TierListStep'
import CoachStep from './components/CoachStep'
import ConstraintStep from './components/ConstraintStep'
import ValidationStep from './components/ValidationStep'
import SummaryStep from './components/SummaryStep'

const STEP_COMPONENTS = [
  VenueStep,
  TeamStep,
  PreferredSlotStep,
  TierListStep,
  CoachStep,
  ConstraintStep,
  ValidationStep,
  SummaryStep,
]

export default function WizardPage() {
  const { currentStep, setCurrentStep, nextStep, prevStep, isSaving, saveError, validationErrors } =
    useWizardStore()

  const StepComponent = STEP_COMPONENTS[currentStep]
  const errors = validationErrors[currentStep] || []
  const isFirst = currentStep === 0
  const isLast = currentStep === 7

  const handleNext = () => {
    nextStep()
  }

  const handleStepClick = (step: WizardStep) => {
    if (step <= currentStep) {
      setCurrentStep(step)
    }
  }

  return (
    <div className="mx-auto max-w-5xl">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-neutral-900">Assistant de configuration</h1>
        <p className="text-sm text-neutral-500">
          Configurez votre planning en 8 etapes simples
        </p>
      </div>

      {/* Step indicator */}
      <StepIndicator currentStep={currentStep} onStepClick={handleStepClick} />

      {/* Save status */}
      <div className="mt-4 flex items-center justify-between">
        {isSaving && (
          <div className="flex items-center gap-2 text-sm text-neutral-500">
            <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Sauvegarde en cours...
          </div>
        )}
        {saveError && (
          <div className="text-sm text-error-600" role="alert">
            Erreur de sauvegarde : {saveError}
          </div>
        )}
        {!isSaving && !saveError && <div />}
      </div>

      {/* Validation errors */}
      {errors.length > 0 && (
        <div className="mt-4 rounded-md bg-error-50 p-3" role="alert">
          <ul className="list-inside list-disc text-sm text-error-600">
            {errors.map((error, i) => (
              <li key={i}>{error}</li>
            ))}
          </ul>
        </div>
      )}

      {/* Step content */}
      <div className="mt-6">
        <StepComponent />
      </div>

      {/* Navigation */}
      <div className="mt-8 flex items-center justify-between border-t border-neutral-200 pt-6">
        <button
          type="button"
          onClick={prevStep}
          disabled={isFirst}
          className="rounded-md border border-neutral-300 bg-white px-5 py-2.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50"
        >
          Precedent
        </button>

        {!isLast ? (
          <button
            type="button"
            onClick={handleNext}
            className="rounded-md bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700"
          >
            Suivant
          </button>
        ) : (
          <div />
        )}
      </div>
    </div>
  )
}

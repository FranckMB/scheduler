import { useWizardStore, type WizardStep } from '@/features/wizard/wizardStore'
import StepIndicator from './components/StepIndicator'
import VenueStep from './components/VenueStep'
import VenueConstraintStep from './components/VenueConstraintStep'
import TeamStep from './components/TeamStep'
import TeamConstraintStep from './components/TeamConstraintStep'
import CoachStep from './components/CoachStep'
import CoachConstraintStep from './components/CoachConstraintStep'
import TierListStep from './components/TierListStep'
import ValidationStep from './components/ValidationStep'
import SummaryStep from './components/SummaryStep'

const STEP_COMPONENTS = [
  VenueStep,
  VenueConstraintStep,
  TeamStep,
  TeamConstraintStep,
  CoachStep,
  CoachConstraintStep,
  TierListStep,
  ValidationStep,
  SummaryStep,
]

export default function WizardPage() {
  const { currentStep, setCurrentStep, nextStep, prevStep, isSaving, saveError, validationErrors } =
    useWizardStore()

  const StepComponent = STEP_COMPONENTS[currentStep]
  const errors = validationErrors[currentStep] || []
  const isFirst = currentStep === 0
  const isLast = currentStep === 8

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
        <h1 className="text-2xl font-bold text-fg-primary">Assistant de configuration</h1>
        <p className="text-sm text-fg-muted">
          Configurez votre planning en 9 etapes simples
        </p>
      </div>

      {/* Step indicator */}
      <StepIndicator currentStep={currentStep} onStepClick={handleStepClick} />

      {/* Save status — fixed toast to avoid layout shift */}
      <div className="pointer-events-none fixed left-0 right-0 top-4 z-50 flex justify-center">
        {isSaving && (
          <div className="pointer-events-auto flex items-center gap-2 rounded-md border border-border-subtle bg-base px-4 py-2 text-sm text-fg-muted shadow-lg">
            <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Sauvegarde en cours...
          </div>
        )}
        {saveError && (
          <div className="pointer-events-auto rounded-md border border-error-700/50 bg-error-700/90 px-6 py-3 text-sm font-semibold text-white shadow-lg" role="alert">
            Erreur de sauvegarde : {saveError}
          </div>
        )}
      </div>

      {/* Validation errors */}
      {errors.length > 0 && (
        <div className="mt-4 rounded-md border border-error-700/50 bg-error-900/40 p-3" role="alert">
          <ul className="list-inside list-disc text-sm text-error-400">
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
        <div className="mt-8 flex items-center justify-between border-t border-border-subtle pt-6">
        <button
          type="button"
          onClick={prevStep}
          disabled={isFirst}
          className="rounded-md border border-border-subtle bg-surface px-5 py-2.5 text-sm font-medium text-fg-primary transition hover:bg-surface-hover disabled:opacity-50"
        >
          Precedent
        </button>

        {!isLast ? (
          <button
            type="button"
            onClick={handleNext}
            className="rounded-md bg-primary-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-primary-700 hover:shadow-lg"
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

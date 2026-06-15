import type { WizardStep } from '@/features/wizard/wizardStore'

const STEP_LABELS = [
  'Salles',
  'Contraintes salles',
  'Equipes',
  'Contraintes equipes',
  'Coachs',
  'Contraintes coachs',
  'Tiers',
  'Validation',
  'Resume',
]

interface StepIndicatorProps {
  currentStep: WizardStep
  onStepClick?: (step: WizardStep) => void
}

export default function StepIndicator({ currentStep, onStepClick }: StepIndicatorProps) {
  const progress = ((currentStep + 1) / STEP_LABELS.length) * 100

  return (
    <div className="w-full">
      {/* Progress bar */}
      <div className="mb-6 h-2 w-full overflow-hidden rounded-full bg-neutral-800">
        <div
          className="h-full rounded-full bg-primary-600 transition-all duration-500 ease-out"
          style={{ width: `${progress}%` }}
        />
      </div>

      {/* Step labels */}
      <div className="flex items-center justify-between">
        {STEP_LABELS.map((label, index) => {
          const step = index as WizardStep
          const isActive = step === currentStep
          const isCompleted = step < currentStep

          return (
            <button
              key={label}
              type="button"
              onClick={() => onStepClick?.(step)}
              className={`flex flex-1 items-center justify-center gap-1 rounded-lg px-1 py-2 text-xs font-medium transition-colors sm:gap-2 sm:px-3 sm:py-2 sm:text-sm ${
                isActive
                  ? 'bg-surface text-primary-400'
                  : isCompleted
                    ? 'text-success-400 hover:bg-surface'
                    : 'text-fg-disabled'
              }`}
              disabled={!onStepClick}
            >
              <span
                className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold sm:h-7 sm:w-7 ${
                  isActive
                    ? 'bg-primary-600 text-white'
                    : isCompleted
                      ? 'bg-success-600 text-white'
                      : 'bg-neutral-800 text-fg-disabled'
                }`}
              >
                {isCompleted ? (
                  <svg className="h-3 w-3 sm:h-4 sm:w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                ) : (
                  index + 1
                )}
              </span>
              <span className="hidden lg:inline">{label}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
}

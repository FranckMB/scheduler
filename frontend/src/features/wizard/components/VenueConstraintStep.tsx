import { useSportCategories } from '@/features/wizard/api/useSportCategories'
import {
  useWizardStore,
  type VenueConstraint,
  type VenueConstraintType,
} from '@/features/wizard/wizardStore'

const CONSTRAINT_TYPE_LABELS: Record<VenueConstraintType, string> = {
  gender_restriction: 'Restriction de genre',
  level_preference: 'Preference de niveau',
}

const GENDER_OPTIONS = [
  { value: 'M', label: 'Masculin (M)' },
  { value: 'F', label: 'Féminin (F)' },
] as const

export default function VenueConstraintStep() {
  const { data, addVenueConstraint, updateVenueConstraint, removeVenueConstraint } = useWizardStore()
  const { data: sportCategories = [], isLoading: sportCategoriesLoading } = useSportCategories()

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h2 className="text-xl font-bold text-fg-primary">Contraintes par salle</h2>
          <p className="text-sm text-fg-muted">
            Associez une salle à une restriction de genre ou à un niveau de catégorie.
          </p>
        </div>
        <button
          type="button"
          onClick={addVenueConstraint}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 hover:shadow-lg"
        >
          + Ajouter une contrainte
        </button>
      </div>

      {data.venueConstraints.length === 0 ? (
        <p className="glass rounded-md border border-border-subtle bg-bg-elevated p-4 text-sm text-fg-disabled">
          Aucune contrainte de salle.
        </p>
      ) : (
        <div className="space-y-3">
          {data.venueConstraints.map((constraint) => (
            <VenueConstraintRow
              key={constraint.id}
              constraint={constraint}
              venues={data.venues}
              sportCategories={sportCategories}
              sportCategoriesLoading={sportCategoriesLoading}
              onUpdate={(updates) => updateVenueConstraint(constraint.id, updates)}
              onRemove={() => removeVenueConstraint(constraint.id)}
            />
          ))}
        </div>
      )}
    </div>
  )
}

interface VenueConstraintRowProps {
  constraint: VenueConstraint
  venues: { id: string; name: string }[]
  sportCategories: { id: string; name: string }[]
  sportCategoriesLoading: boolean
  onUpdate: (updates: Partial<VenueConstraint>) => void
  onRemove: () => void
}

function VenueConstraintRow({
  constraint,
  venues,
  sportCategories,
  sportCategoriesLoading,
  onUpdate,
  onRemove,
}: VenueConstraintRowProps) {
  const handleTypeChange = (nextType: VenueConstraintType) => {
    if (nextType === 'gender_restriction') {
      onUpdate({
        constraintType: nextType,
        constraintValue: constraint.constraintValue === 'F' ? 'F' : 'M',
      })
      return
    }

    onUpdate({
      constraintType: nextType,
      constraintValue: sportCategories[0]?.id || '',
    })
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border border-border-subtle bg-bg-elevated p-4 lg:flex-row lg:flex-wrap lg:items-center">
      <div className="min-w-0 flex-1">
        <label className="mb-1 block text-xs font-medium text-fg-muted">Salle</label>
        <select
          value={constraint.venueId}
          onChange={(e) => onUpdate({ venueId: e.target.value })}
          className="w-full rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
        >
          <option value="">Choisir une salle</option>
          {venues.map((venue) => (
            <option key={venue.id} value={venue.id}>
              {venue.name || 'Sans nom'}
            </option>
          ))}
        </select>
      </div>

      <div className="min-w-0 flex-1">
        <label className="mb-1 block text-xs font-medium text-fg-muted">Type</label>
        <select
          value={constraint.constraintType}
          onChange={(e) => handleTypeChange(e.target.value as VenueConstraintType)}
          className="w-full rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
        >
          {Object.entries(CONSTRAINT_TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </div>

      <div className="min-w-0 flex-1">
        <label className="mb-1 block text-xs font-medium text-fg-muted">Valeur</label>
        {constraint.constraintType === 'gender_restriction' ? (
          <select
            value={constraint.constraintValue}
            onChange={(e) => onUpdate({ constraintValue: e.target.value })}
            className="w-full rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500"
          >
            {GENDER_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        ) : (
          <select
            value={constraint.constraintValue}
            onChange={(e) => onUpdate({ constraintValue: e.target.value })}
            disabled={sportCategoriesLoading && sportCategories.length === 0}
            className="w-full rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-60"
          >
            <option value="">
              {sportCategoriesLoading && sportCategories.length === 0
                ? 'Chargement des niveaux...'
                : 'Choisir un niveau'}
            </option>
            {sportCategories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name || 'Sans nom'}
              </option>
            ))}
          </select>
        )}
      </div>

      <button
        type="button"
        onClick={onRemove}
        className="self-end rounded-md border border-border-subtle px-3 py-2 text-sm text-fg-muted transition hover:bg-surface-hover hover:text-fg-primary lg:self-auto"
        aria-label="Supprimer la contrainte de salle"
      >
        x
      </button>
    </div>
  )
}

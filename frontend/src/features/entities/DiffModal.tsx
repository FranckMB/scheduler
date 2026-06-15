interface PendingChange {
  entityType: string
  entityId: string
  entityName: string
  before: string
  after: string
}

interface DiffModalProps {
  changes: PendingChange[]
  onConfirm: () => void
  onCancel: () => void
  isRegenerating: boolean
}

const TYPE_LABELS: Record<string, string> = {
  Salle: 'Salle',
  'Contrainte salle': 'Contrainte salle',
  Équipe: 'Équipe',
  'Contrainte équipe': 'Contrainte équipe',
  Coach: 'Coach',
  'Contrainte coach': 'Contrainte coach',
}

export default function DiffModal({
  changes,
  onConfirm,
  onCancel,
  isRegenerating,
}: DiffModalProps) {
  if (changes.length === 0) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-lg rounded-2xl border border-border-subtle bg-bg-deep shadow-2xl">
        {/* Header */}
        <div className="border-b border-border-subtle px-6 py-4">
          <h3 className="text-lg font-semibold text-fg-primary">
            Aperçu des changements avant regénération
          </h3>
        </div>

        {/* Changes list */}
        <div className="max-h-80 overflow-y-auto px-6 py-4">
          <div className="space-y-3">
            {changes.map((change, index) => (
              <div
                key={`${change.entityId}-${index}`}
                className="rounded-lg border border-border-subtle bg-surface/50 p-3"
              >
                <div className="mb-2 flex items-center gap-2">
                  <span className="rounded-md bg-accent/20 px-2 py-0.5 text-xs font-medium text-accent">
                    {TYPE_LABELS[change.entityType] ?? change.entityType}
                  </span>
                  <span className="text-sm font-medium text-fg-primary">
                    {change.entityName}
                  </span>
                </div>

                <div className="grid grid-cols-2 gap-3 text-xs">
                  <div>
                    <div className="mb-1 text-fg-muted">Avant</div>
                    <div className="rounded-md bg-error-500/10 px-2 py-1.5 font-mono text-error-400 line-through">
                      {change.before}
                    </div>
                  </div>
                  <div>
                    <div className="mb-1 text-fg-muted">Après</div>
                    <div className="rounded-md bg-success-500/10 px-2 py-1.5 font-mono text-success-400">
                      {change.after}
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Warning */}
        <div className="mx-6 mb-4 rounded-lg border border-warning-700/50 bg-warning-500/10 px-4 py-3">
          <div className="flex items-start gap-2">
            <svg className="mt-0.5 h-4 w-4 shrink-0 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
            <p className="text-sm text-warning-400">
              Le planning actuel sera remplacé.
            </p>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 border-t border-border-subtle px-6 py-4">
          <button
            type="button"
            onClick={onCancel}
            disabled={isRegenerating}
            className="rounded-md border border-border-subtle bg-surface px-4 py-2 text-sm font-medium text-fg-primary hover:bg-surface-hover disabled:opacity-50"
          >
            Annuler
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={isRegenerating}
            className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent/90 disabled:opacity-60"
          >
            {isRegenerating ? 'Regénération...' : 'Regénérer'}
          </button>
        </div>
      </div>
    </div>
  )
}

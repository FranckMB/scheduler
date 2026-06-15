interface RegenerationWarningModalProps {
  onConfirm: () => void
  onCancel: () => void
  isPending: boolean
}

export default function RegenerationWarningModal({
  onConfirm,
  onCancel,
  isPending,
}: RegenerationWarningModalProps) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-md rounded-2xl border border-warning-700 bg-bg-deep p-6 shadow-2xl">
        <h3 className="text-xl font-semibold text-fg-primary">
          ⚠️ Attention
        </h3>
        <p className="mt-3 text-sm text-fg-muted">
          Modifier une entité nécessitera une regénération du planning.
        </p>

        <div className="mt-6 flex items-center justify-end gap-3">
          <button
            type="button"
            onClick={onCancel}
            disabled={isPending}
            className="rounded-md border border-border-subtle bg-surface px-4 py-2 text-sm font-medium text-fg-primary"
          >
            Annuler
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={isPending}
            className="rounded-md bg-warning-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
          >
            {isPending ? 'Regénération...' : 'Modifier et regénérer'}
          </button>
        </div>
      </div>
    </div>
  )
}

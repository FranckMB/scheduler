import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import { useAuthStore } from '@/features/auth/authStore'

const CONFIRMATION_TEXT = 'je veux réinitialiser ma saison'

export default function ProfilePage() {
  const navigate = useNavigate()
  const [isOpen, setIsOpen] = useState(false)
  const [confirmationInput, setConfirmationInput] = useState('')

  const isConfirmed = confirmationInput === CONFIRMATION_TEXT

  const resetMutation = useMutation({
    mutationFn: async () => {
      await apiClient.delete('reset-season')
    },
    onSuccess: () => {
      useAuthStore.getState().setHasGenerated(false)
      navigate('/wizard')
    },
  })

  const handleOpen = () => {
    setConfirmationInput('')
    setIsOpen(true)
  }

  const handleClose = () => {
    setConfirmationInput('')
    setIsOpen(false)
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-fg-primary">Profil</h1>
        <p className="mt-1 text-sm text-fg-muted">Gestion du compte et réinitialisation de la saison.</p>
      </div>

      {/* Danger zone */}
      <section className="glass rounded-xl border border-error-600/30 p-6 shadow-lg">
        <h2 className="text-lg font-semibold text-error-500">Zone de danger</h2>
        <p className="mt-2 text-sm text-fg-muted">
          Les actions de cette zone sont irréversibles. Procédez avec précaution.
        </p>

        <button
          type="button"
          onClick={handleOpen}
          className="mt-4 rounded-lg bg-error-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-error-700"
        >
          Réinitialiser ma saison
        </button>
      </section>

      {/* Confirmation modal */}
      {isOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className="w-full max-w-lg rounded-2xl border border-error-700 bg-bg-deep p-6 shadow-2xl">
            {/* Header */}
            <div className="flex items-start gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-error-600/20 text-xl">
                ⚠️
              </div>
              <div>
                <h3 className="text-lg font-semibold text-fg-primary">
                  Cette action est irréversible
                </h3>
                <p className="mt-1 text-sm text-fg-muted">
                  Veuillez lire attentivement les conséquences avant de continuer.
                </p>
              </div>
            </div>

            {/* Risk list */}
            <div className="mt-5 rounded-lg border border-error-600/30 bg-error-600/10 p-4">
              <ul className="space-y-2 text-sm text-error-400">
                <li className="flex items-start gap-2">
                  <span className="mt-0.5 text-error-500">•</span>
                  Toutes les données de la saison seront supprimées
                </li>
                <li className="flex items-start gap-2">
                  <span className="mt-0.5 text-error-500">•</span>
                  Vous devrez refaire le wizard d&apos;onboarding
                </li>
                <li className="flex items-start gap-2">
                  <span className="mt-0.5 text-error-500">•</span>
                  Cette action ne peut pas être annulée
                </li>
              </ul>
            </div>

            {/* Confirmation input */}
            <div className="mt-5">
              <label htmlFor="confirm-reset" className="mb-1.5 block text-sm font-medium text-fg-primary">
                Pour confirmer, tapez exactement le texte ci-dessous :
              </label>
              <code className="mb-2 block rounded-md border border-border-subtle bg-surface px-3 py-1.5 text-sm text-fg-muted">
                {CONFIRMATION_TEXT}
              </code>
              <input
                id="confirm-reset"
                type="text"
                value={confirmationInput}
                onChange={(e) => setConfirmationInput(e.target.value)}
                placeholder="Tapez 'je veux réinitialiser ma saison' pour confirmer"
                className="w-full rounded-md border border-border-subtle bg-surface px-3 py-2 text-sm text-fg-primary placeholder:text-fg-disabled focus:border-error-500 focus:outline-none focus:ring-1 focus:ring-error-500"
                autoComplete="off"
              />
            </div>

            {/* Actions */}
            <div className="mt-6 flex items-center justify-end gap-3">
              <button
                type="button"
                onClick={handleClose}
                disabled={resetMutation.isPending}
                className="rounded-md border border-border-subtle bg-surface px-4 py-2 text-sm font-medium text-fg-primary transition hover:bg-surface-hover disabled:opacity-60"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={() => resetMutation.mutate()}
                disabled={!isConfirmed || resetMutation.isPending}
                className="rounded-md bg-error-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-error-700 disabled:cursor-not-allowed disabled:opacity-40"
              >
                {resetMutation.isPending ? (
                  <span className="flex items-center gap-2">
                    <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                    Réinitialisation...
                  </span>
                ) : (
                  'Réinitialiser'
                )}
              </button>
            </div>

            {/* Error message */}
            {resetMutation.isError && (
              <div className="mt-4 rounded-md border border-error-600/30 bg-error-600/10 p-3 text-sm text-error-400">
                Échec de la réinitialisation. Veuillez réessayer.
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

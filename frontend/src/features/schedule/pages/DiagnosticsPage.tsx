import { useParams } from 'react-router-dom'
import { DiagnosticsPanel } from '@/features/schedule/components/DiagnosticsPanel'
import { useScheduleDiagnostics } from '@/features/schedule/api/useScheduleDiagnostics'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'

export default function DiagnosticsPage() {
  const { id } = useParams<{ id: string }>()
  const { data, isLoading, error } = useScheduleDiagnostics(id ?? '')

  if (!id) {
    return (
      <div className="mx-auto max-w-4xl">
        <div className="rounded-lg bg-error-50 p-6 text-center">
          <p className="text-error-600">Identifiant d&apos;emploi du temps manquant.</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="mx-auto max-w-4xl">
        <div className="rounded-lg bg-white p-12 text-center shadow-sm">
          <LoadingSpinner size="lg" />
          <p className="mt-4 text-neutral-600">Analyse de l&apos;emploi du temps en cours...</p>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="mx-auto max-w-4xl">
        <div className="rounded-lg bg-error-50 p-6 text-center">
          <svg
            className="mx-auto h-12 w-12 text-error-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M12 9v2m0 4h.01M12 3l9.5 16.5H2.5L12 3z"
            />
          </svg>
          <h3 className="mt-3 text-lg font-medium text-error-600">Erreur de chargement</h3>
          <p className="mt-1 text-sm text-neutral-600">
            Impossible de récupérer les diagnostics. Veuillez réessayer.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-6">
        <h2 className="text-2xl font-bold text-neutral-900">Diagnostics de l&apos;emploi du temps</h2>
        <p className="mt-1 text-sm text-neutral-600">
          Résultats de l&apos;analyse après génération. Les éléments ci-dessous indiquent les
          problèmes détectés et les actions recommandées.
        </p>
      </div>

      <DiagnosticsPanel diagnostics={data ?? []} />
    </div>
  )
}

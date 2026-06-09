import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'
import type { SchedulePdfExport } from '@/features/schedule/types/schedule'

interface ExportPdfButtonProps {
  scheduleId: string
}

export function ExportPdfButton({ scheduleId }: ExportPdfButtonProps) {
  const [isExporting, setIsExporting] = useState(false)
  const queryClient = useQueryClient()

  const exportMutation = useMutation({
    mutationFn: () =>
      apiClient.post(`schedules/${scheduleId}/export-pdf`).json(),
    onSuccess: () => {
      setIsExporting(true)
      queryClient.invalidateQueries({ queryKey: ['schedule-pdf', scheduleId] })
    },
  })

  const { data: schedulePdf } = useQuery({
    queryKey: ['schedule-pdf', scheduleId],
    queryFn: () =>
      apiClient
        .get(`schedules/${scheduleId}`)
        .json<SchedulePdfExport>(),
    enabled: isExporting,
    refetchInterval: (query) => {
      const status = query.state.data?.pdfExportStatus
      return status === 'done' || status === 'failed' ? false : 3000
    },
  })

  const status = schedulePdf?.pdfExportStatus
  const isDone = status === 'done'
  const isFailed = status === 'failed'
  const isLoading = isExporting && !isDone && !isFailed

  const handleRetry = () => {
    setIsExporting(false)
    queryClient.removeQueries({ queryKey: ['schedule-pdf', scheduleId] })
  }

  if (isLoading) {
    return (
      <button
        type="button"
        disabled
        className="inline-flex items-center gap-2 rounded-md bg-neutral-200 px-4 py-2 text-sm font-medium text-neutral-600 cursor-not-allowed"
      >
        <LoadingSpinner size="sm" />
        Génération en cours...
      </button>
    )
  }

  if (isDone && schedulePdf?.pdfExportUrl) {
    return (
      <a
        href={schedulePdf.pdfExportUrl}
        download
        className="inline-flex items-center gap-2 rounded-md bg-success-600 px-4 py-2 text-sm font-medium text-white hover:bg-success-500 transition-colors"
      >
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        Télécharger PDF
      </a>
    )
  }

  if (isFailed) {
    return (
      <div className="flex items-center gap-2">
        <span className="text-sm text-error-600">Échec de la génération</span>
        <button
          type="button"
          onClick={handleRetry}
          className="rounded-md border border-error-600 px-3 py-1.5 text-sm font-medium text-error-600 hover:bg-error-50 transition-colors"
        >
          Réessayer
        </button>
      </div>
    )
  }

  return (
    <button
      type="button"
      onClick={() => exportMutation.mutate()}
      disabled={exportMutation.isPending}
      className="inline-flex items-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
    >
      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
      Exporter PDF
    </button>
  )
}

import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/shared/api/client'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'

interface ExportPdfButtonProps {
  scheduleId: string
}

type PdfExportStatus = 'idle' | 'pending' | 'generating' | 'completed' | 'failed'

interface SchedulePdfExportResponse {
  pdfExportStatus: string | null
  pdfExportUrl: string | null
}

function normalizePdfExportStatus(status: string | null | undefined): PdfExportStatus {
  switch (status) {
    case 'pending':
    case 'generating':
    case 'completed':
    case 'failed':
      return status
    case 'done':
      return 'completed'
    default:
      return 'idle'
  }
}

function getStatusLabel(status: PdfExportStatus): string {
  switch (status) {
    case 'pending':
      return 'pending'
    case 'generating':
      return 'generating'
    case 'completed':
      return 'completed'
    case 'failed':
      return 'failed'
    default:
      return ''
  }
}

function isTerminalStatus(status: PdfExportStatus): boolean {
  return status === 'completed' || status === 'failed'
}

export function ExportPdfButton({ scheduleId }: ExportPdfButtonProps) {
  const [isExportRequested, setIsExportRequested] = useState(false)
  const queryClient = useQueryClient()

  const scheduleQuery = useQuery({
    queryKey: ['schedule', scheduleId],
    queryFn: () =>
      apiClient
        .get(`schedules/${scheduleId}`)
        .json<SchedulePdfExportResponse>(),
    enabled: !!scheduleId,
    refetchInterval: (query) => {
      const currentStatus = normalizePdfExportStatus(query.state.data?.pdfExportStatus)

      return isExportRequested && !isTerminalStatus(currentStatus) ? 2000 : false
    },
    refetchIntervalInBackground: true,
  })

  const exportMutation = useMutation({
    mutationFn: () =>
      apiClient.post(`schedules/${scheduleId}/export-pdf`).json(),
    onSuccess: () => {
      setIsExportRequested(true)
      queryClient.invalidateQueries({ queryKey: ['schedule', scheduleId] })
      void scheduleQuery.refetch()
    },
  })

  const status = normalizePdfExportStatus(scheduleQuery.data?.pdfExportStatus)
  const statusLabel = useMemo(() => getStatusLabel(status), [status])
  const pdfExportUrl = scheduleQuery.data?.pdfExportUrl

  if (status === 'completed' && pdfExportUrl) {
    return (
      <a
        href={pdfExportUrl}
        download
        className="inline-flex items-center gap-2 rounded-md bg-success-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-success-500"
      >
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
        </svg>
        Télécharger PDF
        <span className="rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
          {statusLabel}
        </span>
      </a>
    )
  }

  if (status === 'failed') {
    return (
      <div className="flex flex-col gap-2">
        <div className="inline-flex items-center gap-2 text-sm font-medium text-error-600">
          <span>Export PDF failed</span>
          <span className="rounded-full bg-error-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-error-700">
            {statusLabel}
          </span>
        </div>
        <button
          type="button"
          onClick={() => {
            setIsExportRequested(false)
            queryClient.removeQueries({ queryKey: ['schedule', scheduleId] })
            exportMutation.mutate()
          }}
          className="inline-flex items-center gap-2 rounded-md border border-error-600 px-3 py-1.5 text-sm font-medium text-error-600 transition-colors hover:bg-error-50"
        >
          Réessayer
        </button>
      </div>
    )
  }

  if (status === 'pending' || status === 'generating') {
    return (
      <button
        type="button"
        disabled
        className="inline-flex items-center gap-2 rounded-md bg-neutral-200 px-4 py-2 text-sm font-medium text-neutral-700 cursor-not-allowed"
      >
        <LoadingSpinner size="sm" />
        Export PDF
        <span className="rounded-full bg-neutral-300 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-neutral-700">
          {statusLabel}
        </span>
      </button>
    )
  }

  return (
    <button
      type="button"
      onClick={() => exportMutation.mutate()}
      disabled={exportMutation.isPending}
      className="inline-flex items-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50"
    >
      <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
      Exporter PDF
      {exportMutation.isPending && <LoadingSpinner size="sm" />}
    </button>
  )
}

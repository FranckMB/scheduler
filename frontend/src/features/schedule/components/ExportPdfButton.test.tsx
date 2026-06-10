import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, fireEvent, waitFor } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const { apiClientMock, getJsonMock, postJsonMock } = vi.hoisted(() => {
  const getJsonMock = vi.fn()
  const postJsonMock = vi.fn()

  return {
    getJsonMock,
    postJsonMock,
    apiClientMock: {
      get: vi.fn(() => ({ json: getJsonMock })),
      post: vi.fn(() => ({ json: postJsonMock })),
    },
  }
})

vi.mock('@/shared/api/client', () => ({
  apiClient: apiClientMock,
}))

import { ExportPdfButton } from './ExportPdfButton'

function renderButton() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
      mutations: {
        retry: 0,
      },
    },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <ExportPdfButton scheduleId="schedule-1" />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  getJsonMock.mockReset()
  postJsonMock.mockReset()
  apiClientMock.get.mockClear()
  apiClientMock.post.mockClear()
})

afterEach(() => {
  cleanup()
  vi.clearAllTimers()
})

describe('ExportPdfButton', () => {
  it('posts the export request when clicked', async () => {
    getJsonMock.mockResolvedValue({ pdfExportStatus: null, pdfExportUrl: null })
    postJsonMock.mockResolvedValueOnce({})

    renderButton()

    fireEvent.click(screen.getByRole('button', { name: /exporter pdf/i }))

    await waitFor(() => {
      expect(apiClientMock.post).toHaveBeenCalledWith('schedules/schedule-1/export-pdf')
    })
  })

  it('shows pending status when the backend reports it', async () => {
    getJsonMock.mockResolvedValueOnce({ pdfExportStatus: 'pending', pdfExportUrl: null })

    renderButton()

    const status = await screen.findByText('pending')
    expect(status.closest('button')).toBeDisabled()
  })

  it('renders the generating status when the backend reports it', async () => {
    getJsonMock.mockResolvedValueOnce({ pdfExportStatus: 'generating', pdfExportUrl: null })

    renderButton()

    const status = await screen.findByText('generating')
    expect(status.closest('button')).toBeDisabled()
  })

  it('enables download when the export is completed', async () => {
    getJsonMock.mockResolvedValueOnce({
      pdfExportStatus: 'completed',
      pdfExportUrl: '/media/schedules/schedule-1.pdf',
    })

    renderButton()

    const link = await screen.findByRole('link', { name: /télécharger pdf/i })

    expect(link).toHaveAttribute('href', '/media/schedules/schedule-1.pdf')
    expect(link).toHaveAttribute('download')
    expect(screen.getByText('completed')).toBeInTheDocument()
  })

  it('shows the failure state with a retry action', async () => {
    getJsonMock.mockResolvedValueOnce({ pdfExportStatus: 'failed', pdfExportUrl: null })

    renderButton()

    expect(await screen.findByText('Export PDF failed')).toBeInTheDocument()
    expect(screen.getByText('failed')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeInTheDocument()
  })
})

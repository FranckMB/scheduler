import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ManualEditDialog from './ManualEditDialog'
import type { ScheduleSlot } from '@/features/schedule/types'

const mockConstraintMutateAsync = vi.fn()
const mockLockMutateAsync = vi.fn()
const mockOneTimeMutateAsync = vi.fn()

vi.mock('@/features/schedule/useSchedule', () => ({
  useManualEditConstraint: () => ({
    mutate: vi.fn(),
    mutateAsync: mockConstraintMutateAsync,
    isPending: false,
    isError: false,
    error: null,
  }),
  useManualEditLock: () => ({
    mutate: vi.fn(),
    mutateAsync: mockLockMutateAsync,
    isPending: false,
    isError: false,
    error: null,
  }),
  useManualEditOneTime: () => ({
    mutate: vi.fn(),
    mutateAsync: mockOneTimeMutateAsync,
    isPending: false,
    isError: false,
    error: null,
  }),
}))

const mockSlot: ScheduleSlot = {
  id: 'slot-1',
  version: 1,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  scheduleId: 'schedule-1',
  teamId: 'team-abc123',
  venueId: 'venue-main',
  coachId: 'coach-1',
  dayOfWeek: 1,
  startTime: '10:00:00',
  durationMinutes: 60,
  lockLevel: 'NONE',
  temporaryLock: false,
  temporaryLockFor: null,
  temporaryMinSessionsOverride: null,
  pendingConstraintSuggestion: null,
}

const mockOriginalSlot: ScheduleSlot = {
  ...mockSlot,
  dayOfWeek: 1,
  startTime: '10:00:00',
  venueId: 'venue-main',
}

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      mutations: { retry: false },
      queries: { retry: false },
    },
  })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    )
  }
}

function renderDialog(overrides: {
  slot?: ScheduleSlot
  originalSlot?: ScheduleSlot
  newDayOfWeek?: number
  newStartTime?: string
  newVenueId?: string
} = {}) {
  const onClose = vi.fn()
  const onSuccess = vi.fn()

  const result = render(
    <ManualEditDialog
      slot={overrides.slot ?? mockSlot}
      originalSlot={overrides.originalSlot ?? mockOriginalSlot}
      newDayOfWeek={overrides.newDayOfWeek ?? 2}
      newStartTime={overrides.newStartTime ?? '14:00:00'}
      newVenueId={overrides.newVenueId ?? 'venue-main'}
      onClose={onClose}
      onSuccess={onSuccess}
    />,
    { wrapper: createWrapper() },
  )

  const dialog = result.container.querySelector('[role="dialog"]') as HTMLElement
  return { ...result, onClose, onSuccess, dialog }
}

function clickAction(dialog: HTMLElement, label: string) {
  const buttons = dialog.querySelectorAll('button')
  const btn = Array.from(buttons).find(
    (b) => b.textContent?.includes(label) && !b.textContent?.includes('Appliquer') && !b.textContent?.includes('Annuler'),
  ) as HTMLElement | undefined
  if (btn) fireEvent.click(btn)
}

function getApplyButton(dialog: HTMLElement): HTMLButtonElement | undefined {
  const buttons = dialog.querySelectorAll('button')
  return Array.from(buttons).find(
    (b) => b.textContent === 'Appliquer',
  ) as HTMLButtonElement | undefined
}

function getCancelButton(dialog: HTMLElement): HTMLButtonElement | undefined {
  const buttons = dialog.querySelectorAll('button')
  return Array.from(buttons).find(
    (b) => b.textContent === 'Annuler',
  ) as HTMLButtonElement | undefined
}

afterEach(() => {
  cleanup()
})

beforeEach(() => {
  vi.clearAllMocks()
})

describe('ManualEditDialog', () => {
  it('renders with change summary when day changed', () => {
    const { dialog } = renderDialog({ newDayOfWeek: 2 })

    expect(dialog.textContent).toContain('Modifier le créneau')
    expect(dialog.textContent).toContain('Lundi')
    expect(dialog.textContent).toContain('Mardi')
  })

  it('renders with change summary when time changed', () => {
    const { dialog } = renderDialog({ newStartTime: '14:00:00' })

    expect(dialog.textContent).toContain('10:00')
    expect(dialog.textContent).toContain('14:00')
  })

  it('renders with change summary when venue changed', () => {
    const { dialog } = renderDialog({ newVenueId: 'venue-secondary' })

    expect(dialog.textContent).toContain('venue-ma')
    expect(dialog.textContent).toContain('venue-se')
  })

  it('shows all three action options', () => {
    const { dialog } = renderDialog()

    expect(dialog.textContent).toContain('Créer contrainte permanente')
    expect(dialog.textContent).toContain('Verrouiller')
    expect(dialog.textContent).toContain('Juste ponctuel')
  })

  it('shows constraint type selector when constraint action selected', () => {
    const { dialog } = renderDialog()

    clickAction(dialog, 'Créer contrainte')

    expect(dialog.textContent).toContain('Jour + Horaire fixe')
  })

  it('shows lock level selector when lock action selected', () => {
    const { dialog } = renderDialog()

    clickAction(dialog, 'Verrouiller')

    expect(dialog.textContent).toContain('SOFT — Pénalité 10k')
    expect(dialog.textContent).toContain('HARD — Intouchable')
  })

  it('calls onClose when cancel button clicked', () => {
    const { onClose, dialog } = renderDialog()

    const cancelBtn = getCancelButton(dialog)
    if (cancelBtn) fireEvent.click(cancelBtn)

    expect(onClose).toHaveBeenCalled()
  })

  it('calls onClose when backdrop clicked', () => {
    const { onClose, container } = renderDialog()

    const backdrop = container.querySelector('.fixed.inset-0.z-40') as HTMLElement | null
    if (backdrop) fireEvent.click(backdrop)

    expect(onClose).toHaveBeenCalled()
  })

  it('disables apply button when no action selected', () => {
    const { dialog } = renderDialog()

    const applyBtn = getApplyButton(dialog)
    expect(applyBtn?.disabled).toBe(true)
  })

  it('enables apply button when action selected', () => {
    const { dialog } = renderDialog()

    clickAction(dialog, 'Juste ponctuel')

    const applyBtn = getApplyButton(dialog)
    expect(applyBtn?.disabled).toBe(false)
  })

  it('calls one-time mutation and onSuccess when applying one-time action', async () => {
    mockOneTimeMutateAsync.mockResolvedValueOnce({})

    const { onSuccess, dialog } = renderDialog()

    clickAction(dialog, 'Juste ponctuel')
    const applyBtn = getApplyButton(dialog)
    if (applyBtn) fireEvent.click(applyBtn)

    await waitFor(() => {
      expect(mockOneTimeMutateAsync).toHaveBeenCalledWith({
        slotId: 'slot-1',
        data: {
          dayOfWeek: 2,
          startTime: '14:00:00',
          venueId: 'venue-main',
        },
      })
    })
    expect(onSuccess).toHaveBeenCalled()
  })

  it('calls lock mutation then one-time mutation when applying lock action', async () => {
    mockLockMutateAsync.mockResolvedValueOnce({})
    mockOneTimeMutateAsync.mockResolvedValueOnce({})

    const { onSuccess, dialog } = renderDialog()

    clickAction(dialog, 'Verrouiller')
    const applyBtn = getApplyButton(dialog)
    if (applyBtn) fireEvent.click(applyBtn)

    await waitFor(() => {
      expect(mockLockMutateAsync).toHaveBeenCalledWith({
        slotId: 'slot-1',
        lockLevel: 'SOFT',
      })
    })
    expect(onSuccess).toHaveBeenCalled()
  })

  it('calls constraint mutation when applying constraint action', async () => {
    mockConstraintMutateAsync.mockResolvedValueOnce({})

    const { onSuccess, dialog } = renderDialog()

    clickAction(dialog, 'Créer contrainte')
    const applyBtn = getApplyButton(dialog)
    if (applyBtn) fireEvent.click(applyBtn)

    await waitFor(() => {
      expect(mockConstraintMutateAsync).toHaveBeenCalledWith({
        slotId: 'slot-1',
        type: 'day_time',
        reason: expect.stringContaining('day change'),
      })
    })
    expect(onSuccess).toHaveBeenCalled()
  })

  it('shows error message when mutation fails', async () => {
    mockOneTimeMutateAsync.mockRejectedValueOnce(new Error('Conflict detected'))

    const { dialog } = renderDialog()

    clickAction(dialog, 'Juste ponctuel')
    const applyBtn = getApplyButton(dialog)
    if (applyBtn) fireEvent.click(applyBtn)

    await waitFor(() => {
      expect(dialog.textContent).toContain('Conflict detected')
    })
  })

  it('shows lock level indicator in header', () => {
    const { dialog } = renderDialog({ slot: { ...mockSlot, lockLevel: 'HARD' } })

    expect(dialog.textContent).toContain('HARD')
  })

  it('shows SOFT lock level indicator', () => {
    const { dialog } = renderDialog({ slot: { ...mockSlot, lockLevel: 'SOFT' } })

    expect(dialog.textContent).toContain('SOFT')
  })
})

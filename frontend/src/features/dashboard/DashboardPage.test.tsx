import { cleanup, render, screen, waitFor } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'

import DashboardPage from './DashboardPage'
import * as dashboardApi from './dashboardApi'
import * as useSchedule from '@/features/schedule/useSchedule'
import * as authStore from '@/features/auth/authStore'
import type { AuthState } from '@/features/auth/authStore'

// Mock FullCalendar because it requires a real browser environment
vi.mock('@fullcalendar/react', () => ({
  default: function FullCalendarMock(props: Record<string, unknown>) {
    // Extract events from props and render them as simple divs so we can assert on them
    const events = (props.events as Array<Record<string, unknown>>) || []
    return (
      <div data-testid="fullcalendar-mock">
        {events.map((event) => {
          const slot = (event.extendedProps as Record<string, unknown>)?.slot as Record<string, unknown>
          return (
            <div
              key={String(event.id)}
              data-testid={`calendar-event-${String(event.id)}`}
              data-team-id={slot?.teamId}
              data-day={slot?.dayOfWeek}
              data-start-time={slot?.startTime}
              data-duration={slot?.durationMinutes}
            >
              {String(event.title)}
            </div>
          )
        })}
      </div>
    )
  },
}))

vi.mock('@fullcalendar/timegrid', () => ({
  default: vi.fn(),
}))

vi.mock('@fullcalendar/daygrid', () => ({
  default: vi.fn(),
}))

vi.mock('@fullcalendar/list', () => ({
  default: vi.fn(),
}))

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        staleTime: Infinity,
      },
    },
  })
}

function renderDashboard() {
  const queryClient = createTestQueryClient()
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

describe('DashboardPage schedule contract', () => {
  beforeEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders slots in the calendar when schedule and slots exist', async () => {
    // Mock auth store
    vi.spyOn(authStore, 'useAuthStore').mockImplementation((selector) =>
      selector({ club: { id: 'club-123' }, token: 'test-token', seasonId: 'season-123' } as unknown as AuthState)
    )

    // Mock latest schedule hook
    vi.spyOn(dashboardApi, 'useLatestSchedule').mockReturnValue({
      data: {
        id: 'schedule-456',
        name: 'Test Schedule',
        status: 'done',
        score: 9500,
      },
      isLoading: false,
      isError: false,
      error: null,
      isPending: false,
      isSuccess: true,
      status: 'success',
      fetchStatus: 'idle',
      isFetching: false,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: false,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve({
        id: 'schedule-456',
        name: 'Test Schedule',
        status: 'done',
        score: 9500,
      }),
    } as unknown as ReturnType<typeof dashboardApi.useLatestSchedule>)

    // Mock schedule slots hook
    vi.spyOn(useSchedule, 'useScheduleSlots').mockReturnValue({
      data: [
        {
          id: 'slot-1',
          scheduleId: 'schedule-456',
          teamId: 'team-abc',
          venueId: 'venue-xyz',
          coachId: 'coach-123',
          dayOfWeek: 2,
          startTime: '18:00',
          durationMinutes: 90,
          lockLevel: 'NONE',
          temporaryLock: false,
          temporaryLockFor: null,
          temporaryMinSessionsOverride: null,
          pendingConstraintSuggestion: null,
          version: 1,
          createdAt: '2025-01-01T00:00:00Z',
          updatedAt: '2025-01-01T00:00:00Z',
        },
        {
          id: 'slot-2',
          scheduleId: 'schedule-456',
          teamId: 'team-def',
          venueId: 'venue-xyz',
          coachId: null,
          dayOfWeek: 4,
          startTime: '19:30',
          durationMinutes: 120,
          lockLevel: 'SOFT',
          temporaryLock: false,
          temporaryLockFor: null,
          temporaryMinSessionsOverride: null,
          pendingConstraintSuggestion: null,
          version: 1,
          createdAt: '2025-01-01T00:00:00Z',
          updatedAt: '2025-01-01T00:00:00Z',
        },
      ],
      isLoading: false,
      isError: false,
      error: null,
      isPending: false,
      isSuccess: true,
      status: 'success',
      fetchStatus: 'idle',
      isFetching: false,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: false,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve([]),
    } as unknown as ReturnType<typeof useSchedule.useScheduleSlots>)

    renderDashboard()

    // Wait for calendar mock to render events
    await waitFor(() => {
      expect(screen.getByTestId('fullcalendar-mock')).toBeInTheDocument()
    })

    // Verify both slots appear as calendar events
    expect(screen.getByTestId('calendar-event-slot-1')).toBeInTheDocument()
    expect(screen.getByTestId('calendar-event-slot-2')).toBeInTheDocument()

    // Verify slot data is passed through correctly
    const event1 = screen.getByTestId('calendar-event-slot-1')
    expect(event1).toHaveAttribute('data-team-id', 'team-abc')
    expect(event1).toHaveAttribute('data-day', '2')
    expect(event1).toHaveAttribute('data-start-time', '18:00')
    expect(event1).toHaveAttribute('data-duration', '90')

    const event2 = screen.getByTestId('calendar-event-slot-2')
    expect(event2).toHaveAttribute('data-team-id', 'team-def')
    expect(event2).toHaveAttribute('data-day', '4')
    expect(event2).toHaveAttribute('data-start-time', '19:30')
    expect(event2).toHaveAttribute('data-duration', '120')

    // Verify schedule summary is shown in sidebar
    expect(screen.getByText('Test Schedule')).toBeInTheDocument()
    expect(screen.getByText(/Statut: done/)).toBeInTheDocument()
    expect(screen.getByText(/Score: 9500/)).toBeInTheDocument()
  })

  it('shows empty state when no schedule exists', async () => {
    vi.spyOn(authStore, 'useAuthStore').mockImplementation((selector) =>
      selector({ club: { id: 'club-123' }, token: 'test-token', seasonId: 'season-123' } as unknown as AuthState)
    )

    vi.spyOn(dashboardApi, 'useLatestSchedule').mockReturnValue({
      data: null,
      isLoading: false,
      isError: false,
      error: null,
      isPending: false,
      isSuccess: true,
      status: 'success',
      fetchStatus: 'idle',
      isFetching: false,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: false,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve(null),
    } as unknown as ReturnType<typeof dashboardApi.useLatestSchedule>)

    vi.spyOn(useSchedule, 'useScheduleSlots').mockReturnValue({
      data: [],
      isLoading: false,
      isError: false,
      error: null,
      isPending: false,
      isSuccess: true,
      status: 'success',
      fetchStatus: 'idle',
      isFetching: false,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: false,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve([]),
    } as unknown as ReturnType<typeof useSchedule.useScheduleSlots>)

    renderDashboard()

    await waitFor(() => {
      expect(screen.getByText('Aucun planning disponible. Créez un planning pour voir le calendrier.')).toBeInTheDocument()
    })

    // Calendar mock should NOT be rendered when there is no schedule
    expect(screen.queryByTestId('fullcalendar-mock')).not.toBeInTheDocument()
  })

  it('shows loading state while fetching schedule', async () => {
    vi.spyOn(authStore, 'useAuthStore').mockImplementation((selector) =>
      selector({ club: { id: 'club-123' }, token: 'test-token', seasonId: 'season-123' } as unknown as AuthState)
    )

    vi.spyOn(dashboardApi, 'useLatestSchedule').mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      error: null,
      isPending: true,
      isSuccess: false,
      status: 'pending',
      fetchStatus: 'fetching',
      isFetching: true,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: true,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve(null),
    } as unknown as ReturnType<typeof dashboardApi.useLatestSchedule>)

    vi.spyOn(useSchedule, 'useScheduleSlots').mockReturnValue({
      data: undefined,
      isLoading: true,
      isError: false,
      error: null,
      isPending: true,
      isSuccess: false,
      status: 'pending',
      fetchStatus: 'fetching',
      isFetching: true,
      isPlaceholderData: false,
      isStale: false,
      dataUpdatedAt: 0,
      errorUpdatedAt: 0,
      failureCount: 0,
      failureReason: null,
      errorUpdateCount: 0,
      isInitialLoading: true,
      isRefetching: false,
      isLoadingError: false,
      isRefetchError: false,
      isPaused: false,
      refetch: vi.fn(),
      promise: Promise.resolve([]),
    } as unknown as ReturnType<typeof useSchedule.useScheduleSlots>)

    renderDashboard()

    // Should show loading spinner
    await waitFor(() => {
      expect(screen.getAllByRole('status').length).toBeGreaterThan(0)
    })
  })
})

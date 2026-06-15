import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'

import AuthGuard from './AuthGuard'
import { useAuthStore } from '@/features/auth/authStore'

function LocationProbe() {
  const location = useLocation()

  return <div data-testid="location">{`${location.pathname}${location.search}`}</div>
}

function renderGuard(initialEntry = '/private') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route element={<AuthGuard />}>
          <Route path="/private" element={<div>Protected content</div>} />
        </Route>
        <Route path="/login" element={<LocationProbe />} />
      </Routes>
    </MemoryRouter>
  )
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.setState({
    token: null,
    user: null,
    club: null,
    isAuthenticated: false,
    isAuthInitialized: true,
    initAuth: vi.fn().mockResolvedValue(undefined),
  })
})

afterEach(() => {
  cleanup()
})

describe('AuthGuard', () => {
  it('renders the outlet when authenticated', () => {
    useAuthStore.setState({
      token: 'jwt-token',
      user: { id: 'user-1', email: 'captain@example.com', roles: ['ROLE_USER'] },
      club: { id: 'club-1', name: 'North Stars', slug: 'north-stars' },
      isAuthenticated: true,
      isAuthInitialized: true,
    })

    renderGuard()

    expect(screen.getByText('Protected content')).toBeInTheDocument()
    expect(screen.queryByTestId('location')).not.toBeInTheDocument()
  })

  it('redirects to login with the current path when not authenticated', () => {
    renderGuard('/private?tab=profile#details')

    expect(screen.getByTestId('location')).toHaveTextContent('/login?redirect=%2Fprivate%3Ftab%3Dprofile%23details')
    expect(screen.queryByText('Protected content')).not.toBeInTheDocument()
  })
})

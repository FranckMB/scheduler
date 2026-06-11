import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import AppLayout from './AppLayout'
import { useAuthStore } from '@/features/auth/authStore'
import { useUIStore } from '@/features/ui/uiStore'

function renderLayout() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route element={<AppLayout />}>
          <Route path="/" element={<div>Dashboard content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>
  )
}

beforeEach(() => {
  localStorage.clear()
  useUIStore.setState({ sidebarOpen: false })
  useAuthStore.setState({
    token: 'jwt-token',
    user: { id: 'user-1', email: 'captain@example.com', roles: ['ROLE_USER'] },
    club: { id: 'club-1', name: 'North Stars', slug: 'north-stars' },
    isAuthenticated: true,
    logout: () => {
      useAuthStore.getState().clearAuth()
    },
  })
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('AppLayout', () => {
  it('shows the authenticated email in the profile menu', { timeout: 10000 }, () => {
    renderLayout()

    expect(screen.getByRole('button', { name: /captain@example.com/i })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /captain@example.com/i }))

    expect(screen.getByRole('link', { name: 'Profil' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Se déconnecter' })).toBeInTheDocument()
  })

  it('clears auth state when logging out', { timeout: 10000 }, () => {
    renderLayout()

    fireEvent.click(screen.getByRole('button', { name: /captain@example.com/i }))
    fireEvent.click(screen.getByRole('button', { name: 'Se déconnecter' }))

    expect(useAuthStore.getState().token).toBeNull()
    expect(useAuthStore.getState().user).toBeNull()
    expect(useAuthStore.getState().club).toBeNull()
    expect(useAuthStore.getState().isAuthenticated).toBe(false)
  })
})

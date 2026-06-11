import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import LoginPage from './LoginPage'
import { useAuthStore } from '@/features/auth/authStore'

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/login']}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
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
  })
})

afterEach(() => {
  cleanup()
})

describe('LoginPage', () => {
  it('shows the register link with the expected href', { timeout: 10000 }, () => {
    renderPage()

    expect(screen.getByRole('link', { name: 'Créer un compte' })).toHaveAttribute('href', '/register')
  })
})

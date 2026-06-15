import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'

import LoginPage from './LoginPage'
import { useAuthStore } from '@/features/auth/authStore'

const { getJsonMock, postJsonMock, apiClientMock } = vi.hoisted(() => {
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
  getJsonMock.mockReset()
  postJsonMock.mockReset()
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

  it('toggles password visibility', () => {
    renderPage()

    const passwordInput = screen.getByLabelText('Password')

    expect(passwordInput).toHaveAttribute('type', 'password')

    fireEvent.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }))

    expect(passwordInput).toHaveAttribute('type', 'text')
    expect(screen.getByRole('button', { name: 'Masquer le mot de passe' })).toBeInTheDocument()
  })

  it('shows a fallback error when the account has no club', async () => {
    postJsonMock.mockResolvedValueOnce({ token: 'token-123' })
    getJsonMock.mockResolvedValueOnce({
      id: 'user-1',
      email: 'coach@example.com',
      firstName: 'Coach',
      lastName: 'User',
      club: null,
    })

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'coach@example.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'password123' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    expect(await screen.findByRole('alert')).toHaveTextContent('Aucun club associé à ce compte')
    expect(useAuthStore.getState().token).toBeNull()
  })
})

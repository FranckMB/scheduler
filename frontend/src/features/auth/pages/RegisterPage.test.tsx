import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import '@testing-library/jest-dom/vitest'

import RegisterPage from './RegisterPage'
import { useAuthStore } from '@/features/auth/authStore'

const { postMock } = vi.hoisted(() => ({
  postMock: vi.fn(),
}))

vi.mock('@/shared/api/client', () => ({
  apiClient: {
    post: postMock,
  },
}))

function renderPage() {
  const router = createMemoryRouter(
    [
      {
        path: '/register',
        element: <RegisterPage />,
      },
      {
        path: '/wizard',
        element: <div>Wizard route</div>,
      },
    ],
    { initialEntries: ['/register'] }
  )

  return render(<RouterProvider router={router} />)
}

beforeEach(() => {
  localStorage.clear()
  postMock.mockReset()
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

describe('RegisterPage', () => {
  it('validates all fields before submission', async () => {
    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'invalid-email' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'short' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'different' } })
    fireEvent.change(screen.getByLabelText('ARA number'), { target: { value: 'abc' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument()
    expect(screen.getByText('Password must be at least 8 characters.')).toBeInTheDocument()
    expect(screen.getByText('Passwords do not match.')).toBeInTheDocument()
    expect(
      screen.getByText(/ARA must be 3-20 uppercase letters or numbers\./)
    ).toBeInTheDocument()
    expect(screen.getByText('Club name is required.')).toBeInTheDocument()
    expect(postMock).not.toHaveBeenCalled()
  })

  it('shows an error when the ARA is already registered', async () => {
    postMock.mockReturnValue({
      json: vi.fn().mockRejectedValue({
        response: {
          json: vi.fn().mockResolvedValue({ error: 'ARA already registered' }),
        },
      }),
    })

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'captain@example.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('ARA number'), { target: { value: 'ABC123' } })
    fireEvent.change(screen.getByLabelText('Club name'), { target: { value: 'North Stars' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    expect(await screen.findByRole('alert')).toHaveTextContent('ARA already registered')
    expect(useAuthStore.getState().token).toBeNull()
  })

  it('stores the token and redirects to the wizard after success', async () => {
    postMock.mockReturnValue({
      json: vi.fn().mockResolvedValue({ token: 'jwt-token' }),
    })

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'captain@example.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('ARA number'), { target: { value: 'ABC123' } })
    fireEvent.change(screen.getByLabelText('Club name'), { target: { value: 'North Stars' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    await waitFor(() => {
      expect(useAuthStore.getState().token).toBe('jwt-token')
    })
    expect(await screen.findByText('Wizard route')).toBeInTheDocument()
  })
})

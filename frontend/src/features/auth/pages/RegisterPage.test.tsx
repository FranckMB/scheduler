import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import '@testing-library/jest-dom/vitest'

import RegisterPage from './RegisterPage'

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
      { path: '/login', element: <div>Login route</div> },
    ],
    { initialEntries: ['/register'] }
  )

  return render(<RouterProvider router={router} />)
}

beforeEach(() => {
  localStorage.clear()
  postMock.mockReset()
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
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: '' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: '' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument()
    expect(screen.getByText('Password must be at least 8 characters.')).toBeInTheDocument()
    expect(screen.getByText('Passwords do not match.')).toBeInTheDocument()
    expect(screen.getByText('First name is required.')).toBeInTheDocument()
    expect(screen.getByText('Last name is required.')).toBeInTheDocument()
    expect(screen.getByText('Club name is required.')).toBeInTheDocument()
    expect(postMock).not.toHaveBeenCalled()
  })

  it('shows an API error when registration fails', { timeout: 10000 }, async () => {
    postMock.mockRejectedValueOnce({
      response: {
        json: vi.fn().mockResolvedValue({ error: 'Club already registered' }),
      },
    })

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'captain@example.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Alice' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Martin' } })
    fireEvent.change(screen.getByLabelText('Club name'), { target: { value: 'North Stars' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    expect(await screen.findByRole('alert')).toHaveTextContent('Club already registered')
    expect(postMock).toHaveBeenCalledWith('register', {
      json: {
        email: 'captain@example.com',
        password: 'supersecure',
        firstName: 'Alice',
        lastName: 'Martin',
        clubName: 'North Stars',
      },
    })
  })

  it('posts the registration payload and redirects to login after success', async () => {
    postMock.mockResolvedValueOnce({})

    renderPage()

    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'captain@example.com' } })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'supersecure' } })
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Alice' } })
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Martin' } })
    fireEvent.change(screen.getByLabelText('Club name'), { target: { value: 'North Stars' } })
    fireEvent.submit(document.querySelector('form') as HTMLFormElement)

    await waitFor(() => {
      expect(postMock).toHaveBeenCalledWith('register', {
        json: {
          email: 'captain@example.com',
          password: 'supersecure',
          firstName: 'Alice',
          lastName: 'Martin',
          clubName: 'North Stars',
        },
      })
    })
    expect(await screen.findByText('Login route')).toBeInTheDocument()
  })
})

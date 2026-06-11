import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export interface AuthUser {
  id: string
  email: string
  roles: string[]
}

export interface AuthClub {
  id: string
  name: string
  slug: string
}

interface AuthState {
  token: string | null
  user: AuthUser | null
  club: AuthClub | null
  seasonId?: string | null
  isAuthenticated: boolean
  setAuth: (token: string, user: AuthUser, club: AuthClub) => void
  clearAuth: () => void
  setToken: (token: string) => void
  logout: () => void
  initAuth: () => Promise<void>
}

const emptyAuthState = {
  token: null,
  user: null,
  club: null,
  isAuthenticated: false,
}

const isAuthUser = (value: unknown): value is AuthUser => {
  if (typeof value !== 'object' || value === null) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    typeof candidate.id === 'string' &&
    typeof candidate.email === 'string' &&
    Array.isArray(candidate.roles) &&
    candidate.roles.every((role): role is string => typeof role === 'string')
  )
}

const extractAuthUser = (value: unknown): AuthUser | null => {
  if (isAuthUser(value)) {
    return value
  }

  if (typeof value !== 'object' || value === null) {
    return null
  }

  const candidate = value as Record<string, unknown>

  return isAuthUser(candidate.user) ? candidate.user : null
}

const isAuthClub = (value: unknown): value is AuthClub => {
  if (typeof value !== 'object' || value === null) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    typeof candidate.id === 'string' &&
    typeof candidate.name === 'string' &&
    typeof candidate.slug === 'string'
  )
}

const extractAuthClub = (value: unknown): AuthClub | null => {
  if (typeof value !== 'object' || value === null) {
    return null
  }

  const candidate = value as Record<string, unknown>

  if (isAuthClub(candidate.club)) {
    return candidate.club
  }

  return null
}

export const useAuthStore = create<AuthState>()(
  persist((set, get) => {
    const clearAuthState = () => set(emptyAuthState)

    return {
      ...emptyAuthState,

      setAuth: (token, user, club) =>
        set({
          token,
          user,
          club,
          isAuthenticated: true,
        }),

      clearAuth: clearAuthState,

      setToken: (token) => set({ token }),

      logout: () => {
        clearAuthState()
        window.location.href = '/login'
      },

      initAuth: async () => {
        const { token } = get()

        if (!token) {
          return
        }

        const response = await fetch('/api/me', {
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        })

        if (response.status === 401) {
          clearAuthState()
          return
        }

        if (!response.ok) {
          return
        }

        const data: unknown = await response.json()
        const user = extractAuthUser(data)
        const club = extractAuthClub(data)

        if (user) {
          set({
            user,
            club,
            isAuthenticated: true,
          })
        }
      },
    }
  },
  {
    name: 'auth-storage',
  })
)

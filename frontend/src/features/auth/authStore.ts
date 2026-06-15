import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export interface AuthUser {
  id: string
  email: string
  roles?: string[]
}

export interface AuthClub {
  id: string
  name: string
  slug?: string
}

export interface AuthState {
  token: string | null
  user: AuthUser | null
  club: AuthClub | null
  seasonId?: string | null
  hasGenerated: boolean
  isAuthenticated: boolean
  isAuthInitialized: boolean
  setAuth: (token: string, user: AuthUser, club: AuthClub) => void
  clearAuth: () => void
  setToken: (token: string) => void
  setHasGenerated: (hasGenerated: boolean) => void
  logout: () => void
  initAuth: () => Promise<void>
}

const emptyAuthState = {
  token: null,
  user: null,
  club: null,
  hasGenerated: false,
  isAuthenticated: false,
  isAuthInitialized: false,
}

const isAuthUser = (value: unknown): value is AuthUser => {
  if (typeof value !== 'object' || value === null) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    typeof candidate.id === 'string' &&
    typeof candidate.email === 'string' &&
    (candidate.roles === undefined ||
      (Array.isArray(candidate.roles) &&
        candidate.roles.every((role): role is string => typeof role === 'string')))
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
    (candidate.slug === undefined || typeof candidate.slug === 'string')
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

const extractHasGenerated = (value: unknown): boolean | null => {
  if (typeof value !== 'object' || value === null) {
    return null
  }

  const candidate = value as Record<string, unknown>

  if (typeof candidate.hasGenerated === 'boolean') {
    return candidate.hasGenerated
  }

  if (typeof candidate.club === 'object' && candidate.club !== null) {
    const clubCandidate = candidate.club as Record<string, unknown>

    if (typeof clubCandidate.hasGenerated === 'boolean') {
      return clubCandidate.hasGenerated
    }
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

      setHasGenerated: (hasGenerated) => set({ hasGenerated }),

      logout: () => {
        clearAuthState()
        window.location.href = '/login'
      },

      initAuth: async () => {
        const { token } = get()

        if (!token) {
          set({ isAuthInitialized: true })
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
          set({ isAuthInitialized: true })
          return
        }

        if (!response.ok) {
          set({ isAuthInitialized: true })
          return
        }

        const data: unknown = await response.json()
        const user = extractAuthUser(data)
        const club = extractAuthClub(data)
        const hasGenerated = extractHasGenerated(data)

        if (user && club) {
          set({
            user,
            club,
            ...(hasGenerated !== null ? { hasGenerated } : {}),
            isAuthenticated: true,
            isAuthInitialized: true,
          })
          return
        }

        clearAuthState()
        set({ isAuthInitialized: true })
      },
    }
  },
  {
    name: 'auth-storage',
  })
)

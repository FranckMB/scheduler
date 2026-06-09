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
  isAuthenticated: boolean
  setAuth: (token: string, user: AuthUser, club: AuthClub) => void
  clearAuth: () => void
  setToken: (token: string) => void
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      club: null,
      isAuthenticated: false,

      setAuth: (token, user, club) =>
        set({
          token,
          user,
          club,
          isAuthenticated: true,
        }),

      clearAuth: () =>
        set({
          token: null,
          user: null,
          club: null,
          isAuthenticated: false,
        }),

      setToken: (token) => set({ token }),
    }),
    {
      name: 'auth-storage',
    }
  )
)

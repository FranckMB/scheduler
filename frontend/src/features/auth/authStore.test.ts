import { beforeEach, describe, expect, it } from 'vitest'

import { useAuthStore, type AuthClub, type AuthUser } from './authStore'

const user: AuthUser = {
  id: 'user-1',
  email: 'captain@example.com',
  roles: ['ROLE_USER'],
}

const club: AuthClub = {
  id: 'club-1',
  name: 'North Stars',
  slug: 'north-stars',
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

describe('useAuthStore', () => {
  it('stores and clears auth session state', () => {
    useAuthStore.getState().setAuth('jwt-token', user, club)

    expect(useAuthStore.getState()).toMatchObject({
      token: 'jwt-token',
      user,
      club,
      isAuthenticated: true,
    })

    useAuthStore.getState().clearAuth()

    expect(useAuthStore.getState()).toMatchObject({
      token: null,
      user: null,
      club: null,
      isAuthenticated: false,
    })
  })
})

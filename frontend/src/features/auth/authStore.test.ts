import { beforeEach, describe, expect, it, vi } from 'vitest'

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
    hasGenerated: false,
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
      hasGenerated: false,
      isAuthenticated: true,
    })

    useAuthStore.getState().clearAuth()

    expect(useAuthStore.getState()).toMatchObject({
      token: null,
      user: null,
      club: null,
      hasGenerated: false,
      isAuthenticated: false,
    })
  })

  it('tracks first generation in auth state', () => {
    useAuthStore.getState().setHasGenerated(true)

    expect(useAuthStore.getState()).toMatchObject({
      hasGenerated: true,
    })
  })

  it('clears auth when initAuth receives a user without a club', async () => {
    useAuthStore.setState({
      token: 'jwt-token',
      user: null,
      club: null,
      isAuthenticated: false,
    })

    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        id: user.id,
        email: user.email,
        roles: user.roles,
        club: null,
      }),
    } as Response)

    await useAuthStore.getState().initAuth()

    expect(useAuthStore.getState()).toMatchObject({
      token: null,
      user: null,
      club: null,
      hasGenerated: false,
      isAuthenticated: false,
    })

    fetchMock.mockRestore()
  })

  it('accepts partial auth payloads without roles or club slug', async () => {
    useAuthStore.setState({
      token: 'jwt-token',
      user: null,
      club: null,
      isAuthenticated: false,
    })

    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        id: user.id,
        email: user.email,
        club: {
          id: club.id,
          name: club.name,
        },
      }),
    } as Response)

    await useAuthStore.getState().initAuth()

    expect(useAuthStore.getState()).toMatchObject({
      token: 'jwt-token',
      user: {
        id: user.id,
        email: user.email,
      },
      club: {
        id: club.id,
        name: club.name,
      },
      hasGenerated: false,
      isAuthenticated: true,
    })

    fetchMock.mockRestore()
  })

  it('restores hasGenerated from initAuth payloads', async () => {
    useAuthStore.setState({
      token: 'jwt-token',
      user: null,
      club: null,
      hasGenerated: false,
      isAuthenticated: false,
    })

    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        id: user.id,
        email: user.email,
        club: {
          id: club.id,
          name: club.name,
        },
        hasGenerated: true,
      }),
    } as Response)

    await useAuthStore.getState().initAuth()

    expect(useAuthStore.getState()).toMatchObject({
      hasGenerated: true,
      isAuthenticated: true,
    })

    fetchMock.mockRestore()
  })
})

import { useEffect } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { useAuthStore } from '@/features/auth/authStore'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'

type AuthStoreWithInit = ReturnType<typeof useAuthStore.getState> & {
  initAuth?: () => void | Promise<void>
}

export default function AuthGuard() {
  const location = useLocation()
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const isAuthInitialized = useAuthStore((state) => state.isAuthInitialized)

  useEffect(() => {
    const { initAuth } = useAuthStore.getState() as AuthStoreWithInit

    if (!initAuth) {
      return
    }

    void initAuth()
  }, [])

  if (!isAuthInitialized) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!isAuthenticated) {
    const redirect = `${location.pathname}${location.search}${location.hash}`

    return <Navigate replace to={`/login?redirect=${encodeURIComponent(redirect)}`} />
  }

  return <Outlet />
}

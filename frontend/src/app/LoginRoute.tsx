import { Navigate } from 'react-router-dom'

import { useAuthStore } from '@/features/auth/authStore'
import { LoginPage } from '@/app/routes'

export default function LoginRoute() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)

  if (isAuthenticated) {
    return <Navigate replace to="/" />
  }

  return <LoginPage />
}

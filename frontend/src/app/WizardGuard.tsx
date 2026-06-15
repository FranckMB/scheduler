import { Navigate } from 'react-router-dom'

import { useAuthStore } from '@/features/auth/authStore'
import { WizardPage } from '@/app/routes'

export default function WizardGuard() {
  const hasGenerated = useAuthStore((state) => state.hasGenerated)

  if (hasGenerated) {
    return <Navigate replace to="/dashboard" />
  }

  return <WizardPage />
}

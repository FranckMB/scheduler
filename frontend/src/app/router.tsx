import { Navigate, createBrowserRouter } from 'react-router-dom'
import AppLayout from '@/app/AppLayout'
import AuthGuard from '@/app/AuthGuard'
import { HomePage, LoginPage, RegisterPage, WizardPage, ScheduleViewPage, DiagnosticsPage } from '@/app/routes'
import { useAuthStore } from '@/features/auth/authStore'

function LoginRoute() {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)

  if (isAuthenticated) {
    return <Navigate replace to="/" />
  }

  return <LoginPage />
}

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    children: [
      {
        index: true,
        element: <HomePage />,
      },
      {
        path: 'login',
        element: <LoginRoute />,
      },
      {
        path: 'register',
        element: <RegisterPage />,
      },
      {
        element: <AuthGuard />,
        children: [
          {
            path: 'wizard',
            element: <WizardPage />,
          },
          {
            path: 'schedules/:id',
            element: <ScheduleViewPage />,
          },
          {
            path: 'schedules/:id/diagnostics',
            element: <DiagnosticsPage />,
          },
        ],
      },
    ],
  },
])

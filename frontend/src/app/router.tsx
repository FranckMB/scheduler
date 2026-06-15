import { createBrowserRouter } from 'react-router-dom'
import AppLayout from '@/app/AppLayout'
import AuthGuard from '@/app/AuthGuard'
import LoginRoute from '@/app/LoginRoute'
import WizardGuard from '@/app/WizardGuard'
import {
  HomePage,
  RegisterPage,
  ScheduleViewPage,
  DiagnosticsPage,
  DashboardPage,
  EntityPage,
  ProfilePage,
} from '@/app/routes'

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
            element: <WizardGuard />,
          },
          {
            path: 'dashboard',
            element: <DashboardPage />,
          },
          {
            path: 'entities',
            element: <EntityPage />,
          },
          {
            path: 'profile',
            element: <ProfilePage />,
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

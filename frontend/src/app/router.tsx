import { createBrowserRouter } from 'react-router-dom'
import AppLayout from '@/app/AppLayout'
import { HomePage, LoginPage, RegisterPage, WizardPage, ScheduleViewPage, DiagnosticsPage } from '@/app/routes'

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
        element: <LoginPage />,
      },
      {
        path: 'register',
        element: <RegisterPage />,
      },
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
])

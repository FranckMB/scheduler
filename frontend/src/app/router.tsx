import { createBrowserRouter } from 'react-router-dom'
import AppLayout from '@/app/AppLayout'
import { HomePage, LoginPage, WizardPage, ScheduleViewPage, DiagnosticsPage } from '@/app/routes'

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

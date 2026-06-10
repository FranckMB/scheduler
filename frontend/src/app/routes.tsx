import { lazy } from 'react'

export const HomePage = lazy(() => import('@/features/ui/pages/HomePage'))
export const LoginPage = lazy(() => import('@/features/auth/pages/LoginPage'))
export const RegisterPage = lazy(() => import('@/features/auth/pages/RegisterPage'))
export const WizardPage = lazy(() => import('@/features/wizard/WizardPage'))
export const ScheduleViewPage = lazy(() => import('@/features/schedule/pages/ScheduleViewPage'))
export const DiagnosticsPage = lazy(() => import('@/features/schedule/pages/DiagnosticsPage'))
export const TierListPage = lazy(() => import('@/features/priorities/TierListPage'))

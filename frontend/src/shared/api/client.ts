import ky from 'ky'
import type { BeforeRequestHook, AfterResponseHook } from 'ky'
import { useAuthStore } from '@/features/auth/authStore'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api'

const authHook: BeforeRequestHook = ({ request }) => {
  const token = useAuthStore.getState().token
  if (token) {
    request.headers.set('Authorization', `Bearer ${token}`)
  }
  request.headers.set('Content-Type', 'application/json')
  request.headers.set('Accept', 'application/json')
}

const errorHook: AfterResponseHook = ({ response }) => {
  if (response.status === 401) {
    useAuthStore.getState().clearAuth()
    window.location.href = '/login'
  }
}

export const apiClient = ky.create({
  prefix: API_BASE_URL,
  timeout: 15000,
  hooks: {
    beforeRequest: [authHook],
    afterResponse: [errorHook],
  },
})

export default apiClient

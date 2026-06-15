import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuthStore } from '@/features/auth/authStore'
import { apiClient } from '@/shared/api/client'

export default function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const setAuth = useAuthStore((state) => state.setAuth)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setIsLoading(true)

    try {
      const data = await apiClient
        .post('login', {
          json: { email, password },
        })
        .json<{ token: string }>()

      useAuthStore.getState().setToken(data.token)

      // Fetch user info after authentication
      const me = await apiClient.get('me').json<{ id: string; email: string; firstName: string; lastName: string; club?: { id: string; name: string }; hasGenerated?: boolean }>()

      if (!me.club) {
        useAuthStore.getState().clearAuth()
        setError('Aucun club associé à ce compte')
        return
      }

      const user = { id: me.id, email: me.email, roles: ['ROLE_USER'] }
      const club = { id: me.club.id, name: me.club.name, slug: me.club.id }

      setAuth(data.token, user, club)

      if (typeof me.hasGenerated === 'boolean') {
        useAuthStore.getState().setHasGenerated(me.hasGenerated)
      }

      window.location.href = '/'
    } catch {
      setError('Invalid credentials. Please try again.')
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <div className="flex min-h-[calc(100vh-4rem)] items-center justify-center">
      <div className="glass-strong w-full max-w-md rounded-2xl p-8 shadow-lg">
        <h2 className="mb-6 text-center text-2xl font-bold text-fg-primary">Sign in</h2>

        {error && (
          <div className="mb-4 rounded-md border border-error-700/50 bg-error-900/40 p-3 text-sm text-error-400" role="alert">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="email" className="mb-1 block text-sm font-medium text-fg-muted">
              Email
            </label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full rounded-lg border border-border-subtle bg-surface px-3 py-2 text-fg-primary placeholder:text-fg-muted focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
              required
              autoComplete="email"
            />
          </div>

          <div>
            <label htmlFor="password" className="mb-1 block text-sm font-medium text-fg-muted">
              Password
            </label>
            <div className="relative">
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-lg border border-border-subtle bg-surface px-3 py-2 pr-20 text-fg-primary placeholder:text-fg-muted focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
                required
                autoComplete="current-password"
              />
              <button
                type="button"
                onClick={() => setShowPassword((value) => !value)}
                aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                aria-pressed={showPassword}
                className="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-sm font-medium text-fg-muted transition hover:text-fg-primary focus:outline-none focus:ring-2 focus:ring-primary-500/20"
              >
                {showPassword ? 'Masquer' : 'Voir'}
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="w-full rounded-lg bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 hover:shadow-lg disabled:opacity-50"
          >
            {isLoading ? 'Signing in...' : 'Sign in'}
          </button>

          <div className="mt-4 text-center">
            <span className="text-sm text-fg-muted">Pas encore de compte ? </span>
            <Link to="/register" className="text-sm text-primary-400 transition hover:text-primary-300" role="link">
              Créer un compte
            </Link>
          </div>
        </form>
      </div>
    </div>
  )
}

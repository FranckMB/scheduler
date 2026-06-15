import { Suspense, useEffect, useRef, useState } from 'react'
import { Link, Outlet } from 'react-router-dom'
import { useUIStore } from '@/features/ui/uiStore'
import { useAuthStore } from '@/features/auth/authStore'
import { LoadingSpinner } from '@/shared/components/LoadingSpinner'

export default function AppLayout() {
  const { sidebarOpen, toggleSidebar } = useUIStore()
  const { user, isAuthenticated, logout, hasGenerated } = useAuthStore()
  const [dropdownOpen, setDropdownOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setDropdownOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  return (
    <div className="flex min-h-screen bg-bg-deep">
      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 transform glass-strong shadow-lg transition-transform duration-200 ease-in-out ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        } lg:relative lg:translate-x-0`}
      >
        <nav className="flex h-full flex-col p-4">
          <div className="mb-6 flex items-center justify-between">
            <h1 className="text-xl font-bold text-primary-400 glow-accent">Scheduler</h1>
            <button
              type="button"
              className="lg:hidden"
              onClick={toggleSidebar}
              aria-label="Close sidebar"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <ul className="space-y-2">
            <li>
              <a
                href="/"
                className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
              >
                Home
              </a>
            </li>
            {hasGenerated ? (
              <>
                <li>
                  <Link
                    to="/entities"
                    className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
                  >
                    Entités
                  </Link>
                </li>
                <li>
                  <Link
                    to="/dashboard"
                    className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
                  >
                    Planning
                  </Link>
                </li>
                <li>
                  <Link
                    to="/dashboard"
                    className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
                  >
                    Exporter
                  </Link>
                </li>
                <li>
                  <Link
                    to="/profile"
                    className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
                  >
                    Profil
                  </Link>
                </li>
              </>
            ) : (
              <li>
                <Link
                  to="/wizard"
                  className="block rounded-md px-3 py-2 text-neutral-300 hover:bg-surface-hover hover:text-fg-primary"
                >
                  Wizard
                </Link>
              </li>
            )}
          </ul>
        </nav>
      </aside>

      {/* Main content */}
      <div className="flex flex-1 flex-col">
        {/* Top bar */}
        <header className="glass-strong sticky top-0 z-30 flex items-center justify-between gap-4 border-b border-border-subtle px-4 py-3 shadow-sm">
          <div className="flex items-center gap-4">
            <button
              type="button"
              className="lg:hidden"
              onClick={toggleSidebar}
              aria-label="Open sidebar"
            >
              <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
            <span className="text-lg font-semibold text-fg-primary">Club Scheduler</span>
          </div>

          {/* Profile menu */}
          <div className="relative" ref={dropdownRef}>
            {isAuthenticated && user ? (
              <>
                <button
                  type="button"
                  className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-neutral-300 hover:bg-surface-hover"
                  onClick={() => setDropdownOpen((prev) => !prev)}
                  aria-expanded={dropdownOpen}
                  aria-haspopup="true"
                >
                  <span className="max-w-48 truncate">{user.email}</span>
                  <svg
                    className={`h-4 w-4 transition-transform ${dropdownOpen ? 'rotate-180' : ''}`}
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                  >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                  </svg>
                </button>

                {dropdownOpen && (
                  <div className="glass-strong absolute right-0 z-50 mt-2 w-48 rounded-md border border-border-subtle py-1 shadow-lg">
                    <Link
                      to="/profile"
                      className="block px-4 py-2 text-sm text-neutral-300 hover:bg-surface-hover"
                      onClick={() => setDropdownOpen(false)}
                    >
                      Profil
                    </Link>
                    <button
                      type="button"
                      className="block w-full px-4 py-2 text-left text-sm text-red-400 hover:bg-surface-hover"
                      onClick={() => {
                        setDropdownOpen(false)
                        logout()
                      }}
                    >
                      Se déconnecter
                    </button>
                  </div>
                )}
              </>
            ) : (
              <Link
                to="/login"
                className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 hover:shadow-lg"
              >
                Se connecter
              </Link>
            )}
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 p-4">
          <Suspense fallback={<LoadingSpinner size="lg" />}>
            <Outlet />
          </Suspense>
        </main>
      </div>

      {/* Overlay for mobile sidebar */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-30 bg-black/50 lg:hidden"
          onClick={toggleSidebar}
          aria-hidden="true"
        />
      )}
    </div>
  )
}

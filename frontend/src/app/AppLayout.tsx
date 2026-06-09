import { Outlet } from 'react-router-dom'
import { useUIStore } from '@/features/ui/uiStore'

export default function AppLayout() {
  const { sidebarOpen, toggleSidebar } = useUIStore()

  return (
    <div className="flex min-h-screen bg-neutral-50">
      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 transform bg-white shadow-lg transition-transform duration-200 ease-in-out ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        } lg:relative lg:translate-x-0`}
      >
        <nav className="flex h-full flex-col p-4">
          <div className="mb-6 flex items-center justify-between">
            <h1 className="text-xl font-bold text-primary-600">Scheduler</h1>
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
                className="block rounded-md px-3 py-2 text-neutral-700 hover:bg-neutral-100"
              >
                Home
              </a>
            </li>
            <li>
              <a
                href="/wizard"
                className="block rounded-md px-3 py-2 text-neutral-700 hover:bg-neutral-100"
              >
                Wizard
              </a>
            </li>
          </ul>
        </nav>
      </aside>

      {/* Main content */}
      <div className="flex flex-1 flex-col">
        {/* Top bar */}
        <header className="flex items-center gap-4 border-b border-neutral-200 bg-white px-4 py-3 shadow-sm">
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
          <span className="text-lg font-semibold text-neutral-800">Club Scheduler</span>
        </header>

        {/* Page content */}
        <main className="flex-1 p-4">
          <Outlet />
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

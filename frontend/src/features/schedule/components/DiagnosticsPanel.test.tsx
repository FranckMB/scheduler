import { render, screen, within } from '@testing-library/react'
import '@testing-library/jest-dom/vitest'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

import { DiagnosticsPanel } from './DiagnosticsPanel'
import type { ScheduleDiagnostic } from '@/features/schedule/types/diagnostic'

function renderPanel(diagnostics: ScheduleDiagnostic[]) {
  return render(
    <MemoryRouter>
      <DiagnosticsPanel diagnostics={diagnostics} />
    </MemoryRouter>
  )
}

describe('DiagnosticsPanel', () => {
  it('renders the empty state in French', () => {
    renderPanel([])

    expect(screen.getByText('Aucun diagnostic')).toBeInTheDocument()
    expect(
      screen.getByText(/L['’]emploi du temps ne présente aucun problème détecté\./)
    ).toBeInTheDocument()
  })

  it('groups diagnostics by severity and shows French business copy', { timeout: 10000 }, () => {
    renderPanel([
      {
        id: 'diag-1',
        type: 'unplaced',
        severity: 'error',
        message: 'Team 44444444-4444-4444-8444-444444444444 could not be placed.',
        teamId: '44444444-4444-4444-8444-444444444444',
        suggestions: ['Add more venue availability.'],
      },
      {
        id: 'diag-2',
        type: 'coach_overload',
        severity: 'warning',
        message: 'Coach overload detected.',
        coachId: '66666666-6666-4666-8666-666666666666',
        suggestions: ['Redistribute sessions.'],
      },
      {
        id: 'diag-3',
        type: 'soft_lock_moved',
        severity: 'info',
        message: 'Preferred slot moved.',
        teamId: '44444444-4444-4444-8444-444444444444',
        venueId: '55555555-5555-4555-8555-555555555555',
        suggestions: ['Review the new time.'],
      },
    ])

    expect(screen.getByRole('heading', { name: 'Erreurs (1)' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Avertissements (1)' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Informations (1)' })).toBeInTheDocument()

    expect(screen.getByText('Aucun créneau compatible n’a été trouvé pour cette équipe.')).toBeInTheDocument()
    expect(screen.getByText('L’entraîneur est trop sollicité sur la période analysée.')).toBeInTheDocument()
    expect(
      screen.getByText('Le créneau préféré a été déplacé pour améliorer l’équilibre global du planning.')
    ).toBeInTheDocument()

    expect(screen.queryByText(/could not be placed/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/overload/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Preferred slot moved/i)).not.toBeInTheDocument()
  })

  it('renders actionable links for the affected entities', () => {
    renderPanel([
      {
        id: 'diag-1',
        type: 'conflict',
        severity: 'error',
        message: 'Technical conflict message.',
        teamId: '44444444-4444-4444-8444-444444444444',
        coachId: '66666666-6666-4666-8666-666666666666',
        venueId: '55555555-5555-4555-8555-555555555555',
        suggestions: [],
      },
    ])

    const article = screen.getByRole('article', { name: 'Diagnostic: Conflit de contrainte' })
    const links = within(article).getAllByRole('link')

    expect(within(article).getByText('Déplacer l’une des séances concernées.')).toBeInTheDocument()
    expect(links.map((link) => link.getAttribute('href'))).toEqual(
      expect.arrayContaining(['/teams/44444444-4444-4444-8444-444444444444'])
    )
    expect(links.map((link) => link.getAttribute('href'))).toEqual(
      expect.arrayContaining(['/coaches/66666666-6666-4666-8666-666666666666'])
    )
    expect(links.map((link) => link.getAttribute('href'))).toEqual(
      expect.arrayContaining(['/venues/55555555-5555-4555-8555-555555555555'])
    )
  })
})

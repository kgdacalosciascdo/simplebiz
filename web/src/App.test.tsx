import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import App from './App'

describe('SimpleBIZ application foundation', () => {
  beforeEach(() => window.localStorage.clear())
  afterEach(() => cleanup())

  it('protects the dashboard route when no session exists', () => {
    render(<MemoryRouter initialEntries={['/']}><App /></MemoryRouter>)
    expect(screen.getByText('Sign in to your business workspace')).toBeInTheDocument()
  })

  it('renders the shared workspace preview without an authenticated session', () => {
    render(<MemoryRouter initialEntries={['/preview']}><App /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.getByText(/Needs Attention/)).toBeInTheDocument()
    expect(screen.getByText(/Profit and loss is owned by the future/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Open navigation' })).toBeInTheDocument()
  })
})

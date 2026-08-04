import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClientProvider } from '@tanstack/react-query'
import App from './App'
import { queryClient } from './lib/queryClient'

function renderApp(initialEntry: string) {
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={[initialEntry]}><App /></MemoryRouter></QueryClientProvider>)
}

describe('SimpleBIZ application foundation', () => {
  beforeEach(() => window.localStorage.clear())
  afterEach(() => cleanup())

  it('protects the dashboard route when no session exists', () => {
    renderApp('/')
    expect(screen.getByText('Sign in to your business workspace')).toBeInTheDocument()
  })

  it('renders the shared workspace preview without an authenticated session', () => {
    renderApp('/preview')
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Needs Attention' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Profit & Loss Snapshot' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Business Snapshot' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Action Center' })).toBeInTheDocument()
    expect(screen.getAllByText('Cash Accounts').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Demo Data').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Main Operating Bank').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: 'Open navigation' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Collapse sidebar' })).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'SimpleBIZ logo' })).toHaveAttribute('src', '/logo.png')
  })

  it('persists the desktop sidebar collapse preference', async () => {
    renderApp('/preview')
    const collapse = screen.getByRole('button', { name: 'Collapse sidebar' })
    fireEvent.click(collapse)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeInTheDocument())
    expect(window.localStorage.getItem('simplebiz.sidebar.collapsed')).toBe('true')
  })
})

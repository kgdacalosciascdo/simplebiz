import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { CollectionsWorkspace } from './CollectionsWorkspace'

describe('CollectionsWorkspace Phase 5B surface', () => {
  beforeEach(() => {
    window.localStorage.clear()
    window.localStorage.setItem('simplebiz_company_id', '1')
    window.localStorage.setItem('simplebiz_token', 'phase5b-test-token')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
      const path = String(input)
      const data = path.includes('/remittances')
        ? [{ id: 'rem-1', remittance_number: 'REM-000001', status: 'submitted', expected_amount: '100', submitted_amount: '100', difference_amount: '0' }]
        : [{ activity_type: 'follow_up', status: 'due', occurred_at: '2026-08-14T09:00:00Z', promise_amount: null }]

      return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('renders the reference workspace and surfaces live activity/remittance reads', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><CollectionsWorkspace /></MemoryRouter></QueryClientProvider>)

    expect(screen.getByRole('heading', { name: 'Collections & Receipts Management' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('follow up · due')).toBeInTheDocument())
    expect(screen.getByText('1 tracked')).toBeInTheDocument()
  })
})

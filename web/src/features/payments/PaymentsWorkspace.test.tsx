import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PaymentsWorkspace } from './PaymentsWorkspace'

describe('PaymentsWorkspace Phase 8A surface', () => {
  beforeEach(() => {
    window.localStorage.clear()
    window.localStorage.setItem('simplebiz_company_id', '1')
    window.localStorage.setItem('simplebiz_token', 'phase8a-test-token')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
      const path = String(input)
      const data = path.includes('/payments/summary')
        ? { eligible_count: 2, eligible_amount: '1000', due_today: '250', overdue: '100', on_hold: 1, pending_approval: 1, scheduled: 0, pending_confirmation: 0, unallocated: '0' }
        : path.includes('/payments/lookups')
          ? { payment_methods: [{ id: 'method-1', code: 'CASH', name: 'Cash Payment', method_class: 'CASH' }], cash_accounts: [{ id: 'account-1', code: 'BANK', name: 'Main Bank' }], currencies: [{ id: 'currency-1', code: 'PHP', symbol: '₱' }], branches: [] }
          : []

      return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('renders the responsive payment workspace from live reads without demo payment rows', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><PaymentsWorkspace /></QueryClientProvider>)

    expect(screen.getByRole('heading', { name: 'Payments & Disbursements Management' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('₱1,000.00')).toBeInTheDocument())
    expect(screen.getByText('No payment instructions yet')).toBeInTheDocument()
    expect(screen.getByText('Cash effect remains owned by MDS-700 until confirmation.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Payment batches' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Check register' })).toBeInTheDocument()
    expect(screen.getByText('No checks issued yet.')).toBeInTheDocument()
  })
})

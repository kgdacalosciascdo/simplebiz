import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { ExpensesWorkspace } from './ExpensesWorkspace'

describe('ExpensesWorkspace Phase 9A surface', () => {
  beforeEach(() => {
    window.localStorage.clear()
    window.localStorage.setItem('simplebiz_company_id', '1')
    window.localStorage.setItem('simplebiz_token', 'phase9a-test-token')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
      const path = String(input)
      let data: unknown = []
      if (path.includes('/summary')) data = { expenses_this_period: 1, period_totals: [{ currency: 'PHP', amount: '125' }], unpaid_by_currency: [{ currency: 'PHP', amount: '125' }], awaiting_approval: 0, missing_evidence: 0, due_soon: 0, overdue: 0, payment_ready: 1 }
      if (path.includes('/lookups')) data = { categories: [{ id: 'cat-1', code: 'OFFICE', name: 'Office', account_title_id: 'acct-1' }], accounts: [{ id: 'acct-1', code: 'EXP-1', name: 'Office Expense' }], payees: [{ id: 'payee-1', display_name: 'Demo Payee' }], currencies: [{ id: 'cur-1', code: 'PHP', name: 'Philippine Peso', symbol: '₱' }], tax_codes: [], payment_terms: [] }
      if (path.includes('/history')) data = [{ id: 'exp-1', expense_number: 'EXP-000001', business_date: '2026-08-14', description: 'Office supplies', payee_name: 'Demo Payee', currency: { code: 'PHP' }, total: '125', paid_amount: '0', remaining_amount: '125', status: 'payment_ready', payment_status: 'unpaid', evidence_status: 'not_required', duplicate_status: 'clear', settlement_intent: 'pay_later', version: 1 }]
      if (path.includes('/attention')) data = { items: [] }
      return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    }))
  })

  afterEach(() => { cleanup(); vi.unstubAllGlobals() })

  it('renders summary, live expense history, and the recording action', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><ExpensesWorkspace /></MemoryRouter></QueryClientProvider>)

    expect(screen.getByRole('heading', { name: 'Expenses Management' })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /record expense/i })).toHaveLength(2)
    await waitFor(() => expect(screen.getByText('EXP-000001')).toBeInTheDocument())
    expect(screen.getByText('Office supplies')).toBeInTheDocument()
  })
})

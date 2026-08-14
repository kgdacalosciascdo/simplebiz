import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { ReportsWorkspace } from './ReportsWorkspace'

const definition = { id: 'definition-1', definition_key: 'REP-COL-001', version: 1, name: 'Receipt Register', description: 'Posted and reversed customer receipts.', business_question: 'Which receipts were recorded?', category: { code: 'collections_receipts', name: 'Collections & Receipts' }, source_owner_module: 'collections', output_capabilities: { display: true, pdf: true, xlsx: true, csv: true, print: true }, favorite: false, sensitivity: 'internal' }
const result = { columns: [{ key: 'receipt_number', label: 'Receipt number', type: 'text', visible: true }], rows: [{ id: 'receipt-1', receipt_number: 'RCT-000001' }], totals: { row_count: 1 }, meta: { title: 'Receipt Register', freshness_state: 'current', as_of_date: '2026-08-14', pagination: { current_page: 1, per_page: 25, total: 1, last_page: 1 } } }

describe('ReportsWorkspace Phase 10B surface', () => {
  beforeEach(() => {
    window.localStorage.setItem('simplebiz_token', 'reports-test-token')
    window.localStorage.setItem('simplebiz_company_id', '1')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input)
      if (path.includes('/reports/catalog?q=') && !path.includes('REP-')) return Promise.resolve(new Response(JSON.stringify({ data: [definition], meta: { categories: [{ code: 'collections_receipts', name: 'Collections & Receipts' }] } }), { status: 200 }))
      if (path.includes('/reports/catalog/REP-COL-001')) return Promise.resolve(new Response(JSON.stringify({ data: { definition, parameters: [{ name: 'from', type: 'date', label: 'From date' }, { name: 'to', type: 'date', label: 'To date' }], columns: result.columns, source_contracts: [{ contract_key: 'collections.receipt_register', version: 1, source_module: 'collections', business_meaning: 'Receipts' }] } }), { status: 200 }))
      if (path.endsWith('/reports/favorites')) return Promise.resolve(new Response(JSON.stringify({ data: [] }), { status: 200 }))
      if (path.endsWith('/reports/schedules') && !init?.method) return Promise.resolve(new Response(JSON.stringify({ data: [] }), { status: 200 }))
      if (init?.method === 'POST' && path.endsWith('/reports/schedules')) return Promise.resolve(new Response(JSON.stringify({ data: { id: 'schedule-1', name: 'Monthly receipts', definition_version: 1, recurrence: 'monthly', status: 'active', recipient_count: 1 } }), { status: 201 }))
      if (init?.method === 'POST' && path.endsWith('/reports/requests')) return Promise.resolve(new Response(JSON.stringify({ data: { id: 'request-1', status: 'completed', outputs: [{ id: 'output-1', format: 'display', status: 'available', result_data: result }] } }), { status: 201 }))
      return Promise.resolve(new Response(JSON.stringify({ data: null }), { status: 200 }))
    }))
  })

  afterEach(() => { cleanup(); vi.unstubAllGlobals(); window.localStorage.clear() })

  it('discovers a governed definition and renders a completed Display Report', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><ReportsWorkspace /></MemoryRouter></QueryClientProvider>)
    expect(await screen.findByRole('heading', { name: 'Reports & Analytics' })).toBeInTheDocument()
    expect(await screen.findByText('Receipt Register')).toBeInTheDocument()
    expect(await screen.findByText('Shared parameters')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Display report' }))
    await waitFor(() => expect(screen.getByText('RCT-000001')).toBeInTheDocument())
    expect(screen.getByText('current')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Open' })).toBeInTheDocument()
  })

  it('exposes the schedule completion surface with in-app delivery scope', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><ReportsWorkspace /></MemoryRouter></QueryClientProvider>)
    fireEvent.click(await screen.findByRole('button', { name: /Schedules/ }))
    expect(await screen.findByText('Scheduled reports')).toBeInTheDocument()
    expect(screen.getByText('In-app delivery only')).toBeInTheDocument()
    fireEvent.change(screen.getByPlaceholderText('Schedule name'), { target: { value: 'Monthly receipts' } })
    fireEvent.click(screen.getByRole('button', { name: /Create schedule/ }))
    await waitFor(() => expect(screen.getByText('In-app report schedule created. No external delivery provider was configured.')).toBeInTheDocument())
  })
})

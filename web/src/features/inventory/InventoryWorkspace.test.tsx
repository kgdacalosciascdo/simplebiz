import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { InventoryWorkspace } from './InventoryWorkspace'

describe('InventoryWorkspace Phase 6A surface', () => {
  beforeEach(() => {
    window.localStorage.clear()
    window.localStorage.setItem('simplebiz_company_id', 'company-1')
    window.localStorage.setItem('simplebiz_token', 'phase6a-test-token')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
      const path = String(input)
      let data: unknown = []

      if (path.includes('/summary')) {
        data = { stock_on_hand: '15.000000', available: '15.000000', inventory_value: null, low_stock: 0, count_variances: 0, balance_count: 1, recent_movements: [] }
      } else if (path.includes('/lookups')) {
        data = { products: [{ id: 'product-1', code: 'SKU-001', name: 'Demo stock item' }], warehouses: [{ id: 'warehouse-1', code: 'MAIN', name: 'Main warehouse' }], stock_locations: [{ id: 'location-1', warehouse_id: 'warehouse-1', code: 'BIN-A', name: 'Bin A' }], reason_codes: [] }
      } else if (path.includes('/balances')) {
        data = [{ id: 'balance-1', product: { code: 'SKU-001', name: 'Demo stock item' }, warehouse: { code: 'MAIN', name: 'Main warehouse' }, stock_location: { code: 'BIN-A', name: 'Bin A' }, unit: { code: 'PCS' }, on_hand: '15.000000', reserved: '0.000000', available: '15.000000', incoming: '0.000000', outgoing: '0.000000', status: 'active' }]
      } else if (path.includes('/movements')) {
        data = []
      }

      return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
  })

  it('renders stock position, movement controls, and responsive inventory actions', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><InventoryWorkspace /></MemoryRouter></QueryClientProvider>)

    expect(screen.getByRole('heading', { name: 'Inventory Management' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Receive Stock/ })).toBeInTheDocument()
    await waitFor(() => expect(screen.getAllByText('15.000000').length).toBeGreaterThan(0))
    expect(screen.getByText('SKU-001')).toBeInTheDocument()
    expect(screen.getByText('15.000000 PCS')).toBeInTheDocument()
  })
})

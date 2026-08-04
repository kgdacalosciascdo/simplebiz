import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { BusinessPartnerForm, MasterRegistriesPage } from './MasterRegistries'
import { ReferenceRegistryPage } from './ReferenceRegistries'

describe('Master Registries workspace', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { counts: { customers: 3, suppliers: 2, products: 4, services: 1, categories: 5, units: 6 } } }), { status: 200, headers: { 'Content-Type': 'application/json' } })))
  })
  afterEach(() => { cleanup(); vi.unstubAllGlobals() })

  it('shows the governed MDS-1000 registries and scope boundaries', async () => {
    render(<MemoryRouter><MasterRegistriesPage /></MemoryRouter>)
    expect(screen.getByText('Master Registries')).toBeInTheDocument()
    expect(screen.getByText('Scope boundaries')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('3')).toBeInTheDocument())
    expect(screen.getByText('Customers')).toBeInTheDocument()
    expect(screen.getByText('Products & Services')).toBeInTheDocument()
  })

  it('provides an explicit shared business partner quick-create form', () => {
    render(<MemoryRouter><BusinessPartnerForm /></MemoryRouter>)
    expect(screen.getByText('New Business Partner')).toBeInTheDocument()
    expect(screen.getByLabelText('Official name')).toBeRequired()
    expect(screen.getByText('customer')).toBeInTheDocument()
  })

  it('renders a transaction-readiness reference registry form', async () => {
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    render(<MemoryRouter><ReferenceRegistryPage registry="payment-methods" /></MemoryRouter>)
    expect(screen.getByText('Payment Methods')).toBeInTheDocument()
    expect(screen.getByText(/do not prove settlement/i)).toBeInTheDocument()
    await waitFor(() => expect(screen.getByLabelText('Code')).toBeInTheDocument())
    expect(screen.getByText('Incoming')).toBeInTheDocument()
    expect(screen.getByText('Outgoing')).toBeInTheDocument()
  })
})

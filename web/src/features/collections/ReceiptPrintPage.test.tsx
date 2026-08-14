import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ReceiptPrintPage } from './ReceiptPrintPage'

const originalReceipt = {
  id: 'receipt-1', company: { name: 'Phase 5C Demo' }, receipt_number: 'RCT-000001', receipt_date: '2026-08-14', receipt_type: 'advance_unapplied_customer_receipt', customer: { code: 'CUS-5C', display_name: 'Long Customer Name' }, payer_name: null, counterparty_name: null, branch: null, currency: { code: 'PHP', symbol: '₱', decimal_precision: 2 }, amount: '75.000000', tender_total: '75.000000', applied_total: '0.000000', unapplied_amount: '75.000000', status: 'posted', application_status: 'unapplied', external_reference: '••••1234', customer_reference: null, source: { sale_id: null, sale_number: null, source_module: null, source_reference: null }, tenders: [{ id: 'tender-1', payment_method: 'Cash', cash_account: 'Main Cash', amount: '75.000000', instrument_status: 'not_applicable', clearing_status: 'not_applicable', external_reference: null, instrument_reference: '••••1234' }], applications: [], issuer: 'Owner', reversal: { status: null, reason: null, reversed_at: null }, copy: { is_reprint: false, label: 'ORIGINAL', reprint_count: 0 }, notes: null, evidence_reference: null,
}

describe('ReceiptPrintPage Phase 5C surface', () => {
  beforeEach(() => {
    window.localStorage.setItem('simplebiz_token', 'phase5c-test-token')
    vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input)
      if (init?.method === 'POST') return Promise.resolve(new Response(JSON.stringify({ data: { id: 'reprint-1' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
      const data = path.includes('reprint_id') ? { ...originalReceipt, copy: { is_reprint: true, label: 'DUPLICATE / REPRINT', reprint_id: 'reprint-1', reprint_reason: 'Customer copy', reprint_count: 1 } } : originalReceipt
      return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    }))
    vi.spyOn(window, 'print').mockImplementation(() => undefined)
  })

  afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); window.localStorage.clear() })

  it('renders masked authoritative receipt details and records an explicit reprint marker', async () => {
    render(<MemoryRouter initialEntries={['/collections/receipts/receipt-1/print']}><Routes><Route path="/collections/receipts/:id/print" element={<ReceiptPrintPage />} /></Routes></MemoryRouter>)
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Payment Receipt' })).toBeInTheDocument())
    expect(screen.getByText('RCT-000001')).toBeInTheDocument()
    expect(screen.getAllByText('••••1234').length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: 'Print Receipt' }))
    expect(window.print).toHaveBeenCalled()
    fireEvent.change(screen.getByRole('textbox', { name: 'Reprint reason' }), { target: { value: 'Customer copy' } })
    fireEvent.click(screen.getByRole('button', { name: /Reprint/ }))
    await waitFor(() => expect(screen.getByText('DUPLICATE / REPRINT')).toBeInTheDocument())
  })
})

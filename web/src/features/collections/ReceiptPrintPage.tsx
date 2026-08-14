/* eslint-disable react-hooks/set-state-in-effect, react-hooks/exhaustive-deps */
import { useEffect, useState } from 'react'
import { ArrowLeft, CheckCircle2, Printer, RefreshCw, ShieldCheck } from 'lucide-react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { apiFetch } from '../../lib/api'

type Envelope<T> = { data: T }
type ReceiptPrint = {
  id: string
  company: { name?: string; legal_name?: string; address?: Record<string, string> | null }
  receipt_number: string
  receipt_date: string
  receipt_type: string
  customer?: { code?: string; display_name?: string } | null
  payer_name?: string | null
  counterparty_name?: string | null
  branch?: { code?: string; name?: string } | null
  currency?: { code?: string; symbol?: string; decimal_precision?: number } | null
  amount: string
  tender_total: string
  applied_total: string
  unapplied_amount: string
  status: string
  application_status: string
  external_reference?: string | null
  customer_reference?: string | null
  source: { sale_id?: string | null; sale_number?: string | null; source_module?: string | null; source_reference?: string | null }
  tenders: { id: string; payment_method?: string; cash_account?: string; amount: string; instrument_status?: string; clearing_status?: string; external_reference?: string | null; instrument_reference?: string | null }[]
  applications: { id: string; open_item_id: string; document_number?: string; source_sale_id?: string; amount: string; application_date?: string; status: string }[]
  issuer?: string | null
  reversal: { status?: string | null; reason?: string | null; reversed_at?: string | null }
  copy: { is_reprint: boolean; label: string; reprint_id?: string; reprint_reason?: string; reprinted_at?: string; reprint_count: number }
  notes?: string | null
  evidence_reference?: string | null
}

const money = (receipt: ReceiptPrint, value: string) => `${receipt.currency?.symbol ?? receipt.currency?.code ?? ''} ${Number(value).toLocaleString(undefined, { minimumFractionDigits: receipt.currency?.decimal_precision ?? 2, maximumFractionDigits: receipt.currency?.decimal_precision ?? 2 })}`.trim()

export function ReceiptPrintPage() {
  const { id } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const [receipt, setReceipt] = useState<ReceiptPrint | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reprintReason, setReprintReason] = useState('')
  const [reprinting, setReprinting] = useState(false)

  async function load() {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const response = await apiFetch<Envelope<ReceiptPrint>>(`/collections/receipts/${id}/print${location.search}`)
      setReceipt(response.data)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Unable to load the printable Receipt.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [id, location.search])

  async function reprint() {
    if (!id || !reprintReason.trim()) {
      setError('Enter a reason before recording a reprint.')
      return
    }
    setReprinting(true)
    setError('')
    try {
      const response = await apiFetch<Envelope<{ id: string }>>(`/collections/receipts/${id}/reprint`, { method: 'POST', headers: { 'Idempotency-Key': `receipt-reprint-${Date.now()}` }, body: JSON.stringify({ reason: reprintReason.trim(), channel: 'screen' }) })
      setReprintReason('')
      navigate(`/collections/receipts/${id}/print?reprint_id=${encodeURIComponent(response.data.id)}`)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Unable to record the Receipt reprint.')
    } finally {
      setReprinting(false)
    }
  }

  if (loading) return <div className="sb-print-state">Loading Receipt…</div>
  if (error || !receipt) return <div className="sb-print-state"><p>{error || 'Receipt unavailable.'}</p><Link className="sb-print-link" to="/collections">Back to Collections</Link></div>

  return <div className="sb-receipt-print-page">
    <div className="sb-print-toolbar" role="toolbar" aria-label="Receipt actions">
      <Link to="/collections" className="sb-print-secondary"><ArrowLeft size={16} /> Back to Collections</Link>
      <div className="sb-print-toolbar-actions">
        <button type="button" className="sb-print-secondary" onClick={() => window.print()}><Printer size={16} /> Print Receipt</button>
        <input aria-label="Reprint reason" placeholder="Reason for reprint" value={reprintReason} onChange={(event) => setReprintReason(event.target.value)} />
        <button type="button" className="sb-print-primary" onClick={() => void reprint()} disabled={reprinting}>{reprinting ? <RefreshCw className="sb-spin" size={16} /> : <RefreshCw size={16} />} Reprint</button>
      </div>
    </div>
    {error && <div className="sb-print-error" role="alert">{error}</div>}
    <article className="sb-receipt-paper" aria-label={`Receipt ${receipt.receipt_number}`}>
      <header className="sb-receipt-paper-header">
        <div><div className="sb-receipt-eyebrow">{receipt.company.legal_name || receipt.company.name || 'SimpleBIZ company'}</div><h1>Payment Receipt</h1><p>{receipt.company.name}</p></div>
        <div className={`sb-receipt-copy ${receipt.copy.is_reprint ? 'is-reprint' : ''}`}><strong>{receipt.copy.label}</strong>{receipt.copy.is_reprint && <small>Copy {receipt.copy.reprint_count}</small>}</div>
      </header>
      <div className="sb-receipt-meta-grid">
        <div><span>Receipt number</span><strong>{receipt.receipt_number}</strong></div>
        <div><span>Receipt date</span><strong>{receipt.receipt_date}</strong></div>
        <div><span>Status</span><strong>{receipt.status}</strong></div>
        <div><span>Receipt type</span><strong>{receipt.receipt_type.replaceAll('_', ' ')}</strong></div>
      </div>
      <section className="sb-receipt-section">
        <h2>Received from</h2>
        <p className="sb-receipt-party">{receipt.customer?.display_name || receipt.counterparty_name || receipt.payer_name || 'Unspecified payer'}</p>
        {receipt.customer?.code && <p className="sb-receipt-muted">Customer code: {receipt.customer.code}</p>}
        {receipt.branch?.name && <p className="sb-receipt-muted">Branch: {receipt.branch.name}</p>}
      </section>
      <section className="sb-receipt-section">
        <h2>Tender summary</h2>
        <div className="sb-receipt-table-wrap"><table><thead><tr><th>Method</th><th>Cash Account</th><th>Reference</th><th className="sb-number">Amount</th></tr></thead><tbody>{receipt.tenders.map((tender) => <tr key={tender.id}><td>{tender.payment_method || 'Payment method'}</td><td>{tender.cash_account || '—'}</td><td>{tender.external_reference || tender.instrument_reference || '—'}</td><td className="sb-number">{money(receipt, tender.amount)}</td></tr>)}</tbody><tfoot><tr><th colSpan={3}>Total received</th><th className="sb-number">{money(receipt, receipt.tender_total)}</th></tr></tfoot></table></div>
      </section>
      <section className="sb-receipt-section">
        <h2>Application summary</h2>
        {receipt.applications.length > 0 ? <div className="sb-receipt-table-wrap"><table><thead><tr><th>Open item</th><th>Source Sale</th><th>Date</th><th className="sb-number">Applied</th></tr></thead><tbody>{receipt.applications.map((application) => <tr key={application.id}><td>{application.document_number || application.open_item_id}</td><td>{application.source_sale_id || '—'}</td><td>{application.application_date || '—'}</td><td className="sb-number">{money(receipt, application.amount)}</td></tr>)}</tbody></table></div> : <p className="sb-receipt-muted">No customer receivable application recorded.</p>}
        <div className="sb-receipt-totals"><span>Applied: <strong>{money(receipt, receipt.applied_total)}</strong></span><span>Unapplied / advance: <strong>{money(receipt, receipt.unapplied_amount)}</strong></span></div>
      </section>
      <section className="sb-receipt-total-box"><span>Total amount received</span><strong>{money(receipt, receipt.amount)}</strong></section>
      {(receipt.source.sale_number || receipt.source.source_reference || receipt.external_reference || receipt.notes) && <section className="sb-receipt-section sb-receipt-support"><h2>References and notes</h2>{receipt.source.sale_number && <p>Source Sale: {receipt.source.sale_number}</p>}{receipt.source.source_reference && <p>Source reference: {receipt.source.source_reference}</p>}{receipt.external_reference && <p>External reference: {receipt.external_reference}</p>}{receipt.notes && <p>{receipt.notes}</p>}</section>}
      {receipt.reversal.status && <div className="sb-receipt-reversal"><strong>Reversed receipt</strong><span>{receipt.reversal.reason || 'Correction recorded'}{receipt.reversal.reversed_at ? ` · ${new Date(receipt.reversal.reversed_at).toLocaleString()}` : ''}</span></div>}
      <footer className="sb-receipt-paper-footer"><span>Issued by {receipt.issuer || 'Authorized user'}</span><span>{receipt.copy.is_reprint ? `Reprinted ${receipt.copy.reprinted_at ? new Date(receipt.copy.reprinted_at).toLocaleString() : ''} · ${receipt.copy.reprint_reason || 'authorized copy'}` : 'Original issued copy'}</span><span><ShieldCheck size={14} /> Authoritative SimpleBIZ Receipt</span></footer>
    </article>
    <div className="sb-print-integrity-note"><CheckCircle2 size={16} /> Printing and reprinting never create a new Receipt, application, cash movement, or accounting transaction.</div>
  </div>
}

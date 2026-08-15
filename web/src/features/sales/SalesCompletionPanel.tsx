import { useState, type FormEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, FilePlus2, LoaderCircle, RotateCcw, ShieldCheck, X } from 'lucide-react'
import { apiFetch, newIdempotencyKey } from '../../lib/api'

type Envelope<T> = { data: T }
type SaleLine = { id: string; product_name?: string; description_snapshot?: string; quantity: string; unit_price?: string }
type Sale = { id: string; sale_number: string; sale_date?: string; status: string; version: number; customer?: { display_name?: string }; currency?: { code?: string }; lines?: SaleLine[] }
type EligibleLine = { id: string; sale_id: string; sale_number?: string; sale_date?: string; customer?: string; currency?: string; product_name?: string; remaining_returnable_quantity: string; unit_price: string; stock_managed: boolean }
type SalesReturn = { id: string; return_number: string; sale?: { sale_number?: string }; customer?: { display_name?: string }; total_amount: string; status: string; version: number }
type SalesAdjustment = { id: string; adjustment_number: string; adjustment_type: 'debit' | 'credit'; sale?: { sale_number?: string }; total_amount: string; status: string; version: number }

type Mode = 'return' | 'adjustment' | null

const today = () => new Date().toISOString().slice(0, 10)

export function SalesCompletionPanel() {
  const queryClient = useQueryClient()
  const [mode, setMode] = useState<Mode>(null)
  const [busy, setBusy] = useState('')
  const [message, setMessage] = useState('')
  const salesQuery = useQuery({ queryKey: ['sales', 'posted'], queryFn: () => apiFetch<Envelope<Sale[]>>('/sales?status=posted&per_page=50') })
  const eligibleQuery = useQuery({ queryKey: ['sales', 'returnable-lines'], queryFn: () => apiFetch<Envelope<{ items: EligibleLine[] }>>('/sales/returns/eligible-lines'), enabled: mode === 'return' })
  const returnsQuery = useQuery({ queryKey: ['sales', 'returns'], queryFn: () => apiFetch<Envelope<SalesReturn[]>>('/sales/returns?per_page=8') })
  const adjustmentsQuery = useQuery({ queryKey: ['sales', 'adjustments'], queryFn: () => apiFetch<Envelope<SalesAdjustment[]>>('/sales/adjustments?per_page=8') })
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['sales'] })

  async function act(path: string, version: number, label: string) {
    if (label === 'Reverse' && !window.confirm('Reverse this posted correction? A reason is required.')) return
    setBusy(path); setMessage('')
    try {
      await apiFetch(path, { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({ version, reason: `${label} from Sales Management` }) })
      refresh()
    } catch (caught) {
      setMessage(caught instanceof Error ? caught.message : 'The sales action could not be completed.')
    } finally { setBusy('') }
  }

  const returns = returnsQuery.data?.data ?? []
  const adjustments = adjustmentsQuery.data?.data ?? []
  const queue = [
    ...returns.map((item) => ({ id: item.id, number: item.return_number, detail: `Sales Return · ${item.sale?.sale_number ?? 'Sale'} · ${item.customer?.display_name ?? 'Customer'}`, status: item.status, version: item.version, action: correctionAction('returns', item.id, item.status) })),
    ...adjustments.map((item) => ({ id: item.id, number: item.adjustment_number, detail: `${item.adjustment_type === 'debit' ? 'Debit' : 'Credit'} Adjustment · ${item.sale?.sale_number ?? 'Sale'}`, status: item.status, version: item.version, action: correctionAction('adjustments', item.id, item.status) })),
  ].slice(0, 6)

  return <section className="sb-sales-completion-grid" aria-label="Sales corrections and governed source documents">
    <article className="sb-sales-completion-card">
      <header><span><ShieldCheck aria-hidden="true" />Sales corrections</span><small>MDS-200 source-owned</small></header>
      <p className="sb-sales-completion-intro">Create linked returns or receivable adjustments from posted Sales. Inventory effects remain with MDS-600 and refunds remain with MDS-500.</p>
      <div className="sb-sales-completion-buttons">
        <button type="button" onClick={() => { setMessage(''); setMode('return') }}><RotateCcw aria-hidden="true" />Record Sales Return<ArrowRight aria-hidden="true" /></button>
        <button type="button" onClick={() => { setMessage(''); setMode('adjustment') }}><FilePlus2 aria-hidden="true" />Create Debit/Credit Adjustment<ArrowRight aria-hidden="true" /></button>
      </div>
      {(salesQuery.isError || returnsQuery.isError || adjustmentsQuery.isError) && <p className="sb-sales-completion-error">Live correction data could not be loaded. Retry the page to refresh the governed source records.</p>}
    </article>
    <article className="sb-sales-completion-card">
      <header><span><ShieldCheck aria-hidden="true" />Correction queue</span><small>{queue.length} records</small></header>
      <div className="sb-sales-completion-queue">
        {queue.length ? queue.map((item) => { const action = item.action; return <div key={`${item.number}-${item.id}`} className="sb-sales-completion-row"><span><strong>{item.number}</strong><small>{item.detail} · {item.status}</small></span>{action && <button type="button" disabled={busy === action.path} onClick={() => void act(action.path, item.version, action.label)}>{busy === action.path ? <LoaderCircle className="sb-sales-spin" aria-label="Saving" /> : action.label}</button>}</div> }) : <p className="sb-sales-completion-empty">No Sales Returns or Adjustments have been recorded yet.</p>}
      </div>
    </article>
    {mode && <SalesCorrectionForm mode={mode} sales={salesQuery.data?.data ?? []} eligibleLines={eligibleQuery.data?.data.items ?? []} eligibleLoading={eligibleQuery.isPending} onClose={() => setMode(null)} onSaved={() => { setMode(null); refresh() }} onError={setMessage} />}
    {message && <div className="sb-sales-completion-toast" role="alert">{message}<button type="button" onClick={() => setMessage('')} aria-label="Dismiss"><X size={16} /></button></div>}
  </section>
}

function correctionAction(kind: 'returns' | 'adjustments', id: string, status: string): { path: string; label: string } | null {
  if (status === 'draft') return { path: `/sales/${kind}/${id}/submit`, label: 'Submit' }
  if (status === 'for_approval') return { path: `/sales/${kind}/${id}/approve`, label: 'Approve' }
  if (status === 'approved') return { path: `/sales/${kind}/${id}/post`, label: 'Post' }
  if (status === 'posted') return { path: `/sales/${kind}/${id}/reverse`, label: 'Reverse' }
  return null
}

function SalesCorrectionForm({ mode, sales, eligibleLines, eligibleLoading, onClose, onSaved, onError }: { mode: Exclude<Mode, null>; sales: Sale[]; eligibleLines: EligibleLine[]; eligibleLoading: boolean; onClose: () => void; onSaved: () => void; onError: (message: string) => void }) {
  const [saving, setSaving] = useState(false)
  const [saleId, setSaleId] = useState(sales[0]?.id ?? '')
  const [lineId, setLineId] = useState(eligibleLines[0]?.id ?? '')
  const [date, setDate] = useState(today())
  const [quantity, setQuantity] = useState(eligibleLines[0]?.remaining_returnable_quantity ?? '1')
  const [adjustmentType, setAdjustmentType] = useState<'debit' | 'credit'>('credit')
  const [amount, setAmount] = useState('0')
  const [explanation, setExplanation] = useState('')

  const selectedLine = eligibleLines.find((line) => line.id === lineId)
  const postedSales = sales.filter((sale) => sale.status === 'posted')

  async function submit(event: FormEvent) {
    event.preventDefault(); setSaving(true)
    try {
      if (mode === 'return') {
        if (!selectedLine) throw new Error('Select an eligible posted-sale line.')
        const created = await apiFetch<Envelope<SalesReturn>>('/sales/returns', { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({ sale_id: selectedLine.sale_id, return_date: date, explanation: explanation || 'Sales Return prepared from the Sales workspace.', lines: [{ sale_line_id: selectedLine.id, quantity }] }) })
        await apiFetch(`/sales/returns/${created.data.id}/submit`, { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({ version: created.data.version }) })
      } else {
        if (!saleId) throw new Error('Select a posted Sale.')
        const created = await apiFetch<Envelope<SalesAdjustment>>('/sales/adjustments', { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({ sale_id: saleId, adjustment_type: adjustmentType, adjustment_date: date, explanation: explanation || 'Sales Adjustment prepared from the Sales workspace.', lines: [{ description: explanation || 'Sales receivable correction', unit_amount: amount, tax_amount: 0 }] }) })
        await apiFetch(`/sales/adjustments/${created.data.id}/submit`, { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({ version: created.data.version }) })
      }
      onSaved()
    } catch (caught) { onError(caught instanceof Error ? caught.message : 'The sales correction could not be completed.') } finally { setSaving(false) }
  }

  return <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/45 p-4" role="dialog" aria-modal="true" aria-labelledby="sales-correction-title"><form onSubmit={submit} className="w-full max-w-xl rounded-xl border-2 border-[#138fc6] bg-white p-5 shadow-2xl"><div className="mb-5 flex items-start justify-between gap-3"><div><h2 id="sales-correction-title" className="text-xl font-bold text-slate-950">{mode === 'return' ? 'Record Sales Return' : 'Create Sales Adjustment'}</h2><p className="mt-1 text-sm text-slate-600">The draft is submitted for governed review. Posting may create receivable effects and an MDS-600 stock effect.</p></div><button type="button" onClick={onClose} className="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900" aria-label="Close"><X size={18} /></button></div><div className="grid gap-4 sm:grid-cols-2">{mode === 'return' ? <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Eligible posted-sale line<select required value={lineId} onChange={(event) => { setLineId(event.target.value); const line = eligibleLines.find((entry) => entry.id === event.target.value); setQuantity(line?.remaining_returnable_quantity ?? '1') }} disabled={eligibleLoading} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5"><option value="">{eligibleLoading ? 'Loading eligible lines…' : 'Select a returnable line'}</option>{eligibleLines.map((line) => <option key={line.id} value={line.id}>{line.sale_number} · {line.product_name} · remaining {line.remaining_returnable_quantity}</option>)}</select></label> : <label className="text-sm font-semibold text-slate-700 sm:col-span-2">Posted Sale<select required value={saleId} onChange={(event) => setSaleId(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5"><option value="">Select a posted Sale</option>{postedSales.map((sale) => <option key={sale.id} value={sale.id}>{sale.sale_number} · {sale.customer?.display_name ?? 'Customer'}</option>)}</select></label>}<label className="text-sm font-semibold text-slate-700">Date<input required type="date" value={date} onChange={(event) => setDate(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" /></label>{mode === 'return' ? <label className="text-sm font-semibold text-slate-700">Quantity<input required min="0.000001" max={selectedLine?.remaining_returnable_quantity} step="0.000001" type="number" value={quantity} onChange={(event) => setQuantity(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" /></label> : <><label className="text-sm font-semibold text-slate-700">Adjustment type<select value={adjustmentType} onChange={(event) => setAdjustmentType(event.target.value as 'debit' | 'credit')} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5"><option value="credit">Credit · reduce receivable</option><option value="debit">Debit · increase receivable</option></select></label><label className="text-sm font-semibold text-slate-700">Amount<input required min="0" step="0.000001" type="number" value={amount} onChange={(event) => setAmount(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" /></label></>}<label className="text-sm font-semibold text-slate-700 sm:col-span-2">Reason / explanation<textarea required rows={3} value={explanation} onChange={(event) => setExplanation(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5" placeholder="Explain the source correction and retain supporting evidence." /></label></div><div className="mt-6 flex justify-end gap-3"><button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button><button disabled={saving || (mode === 'return' && !selectedLine) || (mode === 'adjustment' && (!saleId || Number(amount) <= 0))} className="rounded-lg bg-[#168fc6] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#087caf] disabled:cursor-not-allowed disabled:opacity-50">{saving ? 'Saving…' : 'Create and submit'}</button></div></form></div>
}

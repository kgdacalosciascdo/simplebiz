import { useState, type ChangeEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, FileSpreadsheet, HandCoins, RotateCcw, Send, UploadCloud } from 'lucide-react'
import { apiFetch } from '../../lib/api'

type Envelope<T> = { data: T; meta?: { total?: number } }
type CompletionExpense = { id: string; expense_number: string; description: string; total: string; status: string; settlement_intent: string; reimbursement_claim_id?: string | null; currency?: { code?: string } | null }
type Payee = { id: string; display_name?: string; name?: string; code?: string }
type CompletionLookups = { payees?: Payee[] }
type Claim = { id: string; claim_number: string; business_purpose: string; status: string; total: string; remaining_amount: string; claimant_business_partner?: { display_name?: string } | null }
type Template = { id: string; template_number: string; name: string; frequency: string; next_run_date: string; amount: string; active: boolean }
type Correction = { id: string; adjustment_type: string; amount: string; status: string; reason: string; expense?: { expense_number?: string } | null }

export function ExpenseCompletionPanel({ history, lookups, onNotice, onError }: { history: CompletionExpense[]; lookups?: CompletionLookups; onNotice: (message: string) => void; onError: (message: string) => void }) {
  const companyId = window.localStorage.getItem('simplebiz_company_id')
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<string[]>([])
  const [purpose, setPurpose] = useState('')
  const [activeTab, setActiveTab] = useState<'reimbursements' | 'recurring' | 'corrections' | 'import'>('reimbursements')
  const [batchId, setBatchId] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const claimsQuery = useQuery({ queryKey: ['simplebiz', 'expense-reimbursements', companyId], queryFn: () => apiFetch<Envelope<Claim[]>>('/expenses/reimbursements?per_page=8'), enabled: Boolean(companyId) })
  const recurringQuery = useQuery({ queryKey: ['simplebiz', 'expense-recurring', companyId], queryFn: () => apiFetch<Envelope<Template[]>>('/expenses/recurring'), enabled: Boolean(companyId) })
  const correctionsQuery = useQuery({ queryKey: ['simplebiz', 'expense-corrections', companyId], queryFn: () => apiFetch<Envelope<Correction[]>>('/expenses/corrections?per_page=8'), enabled: Boolean(companyId) })
  const reportQuery = useQuery({ queryKey: ['simplebiz', 'expense-report-summary', companyId], queryFn: () => apiFetch<Envelope<Array<{ currency?: string; amount: string; expense_count: number }>>>('/expenses/reports/summary'), enabled: Boolean(companyId) })
  const claimable = history.filter((expense) => expense.settlement_intent === 'reimbursement' && !expense.reimbursement_claim_id && ['draft', 'approved', 'payment_ready'].includes(expense.status ?? 'draft'))

  const claimMutation = useMutation({
    mutationFn: () => apiFetch<Envelope<Claim>>('/expenses/reimbursements', { method: 'POST', body: JSON.stringify({ claimant_business_partner_id: lookups?.payees?.[0]?.id, expense_ids: selected, business_purpose: purpose }) }),
    onSuccess: () => { setSelected([]); setPurpose(''); onNotice('Reimbursement Claim created. Submit it when the evidence and business purpose are ready.'); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expenses'] }); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expense-reimbursements'] }) },
    onError: (caught) => onError(caught instanceof Error ? caught.message : 'The Reimbursement Claim could not be created.'),
  })
  const generateMutation = useMutation({
    mutationFn: (template: Template) => apiFetch<Envelope<unknown>>(`/expenses/recurring/${template.id}/generate`, { method: 'POST', body: JSON.stringify({}) }),
    onSuccess: () => { onNotice('Recurring Expense draft generated. It remains subject to the normal Expense workflow.'); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expenses'] }); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expense-recurring'] }) },
    onError: (caught) => onError(caught instanceof Error ? caught.message : 'The recurring draft could not be generated.'),
  })
  const previewMutation = useMutation({
    mutationFn: () => { const body = new FormData(); body.append('file', file as File); return apiFetch<Envelope<{ id: string; valid_row_count: number; row_count: number }>>('/expenses/import/preview', { method: 'POST', body }) },
    onSuccess: (result) => { setBatchId(result.data.id); onNotice(`${result.data.valid_row_count} of ${result.data.row_count} import rows are valid drafts.`) },
    onError: (caught) => onError(caught instanceof Error ? caught.message : 'The Expense import could not be previewed.'),
  })
  const applyMutation = useMutation({
    mutationFn: () => apiFetch<Envelope<unknown>>(`/expenses/import/${batchId}/apply`, { method: 'POST', body: JSON.stringify({}) }),
    onSuccess: () => { onNotice('Import batch applied as Expense drafts. No approval or payment was bypassed.'); setBatchId(''); setFile(null); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expenses'] }) },
    onError: (caught) => onError(caught instanceof Error ? caught.message : 'The Expense import could not be applied.'),
  })

  function toggleExpense(id: string) { setSelected((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]) }
  function chooseFile(event: ChangeEvent<HTMLInputElement>) { setFile(event.target.files?.[0] ?? null); setBatchId('') }

  return <section className="sb-expense-completion" aria-label="Expense completion workflows">
    <div className="sb-expense-completion-heading"><div><p className="sb-expense-eyebrow">MDS-800 completion</p><h2>Claims, recurring controls, corrections & import</h2><p>Every action creates governed Expense records and hands payment execution to MDS-500.</p></div><div className="sb-expense-tabs" role="tablist">{([['reimbursements', HandCoins, 'Reimbursements'], ['recurring', CalendarClock, 'Recurring'], ['corrections', RotateCcw, 'Corrections'], ['import', FileSpreadsheet, 'Import']] as const).map(([tab, Icon, text]) => <button key={tab} type="button" role="tab" aria-selected={activeTab === tab} className={activeTab === tab ? 'is-active' : ''} onClick={() => setActiveTab(tab)}><Icon size={15} />{text}</button>)}</div></div>
    {activeTab === 'reimbursements' && <div className="sb-expense-completion-grid">
      <div className="sb-expense-completion-card"><div className="sb-expense-card-title"><HandCoins size={17} /><h3>Build a reimbursement claim</h3></div><p className="sb-expense-card-note">Choose personal Expense Records, then add the claimant business purpose.</p>{claimable.length ? <div className="sb-expense-select-list">{claimable.slice(0, 6).map((expense) => <label key={expense.id}><input type="checkbox" checked={selected.includes(expense.id)} onChange={() => toggleExpense(expense.id)} /><span><strong>{expense.expense_number}</strong><small>{expense.description} · {expense.currency?.code ?? 'Currency'} {Number(expense.total).toLocaleString()}</small></span></label>)}</div> : <p className="sb-expense-card-empty">No unclaimed reimbursement Expenses are ready.</p>}<textarea value={purpose} onChange={(event) => setPurpose(event.target.value)} placeholder="Business purpose" rows={2} /><button type="button" className="sb-expense-secondary-action" disabled={!selected.length || !purpose.trim() || !lookups?.payees?.length || claimMutation.isPending} onClick={() => claimMutation.mutate()}><Send size={15} /> Create Claim</button><small className="sb-expense-card-footnote">Claimant payee defaults to the first active Business Partner until employee/payee selection is configured by the shared registries.</small></div>
      <div className="sb-expense-completion-card"><div className="sb-expense-card-title"><ListIcon /><h3>Recent claims</h3></div>{claimsQuery.data?.data?.length ? <ul className="sb-expense-mini-list">{claimsQuery.data.data.map((claim) => <li key={claim.id}><span><strong>{claim.claim_number}</strong><small>{claim.business_purpose}</small></span><Status value={claim.status} /><b>{Number(claim.total).toLocaleString()}</b></li>)}</ul> : <p className="sb-expense-card-empty">No reimbursement claims yet.</p>}</div>
      <div className="sb-expense-completion-card"><div className="sb-expense-card-title"><HandCoins size={17} /><h3>Settlement boundary</h3></div><p className="sb-expense-card-note">Approved claims create a reimbursement obligation. Payment preparation, approval, confirmation, allocation, and reversal remain MDS-500-owned. Cash movement remains MDS-700-owned.</p><div className="sb-expense-boundary"><strong>{claimsQuery.data?.meta?.total ?? claimsQuery.data?.data?.length ?? 0}</strong><span>claims in this workspace</span></div></div>
    </div>}
    {activeTab === 'recurring' && <div className="sb-expense-completion-grid"><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><CalendarClock size={17} /><h3>Recurring templates</h3></div>{recurringQuery.data?.data?.length ? <ul className="sb-expense-mini-list">{recurringQuery.data.data.map((template) => <li key={template.id}><span><strong>{template.name}</strong><small>{template.frequency} · next {template.next_run_date}</small></span><button type="button" className="sb-expense-table-action" disabled={!template.active || generateMutation.isPending} onClick={() => generateMutation.mutate(template)}>Generate draft</button></li>)}</ul> : <p className="sb-expense-card-empty">No recurring templates configured.</p>}</div><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><CalendarClock size={17} /><h3>Control reminder</h3></div><p className="sb-expense-card-note">Generated records are drafts. Amount changes, evidence, tax, allocation, approval, posting, and settlement are never silently bypassed.</p></div></div>}
    {activeTab === 'corrections' && <div className="sb-expense-completion-grid"><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><RotateCcw size={17} /><h3>Correction history</h3></div>{correctionsQuery.data?.data?.length ? <ul className="sb-expense-mini-list">{correctionsQuery.data.data.map((entry) => <li key={entry.id}><span><strong>{entry.expense?.expense_number ?? 'Expense'}</strong><small>{entry.adjustment_type} · {entry.reason}</small></span><Status value={entry.status} /><b>{Number(entry.amount).toLocaleString()}</b></li>)}</ul> : <p className="sb-expense-card-empty">No adjustments, credits, refunds, or reversals recorded.</p>}</div><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><RotateCcw size={17} /><h3>Correction boundary</h3></div><p className="sb-expense-card-note">Original posted records remain immutable. Refunds wait for an MDS-700 Cash Movement link; confirmed MDS-500 allocations must be corrected first.</p></div></div>}
    {activeTab === 'import' && <div className="sb-expense-completion-grid"><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><UploadCloud size={17} /><h3>Controlled Expense import</h3></div><p className="sb-expense-card-note">CSV creates draft Expense Records only. Required headers: business_date, description, currency_id, expense_category_id, amount.</p><input type="file" accept=".csv,.txt" onChange={chooseFile} /><div className="sb-expense-inline-actions"><button type="button" className="sb-expense-secondary-action" disabled={!file || previewMutation.isPending} onClick={() => previewMutation.mutate()}>Preview rows</button><button type="button" className="sb-expense-primary" disabled={!batchId || applyMutation.isPending} onClick={() => applyMutation.mutate()}>Apply valid rows</button></div></div><div className="sb-expense-completion-card"><div className="sb-expense-card-title"><FileSpreadsheet size={17} /><h3>Report snapshot</h3></div>{reportQuery.data?.data?.length ? <ul className="sb-expense-mini-list">{reportQuery.data.data.map((row) => <li key={row.currency ?? 'currency'}><span><strong>{row.currency ?? 'Currency'}</strong><small>{row.expense_count} records</small></span><b>{Number(row.amount).toLocaleString()}</b></li>)}</ul> : <p className="sb-expense-card-empty">No report data for the selected period.</p>}</div></div>}
  </section>
}

function Status({ value }: { value: string }) { return <span className={`sb-expense-status status-${value}`}>{value.replaceAll('_', ' ')}</span> }
function ListIcon() { return <FileSpreadsheet size={17} /> }

import { useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  AlertTriangle,
  ArrowRight,
  CalendarDays,
  CheckCircle2,
  CircleDollarSign,
  FileText,
  ListChecks,
  Plus,
  Receipt,
  Search,
  Upload,
  WalletCards,
  X,
} from 'lucide-react'
import { DataTable } from '../../components/DataTable'
import { apiFetch } from '../../lib/api'
import { ExpenseCompletionPanel } from './ExpenseCompletionPanel'

type ApiEnvelope<T> = { data: T; meta?: { total?: number } }
type Lookup = { id: string; code?: string; name?: string; display_name?: string; symbol?: string; rate?: string | number; account_title_id?: string; account_subtype?: string; locked?: boolean }
type Summary = {
  expenses_this_period: number
  period_totals: { currency?: string; amount: string }[]
  unpaid_by_currency: { currency?: string; amount: string }[]
  awaiting_approval: number
  missing_evidence: number
  due_soon: number
  overdue: number
  payment_ready: number
}
type ExpenseLine = { id?: string; description: string; quantity: string; unit_amount: string; line_total?: string; expense_category_id?: string; expense_account_title_id?: string }
type Expense = {
  id: string
  expense_number: string
  business_date: string
  description: string
  payee_name?: string | null
  currency?: { code?: string; symbol?: string } | null
  total: string
  paid_amount: string
  remaining_amount: string
  status: string
  payment_status: string
  evidence_status: string
  duplicate_status: string
  settlement_intent: string
  version: number
  lines?: ExpenseLine[]
}
type Lookups = {
  categories: Lookup[]
  accounts: Lookup[]
  payees: Lookup[]
  currencies: Lookup[]
  tax_codes: Lookup[]
  payment_terms: Lookup[]
}
type AttentionItem = Expense & { obligation?: { due_status?: string; payment_ready?: boolean } | null }

const today = () => new Date().toISOString().slice(0, 10)
const money = (amount: string | number, currency = 'PHP') => `${currency} ${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const label = (value: string) => value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())

export function ExpensesWorkspace() {
  const companyId = window.localStorage.getItem('simplebiz_company_id')
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const summaryQuery = useQuery({ queryKey: ['simplebiz', 'expenses-summary', companyId], queryFn: () => apiFetch<ApiEnvelope<Summary>>('/expenses/summary'), enabled: Boolean(companyId) })
  const lookupsQuery = useQuery({ queryKey: ['simplebiz', 'expenses-lookups', companyId], queryFn: () => apiFetch<ApiEnvelope<Lookups>>('/expenses/lookups'), enabled: Boolean(companyId) })
  const historyQuery = useQuery({ queryKey: ['simplebiz', 'expenses-history', companyId], queryFn: () => apiFetch<ApiEnvelope<Expense[]>>('/expenses/history?per_page=100'), enabled: Boolean(companyId) })
  const unpaidQuery = useQuery({ queryKey: ['simplebiz', 'expenses-unpaid', companyId], queryFn: () => apiFetch<ApiEnvelope<Expense[]>>('/expenses/unpaid?per_page=100'), enabled: Boolean(companyId) })
  const attentionQuery = useQuery({ queryKey: ['simplebiz', 'expenses-attention', companyId], queryFn: () => apiFetch<ApiEnvelope<{ items: AttentionItem[] }>>('/expenses/attention'), enabled: Boolean(companyId) })

  const actionMutation = useMutation({
    mutationFn: async ({ expense, action }: { expense: Expense; action: 'submit' | 'post' | 'cancel' }) => apiFetch<ApiEnvelope<Expense>>(`/expenses/${expense.id}/${action}`, { method: 'POST', body: JSON.stringify({ version: expense.version, ...(action === 'cancel' ? { reason: 'Cancelled from Expenses workspace.' } : {}) }) }),
    onSuccess: (_, variables) => { setNotice(`${variables.expense.expense_number} ${variables.action} action completed.`); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expenses'] }) },
    onError: (caught) => setError(caught instanceof Error ? caught.message : 'The Expense action could not be completed.'),
  })

  const history = (historyQuery.data?.data ?? []).filter((item) => !search || `${item.expense_number} ${item.description} ${item.payee_name ?? ''}`.toLowerCase().includes(search.toLowerCase()))
  const unpaid = unpaidQuery.data?.data ?? []
  const attention = attentionQuery.data?.data.items ?? []
  const summary = summaryQuery.data?.data
  const chart = summary?.period_totals?.map((item) => ({ name: item.currency ?? 'Currency', amount: Number(item.amount) })) ?? []
  const lookups = lookupsQuery.data?.data

  const columns = useMemo<ColumnDef<Expense, unknown>[]>(() => [
    { accessorKey: 'expense_number', header: 'Record', cell: ({ row }) => <span className="font-semibold text-slate-900">{row.original.expense_number}</span> },
    { accessorKey: 'business_date', header: 'Date' },
    { accessorKey: 'payee_name', header: 'Payee', cell: ({ row }) => row.original.payee_name || <span className="text-slate-400">Unassigned</span> },
    { accessorKey: 'description', header: 'Description' },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => <span className="font-semibold">{money(row.original.total, row.original.currency?.code)}</span> },
    { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusPill value={row.original.status} /> },
    { id: 'actions', header: 'Actions', cell: ({ row }) => <div className="flex flex-wrap gap-2">{['draft', 'returned'].includes(row.original.status) && <button type="button" className="sb-expense-table-action" onClick={() => actionMutation.mutate({ expense: row.original, action: 'submit' })}>Submit</button>}{row.original.status === 'approved' && <button type="button" className="sb-expense-table-action" onClick={() => actionMutation.mutate({ expense: row.original, action: 'post' })}>Post</button>}</div> },
  ], [actionMutation])

  return <main className="sb-expenses-workspace" aria-labelledby="expenses-title">
    <header className="sb-expenses-header">
      <div className="sb-expenses-title-wrap"><span className="sb-expenses-title-icon"><Receipt size={29} /></span><div><h1 id="expenses-title">Expenses Management</h1><p>Record, classify, evidence, approve, and monitor business expenses.</p></div></div>
      <button type="button" className="sb-expense-primary" onClick={() => { setError(''); setNotice(''); setFormOpen(true) }}><Plus size={17} /> Record expense</button>
    </header>

    {(notice || error) && <div className={`sb-expense-notice ${error ? 'is-error' : ''}`} role={error ? 'alert' : 'status'}>{error || notice}<button type="button" onClick={() => { setError(''); setNotice('') }} aria-label="Dismiss message"><X size={15} /></button></div>}

    <section className="sb-expense-summary-grid" aria-label="Expense summary">
      <SummaryCard icon={CircleDollarSign} title="Expenses this period" value={String(summary?.expenses_this_period ?? 0)} detail={summary?.period_totals?.map((item) => `${item.currency ?? ''} ${Number(item.amount).toLocaleString()}`).join(' · ') || 'No posted expenses'} tone="blue" />
      <SummaryCard icon={WalletCards} title="Unpaid obligations" value={String(unpaid.length)} detail={summary?.unpaid_by_currency?.map((item) => `${item.currency ?? ''} ${Number(item.amount).toLocaleString()}`).join(' · ') || 'Nothing unpaid'} tone="mint" />
      <SummaryCard icon={ListChecks} title="Payment ready" value={String(summary?.payment_ready ?? 0)} detail="Expense obligations ready for MDS-500" tone="yellow" />
      <SummaryCard icon={AlertTriangle} title="Needs attention" value={String((summary?.awaiting_approval ?? 0) + (summary?.missing_evidence ?? 0) + (summary?.overdue ?? 0))} detail={`${summary?.awaiting_approval ?? 0} approval · ${summary?.missing_evidence ?? 0} evidence · ${summary?.overdue ?? 0} overdue`} tone="peach" />
    </section>

    <section className="sb-expense-primary-grid">
      <Panel title="Expense activity" icon={Receipt}><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><label className="sb-expense-search"><Search size={16} /><span className="sr-only">Search expenses</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search expense number, payee, or description" /></label><span className="text-xs font-semibold text-slate-500">{history.length} record{history.length === 1 ? '' : 's'}</span></div><DataTable data={history} columns={columns} caption="Expense history" empty={<EmptyState title="No expenses recorded yet" detail="Create the first expense record to start the governed workflow." />} /></Panel>
      <Panel title="Period totals" icon={CircleDollarSign}><div className="h-52 w-full">{chart.length ? <ResponsiveContainer width="100%" height="100%"><BarChart data={chart} layout="vertical" margin={{ left: 8, right: 18 }}><XAxis type="number" hide /><YAxis type="category" dataKey="name" width={52} tick={{ fontSize: 11 }} /><Tooltip formatter={(value) => money(Number(value))} /><Bar dataKey="amount" fill="#168fc6" radius={[0, 5, 5, 0]} /></BarChart></ResponsiveContainer> : <EmptyState title="No posted totals" detail="Posted expenses will appear here by currency." />}</div></Panel>
    </section>

    <section className="sb-expense-secondary-grid"><Panel title="Needs attention" icon={AlertTriangle}><div className="space-y-2">{attention.length ? attention.slice(0, 6).map((item) => <div key={item.id} className="sb-expense-attention-row"><span className="sb-expense-attention-dot"><AlertTriangle size={14} /></span><div className="min-w-0 flex-1"><p className="truncate font-semibold text-slate-800">{item.expense_number} · {item.description}</p><p className="text-xs text-slate-500">{item.evidence_status === 'missing' ? 'Evidence missing' : item.obligation?.due_status === 'overdue' ? 'Payment overdue' : label(item.status)}</p></div><ArrowRight size={15} className="text-slate-400" /></div>) : <EmptyState title="Nothing needs attention" detail="Approval, evidence, duplicate, and due-date exceptions appear here." />}</div></Panel><Panel title="Unpaid expenses" icon={WalletCards}><DataTable data={unpaid.slice(0, 6)} columns={unpaidColumns} caption="Unpaid expense obligations" empty={<EmptyState title="No unpaid obligations" detail="Posted Pay Later expenses will be ready for MDS-500 here." />} /></Panel></section>

    <section className="sb-expense-footer-grid"><Panel title="Evidence and control status" icon={FileText}><div className="grid gap-3 sm:grid-cols-3"><ControlStat title="Missing evidence" value={String(summary?.missing_evidence ?? 0)} /><ControlStat title="Awaiting approval" value={String(summary?.awaiting_approval ?? 0)} /><ControlStat title="Overdue" value={String(summary?.overdue ?? 0)} /></div><p className="mt-4 text-xs text-slate-500">Expense classification and evidence remain owned by MDS-800. Payment execution and allocation remain owned by MDS-500; cash movement remains owned by MDS-700.</p></Panel><Panel title="Quick actions" icon={CheckCircle2}><div className="grid gap-2 sm:grid-cols-2"><button type="button" className="sb-expense-secondary-action" onClick={() => setFormOpen(true)}><Plus size={16} /> Record expense</button><button type="button" className="sb-expense-secondary-action" onClick={() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })}><Upload size={16} /> Review evidence queue</button></div></Panel></section>

    <ExpenseCompletionPanel history={history} lookups={lookups} onNotice={setNotice} onError={setError} />

    {formOpen && <ExpenseForm lookups={lookups} onClose={() => setFormOpen(false)} onSaved={() => { setFormOpen(false); setNotice('Expense draft created. Submit it from the history table when ready.'); void queryClient.invalidateQueries({ queryKey: ['simplebiz', 'expenses'] }) }} onError={setError} />}
  </main>
}

const unpaidColumns: ColumnDef<Expense, unknown>[] = [
  { accessorKey: 'expense_number', header: 'Record' },
  { accessorKey: 'payee_name', header: 'Payee' },
  { accessorKey: 'remaining_amount', header: 'Remaining', cell: ({ row }) => <span className="font-semibold">{money(row.original.remaining_amount, row.original.currency?.code)}</span> },
  { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusPill value={row.original.payment_status} /> },
]

function SummaryCard({ icon: Icon, title, value, detail, tone }: { icon: typeof CircleDollarSign; title: string; value: string; detail: string; tone: string }) { return <article className={`sb-expense-summary-card tone-${tone}`}><span className="sb-expense-summary-icon"><Icon size={22} /></span><div><p>{title}</p><strong>{value}</strong><small>{detail}</small></div></article> }
function Panel({ title, icon: Icon, children }: { title: string; icon: typeof Receipt; children: ReactNode }) { return <section className="sb-expense-panel"><div className="sb-expense-panel-heading"><h2><Icon size={17} />{title}</h2><span aria-hidden="true">⌃</span></div>{children}</section> }
function StatusPill({ value }: { value: string }) { return <span className={`sb-expense-status status-${value}`}>{label(value)}</span> }
function ControlStat({ title, value }: { title: string; value: string }) { return <div className="sb-expense-control-stat"><span>{value}</span><p>{title}</p></div> }
function EmptyState({ title, detail }: { title: string; detail: string }) { return <div className="sb-expense-empty"><FileText size={24} /><p>{title}</p><small>{detail}</small></div> }

function ExpenseForm({ lookups, onClose, onSaved, onError }: { lookups?: Lookups; onClose: () => void; onSaved: () => void; onError: (message: string) => void }) {
  const [date, setDate] = useState(today())
  const [description, setDescription] = useState('')
  const [payeeId, setPayeeId] = useState('')
  const [currencyId, setCurrencyId] = useState(lookups?.currencies?.[0]?.id ?? '')
  const [settlement, setSettlement] = useState<'paid_now' | 'pay_later' | 'reimbursement'>('pay_later')
  const [categoryId, setCategoryId] = useState(lookups?.categories?.[0]?.id ?? '')
  const [accountId, setAccountId] = useState('')
  const [lineDescription, setLineDescription] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [unitAmount, setUnitAmount] = useState('0')
  const [taxCodeId, setTaxCodeId] = useState('')
  const [evidenceFile, setEvidenceFile] = useState<File | null>(null)
  const [approvalRequired, setApprovalRequired] = useState(false)
  const [evidenceRequired, setEvidenceRequired] = useState(false)
  const [saving, setSaving] = useState(false)
  const selectedCategory = lookups?.categories?.find((item) => item.id === categoryId)
  const selectedCurrency = lookups?.currencies?.find((item) => item.id === currencyId)
  const total = Number(quantity || 0) * Number(unitAmount || 0)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); onError('')
    try {
      const created = await apiFetch<ApiEnvelope<Expense>>('/expenses', { method: 'POST', headers: { 'Idempotency-Key': `expense-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ business_date: date, description, payee_id: payeeId || null, currency_id: currencyId, settlement_intent: settlement, approval_required: approvalRequired, evidence_required: evidenceRequired, lines: [{ expense_category_id: categoryId, expense_account_title_id: accountId || selectedCategory?.account_title_id || null, description: lineDescription || description, quantity, unit_amount: unitAmount, tax_code_id: taxCodeId || null }] }) })
      if (evidenceFile) { const form = new FormData(); form.append('file', evidenceFile); await apiFetch(`/expenses/${created.data.id}/evidence`, { method: 'POST', body: form }) }
      onSaved()
    } catch (caught) { onError(caught instanceof Error ? caught.message : 'The Expense draft could not be saved.') } finally { setSaving(false) }
  }

  return <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/45 p-4" role="dialog" aria-modal="true" aria-labelledby="expense-form-title"><form onSubmit={submit} className="w-full max-w-3xl rounded-2xl border-2 border-[#168fc6] bg-white p-5 shadow-2xl sm:p-7"><div className="mb-6 flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-[0.16em] text-[#168fc6]">MDS-800 · Expense Record</p><h2 id="expense-form-title" className="mt-1 text-2xl font-bold text-slate-950">Record expense</h2><p className="mt-1 text-sm text-slate-500">Totals are calculated by the server from the classified line.</p></div><button type="button" onClick={onClose} className="rounded-full p-2 text-slate-500 hover:bg-slate-100" aria-label="Close"><X size={19} /></button></div><div className="grid gap-4 sm:grid-cols-2"><Field label="Business date"><input required type="date" value={date} onChange={(event) => setDate(event.target.value)} /></Field><Field label="Settlement intent"><select value={settlement} onChange={(event) => setSettlement(event.target.value as 'paid_now' | 'pay_later')}><option value="pay_later">Pay Later · create obligation</option><option value="paid_now">Paid Now · prepare payment</option></select></Field><Field label="Payee"><select value={payeeId} onChange={(event) => setPayeeId(event.target.value)}><option value="">Select payee</option>{lookups?.payees?.map((item) => <option key={item.id} value={item.id}>{item.display_name ?? item.name ?? item.code}</option>)}</select></Field><Field label="Currency"><select required value={currencyId} onChange={(event) => setCurrencyId(event.target.value)}>{lookups?.currencies?.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}</select></Field><Field label="Description" wide><textarea required rows={2} value={description} onChange={(event) => setDescription(event.target.value)} placeholder="What was this expense for?" /></Field><Field label="Expense category"><select required value={categoryId} onChange={(event) => setCategoryId(event.target.value)}><option value="">Select category</option>{lookups?.categories?.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}</select></Field><Field label="Posting expense account"><select value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">Use category mapping</option>{lookups?.accounts?.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}{item.locked ? ' · locked' : ''}</option>)}</select></Field><Field label="Line description"><input required value={lineDescription} onChange={(event) => setLineDescription(event.target.value)} placeholder="Line item" /></Field><Field label="Tax code"><select value={taxCodeId} onChange={(event) => setTaxCodeId(event.target.value)}><option value="">No tax</option>{lookups?.tax_codes?.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name} · {item.rate}%</option>)}</select></Field><Field label="Quantity"><input required min="0.000001" step="0.000001" type="number" value={quantity} onChange={(event) => setQuantity(event.target.value)} /></Field><Field label={`Unit amount${selectedCurrency?.symbol ? ` (${selectedCurrency.symbol})` : ''}`}><input required min="0" step="0.000001" type="number" value={unitAmount} onChange={(event) => setUnitAmount(event.target.value)} /></Field><Field label="Receipt or evidence" wide><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setEvidenceFile(event.target.files?.[0] ?? null)} /><small className="mt-1 block text-xs font-normal text-slate-500">Optional in the draft; the existing governed attachment service stores the file after the Expense is created.</small></Field></div><div className="mt-5 flex flex-wrap gap-4 rounded-lg bg-slate-50 p-4 text-sm"><label className="flex items-center gap-2 font-semibold"><input type="checkbox" checked={approvalRequired} onChange={(event) => setApprovalRequired(event.target.checked)} /> Require approval</label><label className="flex items-center gap-2 font-semibold"><input type="checkbox" checked={evidenceRequired} onChange={(event) => setEvidenceRequired(event.target.checked)} /> Require evidence</label><span className="ml-auto font-bold text-slate-900">Estimated total: {money(total, selectedCurrency?.code)}</span></div><p className="mt-3 text-xs text-slate-500"><CalendarDays size={13} className="mr-1 inline" />Evidence upload is company-scoped and hash-deduplicated.</p><div className="mt-7 flex justify-end gap-3"><button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button><button disabled={saving || !lookups?.categories?.length || !lookups?.currencies?.length} className="inline-flex items-center gap-2 rounded-lg bg-[#168fc6] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#147dae] disabled:opacity-50">{saving ? 'Saving…' : 'Save expense draft'}<ArrowRight size={16} /></button></div></form></div>
}

function Field({ label: fieldLabel, children, wide = false }: { label: string; children: ReactNode; wide?: boolean }) { return <label className={`text-sm font-semibold text-slate-700 ${wide ? 'sm:col-span-2' : ''}`}>{fieldLabel}{children}</label> }

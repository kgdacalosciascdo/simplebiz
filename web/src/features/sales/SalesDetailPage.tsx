import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, ClipboardList, FileText, PackageCheck, ReceiptText, RotateCcw, ShieldCheck } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import type { ColumnDef } from '@tanstack/react-table'
import { DataTable } from '../../components/DataTable'
import { Badge, Card, EmptyState, ErrorPanel, LoadingPanel, PageHeader } from '../../components/ui'
import { apiFetch } from '../../lib/api'

type Envelope<T> = { data: T }
type SaleLine = { id: string; description?: string; product_service_id?: string; product_service?: { name?: string; code?: string }; quantity: string; unit_price: string; discount_amount: string; tax_amount: string; net_amount: string }
type History = { id: string; from_status?: string | null; to_status: string; reason?: string | null; created_at: string }
type Related = { id: string; status: string; total_amount?: string; amount?: string; return_number?: string; adjustment_number?: string }
type Receipt = { id: string; receipt_number: string; status: string; amount: string; receipt_type: string }
type SaleDetail = { id: string; sale_number: string; sale_type: string; payment_basis: string; sale_date: string; due_date?: string | null; status: string; settlement_status: string; due_status: string; customer?: { display_name?: string; code?: string } | null; currency?: { code?: string; symbol?: string } | null; subtotal: string; line_discount_total: string; document_discount_total: string; tax_total: string; total: string; paid_amount: string; receivable_amount: string; remaining_amount: string; blocked_reason?: string | null; lines: SaleLine[]; inventory_movements?: { id: string; movement_type?: string; quantity?: string; status?: string }[]; returns?: Related[]; adjustments?: Related[]; receipts?: Receipt[]; receivable?: { id: string; original_amount: string; applied_amount: string; remaining_amount: string; settlement_status: string; due_date?: string | null } | null; history?: History[] }

const money = (sale: SaleDetail, value: string | undefined) => `${sale.currency?.symbol ?? sale.currency?.code ?? ''} ${Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim()
const tone = (status: string) => status === 'posted' || status === 'paid' ? 'success' : status === 'failed' || status === 'overdue' ? 'danger' : status === 'approved' ? 'info' : 'warning'

export function SalesDetailPage() {
  const { id } = useParams<{ id: string }>()
  const query = useQuery({ queryKey: ['simplebiz', 'sale', id], queryFn: () => apiFetch<Envelope<SaleDetail>>(`/sales/${id}`), enabled: Boolean(id) })
  if (query.isPending) return <LoadingPanel label="Loading Sale detail…" />
  if (query.isError || !query.data) return <ErrorPanel message={query.error instanceof Error ? query.error.message : 'Unable to load this Sale.'} onRetry={() => void query.refetch()} />
  const sale = query.data.data
  const lineColumns: ColumnDef<SaleLine>[] = [
    { header: 'Item', cell: ({ row }) => <span className="sb-table-primary"><strong>{row.original.product_service?.name ?? row.original.description ?? 'Sale line'}</strong><small>{row.original.product_service?.code ?? row.original.product_service_id ?? '—'}</small></span> },
    { header: 'Quantity', accessorKey: 'quantity' },
    { header: 'Unit price', cell: ({ row }) => money(sale, row.original.unit_price) },
    { header: 'Discount', cell: ({ row }) => money(sale, row.original.discount_amount) },
    { header: 'Total', cell: ({ row }) => <strong>{money(sale, row.original.net_amount)}</strong> },
  ]

  return <div className="space-y-4">
    <PageHeader icon={<FileText size={30} strokeWidth={1.7} />} title={sale.sale_number} subtitle="Sales detail, governed lifecycle, settlement, and related documents." action={<Link to="/sales" className="sb-button-secondary"><ArrowLeft size={15} /> Back to Sales</Link>} />
    <div className="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(280px,1fr)]">
      <Card><div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-3"><div><div className="flex flex-wrap items-center gap-2"><Badge tone={tone(sale.status)}>{sale.status}</Badge><Badge tone={tone(sale.settlement_status)}>{sale.settlement_status.replaceAll('_', ' ')}</Badge><span className="text-sm text-slate-500">{sale.sale_type.replaceAll('_', ' ')} · {sale.sale_date}</span></div><h2 className="mt-2 text-xl font-semibold text-slate-900">{sale.customer?.display_name ?? 'Unidentified customer'}</h2><p className="text-sm text-slate-500">{sale.customer?.code ?? 'No customer code'}{sale.due_date ? ` · Due ${sale.due_date}` : ' · Paid-now or cash settlement'}</p></div><div className="text-right"><span className="block text-xs uppercase tracking-wide text-slate-500">Sale total</span><strong className="text-2xl text-slate-900">{money(sale, sale.total)}</strong></div></div><div className="grid gap-3 py-4 sm:grid-cols-3"><Metric label="Paid" value={money(sale, sale.paid_amount)} /><Metric label="Receivable" value={money(sale, sale.receivable_amount)} /><Metric label="Remaining" value={money(sale, sale.remaining_amount)} /></div>{sale.blocked_reason && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-800">{sale.blocked_reason}</div>}</Card>
      <Card><SectionTitle icon={ShieldCheck} title="Lifecycle" /><div className="space-y-3">{sale.history?.length ? sale.history.map((item) => <div key={item.id} className="flex gap-3 text-sm"><CheckCircle2 className="mt-0.5 shrink-0 text-[#168fc6]" size={16} /><div><strong className="capitalize">{item.to_status.replaceAll('_', ' ')}</strong><p className="text-xs text-slate-500">{new Date(item.created_at).toLocaleString()}{item.reason ? ` · ${item.reason}` : ''}</p></div></div>) : <EmptyState title="No lifecycle history" detail="Status changes will appear here." />}</div></Card>
    </div>
    <Card><SectionTitle icon={ClipboardList} title="Sale lines" /><DataTable data={sale.lines ?? []} columns={lineColumns} caption="Sale line items" empty={<EmptyState title="No lines" detail="This Sale has no line items." />} /></Card>
    <div className="grid gap-4 lg:grid-cols-2">
      <Card><SectionTitle icon={ReceiptText} title="Receivable and collections" />{sale.receivable ? <div className="grid gap-3 sm:grid-cols-2"><Metric label="Original" value={money(sale, sale.receivable.original_amount)} /><Metric label="Applied" value={money(sale, sale.receivable.applied_amount)} /><Metric label="Remaining" value={money(sale, sale.receivable.remaining_amount)} /><Metric label="Due" value={sale.receivable.due_date ?? 'On receipt'} /><div className="sm:col-span-2"><Badge tone={tone(sale.receivable.settlement_status)}>{sale.receivable.settlement_status.replaceAll('_', ' ')}</Badge></div></div> : <EmptyState title="No receivable" detail="The commercial receivable has not been posted." />}{sale.receipts?.length ? <div className="mt-4 space-y-2 border-t border-slate-200 pt-3">{sale.receipts.map((receipt) => <Link key={receipt.id} to={`/collections/receipts/${receipt.id}/print`} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm transition hover:border-[#168fc6] hover:bg-[#eef9fd]"><span><strong>{receipt.receipt_number}</strong><small className="ml-2 text-slate-500">{receipt.receipt_type.replaceAll('_', ' ')}</small></span><span className="flex items-center gap-2"><Badge tone={tone(receipt.status)}>{receipt.status}</Badge>{money(sale, receipt.amount)}</span></Link>)}</div> : null}</Card>
      <Card><SectionTitle icon={PackageCheck} title="Inventory effects" />{sale.inventory_movements?.length ? <div className="space-y-2">{sale.inventory_movements.map((movement) => <div key={movement.id} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm"><span>{movement.movement_type ?? 'Stock movement'}</span><span className="text-slate-500">{movement.quantity ?? '—'} · {movement.status ?? 'recorded'}</span></div>)}</div> : <EmptyState title="No inventory movement" detail="This Sale has no stock-managed line effect." />}</Card>
    </div>
    <div className="grid gap-4 lg:grid-cols-2"><RelatedPanel title="Sales returns" icon={RotateCcw} items={sale.returns ?? []} numberKey="return_number" /><RelatedPanel title="Sales adjustments" icon={FileText} items={sale.adjustments ?? []} numberKey="adjustment_number" /></div>
  </div>
}

function Metric({ label, value }: { label: string; value: string }) { return <div><span className="block text-xs uppercase tracking-wide text-slate-500">{label}</span><strong className="mt-1 block text-base text-slate-900">{value}</strong></div> }
function SectionTitle({ icon: Icon, title }: { icon: typeof ClipboardList; title: string }) { return <div className="mb-3 flex items-center gap-2 border-b border-slate-200 pb-2 text-base font-semibold text-slate-900"><Icon size={17} className="text-[#168fc6]" />{title}</div> }
function RelatedPanel({ title, icon: Icon, items, numberKey }: { title: string; icon: typeof RotateCcw; items: Related[]; numberKey: 'return_number' | 'adjustment_number' }) { return <Card><SectionTitle icon={Icon} title={title} />{items.length ? <div className="space-y-2">{items.map((item) => <div key={item.id} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm"><strong>{item[numberKey] ?? item.id}</strong><span className="flex items-center gap-2"><Badge tone={tone(item.status)}>{item.status}</Badge>{item.total_amount ?? item.amount ?? ''}</span></div>)}</div> : <EmptyState title={`No ${title.toLowerCase()}`} detail="Related corrections will appear here when recorded." />}</Card> }

import { useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, Boxes, ChevronRight, ClipboardCheck, ClipboardList, History, PackageCheck, Search, ShieldCheck, SlidersHorizontal, TriangleAlert } from 'lucide-react'
import { DataTable } from '../../components/DataTable'
import { apiFetch } from '../../lib/api'
import { queryClient } from '../../lib/queryClient'

type Envelope<T> = { data: T; meta?: { pagination?: { total?: number } } }
type Product = { id: string; code: string; name: string; base_unit_id?: string }
type Warehouse = { id: string; code: string; name: string }
type Location = { id: string; warehouse_id: string; code: string; name: string }
type LookupData = { products: Product[]; warehouses: Warehouse[]; stock_locations: Location[]; reason_codes: { id: string; code: string; name: string }[] }
type Balance = { id: string; product?: { code: string; name: string }; warehouse?: { code: string; name: string }; stock_location?: { code: string; name: string }; unit?: { code: string }; on_hand: string; reserved: string; available: string; incoming: string; outgoing: string; status: string }
type Movement = { id: string; product?: { code: string; name: string }; movement_type: string; direction: string; quantity: string; unit_code: string; source_document_number?: string; business_date: string; status: string }
type Summary = { stock_on_hand: string; available: string; inventory_value: string | null; low_stock: number; out_of_stock?: number; count_variances: number; balance_count: number; recent_movements: Movement[] }
type FormKind = 'receipt' | 'issue' | 'transfer' | 'opening' | null
type CompletionKind = 'adjustment' | 'count' | 'reservation' | 'reorder' | null
type Attention = { type: string; severity: string; product_name?: string; available: string; reorder_point: string }
type Count = { id: string; count_number: string; status: string }
type Adjustment = { id: string; adjustment_number: string; status: string; business_date: string }
type Reservation = { id: string; reservation_number: string; product?: { name: string }; remaining_quantity: string; status: string }
type ReorderRule = { id: string; product?: { name: string }; reorder_point: string; status: string }
type InventoryFormData = { business_date: string; explanation: string; source_reference: string; product_service_id: string; warehouse_id: string; stock_location_id: string; quantity: string; source_warehouse_id: string; source_stock_location_id: string; destination_warehouse_id: string; destination_stock_location_id: string }

const emptyLine = { product_service_id: '', warehouse_id: '', stock_location_id: '', quantity: '1' }

export function InventoryWorkspace() {
  const [search, setSearch] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [locationId, setLocationId] = useState('')
  const [formKind, setFormKind] = useState<FormKind>(null)
  const [completionKind, setCompletionKind] = useState<CompletionKind>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const companyId = window.localStorage.getItem('simplebiz_company_id')
  const summaryQuery = useQuery({ queryKey: ['simplebiz', 'inventory-summary', companyId], queryFn: () => apiFetch<Envelope<Summary>>('/inventory/summary') })
  const lookupQuery = useQuery({ queryKey: ['simplebiz', 'inventory-lookups', companyId], queryFn: () => apiFetch<Envelope<LookupData>>('/inventory/lookups') })
  const balanceUrl = '/inventory/balances?per_page=100&q=' + encodeURIComponent(search) + '&warehouse_id=' + encodeURIComponent(warehouseId) + '&stock_location_id=' + encodeURIComponent(locationId)
  const balanceQuery = useQuery({ queryKey: ['simplebiz', 'inventory-balances', companyId, search, warehouseId, locationId], queryFn: () => apiFetch<Envelope<Balance[]>>(balanceUrl) })
  const movementQuery = useQuery({ queryKey: ['simplebiz', 'inventory-movements', companyId], queryFn: () => apiFetch<Envelope<Movement[]>>('/inventory/movements?per_page=12') })
  const attentionQuery = useQuery({ queryKey: ['simplebiz', 'inventory-attention', companyId], queryFn: () => apiFetch<Envelope<{ items: Attention[] }>>('/inventory/attention') })
  const adjustmentQuery = useQuery({ queryKey: ['simplebiz', 'inventory-adjustments', companyId], queryFn: () => apiFetch<Envelope<Adjustment[]>>('/inventory/adjustments?per_page=6') })
  const countQuery = useQuery({ queryKey: ['simplebiz', 'inventory-counts', companyId], queryFn: () => apiFetch<Envelope<Count[]>>('/inventory/counts?per_page=6') })
  const reservationQuery = useQuery({ queryKey: ['simplebiz', 'inventory-reservations', companyId], queryFn: () => apiFetch<Envelope<Reservation[]>>('/inventory/reservations?per_page=6') })
  const reorderQuery = useQuery({ queryKey: ['simplebiz', 'inventory-reorder-rules', companyId], queryFn: () => apiFetch<Envelope<ReorderRule[]>>('/inventory/reorder-rules?per_page=6') })
  const summary = summaryQuery.data?.data
  const lookups = lookupQuery.data?.data
  const balances = balanceQuery.data?.data ?? []
  const movements = movementQuery.data?.data ?? []
  const attention = Array.isArray(attentionQuery.data?.data) ? [] : (attentionQuery.data?.data?.items ?? [])
  const adjustments = Array.isArray(adjustmentQuery.data?.data) ? adjustmentQuery.data.data : []
  const counts = Array.isArray(countQuery.data?.data) ? countQuery.data.data : []
  const reservations = Array.isArray(reservationQuery.data?.data) ? reservationQuery.data.data : []
  const reorderRules = Array.isArray(reorderQuery.data?.data) ? reorderQuery.data.data : []
  const loading = summaryQuery.isLoading || lookupQuery.isLoading || balanceQuery.isLoading

  const columns = useMemo<ColumnDef<Balance, unknown>[]>(() => [
    { accessorKey: 'product', header: 'Product', cell: ({ row }) => <div><strong>{row.original.product?.code}</strong><small>{row.original.product?.name}</small></div> },
    { accessorKey: 'warehouse', header: 'Warehouse', cell: ({ row }) => (row.original.warehouse?.code ?? '—') + ' · ' + (row.original.stock_location?.code ?? '—') },
    { accessorKey: 'on_hand', header: 'On hand' },
    { accessorKey: 'reserved', header: 'Reserved' },
    { accessorKey: 'available', header: 'Available', cell: ({ row }) => <span className={Number(row.original.available) <= 0 ? 'sb-inventory-negative' : 'sb-inventory-positive'}>{row.original.available} {row.original.unit?.code}</span> },
    { accessorKey: 'status', header: 'Status', cell: ({ row }) => <span className="sb-inventory-status">{row.original.status}</span> },
  ], [])

  async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-summary', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-balances', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-movements', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-attention', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-counts', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-reservations', companyId] }),
      queryClient.invalidateQueries({ queryKey: ['simplebiz', 'inventory-reorder-rules', companyId] }),
    ])
  }

  async function submit(kind: Exclude<FormKind, null>, form: InventoryFormData) {
    setError('')
    setMessage('')
    const line = { product_service_id: form.product_service_id, warehouse_id: form.warehouse_id, stock_location_id: form.stock_location_id, quantity: Number(form.quantity) }
    const body = kind === 'transfer'
      ? { business_date: form.business_date, explanation: form.explanation, source_warehouse_id: form.source_warehouse_id, source_stock_location_id: form.source_stock_location_id, destination_warehouse_id: form.destination_warehouse_id, destination_stock_location_id: form.destination_stock_location_id, lines: [line] }
      : { business_date: form.business_date, explanation: form.explanation, source_reference: form.source_reference || undefined, lines: [line] }
    try {
      const endpoint = kind === 'receipt' ? '/inventory/receipts' : kind === 'issue' ? '/inventory/issues' : kind === 'transfer' ? '/inventory/transfers' : '/inventory/opening-stock'
      const created = await apiFetch<Envelope<{ id: string; document_number: string }>>(endpoint, { method: 'POST', body: JSON.stringify(body), headers: { 'Idempotency-Key': kind + '-' + Date.now() + '-' + crypto.randomUUID() } })
      const type = kind === 'receipt' ? 'receipts' : kind === 'issue' ? 'issues' : kind === 'transfer' ? 'transfers' : 'opening-stock'
      await apiFetch('/inventory/' + type + '/' + created.data.id + '/post', { method: 'POST', body: JSON.stringify({}), headers: { 'Idempotency-Key': kind + '-post-' + created.data.id } })
      setMessage(created.data.document_number + ' posted successfully.')
      setFormKind(null)
      await refresh()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Unable to post this Inventory document.')
    }
  }

  return <main className="sb-sales-reference sb-inventory-reference" aria-labelledby="inventory-title">
    <header className="sb-sales-header sb-inventory-header"><div className="sb-sales-title-icon" aria-hidden="true"><Boxes /></div><div><h1 id="inventory-title">Inventory Management</h1><p>Know current stock, receive and issue items, control movements, and complete physical counts.</p></div></header>
    <div className="sb-inventory-actions" aria-label="Inventory actions"><ActionButton icon={ArrowDownToLine} label="Receive Stock" onClick={() => setFormKind('receipt')} /><ActionButton icon={ArrowUpFromLine} label="Issue Stock" onClick={() => setFormKind('issue')} /><ActionButton icon={ArrowRightLeft} label="Transfer Stock" onClick={() => setFormKind('transfer')} /><ActionButton icon={ClipboardCheck} label="Opening Stock" onClick={() => setFormKind('opening')} /><ActionButton icon={SlidersHorizontal} label="Stock Adjustment" onClick={() => setCompletionKind('adjustment')} /><ActionButton icon={ClipboardList} label="Physical Count" onClick={() => setCompletionKind('count')} /><ActionButton icon={ShieldCheck} label="Reservations" onClick={() => setCompletionKind('reservation')} /><ActionButton icon={TriangleAlert} label="Reorder Controls" onClick={() => setCompletionKind('reorder')} /></div>
    {message && <div className="sb-inventory-message sb-inventory-success">{message}</div>}
    {error && <div className="sb-inventory-message sb-inventory-error">{error}</div>}
    <section className="sb-inventory-summary-grid" aria-label="Inventory summary"><SummaryCard icon={Boxes} label="Stock on Hand" value={summary?.stock_on_hand ?? '—'} /><SummaryCard icon={PackageCheck} label="Available" value={summary?.available ?? '—'} /><SummaryCard icon={TriangleAlert} label="Low Stock" value={summary ? String(summary.low_stock) : '—'} /><SummaryCard icon={ShieldCheck} label="Out of Stock" value={summary ? String(summary.out_of_stock ?? 0) : '—'} /><SummaryCard icon={ClipboardCheck} label="Count Variances" value={summary ? String(summary.count_variances) : '—'} /></section>
    <section className="sb-inventory-panel" aria-labelledby="inventory-position-title"><div className="sb-inventory-panel-heading"><div><h2 id="inventory-position-title"><Boxes size={18} />Inventory Position</h2><p>Server-derived stock by product and location.</p></div><span>{summary ? summary.balance_count + ' positions' : 'Loading…'}</span></div><div className="sb-inventory-filters"><label><Search size={16} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products…" aria-label="Search products" /></label><select value={warehouseId} onChange={(event) => { setWarehouseId(event.target.value); setLocationId('') }} aria-label="Filter warehouse"><option value="">All warehouses</option>{lookups?.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}</select><select value={locationId} onChange={(event) => setLocationId(event.target.value)} aria-label="Filter stock location"><option value="">All locations</option>{lookups?.stock_locations.filter((location) => !warehouseId || location.warehouse_id === warehouseId).map((location) => <option key={location.id} value={location.id}>{location.code} · {location.name}</option>)}</select></div>{loading ? <State text="Loading inventory position…" /> : balanceQuery.isError ? <State text="Inventory position could not be loaded. Try again." error /> : balances.length ? <DataTable data={balances} columns={columns} caption="Inventory position" /> : <State text="No posted stock positions yet. Receive or open stock to begin." />}</section>
    <section className="sb-inventory-panel sb-inventory-movement-panel" aria-labelledby="inventory-movements-title"><div className="sb-inventory-panel-heading"><div><h2 id="inventory-movements-title"><History size={18} />Recent Stock Movements</h2><p>Posted movements remain read-only and source-linked.</p></div><ChevronRight size={18} /></div>{movementQuery.isLoading ? <State text="Loading stock movements…" /> : movementQuery.isError ? <State text="Movement history could not be loaded." error /> : movements.length ? <DataTable data={movements} columns={[{ accessorKey: 'business_date', header: 'Date' }, { accessorKey: 'product', header: 'Product', cell: ({ row }) => row.original.product?.name ?? '—' }, { accessorKey: 'movement_type', header: 'Movement' }, { accessorKey: 'quantity', header: 'Quantity', cell: ({ row }) => (row.original.direction === 'out' ? '−' : '+') + row.original.quantity + ' ' + row.original.unit_code }, { accessorKey: 'source_document_number', header: 'Source' }]} caption="Recent stock movements" /> : <State text="No posted movements yet." />}</section>
    <section className="sb-inventory-completion-grid" aria-label="Inventory completion controls"><CompletionPanel icon={TriangleAlert} title="Needs Attention" action="Review attention" onAction={() => setCompletionKind('reorder')}><ListState items={attention.map((item) => `${item.type.replaceAll('_', ' ')} · ${item.product_name ?? 'Inventory item'} · ${item.available} available`)} empty="No low-stock or out-of-stock conditions." /></CompletionPanel><CompletionPanel icon={ClipboardCheck} title="Physical Counts" action="Plan count" onAction={() => setCompletionKind('count')}><ListState items={counts.map((count) => `${count.count_number} · ${count.status.replaceAll('_', ' ')}`)} empty="No physical counts planned." /></CompletionPanel><CompletionPanel icon={SlidersHorizontal} title="Adjustments" action="Open adjustments" onAction={() => setCompletionKind('adjustment')}><ListState items={adjustments.map((adjustment) => `${adjustment.adjustment_number} · ${adjustment.status}`)} empty="No adjustment documents." /></CompletionPanel><CompletionPanel icon={ShieldCheck} title="Reservations" action="View reservations" onAction={() => setCompletionKind('reservation')}><ListState items={reservations.map((reservation) => `${reservation.reservation_number} · ${reservation.status} · ${reservation.remaining_quantity} remaining`)} empty="Reservations are created from governed Sales sources." /></CompletionPanel><CompletionPanel icon={TriangleAlert} title="Reorder Rules" action="Manage rules" onAction={() => setCompletionKind('reorder')}><ListState items={reorderRules.map((rule) => `${rule.product?.name ?? 'Product'} · reorder at ${rule.reorder_point}`)} empty="No reorder controls configured." /></CompletionPanel></section>
    {formKind && lookups && <InventoryForm kind={formKind} lookups={lookups} onCancel={() => setFormKind(null)} onSubmit={submit} />}
    {completionKind && lookups && <CompletionModal kind={completionKind} lookups={lookups} onCancel={() => setCompletionKind(null)} onComplete={async () => { setCompletionKind(null); await refresh() }} />}
  </main>
}

function ActionButton({ icon: Icon, label, onClick }: { icon: typeof Boxes; label: string; onClick: () => void }) { return <button type="button" className="sb-inventory-action" onClick={onClick}><Icon size={19} /><span>{label}</span><ChevronRight size={16} /></button> }
function SummaryCard({ icon: Icon, label, value }: { icon: typeof Boxes; label: string; value: string }) { return <article className="sb-inventory-summary-card"><Icon size={25} /><span>{label}</span><strong>{value}</strong></article> }
function State({ text, error = false }: { text: string; error?: boolean }) { return <div className={'sb-inventory-state ' + (error ? 'sb-inventory-error-text' : '')}>{text}</div> }
function CompletionPanel({ icon: Icon, title, action, onAction, children }: { icon: typeof Boxes; title: string; action: string; onAction: () => void; children: ReactNode }) { return <article className="sb-inventory-completion-panel"><div className="sb-inventory-panel-heading"><h2><Icon size={17} />{title}</h2><button type="button" className="sb-inventory-panel-link" onClick={onAction}>{action}<ChevronRight size={14} /></button></div><div className="sb-inventory-completion-body">{children}</div></article> }
function ListState({ items, empty }: { items: string[]; empty: string }) { return items.length ? <ul className="sb-inventory-list">{items.slice(0, 5).map((item) => <li key={item}>{item}</li>)}</ul> : <p className="sb-inventory-empty">{empty}</p> }

function CompletionModal({ kind, lookups, onCancel, onComplete }: { kind: Exclude<CompletionKind, null>; lookups: LookupData; onCancel: () => void; onComplete: () => Promise<void> }) {
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const firstWarehouse = lookups.warehouses[0]
  const firstLocation = lookups.stock_locations.find((location) => location.warehouse_id === firstWarehouse?.id)
  const title = kind === 'count' ? 'Plan Physical Count' : kind === 'adjustment' ? 'Stock Adjustments' : kind === 'reservation' ? 'Stock Reservations' : 'Reorder Controls'
  async function createCount() {
    if (!firstWarehouse || !firstLocation) { setMessage('Create an active warehouse and stock location first.'); return }
    setBusy(true); setMessage('')
    try {
      const created = await apiFetch<Envelope<{ id: string }>>('/inventory/counts', { method: 'POST', body: JSON.stringify({ business_date: new Date().toISOString().slice(0, 10), mode: 'spot', warehouse_id: firstWarehouse.id, stock_location_id: firstLocation.id, all_products: true, explanation: 'Physical count planned from Inventory workspace.' }), headers: { 'Idempotency-Key': 'count-' + Date.now() + '-' + crypto.randomUUID() } })
      await apiFetch('/inventory/counts/' + created.data.id + '/start', { method: 'POST', body: JSON.stringify({}), headers: { 'Idempotency-Key': 'count-start-' + created.data.id } })
      await onComplete()
    } catch (caught) { setMessage(caught instanceof Error ? caught.message : 'Unable to create the Physical Count.') } finally { setBusy(false) }
  }
  return <div className="sb-inventory-modal-backdrop"><div className="sb-inventory-modal sb-inventory-completion-modal" role="dialog" aria-modal="true" aria-labelledby="completion-modal-title"><div className="sb-inventory-modal-heading"><div><h2 id="completion-modal-title">{title}</h2><p>{kind === 'count' ? 'Snapshot stock, enter counts, review variance, and post one linked adjustment.' : kind === 'adjustment' ? 'Adjustments require an active reason code, explanation, approval, and linked movement posting.' : kind === 'reservation' ? 'Reservations are source-linked and are created or consumed by governed Sales workflows.' : 'Reorder conditions are derived from authoritative available stock and configured reorder points.'}</p></div><button type="button" onClick={onCancel} aria-label="Close">×</button></div><div className="sb-inventory-completion-modal-body">{kind === 'count' ? <><div className="sb-inventory-modal-note"><ClipboardCheck size={23} /><span>This shortcut starts a spot count for the first active warehouse and location. Detailed product selection, recount, approval, and variance review remain available in the governed workflow.</span></div><button type="button" className="sb-inventory-primary" disabled={busy} onClick={() => void createCount()}>{busy ? 'Starting count…' : 'Start physical count'}</button></> : <><div className="sb-inventory-modal-note"><ShieldCheck size={23} /><span>This area is source-controlled. Use the linked Sales, Registry, or Inventory workflow to create the record; no free-floating financial or reservation data is generated here.</span></div><button type="button" className="sb-inventory-secondary" onClick={onCancel}>Close</button></>}{message && <p className="sb-inventory-error-text">{message}</p>}</div></div></div>
}

function InventoryForm({ kind, lookups, onCancel, onSubmit }: { kind: Exclude<FormKind, null>; lookups: LookupData; onCancel: () => void; onSubmit: (kind: Exclude<FormKind, null>, form: InventoryFormData) => Promise<void> }) {
  const [form, setForm] = useState<InventoryFormData>({ business_date: new Date().toISOString().slice(0, 10), explanation: '', source_reference: '', ...emptyLine, source_warehouse_id: '', source_stock_location_id: '', destination_warehouse_id: '', destination_stock_location_id: '' })
  const update = (key: keyof InventoryFormData, value: string) => setForm((current) => ({ ...current, [key]: value }))
  const locationsFor = (warehouseId: string) => lookups.stock_locations.filter((location) => location.warehouse_id === warehouseId)
  const isTransfer = kind === 'transfer'
  const label = kind === 'receipt' ? 'Receive Stock' : kind === 'issue' ? 'Issue Stock' : kind === 'opening' ? 'Opening Stock' : 'Transfer Stock'
  return <div className="sb-inventory-modal-backdrop"><form className="sb-inventory-modal" onSubmit={(event) => { event.preventDefault(); void onSubmit(kind, form) }} role="dialog" aria-modal="true" aria-labelledby="inventory-form-title"><div className="sb-inventory-modal-heading"><div><h2 id="inventory-form-title">{label}</h2><p>{isTransfer ? 'Move stock between two locations without changing total company quantity.' : 'Only a successful post changes the authoritative stock balance.'}</p></div><button type="button" onClick={onCancel} aria-label="Close">×</button></div><div className="sb-inventory-form-grid"><Field label="Business date"><input required type="date" value={form.business_date} onChange={(event) => update('business_date', event.target.value)} /></Field>{!isTransfer && <Field label="Product"><select required value={form.product_service_id} onChange={(event) => update('product_service_id', event.target.value)}><option value="">Select stock-managed product</option>{lookups.products.map((product) => <option key={product.id} value={product.id}>{product.code} · {product.name}</option>)}</select></Field>}{isTransfer ? <><Field label="Source warehouse"><select required value={form.source_warehouse_id} onChange={(event) => { update('source_warehouse_id', event.target.value); update('source_stock_location_id', '') }}><option value="">Select warehouse</option>{lookups.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}</select></Field><Field label="Source location"><select required value={form.source_stock_location_id} onChange={(event) => update('source_stock_location_id', event.target.value)}><option value="">Select location</option>{locationsFor(form.source_warehouse_id).map((location) => <option key={location.id} value={location.id}>{location.code} · {location.name}</option>)}</select></Field><Field label="Destination warehouse"><select required value={form.destination_warehouse_id} onChange={(event) => { update('destination_warehouse_id', event.target.value); update('destination_stock_location_id', '') }}><option value="">Select warehouse</option>{lookups.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}</select></Field><Field label="Destination location"><select required value={form.destination_stock_location_id} onChange={(event) => update('destination_stock_location_id', event.target.value)}><option value="">Select location</option>{locationsFor(form.destination_warehouse_id).map((location) => <option key={location.id} value={location.id}>{location.code} · {location.name}</option>)}</select></Field><Field label="Product"><select required value={form.product_service_id} onChange={(event) => update('product_service_id', event.target.value)}><option value="">Select stock-managed product</option>{lookups.products.map((product) => <option key={product.id} value={product.id}>{product.code} · {product.name}</option>)}</select></Field></> : <><Field label="Warehouse"><select required value={form.warehouse_id} onChange={(event) => { update('warehouse_id', event.target.value); update('stock_location_id', '') }}><option value="">Select warehouse</option>{lookups.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.code} · {warehouse.name}</option>)}</select></Field><Field label="Stock location"><select required value={form.stock_location_id} onChange={(event) => update('stock_location_id', event.target.value)}><option value="">Select location</option>{locationsFor(form.warehouse_id).map((location) => <option key={location.id} value={location.id}>{location.code} · {location.name}</option>)}</select></Field></>}{!isTransfer && <Field label="Reference (optional)"><input value={form.source_reference} onChange={(event) => update('source_reference', event.target.value)} /></Field>}<Field label="Quantity"><input required min="0.000001" step="any" type="number" value={form.quantity} onChange={(event) => update('quantity', event.target.value)} /></Field><Field label="Reason / explanation" full><textarea required rows={3} value={form.explanation} onChange={(event) => update('explanation', event.target.value)} placeholder="Explain the source and purpose of this stock effect." /></Field></div><div className="sb-inventory-modal-footer"><button type="button" className="sb-inventory-secondary" onClick={onCancel}>Cancel</button><button type="submit" className="sb-inventory-primary">{label}</button></div></form></div>
}

function Field({ label, children, full = false }: { label: string; children: ReactNode; full?: boolean }) { return <label className={'sb-inventory-field ' + (full ? 'sb-inventory-field-full' : '')}><span>{label}</span>{children}</label> }

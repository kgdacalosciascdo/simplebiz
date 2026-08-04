import { type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { ArrowRight, BarChart3, Boxes, CheckCircle2, CircleAlert, CircleDollarSign, ClipboardCheck, CreditCard, Database, FileChartColumn, FileClock, HandCoins, Landmark, LayoutDashboard, LockKeyhole, Receipt, ReceiptText, Settings2, ShoppingBag, ShoppingCart, UsersRound, WalletCards } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { apiFetch } from '../../lib/api'
import { demoModeEnabled, sourceLabel, type DataSource } from '../../demo/demoMode'
import { demoAccounts, demoAttention, demoMovements, demoSummary } from '../../demo/dashboardFixtures'
import { DataTable } from '../../components/DataTable'
import { Badge, Card, EmptyState, ErrorPanel, LoadingPanel, PageHeader } from '../../components/ui'

type DashboardSummary = typeof demoSummary
type DashboardAttention = typeof demoAttention
type Account = typeof demoAccounts[number]
type Movement = typeof demoMovements[number]
type Envelope<T> = { data: T }
type ApiAccount = { id: string; code: string; name: string; cash_account_type?: { name: string }; currency?: { code: string }; position?: { posted_balance: string } }
type ApiMovement = { id: string; document_number: string; business_date: string; direction: string; amount: string; currency?: { code: string }; cash_account?: { name: string }; purpose?: { name: string }; status: string }
type Props = { preview?: boolean }

const isEmpty = (value: unknown) => Array.isArray(value) ? value.length === 0 : value && typeof value === 'object' ? Object.keys(value).length === 0 : !value
const money = (value: number | string, currency = 'PHP') => new Intl.NumberFormat('en-PH', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value))
const Icon = ({ icon: IconComponent, size = 20 }: { icon: LucideIcon; size?: number }) => <IconComponent size={size} strokeWidth={1.8} aria-hidden="true" />

export function DashboardPage({ preview = false }: Props) {
  const companyId = typeof window === 'undefined' ? 'preview' : window.localStorage.getItem('simplebiz_company_id') ?? 'preview'
  const previewProvider = preview || (typeof window !== 'undefined' && window.location.pathname === '/preview')
  const explicitDemo = previewProvider || demoModeEnabled
  const summaryQuery = useQuery({ queryKey: ['simplebiz', 'dashboard-summary', companyId], queryFn: () => apiFetch<Envelope<DashboardSummary>>('/cash-accounts/summary') })
  const attentionQuery = useQuery({ queryKey: ['simplebiz', 'dashboard-attention', companyId], queryFn: () => apiFetch<Envelope<DashboardAttention>>('/cash-accounts/needs-attention') })
  const accountsQuery = useQuery({ queryKey: ['simplebiz', 'cash-accounts', companyId], queryFn: () => apiFetch<Envelope<ApiAccount[]>>('/cash-accounts?status=active&per_page=100') })
  const movementsQuery = useQuery({ queryKey: ['simplebiz', 'cash-movements', companyId], queryFn: () => apiFetch<Envelope<ApiMovement[]>>('/cash-accounts/movements?per_page=20') })
  const summary = useDemoValue(summaryQuery, demoSummary, explicitDemo, previewProvider) as DashboardSummary | null
  const attention = useDemoValue(attentionQuery, demoAttention, explicitDemo, previewProvider) as DashboardAttention | null
  const accounts = useDemoValue(accountsQuery, demoAccounts, explicitDemo, previewProvider, (items) => items.map(normalizeAccount)) as Account[] | null
  const movements = useDemoValue(movementsQuery, demoMovements, explicitDemo, previewProvider, (items) => items.map(normalizeMovement)) as Movement[] | null
  const source: DataSource = summary === demoSummary || attention === demoAttention || accounts === demoAccounts || movements === demoMovements ? 'demo' : 'live'
  const loading = source === 'live' && [summaryQuery, attentionQuery, accountsQuery, movementsQuery].some((query) => query.isPending)
  const hasError = !previewProvider && [summaryQuery, attentionQuery, accountsQuery, movementsQuery].some((query) => query.isError)
  const attentionCount = attention ? [attention.reconciliations_pending, attention.cash_count_variances, attention.accounts_without_custodian, attention.statement_imports_pending, attention.draft_accounts?.length, attention.opening_balances_pending?.length].reduce((total, count) => total + (count ?? 0), 0) : 0

  return <div className="sb-reference-dashboard">
    {hasError && <ErrorPanel message="Some live dashboard panels could not be loaded. Demo fixtures were not used." onRetry={() => window.location.reload()} />}
    <PageHeader icon={<LayoutDashboard size={31} strokeWidth={1.7} />} title="Dashboard" subtitle="Monitor your business, manage daily activities, and act on what needs attention." context={summary?.as_of ? `Cash position as of ${new Date(summary.as_of).toLocaleString()}` : 'Current company scope'} action={<div className="sb-reference-header-actions"><Badge tone={source === 'demo' ? 'warning' : 'success'}>{sourceLabel(source)}</Badge><Link to="/cash-accounts?mode=movement&kind=cash_in" className="sb-button"><Icon icon={CircleDollarSign} size={15} />Record cash activity</Link></div>} />
    <ActionTiles />
    <div className="sb-reference-grid sb-reference-grid-middle"><NeedsAttentionPanel attention={attention} count={attentionCount} loading={loading} /><DeferredPanel title="Profit & Loss Snapshot" icon={BarChart3} detail="Profit & Loss is owned by future Sales, Purchases, Expenses, and accounting source modules." actionLabel="View deferred scope" /><BusinessSnapshot summary={summary} accounts={accounts} source={source} /><ReportsPanel /></div>
    <div className="sb-reference-grid sb-reference-grid-lower"><RecentActivity movements={movements ?? []} empty={movementsQuery.isError && !explicitDemo} /><RecordsLedgers /><ActionCenter /></div>
  </div>
}

function useDemoValue<T, U = T>(query: { data?: Envelope<T>; isSuccess: boolean; isError: boolean }, fixture: U, explicitDemo: boolean, previewProvider: boolean, normalize?: (value: T) => U) { if (query.isSuccess && !isEmpty(query.data?.data)) return normalize ? normalize(query.data!.data) : query.data!.data as unknown as U; if (explicitDemo && !query.isError) return fixture; if (previewProvider) return fixture; return query.isSuccess ? fixture : null }
function normalizeAccount(account: ApiAccount): Account { return { id: account.id, code: account.code, name: account.name, type: account.cash_account_type?.name ?? 'Cash account', currency: account.currency?.code ?? 'PHP', balance: account.position?.posted_balance ?? '0' } }
function normalizeMovement(movement: ApiMovement): Movement { return { id: movement.id, document_number: movement.document_number, business_date: movement.business_date, direction: movement.direction === 'decrease' ? 'decrease' : 'increase', amount: movement.amount, currency: movement.currency?.code ?? 'PHP', account: movement.cash_account?.name ?? 'Cash account', purpose: movement.purpose?.name ?? 'Movement', status: movement.status } }

const actionTiles: { title: string; description: string; action: string; icon: LucideIcon; to?: string; active?: boolean; tone: string }[] = [
  { title: 'Sales', description: 'Prepare credit sales and monitor receivables.', action: 'Open workspace', icon: ShoppingBag, tone: 'yellow', to: '/sales', active: true },
  { title: 'Collections', description: 'Receive customer account payments.', action: 'Deferred', icon: HandCoins, tone: 'mint' },
  { title: 'Purchases', description: 'Record a cash or credit purchase.', action: 'Deferred', icon: ShoppingCart, tone: 'peach' },
  { title: 'Payments', description: 'Pay suppliers and other obligations.', action: 'Deferred', icon: CreditCard, tone: 'lilac' },
  { title: 'Inventory', description: 'Receive, issue, transfer, or adjust stock.', action: 'Deferred', icon: Boxes, tone: 'sand' },
  { title: 'Cash Accounts', description: 'Record cash in, cash out, or account transfers.', action: 'Open workspace', icon: WalletCards, tone: 'mint', to: '/cash-accounts', active: true },
  { title: 'Expenses', description: 'Record paid or unpaid business expenses.', action: 'Deferred', icon: Receipt, tone: 'rose' },
]

function ActionTiles() { return <section className="sb-reference-action-grid" aria-label="Business workspaces">{actionTiles.map((tile) => { const TileIcon = tile.icon; const content = <><span className="sb-reference-action-icon"><TileIcon size={51} strokeWidth={1.55} /></span><strong>{tile.title}</strong><p>{tile.description}</p><span className={`sb-reference-action-button ${tile.active ? 'sb-reference-action-button-active' : ''}`}>{tile.active && <CheckCircle2 size={13} aria-hidden="true" />}{tile.action}{tile.active && <ArrowRight size={13} aria-hidden="true" />}</span></>; return tile.to ? <Link key={tile.title} to={tile.to} className={`sb-reference-action-card sb-reference-tone-${tile.tone}`}>{content}</Link> : <article key={tile.title} className={`sb-reference-action-card sb-reference-tone-${tile.tone} sb-reference-action-card-deferred`}>{content}</article> })}</section> }

function PanelHeading({ title, icon, meta }: { title: string; icon: LucideIcon; meta?: ReactNode }) { return <div className="sb-reference-panel-heading"><h2><Icon icon={icon} size={17} />{title}</h2>{meta && <span>{meta}</span>}</div> }

function NeedsAttentionPanel({ attention, count, loading }: { attention: DashboardAttention | null; count: number; loading: boolean }) {
  if (!attention) return <Card className="sb-reference-panel"><PanelHeading title="Needs Attention" icon={CircleAlert} meta="Loading" /><LoadingPanel label="Loading attention items…" /></Card>
  const rows = [{ count: attention.reconciliations_pending ?? 0, title: 'Reconciliations awaiting review', detail: 'Review matching and outstanding items.', tone: 'warning' as const }, { count: attention.cash_count_variances ?? 0, title: 'Cash Count variances', detail: 'A governed disposition may be required.', tone: 'danger' as const }, { count: attention.accounts_without_custodian ?? 0, title: 'Accounts without a custodian', detail: 'Review custody assignments.', tone: 'info' as const }, { count: attention.statement_imports_pending ?? 0, title: 'Statement imports to validate', detail: 'Normalize statement lines before matching.', tone: 'warning' as const }, { count: attention.opening_balances_pending?.length ?? 0, title: 'Opening Balances pending', detail: 'Review evidence and approval status.', tone: 'neutral' as const }].filter((row) => row.count > 0)
  return <Card className="sb-reference-panel"><PanelHeading title="Needs Attention" icon={CircleAlert} meta={`${loading ? '…' : count} items`} /><div className="sb-reference-attention-list">{rows.length ? rows.map((row) => <div key={row.title}><Badge tone={row.tone}>{row.count}</Badge><span><strong>{row.title}</strong><small>{row.detail}</small></span><ArrowRight size={14} aria-hidden="true" /></div>) : <EmptyState title="Nothing needs attention" detail="The Cash Accounts attention feed has no outstanding items." />}</div><Link className="sb-reference-panel-link" to="/cash-accounts">View all <ArrowRight size={13} aria-hidden="true" /></Link></Card>
}

function DeferredPanel({ title, icon, detail, actionLabel }: { title: string; icon: LucideIcon; detail: string; actionLabel: string }) { return <Card className="sb-reference-panel sb-reference-deferred-panel"><PanelHeading title={title} icon={icon} meta="Deferred" /><EmptyState title="Not available yet" detail={detail} action={<span className="sb-reference-deferred-action"><LockKeyhole size={13} aria-hidden="true" />{actionLabel}</span>} /></Card> }

function BusinessSnapshot({ summary, accounts, source }: { summary: DashboardSummary | null; accounts: Account[] | null; source: DataSource }) { const topAccounts = (accounts ?? []).slice(0, 4); return <Card className="sb-reference-panel"><PanelHeading title="Business Snapshot" icon={Landmark} meta={sourceLabel(source)} /><div className="sb-reference-snapshot-total"><span>Total Cash Position</span><strong>{summary?.position_by_currency?.length === 1 ? money(summary.position_by_currency[0].posted_balance, summary.position_by_currency[0].currency_code) : 'Not available'}</strong><small>Authoritative posted position</small></div><div className="sb-reference-snapshot-list"><div><UsersRound size={16} aria-hidden="true" /><span>Active Cash Accounts</span><strong>{summary?.active_account_count ?? '—'}</strong></div><div><CircleAlert size={16} aria-hidden="true" /><span>Needs Attention</span><strong>{summary ? (summary.draft_account_count + summary.restricted_account_count) : '—'}</strong></div></div><div className="sb-reference-account-list">{topAccounts.map((account) => <Link key={account.id} to={`/cash-accounts?account=${account.id}`}><span><WalletCards size={14} aria-hidden="true" />{account.name}</span><strong>{money(account.balance, account.currency)}</strong></Link>)}</div></Card> }

function ReportsPanel() { const reports = ['Profit or Loss Report', 'Cash Flow Report', 'Receivables Report', 'Payables Report', 'Stock Availability Report', 'Due for Reorder Report']; return <Card className="sb-reference-panel"><PanelHeading title="Reports" icon={FileChartColumn} meta="Deferred" /><div className="sb-reference-report-list">{reports.map((report) => <div key={report}><FileClock size={15} aria-hidden="true" /><span>{report}</span><LockKeyhole size={12} aria-hidden="true" /></div>)}</div><span className="sb-reference-panel-link sb-reference-panel-link-disabled">Reports will unlock with their source modules</span></Card> }

function RecentActivity({ movements, empty }: { movements: Movement[]; empty: boolean }) {
  const columns: ColumnDef<Movement>[] = [{ header: 'Date', accessorKey: 'business_date' }, { header: 'Activity', accessorKey: 'purpose', cell: ({ row }) => <span className="sb-table-primary"><strong>{row.original.purpose}</strong><small>{row.original.document_number} · {row.original.account}</small></span> }, { header: 'Amount', accessorKey: 'amount', cell: ({ row }) => <span className={row.original.direction === 'decrease' ? 'sb-amount-out' : 'sb-amount-in'}>{row.original.direction === 'decrease' ? '−' : '+'}{money(row.original.amount, row.original.currency)}</span> }, { header: 'Status', accessorKey: 'status', cell: ({ row }) => <Badge tone={row.original.status === 'posted' ? 'success' : 'warning'}>{row.original.status}</Badge> }]
  return <Card className="sb-reference-panel sb-reference-table-panel"><PanelHeading title="Recent Activity" icon={ReceiptText} meta={<Link to="/cash-accounts?mode=history">View all <ArrowRight size={13} aria-hidden="true" /></Link>} />{empty || !movements.length ? <EmptyState title="No recent activity" detail="Posted Cash Account movements will appear here." /> : <DataTable data={movements.slice(0, 8)} columns={columns} caption="Recent Cash Account activity" />}</Card>
}

const records = [{ title: 'Cash Account History', icon: WalletCards, to: '/cash-accounts?mode=history', active: true }, { title: 'Opening Balances', icon: Landmark, to: '/cash-accounts?mode=opening', active: true }, { title: 'Cash Counts', icon: ClipboardCheck, to: '/cash-accounts?mode=counts', active: true }, { title: 'Statement History', icon: FileClock, to: '/cash-accounts?mode=statements', active: true }, { title: 'Reconciliations', icon: FileChartColumn, to: '/cash-accounts?mode=reconciliations', active: true }, { title: 'Customer Ledger', icon: UsersRound }, { title: 'Payment History', icon: CreditCard }, { title: 'Expense History', icon: Receipt }]
function RecordsLedgers() { return <Card className="sb-reference-panel"><PanelHeading title="Records & Ledgers" icon={Database} meta="Cash Accounts available" /><div className="sb-reference-record-grid">{records.map((record) => { const RecordIcon = record.icon; return record.active ? <Link key={record.title} to={record.to!}><RecordIcon size={26} aria-hidden="true" /><span>{record.title}</span></Link> : <div key={record.title} className="sb-reference-record-deferred"><RecordIcon size={26} aria-hidden="true" /><span>{record.title}</span><Badge>Deferred</Badge></div> })}</div></Card> }

const centerActions = [{ title: 'Record Cash Activity', icon: CircleDollarSign, to: '/cash-accounts?mode=movement&kind=cash_in', tone: 'mint' }, { title: 'Prepare Opening Balance', icon: Landmark, to: '/cash-accounts?opening=new', tone: 'yellow' }, { title: 'Cash Count', icon: ClipboardCheck, to: '/cash-accounts?mode=counts', tone: 'lilac' }, { title: 'Cash Reconciliation', icon: FileChartColumn, to: '/cash-accounts?mode=reconciliations', tone: 'mint' }, { title: 'Import Statement', icon: FileClock, to: '/cash-accounts?mode=statement-import', tone: 'sand' }, { title: 'Cash Handover', icon: HandCoins, to: '/cash-accounts?mode=handovers', tone: 'lilac' }, { title: 'Open Cash Accounts', icon: WalletCards, to: '/cash-accounts', tone: 'yellow' }, { title: 'Master Registries', icon: Database, to: '/master-registries', tone: 'mint' }]
function ActionCenter() { return <Card className="sb-reference-panel"><PanelHeading title="Action Center" icon={Settings2} meta="Available actions" /><div className="sb-reference-center-grid">{centerActions.map((action) => <Link key={action.title} to={action.to} className={`sb-reference-center-action sb-reference-tone-${action.tone}`}><Icon icon={action.icon} size={15} />{action.title}<ArrowRight className="ml-auto" size={13} aria-hidden="true" /></Link>)}</div></Card> }

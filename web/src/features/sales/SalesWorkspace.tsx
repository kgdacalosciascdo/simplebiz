import { useMemo, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import {
  ArrowRight,
  BarChart3,
  BookOpen,
  BriefcaseBusiness,
  Calculator,
  ChevronUp,
  Clock3,
  Contact,
  FilePlus2,
  FileText,
  HandCoins,
  List,
  ReceiptText,
  RotateCcw,
  ShoppingCart,
  TriangleAlert,
  UserRound,
  Vault,
  type LucideIcon,
} from 'lucide-react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'
import { apiFetch } from '../../lib/api'
import { demoModeEnabled } from '../../demo/demoMode'
import { SalesCompletionPanel } from './SalesCompletionPanel'
import { SalesPaidNowPanel } from './SalesPaidNowPanel'

type Activity = {
  date: string
  activity: string
  amount: string
  user: string
}

type SalesDashboard = { metrics: { currency?: { symbol?: string; code?: string }; net_sales: string; sales_amount: string; open_receivable_amount: string; overdue_amount: string }[]; recent_activity: { id: string; occurred_at?: string; sale_number?: string; to_status: string; reason?: string | null }[] }
type SalesAttention = { items: { id: string; title: string; detail: string; amount?: string; currency?: string; severity: string; route?: string }[]; total: number }

type ActionCard = {
  title: string
  description: string
  button: string
  icon: LucideIcon
  tone: string
  to?: string
}

const actionCards: ActionCard[] = [
  { title: 'Cash Sales', description: 'Create a cash sale.', button: 'Paid-now queue', icon: Calculator, tone: 'yellow', to: '/sales#paid-now' },
  { title: 'Credit Sales', description: 'Create a credit sale.', button: 'New Sale', icon: Calculator, tone: 'blue' },
  { title: 'Collections', description: 'Receive money as payment of customer account receivables.', button: 'Receive Payment', icon: HandCoins, tone: 'mint', to: '/collections' },
  { title: 'Customer', description: 'Create a new customer record', button: 'New Customer', icon: Contact, tone: 'peach', to: '/customers' },
  { title: 'Cash Remittance', description: 'Record cash remittance', button: 'Record Remittance', icon: Vault, tone: 'lilac' },
]

const attentionItems = [
  { count: 5, title: 'Overdue customer balances', detail: '₱18,750 requires follow-up', tone: 'red' },
  { count: 1, title: 'Cash remittance shortages / overages', detail: '₱500 cash shortage discovered', tone: 'red' },
  { count: 1, title: 'Sales return', detail: '₱1,000 worth of item is returned', tone: 'amber' },
  { count: 7, title: 'Voided sales', detail: '₱300 worth of sales transaction is voided', tone: 'slate' },
  { count: 3, title: 'Due in the next 7 days', detail: '₱50,580 due for collection in the next 7 days', tone: 'slate' },
]

const snapshotData = [
  { name: 'Sales This Month', value: 493583, label: '₱493,583', color: '#2e86d7' },
  { name: 'Accounts Receivables', value: 176200, label: '₱176,200', color: '#e64943' },
  { name: 'Overdue', value: 84300, label: '₱84,300', color: '#f28a24' },
  { name: 'Collected This Month', value: 312900, label: '₱312,900', color: '#49ae68' },
]

const activities: Activity[] = [
  { date: '29 July 2026', activity: 'Sales return recorded', amount: '₱1,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Sale voided', amount: '₱300', user: 'KVL' },
  { date: '29 July 2026', activity: 'Cash sale recorded', amount: '₱5,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Cash sale recorded', amount: '₱10,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Credit sale recorded', amount: '₱12,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Payment received for Invoice 1234', amount: '₱25,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Cash sale recorded', amount: '₱3,000', user: 'CAL' },
]

const records = [
  { label: 'Sales History', icon: Calculator },
  { label: 'Collection History', icon: HandCoins },
  { label: 'Remittance History', icon: Vault },
  { label: 'Customer Ledger', icon: BookOpen },
  { label: 'Cash Short/Over', icon: BriefcaseBusiness },
  { label: 'Overdue Accounts', icon: Clock3 },
]

const reportColumns = [
  [
    { label: 'Daily Sales Report', icon: BarChart3 },
    { label: 'Sales Summary Report', icon: BarChart3 },
    { label: 'Sales by Product / Service', icon: ShoppingCart },
    { label: 'Sales by Customer', icon: UserRound },
    { label: 'Sales Returns & Discounts', icon: ReceiptText },
    { label: 'Sales Trend', icon: ShoppingCart },
  ],
  [
    { label: 'Receivables Summary', icon: BarChart3 },
    { label: 'Receivables Aging', icon: BarChart3 },
    { label: 'Customer Balances', icon: ReceiptText },
    { label: 'Unpaid & Partially Paid Sales', icon: ShoppingCart },
    { label: 'Overdue Receivables', icon: ReceiptText },
    { label: 'Receivables Movement', icon: ReceiptText },
  ],
]

const centerActions = [
  { label: 'Create Billing Statement', icon: FileText, tone: 'mint', to: '/billing-statements' },
  { label: 'Create Credit Adjustment', icon: FilePlus2, tone: 'blue' },
  { label: 'Create Debit Adjustment', icon: FilePlus2, tone: 'peach' },
  { label: 'Record Sales Return', icon: RotateCcw, tone: 'yellow' },
]

export function SalesWorkspace() {
  const dashboardQuery = useQuery({ queryKey: ['simplebiz', 'sales', 'dashboard'], queryFn: () => apiFetch<{ data: SalesDashboard }>('/sales/dashboard') })
  const attentionQuery = useQuery({ queryKey: ['simplebiz', 'sales', 'attention'], queryFn: () => apiFetch<{ data: SalesAttention }>('/sales/attention') })
  const liveDashboard = dashboardQuery.data?.data
  const liveAttention = attentionQuery.data?.data
  const liveCurrency = liveDashboard?.metrics[0]?.currency
  const liveSnapshotData = liveDashboard?.metrics.length ? [{ name: 'Sales This Month', value: Number(liveDashboard.metrics[0].net_sales), label: `${liveCurrency?.symbol ?? liveCurrency?.code ?? ''} ${Number(liveDashboard.metrics[0].net_sales).toLocaleString()}`, color: '#2e86d7' }, { name: 'Accounts Receivables', value: Number(liveDashboard.metrics[0].open_receivable_amount), label: `${liveCurrency?.symbol ?? liveCurrency?.code ?? ''} ${Number(liveDashboard.metrics[0].open_receivable_amount).toLocaleString()}`, color: '#e64943' }, { name: 'Overdue', value: Number(liveDashboard.metrics[0].overdue_amount), label: `${liveCurrency?.symbol ?? liveCurrency?.code ?? ''} ${Number(liveDashboard.metrics[0].overdue_amount).toLocaleString()}`, color: '#f28a24' }] : demoModeEnabled ? snapshotData : []
  const liveAttentionItems: { count: number; title: string; detail: string; tone: string; route?: string }[] = liveAttention ? liveAttention.items.map((item) => ({ count: 1, title: item.title, detail: `${item.detail}${item.amount ? ` · ${item.currency ?? ''} ${Number(item.amount).toLocaleString()}` : ''}`, tone: item.severity === 'high' ? 'red' : 'amber', route: item.route })) : demoModeEnabled ? attentionItems : []
  const liveActivities = liveDashboard?.recent_activity.length ? liveDashboard.recent_activity.map((item) => ({ date: item.occurred_at ? new Date(item.occurred_at).toLocaleDateString() : '—', activity: `${item.sale_number ?? 'Sale'} ${item.to_status.replaceAll('_', ' ')}`, amount: '—', user: item.reason ?? 'MDS-200' })) : demoModeEnabled ? activities : []
  const columns = useMemo<ColumnDef<Activity, unknown>[]>(() => [
    { accessorKey: 'date', header: 'Date' },
    { accessorKey: 'activity', header: 'Activity' },
    { accessorKey: 'amount', header: 'Amount' },
    { accessorKey: 'user', header: 'User' },
  ], [])

  return <main className="sb-sales-reference" aria-labelledby="sales-management-title">
    <header className="sb-sales-header">
      <div className="sb-sales-title-icon" aria-hidden="true"><Calculator /></div>
      <div>
        <h1 id="sales-management-title">Sales Management</h1>
        <p>Create sales, collect payments, follow up what&apos;s due, and manage customers.</p>
      </div>
    </header>

    <section className="sb-sales-action-grid" aria-label="Sales shortcuts">
      {actionCards.map((card) => <SalesActionCard key={card.title} {...card} />)}
    </section>

    <SalesCompletionPanel />
    <SalesPaidNowPanel />

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-middle">
      <SalesPanel title="Needs Attention" icon={TriangleAlert} meta={`${liveAttention?.total ?? (demoModeEnabled ? 24 : 0)} items`} className="sb-sales-span-two">
        <div className="sb-sales-attention-list">
          {liveAttentionItems.length ? liveAttentionItems.map((item, index) => { const row = <><span className={`sb-sales-count sb-sales-count-${item.tone}`}>{item.count}</span><span><strong>{item.title}</strong><small>{item.detail}</small></span><ArrowRight aria-hidden="true" /></>; return item.route ? <Link to={item.route} className="sb-sales-attention-row" key={`${item.title}-${index}`}>{row}</Link> : <button type="button" className="sb-sales-attention-row" key={`${item.title}-${index}`}>{row}</button> }) : <p className="sb-sales-completion-empty">No Sales or receivables items need attention.</p>}
        </div>
        <PanelLink>View all</PanelLink>
      </SalesPanel>

      <SalesPanel title="Sales & Receivables Snapshot" icon={List} className="sb-sales-span-two">
        <div className="sb-sales-chart" aria-label="Sales and receivables bar chart">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={liveSnapshotData} layout="vertical" margin={{ top: 5, right: 64, bottom: 5, left: 5 }}>
              <XAxis type="number" domain={[0, 550000]} hide />
              <YAxis type="category" dataKey="name" width={142} axisLine={false} tickLine={false} tick={{ fill: '#526678', fontSize: 11 }} />
              <Bar dataKey="value" barSize={21} radius={[0, 2, 2, 0]}>
                {liveSnapshotData.map((entry) => <Cell key={entry.name} fill={entry.color} />)}
                <LabelList dataKey="label" position="right" fill="#526678" fontSize={10} />
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
        <div className="sb-sales-total-row">
          <BarChart3 aria-hidden="true" />
          <span>Total Sales This Month<strong>{liveSnapshotData.length ? liveSnapshotData[0].label : 'Not available'}</strong></span>
          <small>12.6% increase vs last month</small>
          <PanelLink>View report</PanelLink>
        </div>
      </SalesPanel>

      <SalesPanel title="Records & Ledgers" icon={List}>
        <div className="sb-sales-record-grid">
          {records.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-record-item">
            <Icon aria-hidden="true" />
            <span>{label}</span>
          </button>)}
        </div>
      </SalesPanel>
    </section>

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-lower">
      <SalesPanel title="Recent Activity" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-activity-table"><DataTable data={liveActivities} columns={columns} caption="Recent Sales activity" empty={<p className="sb-sales-completion-empty">No Sales activity has been recorded.</p>} /></div>
      </SalesPanel>

      <SalesPanel title="Reports" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-report-grid">
          {reportColumns.map((column, index) => <div key={index}>
            {column.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-report-link">
              <Icon aria-hidden="true" /><span>{label}</span>
            </button>)}
          </div>)}
        </div>
      </SalesPanel>

      <SalesPanel title="Action Center" icon={List} footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-center-actions">
          {centerActions.map(({ label, icon: Icon, tone, to }) => to ? <Link to={to} key={label} className={`sb-sales-center-button sb-sales-center-${tone}`}><Icon aria-hidden="true" /><span>{label}</span></Link> : <button type="button" key={label} className={`sb-sales-center-button sb-sales-center-${tone}`}>
            <Icon aria-hidden="true" /><span>{label}</span>
          </button>)}
        </div>
      </SalesPanel>
    </section>
  </main>
}

function SalesActionCard({ title, description, button, icon: Icon, tone, to }: ActionCard) {
  const content = <><span>{button}</span></>
  return <article className={`sb-sales-action-card sb-sales-action-${tone}`}>
    <Icon className="sb-sales-action-icon" strokeWidth={1.65} aria-hidden="true" />
    <h2>{title}</h2>
    <p>{description}</p>
    {to
      ? <Link className="sb-sales-card-button" to={to}>{content}</Link>
      : <button className="sb-sales-card-button" type="button">{content}</button>}
  </article>
}

function SalesPanel({ title, icon: Icon, meta, className = '', footer, children }: { title: string; icon: LucideIcon; meta?: string; className?: string; footer?: ReactNode; children: ReactNode }) {
  return <article className={`sb-sales-panel ${className}`}>
    <header className="sb-sales-panel-header">
      <span><Icon aria-hidden="true" />{title}</span>
      <span className="sb-sales-panel-meta">{meta}<ChevronUp aria-hidden="true" /></span>
    </header>
    <div className="sb-sales-panel-body">{children}</div>
    {footer && <footer className="sb-sales-panel-footer">{footer}</footer>}
  </article>
}

function PanelLink({ children }: { children: ReactNode }) {
  return <button type="button" className="sb-sales-panel-link">{children}<ArrowRight aria-hidden="true" /></button>
}

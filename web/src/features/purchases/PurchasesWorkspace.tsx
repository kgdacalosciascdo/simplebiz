import { useMemo, type ReactNode } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  ArrowRight,
  BarChart3,
  BookOpen,
  ChevronUp,
  CircleDollarSign,
  ClipboardList,
  FileText,
  HandCoins,
  List,
  LockKeyhole,
  ReceiptText,
  RotateCcw,
  ShoppingCart,
  TriangleAlert,
  Truck,
  UserRound,
  type LucideIcon,
} from 'lucide-react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { DataTable } from '../../components/DataTable'

type Activity = { date: string; activity: string; amount: string; user: string }
type ActionCard = { title: string; description: string; button: string; icon: LucideIcon; tone: string }

const actionCards: ActionCard[] = [
  { title: 'Cash Purchase', description: 'Record purchase paid in cash.', button: 'New Cash Purchase', icon: ShoppingCart, tone: 'mint' },
  { title: 'Credit Purchase', description: 'Record purchase bought in credit.', button: 'New Credit Purchase', icon: ShoppingCart, tone: 'peach' },
  { title: 'Receive Items', description: 'Record goods received from suppliers.', button: 'Receive Items', icon: HandCoins, tone: 'blue' },
  { title: 'Purchase Order', description: 'Create and track orders to suppliers.', button: 'New Purchase Order', icon: FileText, tone: 'lilac' },
  { title: 'Pay Supplier', description: 'Pay outstanding supplier balances.', button: 'Pay Supplier', icon: CircleDollarSign, tone: 'yellow' },
]

const attentionItems = [
  { count: 5, title: 'Payables Due today', detail: '₱5,750 requires follow-up', tone: 'red' },
  { count: 1, title: 'Overdue supplier balances', detail: '₱18,750 requires follow-up', tone: 'red' },
  { count: 1, title: 'Unapplied payments', detail: '₱1,000 payment is not applied to supplier payables', tone: 'amber' },
  { count: 7, title: 'Voided or failed purchases', detail: '₱300 worth of purchase transaction is voided', tone: 'slate' },
  { count: 3, title: 'Due in the next 7 days', detail: '₱50,580 due for payment in the next 7 days', tone: 'slate' },
]

const summaryData = [
  { name: 'Purchases This Month', value: 493583, label: '₱493,583', color: '#2e86d7' },
  { name: 'Supplier Payments This Month', value: 176200, label: '₱176,200', color: '#e64943' },
  { name: 'Outstanding Payables', value: 84300, label: '₱84,300', color: '#f28a24' },
  { name: 'Overdue Payables', value: 312900, label: '₱312,900', color: '#49ae68' },
]

const activities: Activity[] = [
  { date: '29 July 2026', activity: 'Supplier payable paid', amount: '₱1,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Receipt issued', amount: '₱300', user: 'KVL' },
  { date: '29 July 2026', activity: 'Payment applied', amount: '₱5,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Remittance recorded', amount: '₱10,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Receipt voided', amount: '₱12,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Unapplied payment recorded', amount: '₱25,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Cash short/over recorded', amount: '₱3,000', user: 'CAL' },
]

const records = [
  { label: 'Purchase History', icon: ShoppingCart },
  { label: 'Supplier Payment History', icon: HandCoins },
  { label: 'Goods Receipt History', icon: ClipboardList },
  { label: 'Supplier Ledger', icon: BookOpen },
  { label: 'Purchase Return History', icon: RotateCcw },
  { label: 'Purchase Order History', icon: FileText },
]

const reportColumns = [
  [
    { label: 'Daily Purchases Report', icon: BarChart3 },
    { label: 'Purchases Summary Report', icon: BarChart3 },
    { label: 'Purchases by Supplier', icon: UserRound },
    { label: 'Purchases by Product/Service', icon: ShoppingCart },
    { label: 'Purchases Returns & Discounts', icon: ReceiptText },
    { label: 'Purchases Trend', icon: ShoppingCart },
  ],
  [
    { label: 'Payables Summary', icon: BarChart3 },
    { label: 'Payables Aging', icon: ClipboardList },
    { label: 'Supplier Balances', icon: ReceiptText },
    { label: 'Unpaid & Partially Paid Purchases', icon: ShoppingCart },
    { label: 'Overdue Payables', icon: ReceiptText },
    { label: 'Payables Movement', icon: ReceiptText },
  ],
]

const centerActions = [
  { label: 'Request for Quotation', icon: FileText, tone: 'disabled' },
  { label: 'Record Purchase Returns', icon: RotateCcw, tone: 'blue' },
  { label: 'Create Credit Adjustment', icon: FileText, tone: 'peach' },
  { label: 'Create Debit Adjustment', icon: FileText, tone: 'yellow' },
]

export function PurchasesWorkspace() {
  const columns = useMemo<ColumnDef<Activity, unknown>[]>(() => [
    { accessorKey: 'date', header: 'Date' },
    { accessorKey: 'activity', header: 'Activity' },
    { accessorKey: 'amount', header: 'Amount' },
    { accessorKey: 'user', header: 'User' },
  ], [])

  return <main className="sb-sales-reference sb-purchases-reference" aria-labelledby="purchases-management-title">
    <header className="sb-sales-header">
      <div className="sb-sales-title-icon" aria-hidden="true"><Truck /></div>
      <div>
        <h1 id="purchases-management-title">Purchases &amp; Payables Management</h1>
        <p>Buy goods and services, receive items, track what you owe, and manage suppliers.</p>
      </div>
    </header>

    <section className="sb-sales-action-grid" aria-label="Purchases shortcuts">
      {actionCards.map((card) => <PurchaseActionCard key={card.title} {...card} />)}
    </section>

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-middle">
      <PurchasePanel title="Needs Attention" icon={TriangleAlert} meta="17 items" className="sb-sales-span-two">
        <div className="sb-sales-attention-list">
          {attentionItems.map((item) => <button type="button" className="sb-sales-attention-row" key={item.title}>
            <span className={`sb-sales-count sb-sales-count-${item.tone}`}>{item.count}</span>
            <span><strong>{item.title}</strong><small>{item.detail}</small></span>
            <ArrowRight aria-hidden="true" />
          </button>)}
        </div>
        <PanelLink>View all</PanelLink>
      </PurchasePanel>

      <PurchasePanel title="Purchases & Payables Summary" icon={List} className="sb-sales-span-two">
        <div className="sb-sales-chart" aria-label="Purchases and payables summary bar chart">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={summaryData} layout="vertical" margin={{ top: 5, right: 64, bottom: 5, left: 5 }}>
              <XAxis type="number" domain={[0, 550000]} hide />
              <YAxis type="category" dataKey="name" width={142} axisLine={false} tickLine={false} tick={{ fill: '#526678', fontSize: 11 }} />
              <Bar dataKey="value" barSize={21} radius={[0, 2, 2, 0]}>
                {summaryData.map((entry) => <Cell key={entry.name} fill={entry.color} />)}
                <LabelList dataKey="label" position="right" fill="#526678" fontSize={10} />
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
        <div className="sb-sales-total-row">
          <BarChart3 aria-hidden="true" />
          <span>Total Purchases This Month<strong>₱493,583</strong></span>
          <small>12.6% increase vs last month</small>
          <PanelLink>View report</PanelLink>
        </div>
      </PurchasePanel>

      <PurchasePanel title="Records & Ledgers" icon={List}>
        <div className="sb-sales-record-grid">
          {records.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-record-item">
            <Icon aria-hidden="true" /><span>{label}</span>
          </button>)}
        </div>
      </PurchasePanel>
    </section>

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-lower">
      <PurchasePanel title="Recent Purchases Activity" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-activity-table"><DataTable data={activities} columns={columns} caption="Recent purchase activity" /></div>
      </PurchasePanel>

      <PurchasePanel title="Reports" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-report-grid">
          {reportColumns.map((column, index) => <div key={index}>
            {column.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-report-link">
              <Icon aria-hidden="true" /><span>{label}</span>
            </button>)}
          </div>)}
        </div>
      </PurchasePanel>

      <PurchasePanel title="Action Center" icon={List} footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-center-actions">
          {centerActions.map(({ label, icon: Icon, tone }) => <button type="button" key={label} className={`sb-sales-center-button sb-sales-center-${tone}`} disabled={tone === 'disabled'}>
            <Icon aria-hidden="true" /><span>{label}</span>{tone === 'disabled' && <LockKeyhole className="sb-purchase-lock" aria-label="Deferred" />}
          </button>)}
        </div>
      </PurchasePanel>
    </section>
  </main>
}

function PurchaseActionCard({ title, description, button, icon: Icon, tone }: ActionCard) {
  return <article className={`sb-sales-action-card sb-sales-action-${tone}`}>
    <Icon className="sb-sales-action-icon" strokeWidth={1.65} aria-hidden="true" />
    <h2>{title}</h2><p>{description}</p>
    <button className="sb-sales-card-button" type="button">{button}</button>
  </article>
}

function PurchasePanel({ title, icon: Icon, meta, className = '', footer, children }: { title: string; icon: LucideIcon; meta?: string; className?: string; footer?: ReactNode; children: ReactNode }) {
  return <article className={`sb-sales-panel ${className}`}>
    <header className="sb-sales-panel-header"><span><Icon aria-hidden="true" />{title}</span><span className="sb-sales-panel-meta">{meta}<ChevronUp aria-hidden="true" /></span></header>
    <div className="sb-sales-panel-body">{children}</div>
    {footer && <footer className="sb-sales-panel-footer">{footer}</footer>}
  </article>
}

function PanelLink({ children }: { children: ReactNode }) {
  return <button type="button" className="sb-sales-panel-link">{children}<ArrowRight aria-hidden="true" /></button>
}

import { useMemo, type ReactNode } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import {
  ArrowRight,
  BarChart3,
  BookOpen,
  BriefcaseBusiness,
  Calculator,
  ChevronUp,
  ClipboardList,
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
  WalletCards,
  type LucideIcon,
} from 'lucide-react'
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, XAxis, YAxis } from 'recharts'
import { Link } from 'react-router-dom'
import { DataTable } from '../../components/DataTable'

type Activity = {
  date: string
  activity: string
  amount: string
  user: string
}

type ActionCard = {
  title: string
  description: string
  button: string
  icon: LucideIcon
  tone: string
  to?: string
}

const actionCards: ActionCard[] = [
  { title: 'Collections', description: 'Receive customer payments and apply them to outstanding receivables.', button: 'Receive Payment', icon: HandCoins, tone: 'mint' },
  { title: 'Customer Ledger', description: 'View customer balances, receivables, and payment history.', button: 'View Customer Ledger', icon: Contact, tone: 'peach', to: '/customers' },
  { title: 'Receipt History', description: 'Review customer payments and issued receipts.', button: 'View Receipts', icon: ClipboardList, tone: 'blue' },
  { title: 'Cash Remittance', description: 'Record the remittance of collected cash.', button: 'Record Remittance', icon: Vault, tone: 'lilac' },
  { title: 'Other Receipts', description: 'Record money received from non-sales or other business sources.', button: 'Record Other Receipt', icon: WalletCards, tone: 'yellow' },
]

const attentionItems = [
  { count: 5, title: 'Due today', detail: '₱5,750 requires follow-up', tone: 'red' },
  { count: 1, title: 'Overdue customer balances', detail: '₱18,750 requires follow-up', tone: 'red' },
  { count: 1, title: 'Unapplied payments', detail: '₱1,000 collection is not applied to customer receivables', tone: 'amber' },
  { count: 7, title: 'Voided or failed receipts', detail: '₱300 worth of sales transaction is voided', tone: 'slate' },
  { count: 3, title: 'Due in the next 7 days', detail: '₱50,580 due for collection in the next 7 days', tone: 'slate' },
]

const summaryData = [
  { name: 'Collected This Month', value: 493583, label: '₱493,583', color: '#2e86d7' },
  { name: 'Collected Today', value: 176200, label: '₱176,200', color: '#e64943' },
  { name: 'Overdue Receivables', value: 84300, label: '₱84,300', color: '#f28a24' },
  { name: 'Total Receivables', value: 312900, label: '₱312,900', color: '#49ae68' },
]

const activities: Activity[] = [
  { date: '29 July 2026', activity: 'Customer payment received', amount: '₱1,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Receipt issued', amount: '₱300', user: 'KVL' },
  { date: '29 July 2026', activity: 'Payment applied', amount: '₱5,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Remittance recorded', amount: '₱10,000', user: 'CAL' },
  { date: '29 July 2026', activity: 'Receipt voided', amount: '₱12,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Unapplied payment recorded', amount: '₱25,000', user: 'KVL' },
  { date: '29 July 2026', activity: 'Cash short/over recorded', amount: '₱3,000', user: 'CAL' },
]

const records = [
  { label: 'Cash Sales History', icon: Calculator },
  { label: 'Collection History', icon: HandCoins },
  { label: 'Remittance History', icon: Vault },
  { label: 'Customer Ledger', icon: BookOpen },
  { label: 'Cash Short/Over', icon: BriefcaseBusiness },
  { label: 'Other Receipts History', icon: WalletCards },
]

const reportColumns = [
  [
    { label: 'Daily Collections Report', icon: BarChart3 },
    { label: 'Collection Summary', icon: BarChart3 },
    { label: 'Collections by Customer', icon: ShoppingCart },
    { label: 'Collections by Payment Method', icon: UserRound },
    { label: 'Collections by Cash Account', icon: ReceiptText },
    { label: 'Receipt Summary', icon: ShoppingCart },
  ],
  [
    { label: 'Receipt Register', icon: BarChart3 },
    { label: 'Unapplied Payments', icon: FileText },
    { label: 'Voided Receipts', icon: ReceiptText },
    { label: 'Collection Performance', icon: ShoppingCart },
    { label: 'Collections versus Amount Due', icon: ReceiptText },
    { label: 'Receivables Movement', icon: ReceiptText },
  ],
]

const centerActions = [
  { label: 'Record Other Receipt', icon: ReceiptText, tone: 'mint' },
  { label: 'Apply Unapplied Payment', icon: FilePlus2, tone: 'blue' },
  { label: 'Create Billing Statement', icon: FileText, tone: 'peach' },
  { label: 'Issue Customer Refund', icon: RotateCcw, tone: 'yellow' },
]

export function CollectionsWorkspace() {
  const columns = useMemo<ColumnDef<Activity, unknown>[]>(() => [
    { accessorKey: 'date', header: 'Date' },
    { accessorKey: 'activity', header: 'Activity' },
    { accessorKey: 'amount', header: 'Amount' },
    { accessorKey: 'user', header: 'User' },
  ], [])

  return <main className="sb-sales-reference sb-collections-reference" aria-labelledby="collections-management-title">
    <header className="sb-sales-header">
      <div className="sb-sales-title-icon" aria-hidden="true"><HandCoins /></div>
      <div>
        <h1 id="collections-management-title">Collections &amp; Receipts Management</h1>
        <p>Receive customer payments, issue receipts, follow up what&apos;s due, and review collection activity.</p>
      </div>
    </header>

    <section className="sb-sales-action-grid" aria-label="Collections shortcuts">
      {actionCards.map((card) => <CollectionsActionCard key={card.title} {...card} />)}
    </section>

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-middle">
      <CollectionsPanel title="Needs Attention" icon={TriangleAlert} meta="17 items" className="sb-sales-span-two">
        <div className="sb-sales-attention-list">
          {attentionItems.map((item) => <button type="button" className="sb-sales-attention-row" key={item.title}>
            <span className={`sb-sales-count sb-sales-count-${item.tone}`}>{item.count}</span>
            <span><strong>{item.title}</strong><small>{item.detail}</small></span>
            <ArrowRight aria-hidden="true" />
          </button>)}
        </div>
        <PanelLink>View all</PanelLink>
      </CollectionsPanel>

      <CollectionsPanel title="Collection Summary" icon={List} className="sb-sales-span-two">
        <div className="sb-sales-chart" aria-label="Collection summary bar chart">
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
          <span>Total Receipts This Month<strong>₱493,583</strong></span>
          <small>12.6% increase vs last month</small>
          <PanelLink>View report</PanelLink>
        </div>
      </CollectionsPanel>

      <CollectionsPanel title="Records & Ledgers" icon={List}>
        <div className="sb-sales-record-grid">
          {records.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-record-item">
            <Icon aria-hidden="true" /><span>{label}</span>
          </button>)}
        </div>
      </CollectionsPanel>
    </section>

    <section className="sb-sales-dashboard-grid sb-sales-dashboard-grid-lower">
      <CollectionsPanel title="Recent Collection Activity" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-activity-table"><DataTable data={activities} columns={columns} caption="Recent collection activity" /></div>
      </CollectionsPanel>

      <CollectionsPanel title="Reports" icon={List} className="sb-sales-span-two" footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-report-grid">
          {reportColumns.map((column, index) => <div key={index}>
            {column.map(({ label, icon: Icon }) => <button type="button" key={label} className="sb-sales-report-link">
              <Icon aria-hidden="true" /><span>{label}</span>
            </button>)}
          </div>)}
        </div>
      </CollectionsPanel>

      <CollectionsPanel title="Action Center" icon={List} footer={<PanelLink>View all</PanelLink>}>
        <div className="sb-sales-center-actions">
          {centerActions.map(({ label, icon: Icon, tone }) => <button type="button" key={label} className={`sb-sales-center-button sb-sales-center-${tone}`}>
            <Icon aria-hidden="true" /><span>{label}</span>
          </button>)}
        </div>
      </CollectionsPanel>
    </section>
  </main>
}

function CollectionsActionCard({ title, description, button, icon: Icon, tone, to }: ActionCard) {
  const buttonContent = <span>{button}</span>
  return <article className={`sb-sales-action-card sb-sales-action-${tone}`}>
    <Icon className="sb-sales-action-icon" strokeWidth={1.65} aria-hidden="true" />
    <h2>{title}</h2>
    <p>{description}</p>
    {to
      ? <Link className="sb-sales-card-button" to={to}>{buttonContent}</Link>
      : <button className="sb-sales-card-button" type="button">{buttonContent}</button>}
  </article>
}

function CollectionsPanel({ title, icon: Icon, meta, className = '', footer, children }: { title: string; icon: LucideIcon; meta?: string; className?: string; footer?: ReactNode; children: ReactNode }) {
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

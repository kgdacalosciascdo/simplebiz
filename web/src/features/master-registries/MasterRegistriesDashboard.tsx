import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  Box, ChevronUp, CircleCheck, Clock3, CreditCard, Database, Download, FilePlus2, Landmark, List, Ruler,
  Search, Shapes, Tags, Truck, Upload, Users, WalletCards, type LucideIcon,
} from 'lucide-react'
import { apiFetch } from '../../lib/api'

type RegistryCard = { label: string; description: string; button: string; href: string; icon: LucideIcon; tone: number }
type SummaryResponse = {
  counts: Record<string, number>
  registry_summary?: { total_records: number; active_records: number; inactive_records: number; updated_today: number }
  recent_activity?: { id: number | string; title?: string; description?: string }[]
}

const registryCards: RegistryCard[] = [
  { label: 'Customers', description: 'People and businesses that buy from you.', button: 'Manage Customers', href: '/customers', icon: Users, tone: 1 },
  { label: 'Suppliers', description: 'People and businesses you buy goods or services from.', button: 'Manage Suppliers', href: '/suppliers', icon: Truck, tone: 2 },
  { label: 'Products & Services', description: 'Items and services you sell, purchase, or keep in stock.', button: 'Manage Products & Services', href: '/products-services', icon: Box, tone: 3 },
  { label: 'Product Categories', description: 'Organize products and services for easier setup, search, and reporting.', button: 'Manage Categories', href: '/categories', icon: Shapes, tone: 4 },
  { label: 'Units of Measure', description: 'Define how items are counted, measured, purchased, or sold.', button: 'Manage Units', href: '/units', icon: Ruler, tone: 5 },
  { label: 'Cash Accounts', description: 'Open the MDS-700 Cash Accounts workspace for money-account profiles.', button: 'Open Cash Accounts', href: '/cash-accounts', icon: Landmark, tone: 6 },
  { label: 'Expense Categories', description: 'Organize expenses such as rent, utilities, transport, and supplies.', button: 'Manage Expense Categories', href: '/master-registries?registry=expense-categories', icon: Tags, tone: 7 },
  { label: 'Payment Methods', description: 'Define how customers pay and how your business makes payments.', button: 'Manage Payment Methods', href: '/master-registries?registry=payment-methods', icon: CreditCard, tone: 8 },
]

const quickActions = [
  { label: 'Add Customer', href: '/master-registries/business-partners/new', tone: 1 }, { label: 'Add Supplier', href: '/master-registries/business-partners/new', tone: 2 },
  { label: 'Add Product or Service', href: '/products-services/new', tone: 3 }, { label: 'Add Cash Account', href: '/cash-accounts', tone: 4 },
  { label: 'Add Expense Category', href: '/master-registries?registry=expense-categories', tone: 5 }, { label: 'Add Units of Measure', href: '/units', tone: 6 },
  { label: 'Add Product Category', href: '/categories', tone: 7 }, { label: 'Add Payment Method', href: '/master-registries?registry=payment-methods', tone: 8 },
]

export function MasterRegistriesDashboard() {
  const [search, setSearch] = useState('')
  const [summary, setSummary] = useState<SummaryResponse | null>(null)
  const [summaryError, setSummaryError] = useState('')
  useEffect(() => {
    apiFetch<{ data: SummaryResponse }>('/master-registries').then((response) => setSummary(response.data)).catch(() => setSummaryError('Registry summary is temporarily unavailable.'))
  }, [])
  const normalizedSearch = search.trim().toLowerCase()
  const visibleCards = registryCards.filter((card) => `${card.label} ${card.description}`.toLowerCase().includes(normalizedSearch))

  return <main className="sb-master-reference" aria-labelledby="master-registries-title">
    <header className="sb-master-header"><span className="sb-master-grid-mark" aria-hidden="true">{Array.from({ length: 9 }, (_, index) => <i key={index} />)}</span><div><h1 id="master-registries-title">Master Registries</h1><p>Manage the shared business records used throughout SimpleBIZ.</p></div></header>
    <nav className="sb-master-plan-tabs" aria-label="SimpleBIZ plans">{['SimpleBIZ Free', 'Startup', 'Pro', 'Advanced', 'ERP'].map((planName, index) => <button type="button" className={index === 0 ? 'sb-master-plan-active' : ''} key={planName}>{planName}</button>)}</nav>
    <label className="sb-master-search"><Search aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Find a registry..." aria-label="Find a registry" /></label>
    <section className="sb-master-workspace-grid">
      <div className="sb-master-card-grid">{visibleCards.map(({ label, description, button, href, icon: Icon, tone }) => <article className={`sb-master-card sb-master-tone-${tone}`} key={label}><span className="sb-master-card-icon"><Icon aria-hidden="true" /></span><h2>{label}</h2><p>{description}</p><Link to={href}>{button}<span aria-hidden="true">›</span></Link></article>)}{!visibleCards.length && <div className="sb-master-no-results">No registries match “{search}”.</div>}</div>
      <MasterPanel title="Quick Actions" className="sb-master-side-panel"><div className="sb-master-quick-actions">{quickActions.map((item) => <Link className={`sb-master-quick-${item.tone}`} to={item.href} key={item.label}><FilePlus2 aria-hidden="true" />{item.label}</Link>)}</div><MasterPanelLink>View all</MasterPanelLink></MasterPanel>
      <MasterPanel title="Recently Updated" className="sb-master-side-panel"><div className="sb-master-recent-list">{summary?.recent_activity?.length ? summary.recent_activity.map((item) => <button type="button" key={item.id}><strong>{item.title ?? 'Registry change'}</strong><small>{item.description ?? 'Master registry activity'}</small></button>) : <p className="sb-master-empty">{summaryError || 'No registry activity yet.'}</p>}</div><MasterPanelLink>View all</MasterPanelLink></MasterPanel>
    </section>
    <section className="sb-master-bottom-grid">
      <MasterPanel title="Registry Summary" className="sb-master-summary-panel"><div className="sb-master-summary-grid"><SummaryMetric icon={Database} value={summary?.registry_summary?.total_records} label="Total Records" tone="blue" /><SummaryMetric icon={CircleCheck} value={summary?.registry_summary?.active_records} label="Active Records" tone="green" /><SummaryMetric icon={WalletCards} value={summary?.registry_summary?.inactive_records} label="Inactive Records" tone="orange" /><SummaryMetric icon={Clock3} value={summary?.registry_summary?.updated_today} label="Records Updated Today" tone="purple" /></div></MasterPanel>
      <MasterPanel title="Data Tools" className="sb-master-tools-panel"><div className="sb-master-data-tools"><button type="button" disabled title="Initial setup template import is edition-gated."><Upload aria-hidden="true" />Import Records</button><button type="button" disabled title="Governed registry export is edition-gated."><Download aria-hidden="true" />Export Records</button></div><MasterPanelLink>View all</MasterPanelLink></MasterPanel>
    </section>
  </main>
}

function MasterPanel({ title, className = '', children }: { title: string; className?: string; children: ReactNode }) { return <article className={`sb-master-panel ${className}`}><header><span><List aria-hidden="true" />{title}</span><ChevronUp aria-hidden="true" /></header><div className="sb-master-panel-body">{children}</div></article> }
function MasterPanelLink({ children }: { children: ReactNode }) { return <button type="button" className="sb-master-panel-link">{children}</button> }
function SummaryMetric({ icon: Icon, value, label, tone }: { icon: LucideIcon; value?: number; label: string; tone: string }) { return <div className="sb-master-summary-metric"><span className={`sb-master-summary-icon sb-master-summary-${tone}`}><Icon aria-hidden="true" /></span><span><strong>{value === undefined ? '—' : value.toLocaleString()}</strong><small>{label}</small></span></div> }

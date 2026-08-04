import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, Bell, Building2, Database, FileText, GitBranch, History, Search, Settings, ShieldCheck, SlidersHorizontal, UsersRound, UserRound } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Badge, Card, EmptyState, PageHeader } from '../../components/ui'

type SettingCard = { icon: LucideIcon; title: string; description: string; to?: string }
const settings: SettingCard[] = [
  { icon: UserRound, title: 'Account & Subscription', description: 'Manage your profile, plan usage, billing, and connected SimpleBIZ products.' },
  { icon: Building2, title: 'Business Setup', description: 'Configure your company profile, currency, locale, fiscal year, and safe defaults.', to: '/settings/business-setup' },
  { icon: UsersRound, title: 'Users & Access', description: 'Invite users and manage company membership and allowed roles.', to: '/settings/users-access' },
  { icon: FileText, title: 'Finance & Documents', description: 'Accounting, taxes, document numbering, and financial defaults remain deferred.' },
  { icon: GitBranch, title: 'Workflow & Approvals', description: 'Approval policy administration remains deferred.' },
  { icon: SlidersHorizontal, title: 'Modules & Preferences', description: 'Module entitlements and general preferences remain deferred.' },
  { icon: Bell, title: 'Notifications', description: 'Notification administration remains deferred.' },
  { icon: Database, title: 'Data & Integrations', description: 'Import, export, and service integrations remain deferred.' },
  { icon: ShieldCheck, title: 'Security & Audit', description: 'Security administration remains deferred; existing audit behavior is preserved.' },
]

export function SettingsWorkspace() {
  const [search, setSearch] = useState('')
  const visible = useMemo(() => settings.filter((item) => `${item.title} ${item.description}`.toLowerCase().includes(search.toLowerCase())), [search])
  return <><PageHeader icon={<Settings size={28} strokeWidth={1.8} />} title="Settings & Administration" subtitle="Manage your business, users, security, and system settings." /><div className="sb-settings-toolbar"><label><Search size={16} aria-hidden="true" /><span className="sr-only">Search settings</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search settings…" /></label><span className="sb-settings-note">Only implemented destinations are actionable.</span></div><div className="sb-settings-grid">{visible.map((item) => { const ItemIcon = item.icon; return item.to ? <Link key={item.title} to={item.to} className="sb-settings-card sb-settings-card-active"><span className="sb-settings-icon" aria-hidden="true"><ItemIcon size={38} strokeWidth={1.7} /></span><h2>{item.title}</h2><p>{item.description}</p><span className="sb-card-action">Open {item.title} <ArrowUpRight size={14} aria-hidden="true" /></span></Link> : <article key={item.title} className="sb-settings-card"><span className="sb-settings-icon" aria-hidden="true"><ItemIcon size={38} strokeWidth={1.7} /></span><h2>{item.title}</h2><p>{item.description}</p><Badge>Deferred</Badge></article> })}</div>{visible.length === 0 && <Card><EmptyState title="No settings found" detail="Try a different search term." /></Card>}<div className="sb-settings-footer"><Card><div className="sb-panel-heading"><h2><History size={17} aria-hidden="true" />Recent Changes</h2></div><EmptyState title="Recent changes are shown in Users & Access" detail="Open the implemented administrative workspace to review current access activity." action={<Link className="sb-button-secondary" to="/settings/users-access">Open Users & Access</Link>} /></Card></div></>
}

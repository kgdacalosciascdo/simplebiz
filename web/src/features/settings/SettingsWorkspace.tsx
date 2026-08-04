import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Card, EmptyState, PageHeader } from '../../components/ui'

type SettingCard = { icon: string; title: string; description: string; to?: string }
const settings: SettingCard[] = [
  { icon: '◉', title: 'Account & Subscription', description: 'Manage your profile, plan usage, billing, and connected SimpleBIZ products.' },
  { icon: '▣', title: 'Business Setup', description: 'Configure your company profile, currency, locale, fiscal year, and safe defaults.', to: '/settings/business-setup' },
  { icon: '⚿', title: 'Users & Access', description: 'Invite users and manage company membership and allowed roles.', to: '/settings/users-access' },
  { icon: '▤', title: 'Finance & Documents', description: 'Accounting, taxes, document numbering, and financial defaults remain deferred.' },
  { icon: '⌘', title: 'Workflow & Approvals', description: 'Approval policy administration remains deferred.' },
  { icon: '☷', title: 'Modules & Preferences', description: 'Module entitlements and general preferences remain deferred.' },
  { icon: '♧', title: 'Notifications', description: 'Notification administration remains deferred.' },
  { icon: '⌁', title: 'Data & Integrations', description: 'Import, export, and service integrations remain deferred.' },
  { icon: '◇', title: 'Security & Audit', description: 'Security administration remains deferred; existing audit behavior is preserved.' },
]

export function SettingsWorkspace() {
  const [search, setSearch] = useState('')
  const visible = useMemo(() => settings.filter((item) => `${item.title} ${item.description}`.toLowerCase().includes(search.toLowerCase())), [search])
  return <><PageHeader icon="⚙" title="Settings & Administration" subtitle="Manage your business, users, security, and system settings." /><div className="sb-settings-toolbar"><label><span className="sr-only">Search settings</span><span aria-hidden="true">⌕</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search settings…" /></label><span className="sb-settings-note">Only implemented destinations are actionable.</span></div><div className="sb-settings-grid">{visible.map((item) => item.to ? <Link key={item.title} to={item.to} className="sb-settings-card sb-settings-card-active"><span className="sb-settings-icon" aria-hidden="true">{item.icon}</span><h2>{item.title}</h2><p>{item.description}</p><span className="sb-card-action">Open {item.title} →</span></Link> : <article key={item.title} className="sb-settings-card"><span className="sb-settings-icon" aria-hidden="true">{item.icon}</span><h2>{item.title}</h2><p>{item.description}</p><Badge>Deferred</Badge></article>)}</div>{visible.length === 0 && <Card><EmptyState title="No settings found" detail="Try a different search term." /></Card>}<div className="sb-settings-footer"><Card><div className="sb-panel-heading"><h2><span aria-hidden="true">☷</span>Recent Changes</h2></div><EmptyState title="Recent changes are shown in Users & Access" detail="Open the implemented administrative workspace to review current access activity." action={<Link className="sb-button-secondary" to="/settings/users-access">Open Users & Access</Link>} /></Card></div></>
}

import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Bell,
  ChevronUp,
  CircleDollarSign,
  CircleUserRound,
  KeyRound,
  List,
  Network,
  Plug,
  Search,
  Settings,
  ShieldCheck,
  SlidersHorizontal,
  Store,
  type LucideIcon,
} from 'lucide-react'

type SettingCard = {
  icon: LucideIcon
  title: string
  description: string
  button: string
  to?: string
}

const settings: SettingCard[] = [
  { icon: CircleUserRound, title: 'Account & Subscription', description: 'Manage your profile, plan, usage, billing, and connected SimpleBIZ products.', button: 'Account & Billing' },
  { icon: Store, title: 'Business Setup', description: 'Configure your company profile, currency, locale, fiscal year, and business defaults.', button: 'Configure Business', to: '/settings/business-setup' },
  { icon: KeyRound, title: 'Users & Access', description: 'Add users and control their roles, permissions, and access.', button: 'Manage Users & Access', to: '/settings/users-access' },
  { icon: CircleDollarSign, title: 'Finance & Documents', description: 'Configure accounting, taxes, document numbering, and financial defaults.', button: 'Configure Finance & Documents' },
  { icon: Network, title: 'Workflow & Approvals', description: 'Set approval requirements and supported transaction workflows.', button: 'Manage Workflows' },
  { icon: SlidersHorizontal, title: 'Modules & Preferences', description: 'Enable modules and manage general operating preferences.', button: 'Manage Preferences' },
  { icon: Bell, title: 'Notifications', description: 'Control in-app and email alerts for each user.', button: 'Manage Notifications' },
  { icon: Plug, title: 'Data & Integrations', description: 'Import, export, and connect SimpleBIZ with supported services.', button: 'Manage Data & Integrations' },
  { icon: ShieldCheck, title: 'Security & Audit', description: 'Manage MFA, sessions, login history, and administrative audit records.', button: 'Review Security & Audit' },
]

const recentChanges = [
  ['Maria invited a new user', 'by Juan dela Cruz · 12 minutes ago'],
  ['MFA enabled for Juan dela Cruz', 'by Juan dela Cruz · 12 minutes ago'],
  ['Fiscal year changed', 'by Juan dela Cruz · 12 minutes ago'],
  ['Invoice numbering updated', 'by Juan dela Cruz · 12 minutes ago'],
  ['Email notifications disabled', 'by Juan dela Cruz · 12 minutes ago'],
  ['Integration connected', 'by Juan dela Cruz · 12 minutes ago'],
]

export function SettingsWorkspace() {
  const [search, setSearch] = useState('')
  const visible = useMemo(() => {
    const query = search.trim().toLowerCase()
    return settings.filter((item) => `${item.title} ${item.description}`.toLowerCase().includes(query))
  }, [search])

  return <main className="sb-admin-reference" aria-labelledby="settings-administration-title">
    <header className="sb-admin-header">
      <span className="sb-admin-header-icon" aria-hidden="true"><Settings /></span>
      <div>
        <h1 id="settings-administration-title">Settings &amp; Administration</h1>
        <p>Manage your business, users, security, and system settings.</p>
      </div>
    </header>

    <label className="sb-admin-search">
      <Search aria-hidden="true" />
      <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search settings..." aria-label="Search settings" />
    </label>

    <section className="sb-admin-grid" aria-label="Settings categories">
      {visible.map((item) => <SettingsCard key={item.title} {...item} />)}
      {!visible.length && <div className="sb-admin-no-results">No settings match “{search}”.</div>}
      <RecentChanges />
    </section>
  </main>
}

function SettingsCard({ icon: Icon, title, description, button, to }: SettingCard) {
  const action = <>{button}</>
  return <article className="sb-admin-card">
    <Icon className="sb-admin-card-icon" strokeWidth={1.65} aria-hidden="true" />
    <h2>{title}</h2>
    <p>{description}</p>
    {to ? <Link to={to}>{action}</Link> : <button type="button">{action}</button>}
  </article>
}

function RecentChanges() {
  return <article className="sb-admin-recent">
    <header><span><List aria-hidden="true" />Recent Changes</span><ChevronUp aria-hidden="true" /></header>
    <div className="sb-admin-recent-list">
      {recentChanges.map(([title, detail]) => <button type="button" key={title}><strong>{title}</strong><small>{detail}</small></button>)}
    </div>
    <button type="button" className="sb-admin-view-all">View all</button>
  </article>
}

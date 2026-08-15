import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Bell,
  CircleDollarSign,
  CircleUserRound,
  Database,
  FileCog,
  List,
  Network,
  Search,
  Settings,
  ShieldCheck,
  Store,
  UsersRound,
  type LucideIcon,
} from 'lucide-react'
import { apiFetch } from '../../lib/api'

type ApiEnvelope<T> = { data: T; meta?: Record<string, unknown> }
type Destination = { key: string; category: string; title: string; description: string; route: string; permission: string }
type Workspace = {
  destinations: Destination[]
  searchable_destinations: Destination[]
  plan: { edition: string; state: string; subscription_status: string; message: string }
  setup_progress: { completed: number; total: number; percentage: number; status: string; missing: string[] }
  needs_attention: { key: string; severity: string; title: string; detail: string; route: string }[]
  recent_activity: { id: number; event: string; title: string; description?: string; occurred_at: string }[]
}

const icons: Record<string, LucideIcon> = {
  account: CircleUserRound,
  business: Store,
  users: UsersRound,
  financial: CircleDollarSign,
  workflow: Network,
  notifications: Bell,
  data: Database,
  security: ShieldCheck,
}

export function SettingsWorkspace() {
  const [workspace, setWorkspace] = useState<Workspace | null>(null)
  const [search, setSearch] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    apiFetch<ApiEnvelope<Workspace>>('/settings/workspace')
      .then((response) => setWorkspace(response.data))
      .catch((caught) => setError(caught instanceof Error ? caught.message : 'Unable to load settings.'))
  }, [])

  const visible = useMemo(() => {
    if (!workspace) return []
    const query = search.trim().toLowerCase()
    return workspace.searchable_destinations.filter((item) => `${item.title} ${item.description} ${item.category}`.toLowerCase().includes(query))
  }, [search, workspace])

  if (error) return <main className="sb-admin-reference"><div className="sb-admin-no-results">{error}</div></main>
  if (!workspace) return <main className="sb-admin-reference"><div className="sb-admin-no-results">Loading Settings &amp; Administration…</div></main>

  return <main className="sb-admin-reference" aria-labelledby="settings-administration-title">
    <header className="sb-admin-header">
      <span className="sb-admin-header-icon" aria-hidden="true"><Settings /></span>
      <div>
        <h1 id="settings-administration-title">Settings &amp; Administration</h1>
        <p>Manage your business behavior, users, security, and account settings.</p>
      </div>
      <div className="sb-admin-plan" aria-label="Current edition">
        <strong>{workspace.plan.edition}</strong>
        <span>{workspace.plan.subscription_status === 'unavailable' ? 'Commercial status unavailable' : workspace.plan.subscription_status}</span>
      </div>
    </header>

    <div className="sb-admin-toolbar">
      <label className="sb-admin-search">
        <Search aria-hidden="true" />
        <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search settings…" aria-label="Search settings" />
      </label>
      <Link to="/settings/business-setup" className="sb-admin-secondary-action">Open company setup</Link>
    </div>

    <section className="sb-admin-progress" aria-label="Settings progress">
      <div><strong>Setup progress</strong><span>{workspace.setup_progress.completed} of {workspace.setup_progress.total} required items complete</span></div>
      <div className="sb-admin-progress-track"><span style={{ width: `${workspace.setup_progress.percentage}%` }} /></div>
      <strong>{workspace.setup_progress.percentage}%</strong>
    </section>

    {workspace.needs_attention.length > 0 && <section className="sb-admin-attention" aria-labelledby="settings-attention-title">
      <div><strong id="settings-attention-title">Needs Attention</strong><span>{workspace.needs_attention.length} item(s)</span></div>
      <div className="sb-admin-attention-list">{workspace.needs_attention.map((item) => <Link key={item.key} to={item.route}><span className={`sb-admin-severity sb-admin-severity-${item.severity}`}>{item.severity}</span><span><strong>{item.title}</strong><small>{item.detail}</small></span></Link>)}</div>
    </section>}

    <section className="sb-admin-grid" aria-label="Settings categories">
      {visible.map((item) => <SettingsCard key={item.key} item={item} />)}
      {!visible.length && <div className="sb-admin-no-results">No authorized settings match “{search}”.</div>}
      <RecentChanges activity={workspace.recent_activity} />
    </section>

    <p className="sb-admin-source-note">{workspace.plan.message}</p>
  </main>
}

function SettingsCard({ item }: { item: Destination }) {
  const Icon = icons[item.key] ?? FileCog
  return <article className="sb-admin-card">
    <Icon className="sb-admin-card-icon" strokeWidth={1.65} aria-hidden="true" />
    <span className="sb-admin-category">{item.category}</span>
    <h2>{item.title}</h2>
    <p>{item.description}</p>
    <Link to={item.route}>Open settings <span aria-hidden="true">→</span></Link>
  </article>
}

function RecentChanges({ activity }: { activity: Workspace['recent_activity'] }) {
  return <article className="sb-admin-recent">
    <header><span><List aria-hidden="true" />Recent Administrative Activity</span></header>
    <div className="sb-admin-recent-list">
      {activity.length === 0 ? <p className="sb-admin-empty">No administrative activity recorded.</p> : activity.map((item) => <div key={item.id}><strong>{item.title}</strong><small>{item.description ?? item.event} · {new Date(item.occurred_at).toLocaleString()}</small></div>)}
    </div>
    <Link to="/settings/security" className="sb-admin-view-all">View security &amp; audit</Link>
  </article>
}

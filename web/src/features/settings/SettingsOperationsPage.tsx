/* eslint-disable react-hooks/set-state-in-effect, react-hooks/exhaustive-deps */
import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Bell, Database, FileCog, LockKeyhole, Settings2 } from 'lucide-react'
import { apiFetch, downloadApiFile, newIdempotencyKey } from '../../lib/api'

type ApiEnvelope<T> = { data: T }
type Mode = 'configuration' | 'modules' | 'notifications' | 'data'
type Configuration = { profile_version: number; effective: Record<string, { value: unknown; source: string; version: number }>; precedence: string[]; changes: { id: string; setting_key: string; version: number; status: string; reason?: string; published_at?: string }[] }
type Modules = { edition: string; modules: { key: string; name: string; owner: string; state: string; enabled: boolean; editable: boolean }[]; preferences: Record<string, unknown>; disable_behavior: string }
type Notifications = { company_defaults: Record<string, boolean>; user_preferences: Record<string, boolean>; mandatory: Record<string, boolean>; delivery: { state: string; message: string } }
type ExportItem = { id: string; export_type: string; purpose: string; status: string; ready_at?: string; expires_at?: string; downloaded_at?: string }

const labels: Record<Mode, { title: string; subtitle: string; icon: typeof Settings2 }> = {
  configuration: { title: 'Financial & Documents', subtitle: 'Review effective company configuration, source, version, and publication history.', icon: FileCog },
  modules: { title: 'Modules & Workflow', subtitle: 'Review Free-edition module availability and safe operational ownership boundaries.', icon: Settings2 },
  notifications: { title: 'Notifications', subtitle: 'Manage permitted user preferences while mandatory notices remain protected.', icon: Bell },
  data: { title: 'Data & Integrations', subtitle: 'Request a scoped administrative export and review external-service availability honestly.', icon: Database },
}

export function SettingsOperationsPage({ mode }: { mode: Mode }) {
  const [configuration, setConfiguration] = useState<Configuration | null>(null)
  const [modules, setModules] = useState<Modules | null>(null)
  const [notifications, setNotifications] = useState<Notifications | null>(null)
  const [exports, setExports] = useState<ExportItem[]>([])
  const [purpose, setPurpose] = useState('Administrative review')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const definition = labels[mode]
  const Icon = definition.icon

  async function load() {
    setError('')
    try {
      if (mode === 'configuration') setConfiguration((await apiFetch<ApiEnvelope<Configuration>>('/settings/configuration')).data)
      if (mode === 'modules') setModules((await apiFetch<ApiEnvelope<Modules>>('/settings/modules')).data)
      if (mode === 'notifications') setNotifications((await apiFetch<ApiEnvelope<Notifications>>('/settings/notifications')).data)
      if (mode === 'data') setExports((await apiFetch<ApiEnvelope<ExportItem[]>>('/settings/exports')).data)
    } catch (caught) { setError(caught instanceof Error ? caught.message : `Unable to load ${definition.title}.`) }
  }

  useEffect(() => { void load() }, [mode])

  async function saveNotifications() {
    if (!notifications) return
    try { await apiFetch('/settings/notifications', { method: 'PATCH', body: JSON.stringify({ user_preferences: notifications.user_preferences }), headers: { 'Idempotency-Key': newIdempotencyKey() } }); setMessage('Notification preferences saved.'); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to save notification preferences.') }
  }

  async function createExport() {
    try { await apiFetch('/settings/exports', { method: 'POST', body: JSON.stringify({ export_type: 'administration', purpose }), headers: { 'Idempotency-Key': newIdempotencyKey() } }); setMessage('Administrative export generated and is ready for secure download.'); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to generate export.') }
  }

  async function download(id: string) {
    try { const file = await downloadApiFile(`/settings/exports/${id}/download`); const url = URL.createObjectURL(file.blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = file.filename; anchor.click(); URL.revokeObjectURL(url); setMessage('Export downloaded and recorded.'); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to download export.') }
  }

  return <main className="sb-settings-detail" aria-labelledby="settings-operation-title"><header className="sb-settings-detail-header"><Link to="/settings" className="sb-settings-back">← Settings</Link><div><h1 id="settings-operation-title"><Icon size={27} /> {definition.title}</h1><p>{definition.subtitle}</p></div></header>{message && <div className="sb-settings-success" role="status">{message}</div>}{error && <div className="sb-settings-error" role="alert">{error}</div>}{mode === 'configuration' && configuration && <ConfigurationView configuration={configuration} />}{mode === 'modules' && modules && <ModulesView modules={modules} />}{mode === 'notifications' && notifications && <NotificationsView notifications={notifications} onChange={setNotifications} onSave={() => void saveNotifications()} />}{mode === 'data' && <DataView exports={exports} purpose={purpose} onPurposeChange={setPurpose} onCreate={() => void createExport()} onDownload={(id) => void download(id)} />}{!configuration && !modules && !notifications && mode !== 'data' && !error && <div className="sb-settings-state">Loading…</div>}</main>
}

function ConfigurationView({ configuration }: { configuration: Configuration }) {
  return <div className="sb-settings-detail-grid"><section className="sb-settings-panel sb-settings-panel-wide"><PanelHeading icon={<FileCog />} title={`Effective configuration · version ${configuration.profile_version}`} /><div className="sb-config-list">{Object.entries(configuration.effective).map(([key, item]) => <div key={key}><span><strong>{key.replaceAll('_', ' ')}</strong><small>Source: {item.source} · Version {item.version}</small></span><code>{typeof item.value === 'object' ? JSON.stringify(item.value) : String(item.value ?? 'Not configured')}</code></div>)}</div><p className="sb-settings-note">Precedence: {configuration.precedence.join(' → ')}. Effective values are explanatory and do not bypass source-module enforcement.</p></section><section className="sb-settings-panel sb-settings-panel-wide"><PanelHeading icon={<FileCog />} title="Configuration history" /><div className="sb-config-list">{configuration.changes.length === 0 ? <p className="sb-settings-note">No governed configuration changes recorded yet.</p> : configuration.changes.map((change) => <div key={change.id}><span><strong>{change.setting_key} · v{change.version}</strong><small>{change.status} · {change.reason ?? 'No reason recorded'}</small></span><em>{change.published_at ? new Date(change.published_at).toLocaleString() : 'Not published'}</em></div>)}</div></section></div>
}

function ModulesView({ modules }: { modules: Modules }) {
  return <section className="sb-settings-panel"><PanelHeading icon={<Settings2 />} title={`${modules.edition} module state`} /><div className="sb-module-list">{modules.modules.map((module) => <div key={module.key}><span><strong>{module.name}</strong><small>{module.owner} · {module.editable ? 'Configurable' : 'Source-owned / locked in Free'}</small></span><em className="sb-state-included">{module.state}</em></div>)}</div><p className="sb-settings-note">{modules.disable_behavior}</p></section>
}

function NotificationsView({ notifications, onChange, onSave }: { notifications: Notifications; onChange: (value: Notifications) => void; onSave: () => void }) {
  const inApp = notifications.user_preferences.in_app !== false
  const email = notifications.user_preferences.email === true
  return <section className="sb-settings-panel"><PanelHeading icon={<Bell />} title="User preferences" /><label className="sb-check-row"><input type="checkbox" checked={inApp} onChange={(event) => onChange({ ...notifications, user_preferences: { ...notifications.user_preferences, in_app: event.target.checked } })} /> In-app notices</label><label className="sb-check-row"><input type="checkbox" checked={email} onChange={(event) => onChange({ ...notifications, user_preferences: { ...notifications.user_preferences, email: event.target.checked } })} /> Email preference (transport not configured)</label><button type="button" className="sb-settings-primary" onClick={onSave}>Save preferences</button><div className="sb-settings-source-state"><strong>Mandatory notices remain enabled</strong><span>{Object.keys(notifications.mandatory).join(', ')}</span></div><p className="sb-settings-note">{notifications.delivery.message}</p></section>
}

function DataView({ exports, purpose, onPurposeChange, onCreate, onDownload }: { exports: ExportItem[]; purpose: string; onPurposeChange: (value: string) => void; onCreate: () => void; onDownload: (id: string) => void }) {
  return <div className="sb-settings-detail-grid"><section className="sb-settings-panel"><PanelHeading icon={<Database />} title="Administrative export" /><label>Purpose<textarea value={purpose} onChange={(event) => onPurposeChange(event.target.value)} rows={3} maxLength={500} /></label><button type="button" className="sb-settings-primary" onClick={onCreate}>Generate scoped export</button><p className="sb-settings-note">This is a company-scoped administrative package, not an infrastructure backup, report export, or restore operation.</p></section><section className="sb-settings-panel"><PanelHeading icon={<LockKeyhole />} title="Integrations" /><div className="sb-settings-source-state"><strong>Discovery only</strong><span>No external integration provider or secret vault is configured in this deployment. No fake connection is created.</span></div></section><section className="sb-settings-panel sb-settings-panel-wide"><PanelHeading icon={<Database />} title="Export history" /><div className="sb-config-list">{exports.length === 0 ? <p className="sb-settings-note">No administrative exports requested.</p> : exports.map((item) => <div key={item.id}><span><strong>{item.export_type} · {item.status}</strong><small>{item.purpose} · expires {item.expires_at ? new Date(item.expires_at).toLocaleString() : 'unknown'}</small></span>{item.status === 'ready' && <button type="button" className="sb-settings-secondary" onClick={() => onDownload(item.id)}>Download</button>}</div>)}</div></section></div>
}

function PanelHeading({ icon, title }: { icon: ReactNode; title: string }) { return <header className="sb-settings-panel-heading"><span>{icon}</span><h2>{title}</h2></header> }

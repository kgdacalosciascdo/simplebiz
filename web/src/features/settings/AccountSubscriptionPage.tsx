/* eslint-disable react-hooks/set-state-in-effect */
import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, CircleUserRound, KeyRound, LogOut, ShieldCheck } from 'lucide-react'
import { apiFetch, newIdempotencyKey } from '../../lib/api'

type ApiEnvelope<T> = { data: T }
type Session = { id: number; name: string; last_used_at?: string; expires_at?: string; created_at: string; is_current: boolean }
type Account = {
  my_profile: { id: number; name: string; email: string; status: string; last_login_at?: string }
  preferences: { locale?: string; timezone?: string; date_format?: string; number_format?: string; paper_size?: string; preferred_branch_id?: string }
  active_company: { id: number; name: string; timezone: string; currency: string; locale: string }
  company_access: { id: number; name: string; is_owner: boolean; membership_status: string; roles: { id: number; name: string }[] }[]
  default_context: { company_id?: number; branch_id?: string; authorization: string }
  subscription: { edition: string; subscription_status: string; message: string }
  usage: { state: string; message: string }
  billing: { state: string; message: string }
  connected_products: { name: string; state: string; owner: string; message?: string }[]
}

export function AccountSubscriptionPage() {
  const [account, setAccount] = useState<Account | null>(null)
  const [sessions, setSessions] = useState<Session[]>([])
  const [name, setName] = useState('')
  const [timezone, setTimezone] = useState('')
  const [locale, setLocale] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function load() {
    setError('')
    try {
      const [accountResponse, sessionsResponse] = await Promise.all([
        apiFetch<ApiEnvelope<Account>>('/settings/account'),
        apiFetch<ApiEnvelope<Session[]>>('/settings/sessions'),
      ])
      setAccount(accountResponse.data)
      setSessions(sessionsResponse.data)
      setName(accountResponse.data.my_profile.name)
      setTimezone(accountResponse.data.preferences.timezone ?? accountResponse.data.active_company.timezone)
      setLocale(accountResponse.data.preferences.locale ?? accountResponse.data.active_company.locale)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Unable to load Account & Subscription.')
    }
  }

  useEffect(() => { void load() }, [])

  async function saveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage('')
    setError('')
    try {
      await apiFetch('/settings/account/profile', { method: 'PATCH', body: JSON.stringify({ name }), headers: { 'Idempotency-Key': newIdempotencyKey() } })
      setMessage('My Profile saved and recorded in administrative history.')
      await load()
    } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to save your profile.') }
  }

  async function savePreferences(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setMessage('')
    setError('')
    try {
      await apiFetch('/settings/account/preferences', { method: 'PATCH', body: JSON.stringify({ timezone, locale }), headers: { 'Idempotency-Key': newIdempotencyKey() } })
      setMessage('Personal preferences saved. They affect presentation only and cannot expand access.')
      await load()
    } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to save personal preferences.') }
  }

  async function terminate(id: number) {
    if (!window.confirm('Terminate this session?')) return
    try { await apiFetch(`/settings/sessions/${id}`, { method: 'DELETE', headers: { 'Idempotency-Key': newIdempotencyKey() } }); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to terminate the session.') }
  }

  async function terminateOthers() {
    if (!window.confirm('Terminate all other sessions?')) return
    try { await apiFetch('/settings/sessions/terminate-others', { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() } }); setMessage('Other sessions were terminated.'); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to terminate other sessions.') }
  }

  if (error && !account) return <SettingsState message={error} />
  if (!account) return <SettingsState message="Loading Account & Subscription…" />

  return <main className="sb-settings-detail" aria-labelledby="account-subscription-title">
    <header className="sb-settings-detail-header"><Link to="/settings" className="sb-settings-back">← Settings</Link><div><h1 id="account-subscription-title">Account &amp; Subscription</h1><p>Keep your personal identity, company access, edition, security, and account-source state clear.</p></div></header>
    {message && <div className="sb-settings-success" role="status">{message}</div>}
    {error && <div className="sb-settings-error" role="alert">{error}</div>}

    <div className="sb-settings-detail-grid">
      <section className="sb-settings-panel"><PanelHeading icon={<CircleUserRound />} title="My Profile" /><form onSubmit={saveProfile} className="sb-settings-form"><label>Display name<input value={name} onChange={(event) => setName(event.target.value)} required maxLength={120} /></label><label>Email<input value={account.my_profile.email} disabled /></label><button type="submit" className="sb-settings-primary">Save profile</button></form><p className="sb-settings-note">Authentication credentials and verified identity remain owned by Core. Email/password/MFA secrets are not edited or stored here.</p></section>
      <section className="sb-settings-panel"><PanelHeading icon={<KeyRound />} title="Personal Preferences" /><form onSubmit={savePreferences} className="sb-settings-form"><label>Locale<input value={locale} onChange={(event) => setLocale(event.target.value)} maxLength={12} /></label><label>Time zone<input value={timezone} onChange={(event) => setTimezone(event.target.value)} /></label><button type="submit" className="sb-settings-primary">Save preferences</button></form><p className="sb-settings-note">Preferences change presentation only. Company currency, fiscal settings, and access controls remain company-scoped.</p></section>
      <section className="sb-settings-panel"><PanelHeading icon={<ShieldCheck />} title="Security & Sessions" /><div className="sb-settings-panel-actions"><Link to="/settings/security" className="sb-settings-secondary">Open Security &amp; Audit</Link><button type="button" className="sb-settings-danger" onClick={() => void terminateOthers()}><LogOut size={15} /> Terminate other sessions</button></div><div className="sb-session-list">{sessions.map((session) => <div key={session.id}><span><strong>{session.name}{session.is_current && <em>Current</em>}</strong><small>Created {new Date(session.created_at).toLocaleString()} · Last used {session.last_used_at ? new Date(session.last_used_at).toLocaleString() : 'Not used'}</small></span>{!session.is_current && <button type="button" onClick={() => void terminate(session.id)}>Terminate</button>}</div>)}</div></section>
      <section className="sb-settings-panel"><PanelHeading icon={<CheckCircle2 />} title="Company Access" /><p className="sb-settings-note">Default context is a navigation preference and never grants access.</p><div className="sb-access-list">{account.company_access.map((company) => <div key={company.id}><strong>{company.name}{company.id === account.default_context.company_id && <em>Default</em>}</strong><span>{company.is_owner ? 'Business Owner' : company.roles.map((role) => role.name).join(', ') || 'Member'} · {company.membership_status}</span></div>)}</div></section>
      <section className="sb-settings-panel"><PanelHeading icon={<CircleUserRound />} title="Subscription & Edition" /><StateLine label="Edition" value={account.subscription.edition} state="included" /><StateLine label="Commercial status" value={account.subscription.subscription_status} state="unavailable" /><p className="sb-settings-note">{account.subscription.message}</p></section>
      <section className="sb-settings-panel"><PanelHeading icon={<CheckCircle2 />} title="Usage & Limits" /><StateLine label="Current source" value={account.usage.state} state="unavailable" /><p className="sb-settings-note">{account.usage.message}</p><StateLine label="Billing" value={account.billing.state} state="unavailable" /><p className="sb-settings-note">{account.billing.message}</p></section>
      <section className="sb-settings-panel sb-settings-panel-wide"><PanelHeading icon={<CircleUserRound />} title="Connected Products" /><div className="sb-product-list">{account.connected_products.map((product) => <div key={product.name}><span><strong>{product.name}</strong><small>{product.owner}</small></span><em className={`sb-state-${product.state}`}>{product.state}</em></div>)}</div></section>
    </div>
  </main>
}

function PanelHeading({ icon, title }: { icon: ReactNode; title: string }) { return <header className="sb-settings-panel-heading"><span>{icon}</span><h2>{title}</h2></header> }
function StateLine({ label, value, state }: { label: string; value: string; state: string }) { return <div className="sb-state-line"><span>{label}</span><strong className={`sb-state-${state}`}>{value}</strong></div> }
function SettingsState({ message }: { message: string }) { return <main className="sb-settings-detail"><div className="sb-settings-state">{message}</div></main> }

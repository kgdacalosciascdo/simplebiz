/* eslint-disable react-hooks/set-state-in-effect, react-hooks/exhaustive-deps */
import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { FileSearch, LockKeyhole, LogOut, ShieldCheck } from 'lucide-react'
import { apiFetch, newIdempotencyKey } from '../../lib/api'

type ApiEnvelope<T> = { data: T; meta?: { current_page?: number; last_page?: number; total?: number } }
type Session = { id: number; name: string; last_used_at?: string; expires_at?: string; created_at: string; is_current: boolean }
type AuditItem = { id: number; action: string; reason?: string; before?: Record<string, unknown>; after?: Record<string, unknown>; created_at: string; user_id?: number }

export function SecurityAuditPage() {
  const [sessions, setSessions] = useState<Session[]>([])
  const [audit, setAudit] = useState<AuditItem[]>([])
  const [search, setSearch] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function load() {
    try {
      const [sessionsResponse, auditResponse] = await Promise.all([
        apiFetch<ApiEnvelope<Session[]>>('/settings/sessions'),
        apiFetch<ApiEnvelope<AuditItem[]>>(`/settings/audit?search=${encodeURIComponent(search)}`),
      ])
      setSessions(sessionsResponse.data)
      setAudit(auditResponse.data)
    } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to load security and audit.') }
  }

  useEffect(() => { void load() }, [search])

  async function terminate(id: number) {
    if (!window.confirm('Terminate this session?')) return
    try { await apiFetch(`/settings/sessions/${id}`, { method: 'DELETE', headers: { 'Idempotency-Key': newIdempotencyKey() } }); setMessage('Session terminated.'); await load() } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to terminate the session.') }
  }

  return <main className="sb-settings-detail" aria-labelledby="security-audit-title">
    <header className="sb-settings-detail-header"><Link to="/settings" className="sb-settings-back">← Settings</Link><div><h1 id="security-audit-title">Security &amp; Audit</h1><p>Core-owned authentication and sessions are presented here with immutable administrative history.</p></div></header>
    {message && <div className="sb-settings-success" role="status">{message}</div>}
    {error && <div className="sb-settings-error" role="alert">{error}</div>}
    <div className="sb-settings-detail-grid">
      <section className="sb-settings-panel"><PanelHeading icon={<LockKeyhole />} title="Authentication boundary" /><p className="sb-settings-note">Password credentials, MFA cryptography, recovery secrets, token validation, and identity proofing remain owned by MDS-000/Core. No secrets are stored in Settings.</p><div className="sb-settings-source-state"><strong>Core security service</strong><span>Session controls available through the existing Sanctum contract.</span></div></section>
      <section className="sb-settings-panel"><PanelHeading icon={<ShieldCheck />} title="Active sessions" /><div className="sb-session-list">{sessions.length === 0 ? <p className="sb-settings-note">No active sessions are visible.</p> : sessions.map((session) => <div key={session.id}><span><strong>{session.name}{session.is_current && <em>Current</em>}</strong><small>Created {new Date(session.created_at).toLocaleString()} · Last used {session.last_used_at ? new Date(session.last_used_at).toLocaleString() : 'Not used'}</small></span>{!session.is_current && <button type="button" onClick={() => void terminate(session.id)}><LogOut size={14} /> Terminate</button>}</div>)}</div></section>
      <section className="sb-settings-panel sb-settings-panel-wide"><div className="sb-settings-panel-heading"><span><FileSearch /></span><h2>Administrative Activity</h2><label className="sb-audit-search"><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Filter action or reason…" aria-label="Filter administrative audit" /></label></div><div className="sb-audit-list">{audit.length === 0 ? <p className="sb-settings-note">No audit events match this filter.</p> : audit.map((item) => <article key={item.id}><div><strong>{item.action}</strong><small>{item.reason ?? 'No reason recorded'} · {new Date(item.created_at).toLocaleString()}</small></div><code>{item.user_id ? `User ${item.user_id}` : 'System/Core'}</code></article>)}</div></section>
    </div>
  </main>
}

function PanelHeading({ icon, title }: { icon: ReactNode; title: string }) { return <header className="sb-settings-panel-heading"><span>{icon}</span><h2>{title}</h2></header> }

import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Building2, FileText, Globe2 } from 'lucide-react'
import { apiFetch, newIdempotencyKey } from '../../lib/api'

type ApiEnvelope<T> = { data: T }
type CompanyForm = {
  name: string
  legal_name?: string
  primary_contact_name?: string
  primary_contact_email?: string
  primary_contact_phone?: string
  principal_address?: Record<string, string>
  currency: string
  timezone: string
  locale: string
  fiscal_year_start_month?: number | null
  branding?: { logo_url?: string; document_title?: string }
  tax_registration_references?: { tax_id?: string; registration_name?: string }
  date_format?: string
  number_format?: string
  paper_size?: string
  document_preferences?: { show_tax_breakdown?: boolean; include_contact?: boolean }
  settings_version?: number
}

export function CompanyProfilePage() {
  const [form, setForm] = useState<CompanyForm | null>(null)
  const [original, setOriginal] = useState('')
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    apiFetch<ApiEnvelope<CompanyForm>>('/settings/company').then((response) => {
      const next = { ...response.data, principal_address: response.data.principal_address ?? {}, branding: response.data.branding ?? {}, tax_registration_references: response.data.tax_registration_references ?? {}, document_preferences: response.data.document_preferences ?? {} }
      setForm(next)
      setOriginal(JSON.stringify(next))
    }).catch((caught) => setError(caught instanceof Error ? caught.message : 'Unable to load company profile.'))
  }, [])

  function update<K extends keyof CompanyForm>(key: K, value: CompanyForm[K]) { setForm((current) => current ? { ...current, [key]: value } : current) }
  function updateObject(key: 'branding' | 'tax_registration_references' | 'document_preferences', property: string, value: string | boolean) { setForm((current) => current ? { ...current, [key]: { ...(current[key] as Record<string, string | boolean> | undefined), [property]: value } } : current) }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!form) return
    setMessage('')
    setError('')
    try {
      const response = await apiFetch<ApiEnvelope<CompanyForm>>('/settings/company', { method: 'PATCH', body: JSON.stringify({ ...form, fiscal_year_start_month: form.fiscal_year_start_month ? Number(form.fiscal_year_start_month) : null, reason: reason || 'Company profile update', profile_version: form.settings_version }), headers: { 'Idempotency-Key': newIdempotencyKey() } })
      setForm(response.data)
      setOriginal(JSON.stringify(response.data))
      setReason('')
      setMessage(`Company profile published as configuration version ${response.data.settings_version ?? 'current'}.`)
    } catch (caught) { setError(caught instanceof Error ? caught.message : 'Unable to save company profile.') }
  }

  if (error && !form) return <SettingsState message={error} />
  if (!form) return <SettingsState message="Loading company profile…" />
  const changed = JSON.stringify(form) !== original

  return <main className="sb-settings-detail" aria-labelledby="company-profile-title"><header className="sb-settings-detail-header"><Link to="/settings" className="sb-settings-back">← Settings</Link><div><h1 id="company-profile-title"><Building2 size={27} /> Company Profile</h1><p>Maintain company identity and safe defaults without rewriting historical operational documents.</p></div></header>{message && <div className="sb-settings-success" role="status">{message}</div>}{error && <div className="sb-settings-error" role="alert">{error}</div>}<form onSubmit={save} className="sb-settings-detail-grid"><section className="sb-settings-panel"><PanelHeading icon={<Building2 />} title="Business identity" /><div className="sb-settings-form"><Field label="Business name" value={form.name} onChange={(value) => update('name', value)} required /><Field label="Legal name" value={form.legal_name ?? ''} onChange={(value) => update('legal_name', value)} /><Field label="Primary contact name" value={form.primary_contact_name ?? ''} onChange={(value) => update('primary_contact_name', value)} /><Field label="Primary contact email" type="email" value={form.primary_contact_email ?? ''} onChange={(value) => update('primary_contact_email', value)} /><Field label="Primary contact phone" value={form.primary_contact_phone ?? ''} onChange={(value) => update('primary_contact_phone', value)} /></div></section><section className="sb-settings-panel"><PanelHeading icon={<Globe2 />} title="Locale & fiscal behavior" /><div className="sb-settings-form"><Field label="Currency" value={form.currency} onChange={(value) => update('currency', value.toUpperCase())} required /><Field label="Time zone" value={form.timezone} onChange={(value) => update('timezone', value)} required /><Field label="Locale" value={form.locale} onChange={(value) => update('locale', value)} required /><label>Fiscal year start month<select value={form.fiscal_year_start_month ?? ''} onChange={(event) => update('fiscal_year_start_month', event.target.value ? Number(event.target.value) : null)}><option value="">Use safe default</option>{Array.from({ length: 12 }, (_, index) => <option key={index + 1} value={index + 1}>{new Date(2020, index, 1).toLocaleString(undefined, { month: 'long' })}</option>)}</select></label><Field label="Date format" value={form.date_format ?? ''} onChange={(value) => update('date_format', value)} placeholder="locale-default" /><Field label="Number format" value={form.number_format ?? ''} onChange={(value) => update('number_format', value)} placeholder="locale-default" /><label>Paper size<select value={form.paper_size ?? 'A4'} onChange={(event) => update('paper_size', event.target.value)}><option value="A4">A4</option><option value="Letter">Letter</option></select></label></div></section><section className="sb-settings-panel"><PanelHeading icon={<FileText />} title="Branding & legal references" /><div className="sb-settings-form"><Field label="Logo URL / attachment reference" value={form.branding?.logo_url ?? ''} onChange={(value) => updateObject('branding', 'logo_url', value)} placeholder="Approved storage reference" /><Field label="Document title" value={form.branding?.document_title ?? ''} onChange={(value) => updateObject('branding', 'document_title', value)} /><Field label="Tax registration name" value={form.tax_registration_references?.registration_name ?? ''} onChange={(value) => updateObject('tax_registration_references', 'registration_name', value)} /><Field label="Tax registration reference" value={form.tax_registration_references?.tax_id ?? ''} onChange={(value) => updateObject('tax_registration_references', 'tax_id', value)} /><label className="sb-check-row"><input type="checkbox" checked={form.document_preferences?.show_tax_breakdown ?? false} onChange={(event) => updateObject('document_preferences', 'show_tax_breakdown', event.target.checked)} /> Show tax breakdown on new documents</label><label className="sb-check-row"><input type="checkbox" checked={form.document_preferences?.include_contact ?? true} onChange={(event) => updateObject('document_preferences', 'include_contact', event.target.checked)} /> Include principal contact on documents</label></div></section><section className="sb-settings-panel"><PanelHeading icon={<Building2 />} title="Publication control" /><p className="sb-settings-note">Current configuration version: {form.settings_version ?? 1}. Material profile saves publish a new version and retain prior values in administrative history.</p><label>Reason for change<textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} placeholder="Explain the business reason (recommended)" /></label><button type="submit" className="sb-settings-primary" disabled={!changed}>{changed ? 'Publish company profile' : 'No changes to publish'}</button></section></form></main>
}

function Field({ label, value, onChange, type = 'text', required = false, placeholder }: { label: string; value: string; onChange: (value: string) => void; type?: string; required?: boolean; placeholder?: string }) { return <label>{label}<input type={type} value={value} required={required} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} /></label> }
function PanelHeading({ icon, title }: { icon: ReactNode; title: string }) { return <header className="sb-settings-panel-heading"><span>{icon}</span><h2>{title}</h2></header> }
function SettingsState({ message }: { message: string }) { return <main className="sb-settings-detail"><div className="sb-settings-state">{message}</div></main> }

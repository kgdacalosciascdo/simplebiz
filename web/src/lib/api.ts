const configuredApiUrl = import.meta.env.VITE_API_URL?.trim().replace(/\/+$/, '')
// The local XAMPP document root serves Laravel from backend/public. Deployments
// must provide VITE_API_URL explicitly, so this fallback only affects local dev.
const API_URL = configuredApiUrl || (import.meta.env.DEV ? 'http://localhost/simplebiz/backend/public/api/v1' : '')

if (!API_URL) {
  throw new Error('VITE_API_URL must be configured for a production build.')
}

type ApiOptions = RequestInit & { skipAuth?: boolean }

export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { skipAuth, headers, ...requestOptions } = options
  const token = window.localStorage.getItem('simplebiz_token')
  const companyId = window.localStorage.getItem('simplebiz_company_id')
  const isFormData = requestOptions.body instanceof FormData

  const response = await fetch(`${API_URL}${path.startsWith('/') ? path : `/${path}`}`, {
    ...requestOptions,
    headers: {
      Accept: 'application/json',
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token && !skipAuth ? { Authorization: `Bearer ${token}` } : {}),
      ...(companyId && !skipAuth ? { 'X-Company-ID': companyId } : {}),
      ...headers,
    },
  })

  const body = (await response.json().catch(() => ({}))) as T & { message?: string }
  if (!response.ok) {
    throw new Error(body.message ?? 'The request could not be completed.')
  }

  return body
}

export function saveToken(token: string) {
  window.localStorage.setItem('simplebiz_token', token)
}

export function clearToken() {
  window.localStorage.removeItem('simplebiz_token')
  window.localStorage.removeItem('simplebiz_company_id')
}

export function saveCompanyId(companyId: number | string) {
  window.localStorage.setItem('simplebiz_company_id', String(companyId))
}

export function hasToken() {
  return Boolean(window.localStorage.getItem('simplebiz_token'))
}

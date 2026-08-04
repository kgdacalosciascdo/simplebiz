import { beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, clearToken, hasToken, saveCompanyId, saveToken } from './api'

describe('SimpleBIZ API client', () => {
  beforeEach(() => { window.localStorage.clear(); vi.restoreAllMocks() })

  it('stores and clears authentication and company context', () => {
    saveToken('token'); saveCompanyId(12)
    expect(hasToken()).toBe(true)
    expect(window.localStorage.getItem('simplebiz_company_id')).toBe('12')
    clearToken()
    expect(hasToken()).toBe(false)
    expect(window.localStorage.getItem('simplebiz_company_id')).toBeNull()
  })

  it('sends the current token and company hint without trusting either server-side', async () => {
    saveToken('token'); saveCompanyId(7)
    const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: { ok: true } }), { status: 200 }))
    await apiFetch('/context/company')
    expect(fetchMock).toHaveBeenCalledWith('http://localhost/simplebiz/backend/public/api/v1/context/company', expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer token', 'X-Company-ID': '7' }) }))
  })

  it('exposes safe API errors to callers', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ message: 'Forbidden' }), { status: 403 }))
    await expect(apiFetch('/settings/company')).rejects.toThrow('Forbidden')
  })
})

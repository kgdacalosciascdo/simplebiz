import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, Check, LoaderCircle, Search, X } from 'lucide-react'
import { apiFetch, newIdempotencyKey } from '../../lib/api'

type SearchItem = {
  type: string
  id: string | number
  label: string
  description: string
  status?: string | null
  route?: string | null
}

type NotificationItem = {
  id: string
  title: string
  body: string
  source_module: string
  severity: string
  route?: string | null
  read_at?: string | null
  created_at?: string | null
}

type Envelope<T> = { data: T }

export function CoreShellEnhancements() {
  const [mode, setMode] = useState<'search' | 'notifications' | null>(null)
  const [query, setQuery] = useState('')
  const [searchItems, setSearchItems] = useState<SearchItem[]>([])
  const [searchLoading, setSearchLoading] = useState(false)
  const [searchError, setSearchError] = useState('')
  const [notifications, setNotifications] = useState<NotificationItem[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [notificationError, setNotificationError] = useState('')
  const navigate = useNavigate()

  useEffect(() => {
    const searchButton = document.querySelector('.sb-global-search')
    const notificationButton = document.querySelector('button[aria-label="Notifications"]')
    const openSearch = () => setMode((current) => current === 'search' ? null : 'search')
    const openNotifications = () => setMode((current) => current === 'notifications' ? null : 'notifications')
    searchButton?.addEventListener('click', openSearch)
    notificationButton?.addEventListener('click', openNotifications)
    const handleKeyDown = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setMode('search')
      }
      if (event.key === 'Escape') setMode(null)
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => {
      searchButton?.removeEventListener('click', openSearch)
      notificationButton?.removeEventListener('click', openNotifications)
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [])

  useEffect(() => {
    const validQuery = mode === 'search' && query.trim().length >= 2
    const timer = window.setTimeout(() => {
      if (!validQuery) {
        setSearchItems([])
        setSearchError('')
        setSearchLoading(false)
        return
      }
      setSearchLoading(true)
      setSearchError('')
      apiFetch<Envelope<{ items: SearchItem[] }>>(`/search?q=${encodeURIComponent(query.trim())}&limit=12`)
        .then((response) => setSearchItems(response.data.items))
        .catch((error: unknown) => setSearchError(error instanceof Error ? error.message : 'Search is unavailable.'))
        .finally(() => setSearchLoading(false))
    }, validQuery ? 220 : 0)

    return () => window.clearTimeout(timer)
  }, [mode, query])

  useEffect(() => {
    let mounted = true
    apiFetch<Envelope<{ items: NotificationItem[]; unread_count: number }>>('/notifications')
      .then((response) => {
        if (!mounted) return
        setNotifications(response.data.items)
        setUnreadCount(response.data.unread_count)
      })
      .catch(() => undefined)
    return () => { mounted = false }
  }, [])

  useEffect(() => {
    const dot = document.querySelector('.sb-notification-dot')
    if (dot) dot.textContent = unreadCount > 99 ? '99+' : String(unreadCount)
  }, [unreadCount])

  function close() {
    setMode(null)
    setQuery('')
  }

  function visit(route?: string | null) {
    if (route) navigate(route)
    close()
  }

  async function markRead(item: NotificationItem) {
    if (item.read_at) return
    try {
      await apiFetch(`/notifications/${item.id}/read`, { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({}) })
      setNotifications((current) => current.map((entry) => entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry))
      setUnreadCount((current) => Math.max(0, current - 1))
    } catch (error: unknown) {
      setNotificationError(error instanceof Error ? error.message : 'The notification could not be updated.')
    }
  }

  async function markAllRead() {
    if (unreadCount === 0) return
    try {
      await apiFetch('/notifications/read-all', { method: 'POST', headers: { 'Idempotency-Key': newIdempotencyKey() }, body: JSON.stringify({}) })
      setNotifications((current) => current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })))
      setUnreadCount(0)
    } catch (error: unknown) {
      setNotificationError(error instanceof Error ? error.message : 'Notifications could not be updated.')
    }
  }

  return <>
    {mode === 'search' && <div className="sb-core-search-layer" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) close() }}>
      <section className="sb-core-search-dialog" role="dialog" aria-modal="true" aria-label="Global search">
        <div className="sb-core-dialog-heading"><div><strong>Search your workspace</strong><small>Only records available in your active company and permission scope are shown.</small></div><button type="button" className="sb-core-close" onClick={close} aria-label="Close search"><X size={18} /></button></div>
        <label className="sb-core-search-input"><Search size={18} aria-hidden="true" /><span className="sr-only">Search records</span><input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Customers, sales, products, and records..." /></label>
        {searchLoading && <div className="sb-core-state"><LoaderCircle className="sb-spinner" size={18} /> Searching...</div>}
        {!searchLoading && searchError && <div className="sb-core-error">{searchError}</div>}
        {!searchLoading && !searchError && query.trim().length < 2 && <div className="sb-core-state">Type at least two characters to search.</div>}
        {!searchLoading && !searchError && query.trim().length >= 2 && searchItems.length === 0 && <div className="sb-core-state">No authorized records matched this search.</div>}
        {!searchLoading && searchItems.length > 0 && <div className="sb-core-result-list">{searchItems.map((item) => <button type="button" className="sb-core-result" key={`${item.type}:${item.id}`} onClick={() => visit(item.route)}><span className="sb-core-result-copy"><strong>{item.label}</strong><small>{item.description} · {item.type.replaceAll('_', ' ')}</small></span><span className="sb-core-result-status">{item.status ?? 'Open'}<ArrowRight size={15} /></span></button>)}</div>}
      </section>
    </div>}
    {mode === 'notifications' && <div className="sb-core-notification-layer" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) close() }}>
      <section className="sb-core-notification-popover" role="dialog" aria-label="Notifications">
        <div className="sb-core-dialog-heading"><div><strong>Notifications</strong><small>{unreadCount ? `${unreadCount} unread item${unreadCount === 1 ? '' : 's'}` : 'You are all caught up.'}</small></div><button type="button" className="sb-core-close" onClick={close} aria-label="Close notifications"><X size={18} /></button></div>
        {notificationError && <div className="sb-core-error">{notificationError}</div>}
        {notifications.length === 0 && <div className="sb-core-state">No in-app notifications yet.</div>}
        {notifications.length > 0 && <div className="sb-core-notification-list">{notifications.map((item) => <button type="button" className={`sb-core-notification ${item.read_at ? '' : 'sb-core-notification-unread'}`} key={item.id} onClick={() => { void markRead(item); visit(item.route) }}><span className="sb-core-notification-copy"><strong>{item.title}</strong><small>{item.body}</small><em>{item.source_module} · {item.created_at ? new Date(item.created_at).toLocaleString() : 'Just now'}</em></span>{item.read_at ? <Check size={15} aria-label="Read" /> : <span className="sb-core-unread-dot" aria-label="Unread" />}</button>)}</div>}
        <div className="sb-core-notification-footer"><button type="button" className="sb-core-text-button" onClick={() => void markAllRead()} disabled={unreadCount === 0}>Mark all as read</button><span>In-app only</span></div>
      </section>
    </div>}
  </>
}

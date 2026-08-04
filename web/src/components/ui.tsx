import type { ButtonHTMLAttributes, HTMLAttributes, ReactNode } from 'react'

export function Button({ secondary = false, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { secondary?: boolean }) {
  return <button className={`${secondary ? 'sb-button-secondary' : 'sb-button'} ${className}`} {...props} />
}

export function Card({ children, className = '', ...props }: { children: ReactNode; className?: string } & HTMLAttributes<HTMLElement>) {
  return <section className={`sb-panel ${className}`} {...props}>{children}</section>
}

export function Badge({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }) {
  return <span className={`sb-badge sb-badge-${tone}`}>{children}</span>
}

export function EmptyState({ title, detail, action }: { title: string; detail: string; action?: ReactNode }) {
  return <div className="sb-empty" role="status"><div className="sb-empty-icon" aria-hidden="true">○</div><h3>{title}</h3><p>{detail}</p>{action}</div>
}

export function LoadingPanel({ label = 'Loading workspace data…' }: { label?: string }) {
  return <div className="sb-loading" role="status" aria-live="polite"><span className="sb-spinner" aria-hidden="true" />{label}</div>
}

export function ErrorPanel({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return <div className="sb-error" role="alert"><strong>We couldn’t load this panel.</strong><span>{message}</span>{onRetry && <button type="button" onClick={onRetry}>Try again</button>}</div>
}

export function PageHeader({ icon, title, subtitle, context, action }: { icon: string; title: string; subtitle: string; context?: string; action?: ReactNode }) {
  return <div className="sb-page-header"><div className="flex min-w-0 items-start gap-3"><span className="sb-page-icon" aria-hidden="true">{icon}</span><div className="min-w-0"><h1>{title}</h1><p>{subtitle}</p>{context && <span className="sb-page-context">{context}</span>}</div></div>{action}</div>
}

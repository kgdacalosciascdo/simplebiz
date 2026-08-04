import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { StatementImportsWorkspace, ReconciliationWorkspace } from './CashReconciliationWorkspace'

vi.mock('../../lib/api', () => ({
  apiFetch: vi.fn((path: string) => Promise.resolve({ data: path.includes('statement-imports') ? [] : [] })),
}))

describe('Cash Accounts reconciliation workspaces', () => {
  afterEach(() => cleanup())

  it('shows the controlled manual statement import workflow', () => {
    render(<StatementImportsWorkspace onBack={() => undefined} />)
    expect(screen.getByRole('heading', { name: 'Statement Imports' })).toBeInTheDocument()
    expect(screen.getByText(/controlled manual lines/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Create import batch' })).toBeInTheDocument()
  })

  it('shows the reconciliation list and ready-batch creation control', () => {
    render(<ReconciliationWorkspace onBack={() => undefined} />)
    expect(screen.getByRole('heading', { name: 'Reconciliations' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Create reconciliation' })).toBeInTheDocument()
    expect(screen.getByText('No reconciliations yet.')).toBeInTheDocument()
  })
})

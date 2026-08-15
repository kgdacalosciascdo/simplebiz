export const demoSummary = {
  account_count: 4,
  active_account_count: 4,
  restricted_account_count: 0,
  draft_account_count: 0,
  position_by_currency: [{ currency_code: 'PHP', account: 'Main Operating Bank', posted_balance: '551216.50' }],
  as_of: '2026-08-04T09:30:00+08:00',
}

export const demoAttention = {
  draft_accounts: [],
  opening_balances_pending: [{ id: 'ob-demo-1' }],
  configuration: { ready_for_posting: true },
  reconciliations_pending: 2,
  cash_count_variances: 1,
  accounts_without_custodian: 1,
  statement_imports_pending: 1,
}

export const demoAccounts = [
  { id: 'demo-account-1', code: 'BANK-001', name: 'Main Operating Bank', type: 'Bank account', currency: 'PHP', balance: '425680.50' },
  { id: 'demo-account-2', code: 'CASH-001', name: 'Petty Cash', type: 'Petty cash', currency: 'PHP', balance: '18450.00' },
  { id: 'demo-account-3', code: 'CASH-002', name: 'Cash Drawer', type: 'Cash drawer', currency: 'PHP', balance: '32875.25' },
  { id: 'demo-account-4', code: 'WALLET-001', name: 'Digital Wallet', type: 'Digital wallet', currency: 'PHP', balance: '74210.75' },
]

export const demoMovementTrend = [
  { period: 'Jul 08', cashIn: 82000, cashOut: 54000 }, { period: 'Jul 15', cashIn: 94000, cashOut: 62000 },
  { period: 'Jul 22', cashIn: 76000, cashOut: 71000 }, { period: 'Jul 29', cashIn: 111000, cashOut: 68000 },
  { period: 'Aug 05', cashIn: 87000, cashOut: 59000 }, { period: 'Aug 12', cashIn: 123000, cashOut: 74000 },
]

export const demoMovements = [
  { id: 'movement-demo-1', document_number: 'CM-2026-0008', business_date: '2026-08-04', direction: 'increase', amount: '45000.00', currency: 'PHP', account: 'Main Operating Bank', purpose: 'Customer deposit', status: 'posted' },
  { id: 'movement-demo-2', document_number: 'CM-2026-0007', business_date: '2026-08-03', direction: 'decrease', amount: '12500.00', currency: 'PHP', account: 'Petty Cash', purpose: 'Office supplies', status: 'posted' },
  { id: 'movement-demo-3', document_number: 'TR-2026-0004', business_date: '2026-08-02', direction: 'increase', amount: '30000.00', currency: 'PHP', account: 'Cash Drawer', purpose: 'Internal transfer', status: 'posted' },
  { id: 'movement-demo-4', document_number: 'CM-2026-0006', business_date: '2026-08-01', direction: 'decrease', amount: '8750.00', currency: 'PHP', account: 'Digital Wallet', purpose: 'Withdrawal', status: 'posted' },
  { id: 'movement-demo-5', document_number: 'OB-2026-0001', business_date: '2026-07-31', direction: 'increase', amount: '185000.00', currency: 'PHP', account: 'Main Operating Bank', purpose: 'Opening balance', status: 'posted' },
]

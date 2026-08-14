# MDS-800 Phase 9A — Expenses Core Foundation

Status: implemented for the bounded direct-expense vertical slice. This phase does not claim full MDS-800 completion.

The implementation follows the authoritative MDS-800 specification and preserves module ownership: MDS-800 owns the expense record, classification, evidence, approval state, obligation, duplicate control, and payment-readiness projection; MDS-500 owns payment execution and allocation; MDS-700 owns cash movement and cash balance effects; MDS-400 owns procurement; MDS-1000 owns shared registries; MDS-1100 owns configurable policy.

## Implemented scope

- Expense Record identity, company scope, business date, payee snapshot, currency, branch, description, settlement intent, payment term, due date, status, version, correlation and idempotency identity.
- Expense Lines with category/account classification, quantity, unit amount, server-owned tax calculation, recoverable/non-recoverable tax split, and line allocations.
- Server-owned subtotal, taxable amount, tax, recoverable tax, non-recoverable tax, withholding, total, paid and remaining amounts. Client-supplied totals are not accepted.
- Paid Now and Pay Later intent. Reimbursement Claims remain explicitly deferred to the next MDS-800 increment.
- Duplicate candidate detection using same-company payee/currency/date/total and exact external-reference matching. An authorized override requires a reason and is retained on the Expense Record.
- Evidence metadata and governed file upload through the existing shared `AttachmentService`. Evidence is deduplicated by the existing attachment hash boundary; no second file store was introduced.
- Draft, Submitted/For Approval, Returned, Approved, Rejected, Payment Ready, Scheduled, Partially Paid, Paid, Cancelled and Closed lifecycle paths for the bounded slice, with version checks, status history, audit records and after-commit lifecycle events.
- Posting creates one balanced accounting transaction: expense and applicable tax debits against a payable/accrued-liability credit. Posting creates an `ExpenseObligation`; it does not create cash.
- Payment-ready obligations are handed to MDS-500 through `payment_instruction_sources.expense_obligation_id`. Settlement synchronization updates the Expense and Obligation only after MDS-500 allocation; no second payable, payment, cash or attachment store exists.
- Company-scoped permissions, idempotency wrappers, optimistic version checks, row locking at lifecycle and settlement boundaries, and same-company registry validation.

## API surface

- `GET /api/v1/expenses/lookups`
- `GET /api/v1/expenses/summary`
- `GET /api/v1/expenses/attention`
- `GET /api/v1/expenses/history` and `GET /api/v1/expenses/unpaid`
- `GET /api/v1/expenses/obligations` and `GET /api/v1/expenses/obligations/{id}`
- `POST /api/v1/expenses/calculate`
- `GET|POST /api/v1/expenses`, `GET|PATCH /api/v1/expenses/{id}`
- `POST /api/v1/expenses/{id}/submit|review|approve|reject|return|post|cancel|close`
- `POST /api/v1/expenses/{id}/pay`
- `POST /api/v1/expenses/{id}/evidence`, `GET /api/v1/expenses/{id}/evidence`, and governed evidence download

Payment integration adds nullable, mutually exclusive Expense Obligation ownership to MDS-500 payment sources and allocations. Existing supplier-payable ownership remains unchanged.

## Traceability matrix

| Requirement area | State | Evidence / boundary |
| --- | --- | --- |
| Expense Record and line identity | IMPLEMENTED | `expenses`, `expense_lines`, `ExpenseService`, UUID/company constraints |
| Category/account/tax classification | IMPLEMENTED | MDS-1000 lookup validation, category account mapping, server BCMath totals |
| Minimum allocations | IMPLEMENTED | Default 100% line allocation and exact 100% validation |
| Duplicate detection and authorized override | IMPLEMENTED for Phase 9A core | `expense_duplicate_candidates`, exact/probable states, permissioned reason |
| Evidence metadata/upload | IMPLEMENTED | `expense_evidences`, shared `attachments`, hash deduplication, and the responsive recording form upload |
| Approval workflow | IMPLEMENTED when the Expense is marked approval-required | Maker-checker creator restriction and approval history; configurable policy/edition source remains MDS-1100 |
| Paid Now / Pay Later | IMPLEMENTED | Paid Now prepares an MDS-500 source; Pay Later creates a payment-ready obligation |
| Due dates and payment readiness | IMPLEMENTED | Payment-term/manual due date, due status, remaining amount, readiness projection |
| MDS-500 handoff and settlement sync | IMPLEMENTED | Expense-owned payment source/allocations and `ExpenseSettlementService` |
| Accounting posting | IMPLEMENTED for configured core accounts | Balanced expense/tax/liability transaction; MDS-700 cash is not touched at posting |
| Cash movement and cash balance | OWNER MODULE MDS-700 | Only confirmed MDS-500 payment execution can create cash movement |
| Supplier payable storage/allocation | OWNER MODULE MDS-400/MDS-500 | Expense obligations are distinct and linked to MDS-500; no duplicate supplier payable ledger |
| Permissions, audit, events, idempotency, concurrency | IMPLEMENTED | Phase permission catalog, AuditService, after-commit event, Idempotency-Key and locked paths |
| Responsive workspace | IMPLEMENTED for Phase 9A surface | Live TanStack Query reads, TanStack Table, Recharts totals, Lucide icons, mobile-safe layout |
| Reimbursements, recurring expenses, credits/refunds, bulk/import/OCR/AI | DEFERRED TO PHASE 9B | No tables, fake records or undocumented workflow was added |
| Advanced reports, templates, scheduled jobs and policy engine | DEFERRED / OWNER MODULE | MDS-900 and MDS-1100 remain authoritative owners |

## Database migrations

- `2026_08_14_000040_create_expenses_phase9a`
- `2026_08_14_000041_integrate_expenses_with_payments_phase9a`

Both migrations are forward-only additions to the existing model. The local migration run completed successfully; no existing business migration was modified.

## Frontend

`/expenses` now renders `ExpensesWorkspace`, with live summary, attention, history and unpaid reads, a responsive recording form, server-calculated totals, empty/loading-safe states, TanStack Table history, Recharts period totals, and Lucide icon actions. The workspace does not fabricate demo expense records.

## Validation

- Focused `ExpensesPhase9ATest`: 3 passed, 27 assertions.
- Complete backend suite: 71 passed, 1 intentional PostgreSQL-only skip, 677 assertions.
- Frontend Vitest: 16 passed across 9 files.
- Frontend ESLint, TypeScript/Vite build and PHP syntax checks passed.
- Docker validation remains unavailable when the Docker Desktop daemon is not running. Render CLI validation remains unavailable when the Render CLI is not installed.

## Explicit non-goals

Phase 9A does not modify Render configuration, purchase/accounting ownership outside the Expense posting contract, MDS-700 cash behavior, permissions outside the Expenses catalog, or existing business migrations. Phase 9B is still required for the deferred MDS-800 areas above.

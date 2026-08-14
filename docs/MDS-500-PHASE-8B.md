# MDS-500 Phase 8B - Payments & Disbursements Completion

Status date: 2026-08-14  
Branch: `develop`  
Checkpoint: `backups/simplebiz-before-phase8b-payments-completion-20260814-123839.zip`

## Scope review

Phase 8A was reviewed against the authoritative MDS-500 specification, the MDS-400 payable source, the MDS-700 cash movement service, the accounting boundary, the governed attachment service, and the Payments workspace. Verified gaps were payment correction chains, allocation correction, supplier advances, payment batches, fuller check custody, controlled recovery, duplicate review, operational reports/attention, disbursement vouchers, and payment evidence integration.

MDS-500 remains the owner of payment intent, execution state, instruments, confirmation, allocation, correction orchestration, batches, vouchers, and payment evidence. MDS-400 remains the payable owner. MDS-700 remains the cash movement, balance, clearing, and reconciliation owner. MDS-1100 remains the owner of payment-method, checkbook, numbering, and approval policy configuration. MDS-900 remains the owner of the shared report engine and export/scheduling infrastructure.

## Traceability matrix

| MDS-500 area | Phase 8B status | Evidence and boundary |
| --- | --- | --- |
| Supplier payable intake and payment-ready source context | IMPLEMENTED | Existing Phase 8A workbench and `payment_instruction_sources` consume company-scoped MDS-400 Payable Open Items. |
| Payment Request, Instruction, approval, scheduling, release, confirmation, failure, pending | IMPLEMENTED | Existing Phase 8A services, lifecycle histories, attempts, confirmations, permissions, idempotency, and tests remain passing. |
| Draft cancellation and unreleased void | IMPLEMENTED | Reason-controlled `PaymentCorrection` records preserve the original Payment; no cash movement or payable settlement is created. |
| Confirmed Payment reversal | IMPLEMENTED | `PaymentCompletionService::reverse` creates a linked correction, reverses the MDS-700 movement through `CashMovementService`, reverses allocations, restores MDS-400 effects, and blocks duplicate/cleared/reconciled reversal. |
| Allocation unapplication | IMPLEMENTED | Original allocation is preserved; a linked counter allocation and positive PayableEffect restore the payable and payment availability without changing cash. |
| Allocation reallocation | IMPLEMENTED | One transaction un-applies the original allocation and applies the requested same-supplier, same-currency target allocation. |
| Unallocated Payment projection | IMPLEMENTED | Available/unapplied amounts remain derived from confirmed Payments and are exposed by attention and operational report queries. |
| Supplier Advance | IMPLEMENTED for the documented supplier source | A confirmed supplier-advance Payment owns `payment_advances`; later application uses a governed allocation and configured Supplier Advances to A/P reclassification. No Supplier Invoice is fabricated. Employee/expense advances remain deferred to MDS-800/source modules. |
| Payment Batch create, compatibility, server total, maker-checker approval | IMPLEMENTED | `payment_batches`, item rows, status history, company/method/account/currency compatibility, server BCMath total, separation of preparer and approver. |
| Payment Batch validate, generate, release, execute, partial outcomes, close | IMPLEMENTED | Forward migration `000038` adds generated/closed actors/timestamps; item outcomes remain individually traceable and batch close requires final item disposition. External file/provider generation is intentionally not fabricated. |
| Check number reservation and uniqueness | IMPLEMENTED | Existing company/Cash Account/check-number constraint is retained; replacement instruments receive a new number and old instruments remain immutable. |
| Check print, sign, release, stop, void, stale, replacement | IMPLEMENTED | Instrument lifecycle fields, server-authoritative stale period, reason/evidence controls, replacement links, separate Payment status, and audit records are implemented. |
| MDS-700 clearing/reconciliation state | DEFERRED TO OWNER MODULE | Check resources expose linked clearing/reconciliation state read-only; Payments never marks a movement cleared or reconciled. |
| Electronic/manual recovery | IMPLEMENTED at controlled manual boundary | Pending/failed execution attempts require reason and evidence; retry is blocked after a successful attempt and unknown results are never blindly retried. No fake provider success is created. |
| Duplicate-payment control | IMPLEMENTED | Server duplicate-risk check blocks matching supplier/currency/amount/date/reference combinations; explicit override requires review permission and reason. |
| Manual confirmation hardening | IMPLEMENTED | Non-cash confirmation requires external reference, reason, and evidence; cash confirmation retains acknowledgement/evidence requirements and all confirmation remains idempotent. |
| Disbursement Voucher | IMPLEMENTED | `payment_vouchers` provides durable voucher identity and audit-only reprint count; issuing/reprinting creates no financial effect. |
| Remittance Advice and payment history | IMPLEMENTED | Existing remittance and timeline surfaces remain source-linked; correction, batch, check, attempt, allocation, and voucher relations are exposed. |
| Payment register, check register, allocation, unallocated, pending reports | IMPLEMENTED at MDS-500 source layer | `/payments/reports` serves company-scoped operational projections with report type, pagination, and as-of metadata. Shared export/scheduling remains MDS-900. |
| Needs Attention and dashboard source measures | IMPLEMENTED | Attention exposes approvals, pending confirmation, failures, unallocated amount, stopped/stale checks, and partial batches; summary exposes eligible, due, overdue, held, scheduled, pending, and unallocated measures. |
| Payment evidence attachments | IMPLEMENTED | Payments now use the existing governed `AttachmentService` with company scope, hash deduplication, confidentiality, audit, list, upload, and download routes. |
| Permissions, tenant scope, audit, events, idempotency, concurrency | IMPLEMENTED | New correction, advance, batch, check, recovery, duplicate-review, voucher, report, and evidence permissions are server-enforced; material events are dispatched inside governed transactions and row locks are used for corrections/batches/checks. |
| MDS-800 expense/reimbursement sources | DEFERRED TO OWNER MODULE | No expense, employee reimbursement, payroll, or fake source records were created. |
| External bank, wallet, gateway, Open Banking, file transmission | NOT APPLICABLE TO THIS INCREMENT | The authoritative scope and requested Phase 8B boundary prohibit fake provider success; integration contracts remain available for a later approved integration phase. |
| MDS-900 shared report engine/export/scheduling | DEFERRED TO OWNER MODULE | MDS-500 provides governed source projections only. |
| MDS-1100 configurable payment/checkbook/approval policy | DEFERRED TO OWNER MODULE | Current behavior consumes existing registries and uses a documented check-stale default where no policy field exists. |

There are no remaining MDS-500-owned GAP items for the implemented SimpleBIZ Free/staging scope. Owner-module and external integration rows above are not MDS-500 gaps.

## Database and API

Forward-only migrations added:

- `2026_08_14_000035_complete_payments_phase8b.php` - correction, advance, batch, item/history, check-control, source-kind, allocation-correction, and Phase 8B permission schema.
- `2026_08_14_000036_allow_check_replacements_phase8b.php` - permits linked replacement check instruments while preserving check-number uniqueness.
- `2026_08_14_000037_add_payment_vouchers_phase8b.php` - durable Disbursement Voucher identity and reprint tracking.
- `2026_08_14_000038_complete_payment_batch_lifecycle_phase8b.php` - batch validation/generation/closure actors and timestamps plus lifecycle permissions.
- `2026_08_14_000039_seed_payment_evidence_permissions_phase8b.php` - payment evidence view/download permissions.

Payment endpoints added or completed:

- `POST /api/v1/payments/{id}/reverse`, `POST /{id}/voucher`, and payment evidence upload/list/download routes.
- `POST /{id}/allocations/{allocationId}/unapply|reallocate`.
- `GET|POST /advances`, `POST /advances/{id}/apply`.
- `GET /checks`, `POST /checks/{id}/{print|sign|release|stop|void|stale|replace}`.
- `GET|POST /batches`, `GET /batches/{id}`, `POST /batches/{id}/items`, and lifecycle actions `validate|submit|approve|generate|release|execute|close|cancel`.
- `GET /attention` and `GET /reports` with payment-register, check-register, allocation-register, unallocated, and pending projections.

No generic Cash Movement API, direct Payable balance API, generic accounting-line API, fake provider endpoint, or MDS-800 API was added.

## Frontend

`web/src/features/payments/PaymentsWorkspace.tsx` remains a responsive Payments workspace using TanStack Query, TanStack Table, Recharts, and Lucide icons. It now presents live correction/exception measures, unallocated payments, supplier advances, batch list/create/validate/submit/approve/generate/release/close actions, check register controls, clearing/reconciliation read-only status, and mobile-safe control cards. Existing loading, empty, error, hover, and disabled states remain in place.

## Validation

- Focused `PaymentsPhase8ATest` + `PaymentsPhase8BTest`: **10 passed, 130 assertions**.
- Complete backend `php artisan test`: **68 passed, 1 intentionally skipped PostgreSQL-only schema test, 650 assertions**.
- `vendor/bin/pint --test`: passed.
- Frontend `npm run lint`: passed.
- Frontend `npm run test -- --run`: **15 passed across 8 files**.
- Frontend `npm run build`: passed, including TypeScript and Vite production build.
- `php artisan migrate --force`: migrations through `2026_08_14_000039_seed_payment_evidence_permissions_phase8b` applied locally.
- `php artisan route:list --path=api/v1/payments`: payment correction, evidence, advance, batch, check, report, attention, and Phase 8A routes registered.
- `git diff --check`: passed.
- Docker image/container validation: unavailable because the Docker Desktop daemon is not running.
- Render Blueprint validation: unavailable because Render CLI is not installed.
- `render.yaml`: unchanged.

## Safety confirmations

MDS-400 remains the Payable owner and MDS-700 remains the Cash Movement/balance/clearing/reconciliation owner. Payable balances and Cash Account balances are not directly edited by a public payment endpoint. Confirmed Payments are corrected only through linked governed actions. Unknown/timed-out Payments are not blindly retried. Check numbers are not silently reused. Payments cannot mark Cash Movements cleared or reconciled. No fake bank success, MDS-800 Expense records, unsupported payment functionality, demo payment data, deployment changes, staging, commit, or push was introduced.

## Acceptance

`MDS-500 FULLY IMPLEMENTED`

The next official phase may be planned separately as **Phase 9A - MDS-800 Expenses Core Foundation**; it was not started in this run.

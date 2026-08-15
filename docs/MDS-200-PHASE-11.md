# MDS-200 Phase 11 — Sales & Receivables Final Completion

Date: 2026-08-15  
Branch: `develop`  
Scope: MDS-200-owned Sales & Receivables corrections and source reporting.

The authoritative source is `docs/MDS-200 Sales & Receivables Module Design Specification v1.1.docx`. MDS-300 remains the owner of receipts, applications, settlement, and reversal; MDS-600 remains the owner of inventory movements; MDS-700 remains the owner of Cash Accounts; MDS-900 remains the owner of shared report execution and output lifecycle.

## Gap matrix

| Capability | Previous state | Phase 11 result | Owner/status |
|---|---|---|---|
| Credit Sale draft, server totals, mixed Product/Service lines, lifecycle | Existing Phase 4A credit slice | Preserved and regression-tested | MDS-200 implemented |
| Cash/paid-now sale orchestration | Dependency-gated in the existing Sale service | Preserved as a documented handoff to MDS-300/MDS-700; no duplicate receipt or cash engine added | MDS-200 integration gap remains |
| Receivable open item and corrections | Open item existed; no durable Sales correction effect ledger | Added immutable source-owned Sales Receivable Effects with projection refresh and reconciliation fields | MDS-200 implemented; MDS-300 applications remain external |
| Sales Returns | Missing | Added linked return document/lines, eligibility and over-return protection, lifecycle, approval segregation, MDS-600 stock-return handoff, receivable/customer-credit boundary, reversal, audit, events, and permissions | MDS-200 implemented; MDS-500 refund execution remains external |
| Sales Debit/Credit Adjustments | Missing | Added separately numbered linked documents/lines, server totals, direction, lifecycle, approval segregation, effects, audit, events, and reversal | MDS-200 implemented; MDS-500 refund execution remains external |
| Posted Sale reversal | No governed reversal | Added dependency-aware reversal with accounting counter-effect, receivable effect, inventory reversal handoff, reason, version, and audit | MDS-200 implemented for currently supported credit-sale path |
| Billing Statements | Snapshot of positive open items only | Added source snapshot of Sales, Returns, Adjustments, open items, period charges/credits, opening and ending context | MDS-200 implemented for current governed source state |
| Sales source reports | Register/product/aging sources existed; corrections absent | Added returns/adjustments source adapter, direct Sales report endpoint, register correction columns, product return totals, and MDS-900 catalog contract `REP-SAL-003` | MDS-200 source contract implemented |
| Sales workspace | Static reference surface | Added live correction queue and responsive create/submit forms with TanStack Query and governed APIs | MDS-200 UI correction surface implemented |

## Implemented behavior

- Return quantities are positive, server-validated against the original posted Sale line, and cannot exceed the remaining returnable quantity, including concurrent draft/post attempts.
- Return totals are calculated from the authoritative posted Sale line snapshots. Stock-managed returns call the existing Inventory service through `postSalesReturn`; Sales does not edit Inventory balances directly.
- Posted returns reduce the linked receivable through `sales_receivable_effects`. Any excess is recorded as a customer-credit/refund-pending state for the owning Payments module; no refund or Cash Movement is created here.
- Debit adjustments increase the linked receivable. Credit adjustments reduce available receivable and place any excess into the same governed customer-credit/refund-pending boundary.
- Posted correction records are immutable in place. Reversals create counter-accounting/effect records and preserve the original document and history.
- Billing statements capture the source IDs and generation timestamp so a statement is a traceable snapshot rather than a new receivable balance.
- All correction actions are company-scoped, permission-gated, idempotency-capable, version-checked where supplied, audited, and represented in lifecycle history/events.

## Files added or modified

Backend:

- `app/Services/SalesCompletionService.php`
- `app/Services/SalesService.php`
- `app/Services/InventoryService.php`
- `app/Http/Controllers/Api/SalesController.php`
- `routes/api.php`
- `app/Models/SalesReturn.php`, `SalesReturnLine.php`, `SalesReturnStatusHistory.php`
- `app/Models/SalesAdjustment.php`, `SalesAdjustmentLine.php`, `SalesAdjustmentStatusHistory.php`
- `app/Models/SalesReceivableEffect.php`
- `app/Http/Requests/Sales/SalesReturnRequest.php`, `SalesAdjustmentRequest.php`, `SalesCorrectionActionRequest.php`
- `app/Http/Resources/Sales/SalesReturnResource.php`, `SalesReturnLineResource.php`, `SalesAdjustmentResource.php`, `SalesAdjustmentLineResource.php`
- `app/Models/Sale.php`, `ReceivableOpenItem.php`, `BillingStatement.php`
- `app/Http/Resources/Sales/BillingStatementResource.php`
- `database/migrations/2026_08_15_000047_complete_sales_phase11.php`
- `database/migrations/2026_08_15_000048_register_sales_corrections_report.php`
- `tests/Feature/SalesPhase11Test.php`

Frontend:

- `web/src/features/sales/SalesCompletionPanel.tsx`
- `web/src/features/sales/SalesWorkspace.tsx`
- `web/src/index.css`

Documentation:

- `docs/MDS-200-PHASE-11.md`
- `docs/IMPLEMENTATION_STATUS.md`

## Validation

- `php artisan migrate --no-interaction`: migrations `000047` and `000048` applied successfully locally.
- Focused backend tests: `SalesPhase4ATest` and `SalesPhase11Test` — **7 passed, 53 assertions**.
- Frontend `npm run lint`: passed.
- Frontend `npm run test -- --run`: **10 files and 18 tests passed**.
- Frontend `npm run build`: passed.
- TypeScript check: passed.
- Route inspection confirms Sales Return, Sales Adjustment, Sales report, Sale reversal, and existing lifecycle routes are registered with their permission middleware.
- Docker runtime and Render CLI validation were not run in this pass; they require the local Docker daemon and Render CLI respectively.

## Completion gate

`MDS-200 NOT YET FULLY IMPLEMENTED`

Remaining MDS-200-owned gaps are limited to the documented cash/paid-now Sale workflow handoff, complete print/export/statement-history presentation, broader needs-attention/dashboard source wiring, and full Sales workspace coverage for every legacy Sale lifecycle/detail/report surface. Receipts/applications/refunds, Inventory movement implementation, Cash Accounts, and shared report execution remain intentionally owned by MDS-300, MDS-500, MDS-600, MDS-700, and MDS-900. No next official module was started.

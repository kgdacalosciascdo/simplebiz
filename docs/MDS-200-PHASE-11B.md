# MDS-200 Phase 11B — Sales & Receivables Final Closure

Date: 2026-08-15  
Branch: `develop`  
Scope: Close the remaining MDS-200-owned workflow, source-contract, statement-presentation, and Sales-detail gaps identified after Phase 11.

The implementation keeps the existing owner boundaries: MDS-300 owns Receipt, Tender, Payment Application, and collection settlement; MDS-600 owns stock movement; MDS-700 owns Cash Account effects; MDS-900 owns shared report execution and export lifecycle; MDS-100 owns dashboard composition; and MDS-1000 owns shared master data.

## Closure matrix

| MDS-200 requirement | Phase 11 starting point | Phase 11B closure | Result |
|---|---|---|---|
| Cash / paid-now orchestration | Cash Sale posting stopped at the Collections dependency boundary | `SalesPaidNowService` commercially posts an approved cash Sale, then invokes the existing MDS-300 paid-now Receipt/Application path. MDS-300 invokes the existing Inventory and Cash owner effects. The outer transaction rolls back the Sale, receivable, receipt, application, stock, cash, and accounting effects on failure. | Implemented |
| Exactly-once and safe retry | No integrated completion endpoint | `POST /sales/{id}/paid-now` is idempotency-wrapped, source-linked, amount/version checked, and refuses a conflicting second receipt. Replays return the existing posted receipt without a second cash movement or application. | Implemented |
| Partial paid-now settlement | No integrated path | Paid-now amount may be less than the Sale total; MDS-300 application state and the MDS-200 Sale/open-item remaining balance stay partially paid. | Implemented |
| Billing statement historical content | Source IDs and generation totals existed | Statement generation now stores immutable Sale, Return, Adjustment, and Open Item row snapshots, while preserving the source IDs and generation timestamp. The resource exposes `snapshot_open_items`. | Implemented |
| Statement history/detail/print | No complete user-facing presentation | Added responsive `/billing-statements` history and generation workspace plus `/billing-statements/{id}` snapshot detail and browser print/Save-as-PDF presentation. Shared report export remains MDS-900-owned. | Implemented |
| Dashboard and Needs Attention sources | Dashboard was primarily Cash Accounts-driven; Sales workspace used reference fixtures | Added `/sales/dashboard` and `/sales/attention` source contracts with as-of/freshness/currency ownership fields. Dashboard and Sales workspace consume live values; fixture values remain limited to explicit demo mode. | Implemented |
| Sales detail coverage | Sale API/UI did not present all related effects | Enriched Sale detail with lines, lifecycle, receivable, collection receipts, inventory movements, returns, and adjustments, and added responsive `/sales/{id}` UI with governed related links. | Implemented |

## Paid-now ownership and failure behavior

The Sales endpoint does not create a Receipt, Payment Application, Cash Movement, or Stock Movement directly. It calls the existing owner services in one database transaction:

1. MDS-200 validates the approved cash Sale, customer, amount, version, and payment dependency.
2. MDS-200 posts the commercial Sale and linked receivable, invoking existing MDS-600 stock effects where applicable.
3. MDS-300 creates/authorizes/posts the linked `paid_now_sale_receipt` and application.
4. MDS-300 invokes the existing MDS-700 incoming cash effect and balanced receipt accounting.
5. A failed dependency leaves the Sale approved and retryable, with no false posted Sale or partial linked effect.

Approval segregation remains respected for ordinary Sale lifecycle actions. Paid-now completion is available only to an account authorized for both Sales posting and the MDS-300 receipt create/post capabilities. The customer must be an active identified customer or the company’s active Walk-in customer profile; no Walk-in master is created automatically.

## API and UI additions

- `POST /api/v1/sales/{id}/paid-now`
- `GET /api/v1/sales/dashboard`
- `GET /api/v1/sales/attention`
- `GET/POST /api/v1/billing-statements`
- `GET /api/v1/billing-statements/{id}` with immutable source snapshots
- `/sales/{id}` Sales detail screen
- `/billing-statements` statement history/generation screen
- `/billing-statements/{id}` statement detail/print screen

The live Sales and Dashboard surfaces retain TanStack Query/Table, Recharts, and Lucide usage. Statement printing is a presentation operation and does not create financial records; shared PDF/XLSX/CSV report output remains in the MDS-900 report lifecycle.

## Files added or modified in Phase 11B

Backend:

- `app/Http/Requests/Sales/PaidNowSaleRequest.php`
- `app/Services/SalesPaidNowService.php`
- `app/Services/SalesService.php`
- `app/Services/CollectionsService.php`
- `app/Http/Controllers/Api/SalesController.php`
- `app/Http/Resources/Sales/SaleResource.php`
- `app/Http/Resources/Sales/BillingStatementResource.php`
- `app/Models/Sale.php`
- `app/Models/SaleStatusHistory.php`
- `routes/api.php`
- `tests/Feature/SalesPhase11BTest.php`

Frontend:

- `web/src/features/sales/SalesPaidNowPanel.tsx`
- `web/src/features/sales/SalesDetailPage.tsx`
- `web/src/features/sales/BillingStatementsPage.tsx`
- `web/src/features/sales/SalesWorkspace.tsx`
- `web/src/features/dashboard/DashboardPage.tsx`
- `web/src/App.tsx`
- `web/src/index.css`

Documentation:

- `docs/MDS-200-PHASE-11B.md`
- `docs/IMPLEMENTATION_STATUS.md`

## Validation

- Focused Sales tests: **11 passed, 89 assertions** across Sales Phase 4A, Phase 11, and Phase 11B.
- Complete backend suite: **90 passed, 1 intentionally skipped PostgreSQL-only schema test, 882 assertions**.
- `npm ci`: passed. npm reported two high-severity dependency advisories; no automatic audit fix was applied because dependency upgrades are outside this business-scope change.
- Frontend Vitest: **18 tests passed across 10 files**.
- `npm run lint`: passed.
- `npm run build`: passed, including TypeScript compilation and Vite production output.
- `vendor/bin/pint --test`: passed.
- `git diff --check`: passed.
- Migration status is clean through `2026_08_15_000048_register_sales_corrections_report`.
- Docker and Render CLI validation were not run because this phase did not change deployment configuration; no business functionality outside the documented MDS-200 scope was modified.

## Completion gate

`MDS-200 FULLY IMPLEMENTED`

This gate applies to the currently documented MDS-200 application scope. Owner-boundary capabilities remain with MDS-300, MDS-500, MDS-600, MDS-700, MDS-900, and MDS-1000 as documented; no duplicate owner engine or next official module was started.

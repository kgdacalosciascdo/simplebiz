# MDS-900 Reports & Analytics — Phase 10A

Status: implemented shared reporting foundation; MDS-900 completion remains deferred.

Checkpoint: `backups/simplebiz-before-phase10a-reports-foundation-20260814-150000.zip`

Branch: `develop`. The implementation preserves the existing Render, Docker, Nginx, PostgreSQL, and deployment configuration. No files were staged, committed, or pushed.

## Boundary and doctrine

Operational modules remain authoritative for business facts and report meaning. MDS-900 stores governed report metadata and orchestrates access to source-owned query services. It does not create a sales ledger, receivables ledger, payables ledger, payment ledger, stock ledger, cash ledger, expense ledger, arbitrary SQL endpoint, or editable balance store.

The shared path is:

`Report Definition → Source Contract → validated parameters/context → Report Request → source adapter → Display/Export Output → drill-down/history/snapshot`

## Phase 10A traceability

| Requirement area | Phase 10A disposition | Evidence / boundary |
| --- | --- | --- |
| Catalog, search, category, source, sensitivity, output capabilities | IMPLEMENTED | `GET /api/v1/reports/catalog`; governed `report_definitions` and `report_categories` |
| Versioned Report Definition Registry | IMPLEMENTED | Stable `definition_key` + `version`, published/effective fields, historical version columns, supersedes metadata |
| Source Contracts | IMPLEMENTED | `report_source_contracts`; source adapter, permission, schema, freshness, reconciliation, drill-down metadata |
| Shared parameters and context | IMPLEMENTED | Definition-owned date/as-of/paging parameters; server-resolved company, user, timezone, locale, currency and as-of context |
| Request lifecycle | IMPLEMENTED | Requested → Validated → Processing → Completed/Failed; retained failure code/message and status endpoint |
| Synchronous generation | IMPLEMENTED | `ReportsService` uses allow-listed source adapters and persists outputs |
| Asynchronous foundation | FOUNDATION ONLY | Durable request statuses and output metadata exist; no queue worker or scheduled workload was introduced |
| Display Report, paging, sorting, totals | IMPLEMENTED | JSON result data, TanStack Table, allow-listed columns/sorting, definition-permitted currency-separated totals |
| Grouping/subtotals | DEFERRED | No seeded definition currently permits grouping; no ungoverned grouping was added |
| Freshness/as-of/currency/sign/status disclosure | IMPLEMENTED | Output metadata carries freshness, source as-of, currency separation, source status basis and sign convention |
| Source permission inheritance | IMPLEMENTED | Reports permission plus originating module permission are checked on catalog, generation, display, drill-down and export |
| Masking/suppression | BASELINE IMPLEMENTED | Security metadata and non-sensitive initial columns are applied; advanced field-level policy administration remains deferred to MDS-1100/10B |
| Drill-down | IMPLEMENTED FOUNDATION | Returns a company-scoped source target with original parameters and definition version; source modules remain the destination authority |
| Browser print | IMPLEMENTED | Print audit endpoint plus frontend browser print |
| PDF/XLSX/CSV | IMPLEMENTED | Minimal server-side format writers use the same Display Report result and metadata; formula-like spreadsheet values are neutralized |
| Export audit | IMPLEMENTED | `report_access_audits`, existing audit/activity infrastructure, and report lifecycle events |
| Favorites | IMPLEMENTED | User/company scoped, definition-bound favorite records; favorites cannot change formulas or source meaning |
| Saved parameter views | IMPLEMENTED | User/company/definition scoped parameter and presentation metadata |
| Recent/history | IMPLEMENTED | User-scoped request history and catalog recent marker |
| Retained snapshots/reproducibility | IMPLEMENTED | Immutable snapshot rows retain result JSON, parameters, context, source as-of, freshness and definition version |
| Idempotency | IMPLEMENTED | Same company/user/key/definition/parameters returns the existing request instead of generating a duplicate |
| Events | IMPLEMENTED | `ReportLifecycleEvent` covers request, validation, generation, failure, drill-down, export, print, favorite and snapshot events |
| Initial source registrations | IMPLEMENTED, SOURCE-BOUND | Collections, Purchases, Payments, Inventory and Expenses registrations listed below |
| Cross-module analytics | PLUMBING ONLY | Shared contracts and catalog path exist; no invented financial formula or consolidation was added |
| Scheduling/delivery/secure links/report packs | DEFERRED TO 10B | Not required for the safe shared foundation |
| Advanced comparisons/management analytics | DEFERRED TO 10B / OWNER MODULE | Requires verified source formulas and comparison contracts |
| Definition publication administration | DEFERRED TO 10B | Phase 10A seeds reviewed published definitions; no end-user formula editor exists |
| Durable cloud archive, retention purge, legal hold | DEFERRED TO 10B / MDS-1100 | Outputs are transient local files; retained snapshot JSON is database-backed. Render ephemeral disk is not treated as a durable archive |
| Notifications and AI | DEFERRED | No provider or AI functionality was added |

## Initial source inventory and catalog

Only existing source-owned query services were registered:

| Definition | Source owner | Adapter / existing service | Permission |
| --- | --- | --- | --- |
| `REP-COL-001` Receipt Register | Collections | `CollectionsService::report('receipt_register')` | `collections.reports.view` |
| `REP-COL-002` Collections Summary | Collections | `CollectionsService::report('payment_method_summary')` | `collections.reports.view` |
| `REP-PUR-001` Purchase Register | Purchases | `PurchasingService::reports('purchase-register')` | `purchases.reports.view` |
| `REP-AP-001` Aging of Payables | Purchases | `PurchasingService::reports('payables-aging')` | `purchases.reports.view` |
| `REP-PAY-001` Payment Register | Payments | `PaymentCompletionService::report()` | `payments.reports.view` |
| `REP-INV-001` Inventory Position | Inventory | `InventoryCompletionService::report('inventory_position')` | `inventory.reports.view` |
| `REP-INV-002` Stock Card | Inventory | `InventoryCompletionService::report('stock_card')` | `inventory.reports.view` |
| `REP-EXP-001` Expense Register | Expenses | `ExpenseService::listHistory()` | `expenses.reports.view` |
| `REP-EXP-002` Expense Analysis | Expenses | `ExpenseCompletionService::report('by-category')` | `expenses.reports.view` |
| `REP-CTL-002` Report Access History | MDS-900 | `ReportAccessAudit` | `reports.history.view` |

Sales, receivables aging, general Cash Accounts, Profit or Loss, Statement of Financial Position, Cash Flow, and other catalog concepts remain out of the initial catalog where an authoritative source contract/query was not available in the repository. This prevents MDS-900 from silently re-implementing source formulas.

## Database and API

Migrations `2026_08_14_000043_create_reports_foundation_phase10a` and `2026_08_14_000044_align_reports_source_columns_phase10a` add only reporting metadata, output-control tables, and forward-only alignment of catalog columns to the actual source service envelopes:

- `report_categories`, `report_source_contracts`, `report_definitions`
- `report_parameter_definitions`, `report_column_definitions`
- `report_requests`, `report_outputs`, `report_snapshots`
- `report_favorites`, `report_saved_views`, `report_access_audits`

The API is under the existing company context and Sanctum authentication boundary:

- `GET /reports/catalog`, `GET /reports/catalog/{key}`
- `POST /reports/requests`, `GET /reports/requests/{id}`, `GET /reports/requests/{id}/status`
- `GET /reports/outputs/{id}`, `GET /reports/outputs/{id}/download`
- `POST /reports/outputs/{id}/print`, `/drill-down`, `/snapshot`
- `GET /reports/history`, `/recent`
- `GET/POST/DELETE /reports/favorites`
- `GET/POST/PATCH/DELETE /reports/saved-views`

## Frontend

`/reports` is now a responsive Reports workspace using TanStack Query, TanStack Table, Recharts, and Lucide. It provides catalog search/category filtering, definition details, shared parameters, Display Report, freshness/context metadata, totals chart, exports, print, favorites, saved parameter views, and source drill-down metadata. Empty results, failed requests, and unavailable states are distinct.

## Storage and security notes

Display results and retained snapshots are stored as JSON in PostgreSQL. PDF/XLSX/CSV files are generated from the governed result and placed in Laravel's private local disk for immediate download. The output metadata explicitly records this as transient local storage; it is not presented as durable Render archival storage. A future approved durable-storage phase must add retention, expiry, purge, legal hold, and delivery policy.

The initial definitions expose no protected source fields. Spreadsheet formula injection is neutralized, source permissions are re-checked for every output path, company scope is applied through the existing middleware and source services, and a failed request is retained as `failed` rather than represented as a zero-row success.

## Validation evidence

- Migration `2026_08_14_000043_create_reports_foundation_phase10a` applied locally against PostgreSQL.
- Focused `ReportsPhase10ATest`: 2 passed, 52 assertions.
- Focused `ReportsWorkspace.test.tsx`: 1 passed.
- ESLint and TypeScript/Vite build passed after the Reports workspace was added.
- Pint passed for the Phase 10A backend files.
- Docker and Render CLI validation remain environmental follow-ups when those tools are available; deployment configuration was not changed.

## Recommended next phase

The next official increment should be **Phase 10B — MDS-900 Reports & Analytics Completion**, derived from the remaining matrix: scheduling/delivery, durable output retention and delivery policy, definition governance, advanced asynchronous workload handling, report packs, and only source-backed cross-module analytics. It was not started here.

# MDS-900 Reports & Analytics — Phase 10B

Status: Phase 10B completion increment implemented; MDS-900 is not yet fully implemented.

Checkpoint: `backups/simplebiz-before-phase10b-reports-completion-20260814-153525.zip`

Branch: `develop`. No files were staged, committed, pushed, reset, or cleaned. Render, Docker, Nginx, and deployment configuration were preserved.

## Boundary

Operational modules remain authoritative for business facts, workflow status, currency, settlement, inventory, cash, and source permissions. MDS-900 only transforms published source contracts into governed report outputs. It does not create a sales, receivables, payables, payment, inventory, cash, expense, or general-ledger store.

Financial statements and profit or loss remain unavailable because the repository does not expose an authoritative general-ledger/reporting source contract. The management analytics surface therefore reports source-backed operational metrics and never infers profit.

## Phase 10B implementation matrix

| Requirement | Disposition | Evidence |
| --- | --- | --- |
| Sales Register, Sales by Product, Receivables Aging | IMPLEMENTED | `SalesService::report()` and `REP-SAL-001`, `REP-SAL-002`, `REP-AR-001` |
| Cash Position and Cash Account Ledger | IMPLEMENTED | `CashPositionService::report()` and `REP-CAS-001`, `REP-CAS-002` |
| Inventory Valuation | SOURCE-BOUND IMPLEMENTED | Existing MDS-600 valuation adapter exposed as `REP-INV-003`; unavailable cost data remains unavailable |
| Business Performance | IMPLEMENTED, SOURCE-BOUND | `ANL-MGT-001` aggregates Sales, Expenses, Cash, Receivables, Payables, and Inventory metrics with source and currency disclosure |
| Voided and Reversed Records | IMPLEMENTED, SOURCE-BOUND | `REP-CTL-001` reads source-owned cancellation/reversal records; it never edits source records |
| Definition version governance | IMPLEMENTED | Draft supersession, review metadata, publication, effective dates, supersession, and deactivation endpoints |
| Scheduled reports | IMPLEMENTED, IN-APP | Daily/weekly/monthly schedules pin a definition version, validate recipients and source permissions, pause/resume/cancel/run-now, and retain occurrences |
| Delivery history and retry | IMPLEMENTED, IN-APP | `report_deliveries` records pending/sent/partially-sent/failed/expired states; retry rechecks recipient authorization |
| External delivery | NOT CONFIGURED | No email, SMS, cloud-storage, or secure-link provider was authorized; the UI and API state that delivery is in-app only |
| Report comparison | IMPLEMENTED, GOVERNED | Comparison uses the same published definition version and keeps currency contexts separated |
| Report packs | IMPLEMENTED | Draft → generated → reviewed → published → archived lifecycle over immutable available outputs |
| Retention and legal hold | BASELINE IMPLEMENTED | Output expiry, legal hold, archive/purge metadata, private-file deletion, and explicit ephemeral Render-disk disclosure |
| Advanced asynchronous processing | FOUNDATION / REMAINING GAP | Requests remain synchronous; scheduled execution is available through the `reports:run-due` command and scheduler registration, but a dedicated queue worker/large-report execution policy is not yet deployed |
| Notifications and AI | NOT CONFIGURED | No provider or AI source was added |
| Financial/statutory reporting | NOT APPLICABLE TO CURRENT SOURCES | No authoritative GL source contract exists in the current repository |

## Catalog additions

Phase 10B adds these source-bound definitions to the existing Phase 10A catalog:

- `REP-SAL-001` Sales Register
- `REP-SAL-002` Sales by Product
- `REP-AR-001` Aging of Receivables
- `REP-CAS-001` Cash Position
- `REP-CAS-002` Cash Account Ledger
- `REP-INV-003` Inventory Valuation
- `REP-MGT-001` Business Performance
- `REP-CTL-001` Voided and Reversed Records

`REP-CTL-002` is retained as the Report Export and Delivery History definition. Financial statements are intentionally not seeded.

## Database and API

Migration `2026_08_14_000045_complete_reports_phase10b` adds:

- report definition review/deactivation metadata;
- output retention, archive, legal-hold, and purge metadata;
- analytics definitions;
- schedules and schedule occurrences;
- in-app delivery records;
- report packs and pack items;
- completion permissions, source contracts, categories, and catalog definitions.

The company-scoped Reports API now includes:

- `GET/POST/PATCH /reports/schedules`, schedule occurrences, and schedule lifecycle actions;
- `GET /reports/deliveries` and `POST /reports/deliveries/{id}/retry`;
- `POST /reports/compare` and `GET /reports/analytics`;
- definition governance listing, supersession, publication, and deactivation;
- report-pack listing, creation, item addition, lifecycle transitions, and output purge.

The scheduler registration is in `routes/console.php`. A deployment must run Laravel's scheduler for automatic due-schedule execution:

```text
php artisan schedule:work
```

The implementation does not claim that Render's ephemeral local disk is a durable archive. Database snapshots remain the reproducible retained record.

## Frontend

`/reports` retains the Phase 10A catalog, source-parameter form, TanStack Table, Recharts totals, exports, print, favorites, saved views, and drill-down. It now also exposes responsive tabs for:

- scheduled reports with in-app-only delivery messaging;
- delivery history;
- governed analytics definitions;
- report packs;
- report-definition governance.

The UI does not show external delivery as successful because no external provider exists.

## Validation

- `php artisan migrate --force`: migration `000045` applied successfully.
- `php artisan test`: **79 passed, 1 intentionally skipped PostgreSQL-only test, 786 assertions**.
- Focused `ReportsPhase10ATest` and `ReportsPhase10BTest`: passed.
- `vendor/bin/pint --test`: passed.
- `npm run lint`: passed.
+ `npm run test`: **18 tests passed across 10 files**.
- `npm run build`: passed.
- `php artisan route:list --path=api/v1/reports`: completion routes registered.
- Docker runtime validation was not run because the Docker daemon is unavailable in this environment.
- Render CLI validation was not run because Render CLI is not installed.

## Completion gate

`MDS-900 PHASE 10B IMPLEMENTED; MDS-900 NOT YET FULLY IMPLEMENTED`

Remaining owned work is limited to a deployed queue-backed large-report execution policy and any approved notification/provider integration. Financial/statutory reports remain blocked by their authoritative accounting source boundary, and MDS-1100 retains configuration/entitlement/localization ownership. No next official module should be started until those applicable gaps are resolved or explicitly accepted.

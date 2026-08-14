# MDS-900 Reports & Analytics — Phase 10C Final Closure

Status: `MDS-900 FULLY IMPLEMENTED` for the documented and currently applicable application scope.

Checkpoint: `backups/simplebiz-before-phase10c-reports-closure-20260814-161758.zip`

Branch: `develop`. Existing worktree changes were preserved. No files were staged, committed, pushed, reset, or cleaned. `render.yaml` and the Render free-tier deployment shape were not changed.

## Scope and authority

Phase 10C closes the remaining MDS-900-owned application gap identified by Phase 10B: queue-backed generation for governed exports and scheduled reports. The corrected MDS-900 specification, Phase 10A, Phase 10B, and the current implementation status were reviewed before implementation.

MDS-900 remains an information layer. It does not create a Sales, Collections, Purchases, Payments, Inventory, Cash Accounts, Expenses, General Ledger, or other operational source ledger. Queued work calls the same `ReportsService` source-adapter path used by synchronous Display Report generation.

## Final closure matrix

| Requirement | Current State | Owner | Applicable Now? | Phase 10C Action |
| --- | --- | --- | --- | --- |
| Queue-backed large-report generation | IMPLEMENTED | MDS-900 | Yes for governed export/scheduled workloads | `GenerateReportJob` receives stable identifiers and re-resolves company, user, definition, version, parameters, and source contracts. |
| Large export generation | IMPLEMENTED | MDS-900 | Yes | PDF, XLSX, and CSV requests are server-classified as async; the same result and export writer are used. |
| Queue retries | IMPLEMENTED | MDS-900/Laravel queue | Yes | Configured tries/backoff, attempt metadata, retry eligibility, and manual retry endpoint. |
| Queue idempotency | IMPLEMENTED | MDS-900 | Yes | Existing request identity is retained; completed requests and existing outputs are safe no-ops. |
| Queue failure recovery | IMPLEMENTED | MDS-900/Laravel queue | Yes | Safe failure category/message, failure history, retryability, correlation, and no-success-on-failure behavior are persisted. |
| Queue cancellation | IMPLEMENTED | MDS-900 | Yes for queued requests | Queued async requests can be cancelled; workers recheck cancellation before output publication. Processing/completed requests cannot be retroactively cancelled. |
| Queue status inquiry | IMPLEMENTED | MDS-900 | Yes | Status API exposes request identity, lifecycle state, timestamps, attempts, failure category, retryability, and outputs. |
| Worker-safe memory behavior | IMPLEMENTED for current source contracts | MDS-900/source modules | Yes | Long-running export work is removed from the HTTP request and browser receives only governed paged Display Report rows. Source adapters remain authoritative for their current result contract; no competing projection or warehouse was introduced. |
| Scheduled large reports | IMPLEMENTED in application code | MDS-900; schedule settings are MDS-1100 | Edition/configuration dependent | Schedule occurrences create queued Report Requests and wait for completed output before delivery or schedule advancement. |
| Report delivery after generation | IMPLEMENTED in-app | MDS-900 | In-app applicable | Delivery records are created only after a valid output exists; generation and delivery statuses remain distinct. |
| External delivery providers | DEFERRED TO OWNER MODULE / EXTERNAL CONFIGURATION | MDS-1100/Core/deployment | Not configured in this repository | No email, SMS, SMTP, webhook, cloud-storage, or vendor SDK was added. The existing provider-neutral delivery record remains in-app only. |
| Notification-provider dependency | DEFERRED TO OWNER MODULE / EXTERNAL CONFIGURATION | MDS-1100/Core | No approved provider exists | MDS-900 does not claim external delivery success and does not add a second notification subsystem. |
| Delivery retry | IMPLEMENTED in-app | MDS-900 | Yes | Existing retry rechecks recipient access, expiry, output identity, and delivery state. |
| Delivery partial failure | IMPLEMENTED in-app | MDS-900 | Yes | Recipient-level failure remains visible and does not become an all-success delivery. |
| Secure delivery | IMPLEMENTED through authenticated company-scoped output access for current in-app scope | MDS-900/MDS-1100 | Edition/configuration dependent | Output download remains permission-checked and does not expose filesystem paths; external secure-link transport remains configuration-owned. |
| Output retention | IMPLEMENTED | MDS-900/MDS-1100 | Yes | Expiry, legal hold, purge metadata, private file deletion, and immutable snapshot behavior remain intact for queued outputs. |
| Persistent binary storage dependency | DEFERRED TO OWNER MODULE / EXTERNAL CONFIGURATION | MDS-1100/deployment | Current Render local disk is ephemeral | Database metadata/snapshots are not falsely presented as durable binary archive; no storage provider was added. |
| Scheduler behavior | IMPLEMENTED in application code | MDS-900/deployment | Yes when scheduler is run | `reports:run-due` remains registered; deployment must run Laravel scheduler. No Render cron/worker service was added. |
| Definition governance | IMPLEMENTED | MDS-900 | Yes | Version, publication, supersession, review, effective dates, and deactivation remain enforced. |
| Report Packs | IMPLEMENTED for available outputs | MDS-900 | Edition/configuration dependent | Existing immutable pack lifecycle remains unchanged; Phase 10C does not invent pack formulas. |
| Analytics | IMPLEMENTED source-bound | MDS-900/source modules | Yes for published definitions | Existing versioned analytics definitions and source contracts remain the only formula path. |
| Comparisons | IMPLEMENTED | MDS-900 | Yes where a governed comparison source exists | Same definition version and separated currency context remain required. |
| Permissions | IMPLEMENTED | MDS-900/MDS-1100/source modules | Yes | Queue execution revalidates company, report, source permissions, definition version, recipient access, and retry authorization. |
| Events | IMPLEMENTED | MDS-900 | Yes | Queue, generated, failed, scheduled-generated, delivered, and delivery-failed events remain correlation-safe and are not duplicated on idempotent retry. |
| Audit | IMPLEMENTED | MDS-900/Core | Yes | Request, completion, failure, retry, cancellation, export/download, schedule, delivery, and governance evidence remain recorded without report-row leakage. |
| NFRs | IMPLEMENTED for current application scope | MDS-900/deployment | Yes | Queue status, retry, concurrency lock, failure states, responsive polling, correlation, and no-browser-full-result behavior are covered. Production-scale benchmarks remain deployment work, not claimed here. |
| Final acceptance criteria | IMPLEMENTED for current applicable scope | MDS-900 | Yes | Focused and full regression evidence is recorded below. |

The authoritative edition matrix excludes scheduled reports and secure delivery from the SimpleBIZ Free edition and limits spreadsheet/CSV capability. The repository currently has no runtime edition/entitlement service beyond definition metadata; those edition switches belong to MDS-1100. This phase preserves the existing source-bound application behavior and does not invent an entitlement engine.

## Queue architecture and lifecycle

Server classification is deliberate and does not accept a client-selected workload flag:

- `display` requests remain synchronous for the current normal-report path.
- `pdf`, `xlsx`, and `csv` requests use the async path.
- scheduled requests force the async path regardless of output format.

The lifecycle is:

```text
Requested → Validated → Queued → Processing → Completed
                                      ├── Failed
                                      └── (pre-publication) Cancelled
```

`backend/app/Jobs/GenerateReportJob.php` carries only stable identifiers: company, Report Request, Report Definition, definition version, actor, and correlation identity. It never serializes source rows or trusts stale client context. The job re-resolves the original definition version and invokes the same `ReportsService::processRequest()` path used by synchronous generation.

The request migration `2026_08_14_000046_complete_reports_phase10c.php` adds execution mode, queue timestamps, attempt metadata, cancellation metadata, retryability, safe failure category, and bounded failure history. `ReportRequest` remains the single processing identity and `ReportOutput` remains the single output identity.

Concurrent workers claim a request inside a database transaction with `lockForUpdate()`. A completed or cancelled request is a safe no-op. Output creation checks the request/format identity before creating a row, and cancellation is checked before and after generation so a stale worker cannot publish a cancelled result. Stale processing recovery is bounded by the configured worker lease window.

Unexpected worker failures retry through Laravel's queue. Validation/authorization failures are terminal; retryable worker failures retain failure history and return to `Queued` until the configured attempt limit. Manual retry requires current report/source authorization and never bypasses idempotency. User-facing errors never contain a raw stack trace.

## Scheduled reports and delivery

Phase 10B schedules now pass through the same queue engine:

```text
Schedule → Occurrence → Report Request → GenerateReportJob → Report Output → Delivery Records
```

The schedule occurrence identity is preserved in the request context. Completion resolves it safely, revalidates owner/recipient permissions, creates in-app delivery records only after output availability, records partial recipient failure separately, and advances the recurrence once. A generation failure does not become a delivery success, and a delivery retry does not regenerate the report.

The repository has no approved external delivery provider. `external_provider` remains disabled in `config/reports.php`; no third-party provider, SMTP credential, SMS SDK, webhook, or persistent object-storage provider was added. The current Render configuration uses the free backend service with `QUEUE_CONNECTION=sync`; therefore deployed asynchronous worker execution has not been claimed. A deployment that needs true background execution must configure an approved Laravel queue backend and worker within separately authorized infrastructure. The Render blueprint was intentionally not changed.

## API and frontend

Added company-scoped request controls:

- `POST /api/v1/reports/requests/{id}/cancel`
- `POST /api/v1/reports/requests/{id}/retry`
- existing `GET /api/v1/reports/requests/{id}/status` now returns queue lifecycle metadata and output readiness.

Added permissions:

- `reports.requests.cancel`
- `reports.requests.retry`

`web/src/features/reports/ReportsWorkspace.tsx` now polls queued/processing requests with TanStack Query, stops polling at terminal states, preserves parameters, shows queued/processing/completed/failed/cancelled states, exposes valid cancel/retry actions, and enables export download only after an available output exists. It does not fabricate percentage progress or report an external message as sent.

## Storage, retention, packs, and analytics

Queued outputs use the existing private local storage writer and existing `ReportOutput` metadata. Temporary generation files are cleaned by the existing writer where applicable. Render local disk remains explicitly transient. Database-backed retained snapshots preserve definition version, parameters, company context, source as-of time, freshness, and result data. Legal hold and purge rules remain enforced. Report Packs and analytics consume the same completed governed outputs/definitions and do not create another ledger or formula engine.

## Validation

- Migration `2026_08_14_000046_complete_reports_phase10c`: applied successfully locally.
- Focused `ReportsPhase10CTest`: **4 passed, 27 assertions**.
- Complete backend suite: **83 passed, 1 intentionally skipped PostgreSQL-only schema test, 813 assertions**.
- Frontend Vitest: **18 tests passed across 10 files**.
- ESLint: passed.
- TypeScript/Vite production build: passed.
- `vendor/bin/pint --test`: passed.
- `git diff --check`: passed; only existing line-ending warnings were reported by Git.
- Docker image/build/runtime validation: unavailable because Docker Desktop/daemon is not running.
- Queue worker runtime validation: not claimed; the local test suite used `Queue::fake()` and direct job handling, while the test environment uses the sync driver.
- Render CLI/blueprint validation: unavailable because Render CLI is not installed.

## Final catalog and boundaries

The final source-bound catalog remains the Phase 10B catalog: Collections, Sales, Receivables, Cash Position, Cash Ledger, Inventory Valuation, Purchases, Payables, Payments, Expenses, Voided/Reversed Records, Report Access History, and governed Business Performance analytics. Financial/statutory reports remain unavailable where no authoritative accounting source contract exists. No other SimpleBIZ module was started in Phase 10C.

The MDS-900-owned application gaps identified in Phase 10B are closed. Remaining items are external deployment/configuration dependencies: a real queue worker/backend for non-blocking production execution, a scheduler process for automatic due schedules, MDS-1100 edition/entitlement/localization/delivery configuration, and any approved durable storage or external notification provider. None is falsely reported as deployed or successful.

`MDS-900 FULLY IMPLEMENTED`

# MDS-000 Core + Whole-System Final Acceptance - Phase 16

Status: `MDS-000 FULLY IMPLEMENTED`

This is the final planned implementation phase. It closes MDS-000 Core-owned gaps identified by repository inspection and records the acceptance evidence for the completed MDS-100 through MDS-1100 modules. It does not introduce a new business module, second source of truth, second ledger, demo data, external provider integration, or deployment-plan change.

The pre-change checkpoint is `backups/simplebiz-before-phase16-final-acceptance-20260815-115659.zip`. The branch remains `develop`. Existing worktree changes were preserved; no files were staged, committed, pushed, reset, or cleaned.

## Core traceability matrix

The matrix below records the final MDS-000 review against the implementation and the authoritative module boundaries. `EXTERNAL DEPLOYMENT / CONFIGURATION DEPENDENCY` identifies an operational dependency, not an MDS-000-owned application gap.

| Core area | Requirement and evidence | Status | Boundary or dependency |
| --- | --- | --- | --- |
| Authentication | Sanctum login, active-user enforcement, safe credential errors, logout revocation, and protected current-user access | IMPLEMENTED | Token expiry and provider runtime settings remain deployment configuration |
| Session/token lifecycle | Current token can be revoked; settings exposes safe session metadata and termination controls | IMPLEMENTED | Raw token values are never serialized |
| Initial setup/bootstrap | Atomic first company and Business Owner creation, one-company-per-registration-origin guard, idempotent replay | IMPLEMENTED | Registration origin is IP/device policy, not a multi-tenant bypass |
| Company context and switching | Active membership context is resolved for every tenant route and switching is membership-authorized | IMPLEMENTED | MDS-1100 owns administrative presentation |
| Tenant isolation | Core and module queries, search, notifications, attachments, exports, background contracts, and audit records carry company scope | IMPLEMENTED | PostgreSQL deployment must retain the configured database and migrations |
| User and membership lifecycle | Invitations, acceptance, activation, suspension, deactivation, role changes, protected owner rules, and history | IMPLEMENTED | Provider delivery of invitations is outside the Free application boundary |
| Roles and permissions | Server-side permission middleware and company-specific role grants protect direct API access | IMPLEMENTED | Custom role authoring remains outside the Free scope |
| Authorization and segregation | Owner/admin/member grants, maker-checker paths, protected owner controls, and source-module permissions | IMPLEMENTED | Policy configuration remains with MDS-1100 where documented |
| Correlation IDs | Safe accepted/generated correlation IDs are attached to requests, responses, audit, activity, and source documents | IMPLEMENTED | None |
| Idempotency | Company/user-scoped keys, request fingerprints, replay-safe responses, conflict detection, and failed-operation cleanup | IMPLEMENTED | None |
| Audit trail | Immutable audit records include actor, company, action, entity, before/after, reason, correlation, IP, and user agent | IMPLEMENTED | Retention/legal-hold operations remain operational policy |
| Activity log and events | Activity records and after-commit lifecycle events preserve source ownership and traceability | IMPLEMENTED | Queue delivery is an external runtime dependency |
| Notifications | Persisted, company/user-scoped in-app inbox with unread/read/read-all state, source, event, severity, route, payload, and audit | IMPLEMENTED | External email/SMS/push channels are not configured in the Free deployment |
| Attachments and evidence | Private storage metadata, company checks, hash deduplication, safe downloads, evidence-before-posting, and audit | IMPLEMENTED | Durable production object storage is deployment configuration |
| Error envelopes | Validation, authorization, not-found, conflict, HTTP, and unexpected API errors are safe and correlation-addressable | IMPLEMENTED | Stack traces remain server logs only |
| Validation envelopes | API validation returns `message`, `errors`, and `meta.correlation_id` on API requests | IMPLEMENTED | None |
| API versioning | All application API routes are under `/api/v1` and use shared response conventions | IMPLEMENTED | None |
| Pagination conventions | Large lists use bounded pagination/limits and source-owned report/export pagination | IMPLEMENTED | None |
| Optimistic locking | Version checks are used on editable source/configuration records and governed actions | IMPLEMENTED | None |
| Concurrency and atomicity | Transactions, deterministic locks, unique keys, idempotency, and after-commit boundaries protect financial/source effects | IMPLEMENTED | PostgreSQL production runtime is required for the deployed contract |
| Numbering integration | Source modules use shared company-scoped, lock-protected document numbering | IMPLEMENTED | Settings presents configuration; source modules remain numbering consumers |
| Configuration resolution | Company settings, preferences, edition state, effective sources, versioned change sets, and defaults are surfaced | IMPLEMENTED | MDS-1100 owns configuration UI and policy |
| Entitlement enforcement | Edition-aware module and capability state is disclosed and locked behavior is safe | IMPLEMENTED | Commercial Commerce/Usage service is external and unavailable in Free staging |
| Accounting transaction infrastructure | Balanced shared accounting transactions and lines are reused by source modules; no Core journal is invented | IMPLEMENTED | Source modules own business meaning and posting decisions |
| Sensitive-data handling | Passwords, access tokens, secrets, credentials, and private registration metadata are excluded or protected | IMPLEMENTED | Production secret management is deployment configuration |
| File/storage handling | Private paths, bounded uploads, safe download authorization, and evidence references are enforced | IMPLEMENTED | Persistent disk/object storage is an external deployment dependency |
| Queue/background processing | Report jobs, retries, cancellation, stale-worker checks, and source snapshots have shared contracts | IMPLEMENTED | Worker process and durable queue operation are EXTERNAL DEPLOYMENT / CONFIGURATION DEPENDENCY |
| Scheduler contracts | Due-report command, schedule registration, overlap protection, and occurrence history are present | IMPLEMENTED | Scheduler process is EXTERNAL DEPLOYMENT / CONFIGURATION DEPENDENCY |
| Health/readiness | `/api/v1/health` remains available and reports safe service/environment status | IMPLEMENTED | Render health execution is EXTERNAL DEPLOYMENT / CONFIGURATION DEPENDENCY |
| Application shell | Responsive React shell, branded navigation, protected routes, profile/session controls, global search, and notification inbox | IMPLEMENTED | External notification delivery is not claimed |
| Navigation and recovery | Direct routes, unauthorized handling, safe errors, loading/empty/error states, and recovery refreshes are present | IMPLEMENTED | None |
| Responsive behavior | Shell and completed workspaces adapt for mobile, tablet, and wide layouts | IMPLEMENTED | Browser/device coverage remains a normal QA activity |
| Accessibility | Labels, keyboard search shortcut, focusable controls, dialog semantics, status text, and responsive navigation controls | IMPLEMENTED | Full assistive-technology certification is outside repository automation |
| Module enablement | Settings exposes edition/source-owner/module state and safe deferred states | IMPLEMENTED | MDS-1100 owns the administrative surface |
| Cross-module source ownership | Dashboard, reports, payments, inventory, cash, expenses, registries, settings, and notifications use source-owned facts | IMPLEMENTED | No second ledger, duplicate registry, or invented parser/provider was introduced |

There are no remaining MDS-000-owned `GAP` items. Queue workers, scheduler processes, persistent production storage, Render runtime, external notification channels, and commercial entitlement providers are explicitly classified as deployment/provider dependencies.

## Final module matrix

| Module | Final gate | Traceability document or evidence | Remaining owned gaps | External dependencies |
| --- | --- | --- | --- | --- |
| MDS-000 Core | FULLY IMPLEMENTED | This document; CoreFinalAcceptanceTest; shared middleware/services | None | Render runtime, queue/scheduler process, persistent storage |
| MDS-100 Dashboard | FULLY IMPLEMENTED | `docs/MDS-100-PHASE-13.md` | None within Dashboard ownership | Source module availability |
| MDS-200 Sales & Receivables | FULLY IMPLEMENTED | `docs/MDS-200-PHASE-11.md`, `docs/MDS-200-PHASE-11B.md` | None within documented Free scope | Owner modules for cash, inventory, receipts, reports |
| MDS-300 Collections & Receipts | FULLY IMPLEMENTED | Authoritative MDS-300 design and implementation status | None within documented Free scope | Cash and payment owner-module paths |
| MDS-400 Purchases & Payables | FULLY IMPLEMENTED | Implementation status, Phase 7A/7B evidence, purchases tests | None within documented Free scope | Payments, inventory, reports, settings owner boundaries |
| MDS-500 Payments & Disbursements | FULLY IMPLEMENTED | `docs/MDS-500-PHASE-8B.md` and payment tests | None within documented Free scope | External bank/provider success is not fabricated |
| MDS-600 Inventory | FULLY IMPLEMENTED | Authoritative corrected MDS-600 design and inventory tests | None within documented Free scope | Source module handoffs |
| MDS-700 Cash Accounts | FULLY IMPLEMENTED | `docs/MDS-700-PHASE-12.md` and cash tests | None within documented Free scope | Bank feeds/providers are not part of the named contract |
| MDS-800 Expenses | FULLY IMPLEMENTED | `docs/MDS-800-PHASE-9A.md`, `docs/MDS-800-PHASE-9B.md` and expense tests | None within documented Free scope | OCR/AI and external feeds remain provider boundaries |
| MDS-900 Reports & Analytics | FULLY IMPLEMENTED | `docs/MDS-900-PHASE-10A.md`, `docs/MDS-900-PHASE-10B.md`, `docs/MDS-900-PHASE-10C.md` | None within source-bound Free scope | Worker/scheduler, durable archive, external delivery |
| MDS-1000 Master Registries | FULLY IMPLEMENTED | `docs/MDS-1000-PHASE-14.md` and registry tests | None within documented Free scope | None |
| MDS-1100 Settings & Administration | FULLY IMPLEMENTED | `docs/MDS-1100-PHASE-15.md` and settings tests | None within documented Free scope | Commerce/Usage, SSO/SCIM, provider delivery |

## Phase 16 implementation

### API and Core contracts

- Added `CoreController` routes for active-company global search and in-app notification inbox/read operations.
- Added `CoreSearchService` with permission-filtered, company-scoped bounded search across business partners, products/services, sales, purchase orders, cash accounts, and expenses. Search returns safe labels, source type, status, and existing workspace routes.
- Added `core.search` to fresh-company Business Owner, Administrator, and Member role bootstrap grants; existing roles remain covered by migration 000054.
- Added `Notification` and `NotificationService` with stable notification keys, company/user scope, expiry, source/event metadata, in-app delivery state, unread counts, read/read-all actions, and audit evidence.
- Added forward-only migration `2026_08_15_000054_core_final_acceptance`, creating the notification store and registering `core.search` for active system roles.
- Normalized API errors so API validation and unexpected errors expose safe messages plus `meta.correlation_id`. HTTP 404/405-style exceptions preserve their safe status instead of becoming a generic 500.
- Strengthened logout to revoke the bearer token resolved from the current request, including a database assertion in the final acceptance test.

### Shared shell

- Added `web/src/features/core/CoreShell.tsx` and mounted it in the existing shell.
- The existing header search opens a keyboard-accessible global search surface with Ctrl/Cmd+K, active-company/permission-scoped results, loading/empty/error states, and route navigation.
- The existing notification control now reflects the persisted unread count and opens an in-app inbox with read/read-all actions, source/time metadata, route navigation, and explicit `In-app only` delivery state.
- No module card, transaction workflow, accounting formula, table ownership, or user-facing business feature outside the shared Core shell was changed.

## Validation evidence

- Migration status: all migrations through `2026_08_15_000054_core_final_acceptance` are `Ran` locally.
- Focused `tests/Feature/CoreFinalAcceptanceTest.php`: **4 passed, 39 assertions**.
- Complete `php artisan test`: **105 passed, 1 intentionally skipped PostgreSQL-only schema test, 1,039 assertions**.
- `vendor/bin/pint --test`: passed.
- `npm run lint`: passed.
- `npm run test`: **18 tests passed across 10 files**.
- `npm run build`: passed; TypeScript and Vite production build completed successfully.
- `git diff --check`: completed without content errors; Git reports existing LF-to-CRLF normalization warnings for modified text files.
- `php artisan route:list`: global search and notification routes are registered; `php artisan schedule:list` shows the due-report scheduler.
- Docker client is installed, but Docker Desktop's Linux daemon is unavailable (`docker info` cannot connect to `dockerDesktopLinuxEngine`); Docker image, container, Nginx, PHP-FPM, and health-runtime checks could not execute locally.
- Render CLI is not installed; Blueprint validation could not execute locally. Existing `render.yaml`, free plans, `develop` branches, health path, database reference, and deployment startup configuration were preserved.

## Final acceptance decision

The documented Core requirements have no remaining MDS-000-owned gaps, all prior module gates are supported by their phase evidence, the final cross-module regression suite passes, and the shared shell contracts are live.

`MDS-000 FULLY IMPLEMENTED`

`SIMPLEBIZ ALL DOCUMENTED MODULES FULLY IMPLEMENTED`

`SYSTEM FINAL ACCEPTANCE PASSED`

Operational deployment checks remain to be executed in an environment with Docker Desktop/Render runtime access. This does not reopen application scope or authorize a new business module.

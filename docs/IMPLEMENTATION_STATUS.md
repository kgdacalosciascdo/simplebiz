# SimpleBIZ implementation status

Updated: 15 August 2026

The documents in `docs/` are the final, approved, authoritative implementation baseline. This status document records the current as-built repository state and does not replace the MDS documents.

## Completed phases

### Phase 1A

- Laravel 12 backend preserved in `backend/`.
- React, Vite, and Tailwind frontend preserved in `web/`.
- PostgreSQL environment configuration established.
- Sanctum token authentication foundation added.
- Companies, memberships, roles, permissions, audit/activity, idempotency, and correlation foundations added.
- Shared React application shell and workspace routing added.

### Phase 1B

- Phase 1A authentication and company-context gaps hardened.
- Public unrestricted registration removed from the normal API route set.
- Initial setup is available only when no company exists.
- Initial setup creates the first company, protected Business Owner membership, minimum system roles, permissions, safe defaults, audit, and activity evidence atomically.
- Company profile viewing and editing implemented through Settings & Administration.
- Active company selection is membership-authorized and persisted as the user’s preferred company.
- Inactive users and unauthorized company access are rejected server-side.
- User invitation lifecycle implemented: create, resend, cancel, and accept.
- Invitation tokens are stored as SHA-256 hashes, expire, and are single-use.
- Users & Access membership lifecycle implemented: role change, activation, suspension, and deactivation with protected-owner rules.
- Administrative activity API and Settings frontend panels implemented.
- Idempotency applies to setup, company profile updates, invitations, invitation acceptance, role changes, and membership status transitions.
- Idempotent response storage removes access and invitation tokens before persistence.
- Frontend session restoration, protected routes, setup, Business Setup, Users & Access, company switching, API errors, and deferred Settings cards implemented.

### Phase 2A — Master Registries core

- Added the governed MDS-1000 registry vertical slice without starting transaction modules.
- Added normalized, company-scoped PostgreSQL tables for Business Partners, partner roles, contacts, addresses, categories, units of measure, products/services, external identifiers, and registry history.
- Registry records use stable UUID identities, company-scoped codes, status/effective dates, versions, actor fields, correlation/source fields, optimistic concurrency, and forward-only lifecycle changes.
- Business Partners are one shared identity with Customer, Supplier, and Payee roles; Customers and Suppliers are filtered views, not duplicate identities.
- Added active/effective lookup APIs, search, pagination, summary counts, quick-create endpoints, detail/history endpoints, and dependency-aware deactivation.
- Added exact-code conflict handling, probable-duplicate warnings with explicit override reasons, audit/activity evidence, idempotent creates and lifecycle actions, and tenant-isolated queries.
- Added server permission keys for registry view/search/history and Business Partner, Item, Category, and Unit CRUD/lifecycle actions. Owner and Administrator mappings are seeded; Member receives read/search/history access.
- Added the Master Registries workspace with Customers, Suppliers, Products & Services, Categories, Units, quick actions, summary cards, and honest deferred cards for registries owned by later modules.
- Added Phase 2A backend contract tests covering shared roles/history, duplicate override, product/service rules, dependency blocking, and company isolation.

### Phase 2B - Transaction-readiness reference registries

- Added company-scoped currencies, payment methods, payment terms, tax codes, account titles, expense categories, branches, warehouses, stock locations, and reason codes.
- Added forward-only lifecycle, effective-date filtering, stable UUIDs, versioned updates, audit/activity evidence, history, idempotency, permission keys, and tenant-isolated queries for each registry.
- Added safe reference lookups with domain, classification, branch, warehouse, and active/effective filters for downstream transaction forms.
- Added company default links for currency, branch, payment term, payment method, warehouse, stock location, expense category, and expense account title with active same-company validation and deactivation dependency protection.
- Backfilled supported existing company currency values into the currency registry without inventing unsupported currency identities.
- Added explicit dependency rules for expense-category/account-title mappings and branch/warehouse/location hierarchies. No cash-account, inventory-balance, stock-movement, expense-transaction, or tax-engine tables were added.
- Added Phase 2B reference registry pages and quick-create forms to the Master Registries workspace. Cash Account profiles and Opening Balances are now implemented in the Phase 3A MDS-700 workspace.
- Added focused Phase 2B backend tests for currency defaults, payment directions/terms, tax rates, expense mappings, location hierarchy, reason-code lookups, and scope boundaries.

### Phase 3A - Cash Account Registry and Opening Balance Foundation

- Added MDS-700 cash-account types, company-scoped Cash Account profiles, institution/provider metadata, encrypted account identifiers with masked/last-four responses, Account Title/Currency/Branch linkage, capabilities, custodians, lifecycle status, optimistic concurrency, audit/history, and tenant isolation.
- Added standard physical and non-physical Cash Account Types with default/allowed capability profiles. Physical account activation requires a current primary custodian; account-type capabilities remain separate from user permissions.
- Added the minimum transaction/accounting foundation required for Opening Balances: Business Transactions, Accounting Transactions, balanced accounting lines, Cash Movements, and private Attachment metadata/storage. No generic movement endpoint or full accounting engine was introduced.
- Added governed Opening Balance drafts with effective-date/lock-date checks, same-company active OPENING_BALANCE Reason Codes, evidence-before-submission, preparer/reviewer segregation, approval, atomic posting, balanced accounting effect, linked posted Cash Movement, derived position, and governed reversal with a linked counter-movement.
- Added company Opening Balance offset Account Title configuration and dependency protection against deactivation. Posting is blocked until the offset is configured and active.
- Added Cash Accounts APIs, permission keys, owner/administrator/member mappings for newly bootstrapped companies, idempotency, correlation IDs, audit/activity evidence, and safe response serialization. Provider credentials, passwords, PINs, OTPs, API keys, private keys, and access tokens are prohibited.
- Added the Cash Accounts workspace to the existing React shell: registry search/list, currency-grouped derived positions, needs-attention indicators, profile creation, type-driven capabilities, lifecycle actions, and Opening Balance evidence/review/post/reversal. Phase 3B extends this same workspace with governed movement actions.
- Added focused Phase 3A tests covering seeded types, no movement on profile creation, physical custodian enforcement, capability separation, evidence/approval/configuration, balanced posting, derived balance, and reversal.

### Phase 3B - Governed Cash Movements and Internal Transfers

- Added company-scoped governed Cash Movement and Cash Transfer documents above the Phase 3A posted-movement ledger. Documents receive deterministic company numbering and retain lifecycle/status history, versions, actors, correlation IDs, idempotency identities, evidence links, original/reversal links, and accounting/movement references.
- Implemented direct Cash In and Cash Out with purpose/source-type restrictions, active Cash Account capability checks, Account Title offsets, reference-registry payment/branch/reason validation, evidence-before-submission, preparer/reviewer segregation, approval, cancellation, atomic posting, and governed reversal.
- Implemented Internal Transfer, Deposit, and Withdrawal documents through one atomic two-leg transfer engine. Source and destination accounts are locked in deterministic order, cross-currency and same-account transfers are rejected, and both Cash Movements plus balanced accounting lines are committed together.
- Added company negative-balance policy, account capability gating, explicit permission-based override, required override reason, negative-balance audit events, and derived available-balance checks. No balance is edited directly by a document or UI.
- Added movement history with clearing and reconciliation state visibility, lifecycle/audit/history/evidence APIs, after-commit lifecycle events, idempotency boundaries, optimistic version checks, and recovery refreshes after failed action requests.
- Added the Cash Accounts movement workspace for Cash In, Cash Out, Transfers, Deposits, Withdrawals, evidence upload, lifecycle actions, source/destination balance presentation, negative-balance warnings, and authoritative movement history. Full customer receipts, supplier payments, expenses, cash counts, statement import, reconciliation, and cross-currency remain outside this phase.
- Added Phase 3B focused feature coverage for balanced Cash In posting/reversal, negative-balance blocking/override, and atomic transfer legs. The new forward-only migrations are `2026_08_04_000012_create_cash_movement_documents` and `2026_08_04_000013_seed_cash_movement_catalog`.

### Phase 3C-A - Cash Counts, Variances, Adjustments, and Custodian Handover

- Added the forward-only cash-count foundation for count types, company/system denomination catalogs, Cash Count headers, immutable count attempts, denomination and non-denomination lines, confirmations, variance records, adjustment records, custodian handovers, and status history.
- Cash Count eligibility is enforced server-side: the account must be active physical Cash Account with the `supports_cash_count` type flag and `CASH_COUNT` capability. Bank and Digital Wallet accounts are not eligible merely because they have a balance.
- Starting a count takes an explicit cut-off and stores the expected amount, movement count, movement hash, and any posted backdated movement detected after the cut-off. Expected cash is derived from posted `cash_movements`; no balance is edited or manually entered.
- Actual cash is calculated on the server from snapshotted denomination face values and quantities. Recounts create a new attempt and preserve all prior attempts. Custodian and witness confirmations are recorded against the accepted attempt, with counter/self-approval segregation and evidence requirements enforced by the API.
- Variances are classified as balanced, overage, or shortage. Tolerance is explicit and visible. A non-balanced count cannot close until it has a governed disposition; adjustment dispositions prepare a linked Cash Movement through the existing movement/accounting engine, and reversal uses the existing movement reversal path.
- Custodian handover is a separate governed workflow. Completion atomically ends the outgoing primary custody assignment and creates the incoming primary assignment only after the handover confirmations, approval, count linkage, and unresolved-variance checks pass.
- Added permissions, audit/activity evidence, correlation IDs, idempotency boundaries, optimistic versions, deterministic account/count locks, tenant isolation, and after-commit lifecycle events for the new workflows.
- Added `Cash Counts & Variances` and `Custodian Handovers` workspace modes to Cash Accounts, including count scheduling, denomination entry, evidence upload, confirmations, review/disposition actions, reports/needs-attention views, and handover controls.
- Added focused Phase 3C-A coverage for cut-off snapshots, denomination totals, balanced count completion, variance adjustment posting/reversal, and handover completion. Statement import, statement-line matching, reconciliation, rematching, and completion locking remain outside this phase.

### Phase 3C-B - Statement Import, Matching, Reconciliation, Locking, and Reopening

- Added forward-only migrations `2026_08_04_000016_create_statement_reconciliation_foundation` and `2026_08_04_000017_seed_statement_reconciliation_catalog`.
- Implemented company-scoped Statement Import Batches, private evidence links through the existing Attachment Service, manual normalized Statement Lines, validation summaries, opening/closing statement controls, period controls, duplicate file hashes, line fingerprints, and idempotent import boundaries.
- MDS-700 does not name CSV, XLSX, OFX, QIF, MT940, CAMT, PDF, or another machine-readable format. This phase therefore supports controlled manual statement-line entry and secure raw statement evidence upload only. No invented parser, bank feed, provider login, OCR, or API synchronization was added.
- Statement Lines use one explicit sign convention: positive signed amount is credit/inflow and negative signed amount is debit/outflow. Debit, credit, signed amount, currency, dates, period, duplicate fingerprint, and running-balance continuity are validated server-side. Statement data never creates a Cash Movement or edits a derived balance.
- Implemented reconciliation population from posted `cash_movements` by inclusive `business_date` period. Draft/failed movements are excluded, original reversed movements are excluded while their posted reversal counter-effect remains eligible, and posting, clearing, and reconciliation statuses remain separate.
- Implemented exact, tolerance/manual, one-statement-to-many-movements split, and many-statements-to-one-movement combined matching. Allocations are locked transactionally and cannot exceed either remaining side; non-zero tolerance requires the governed override permission and a reason. Match history is preserved on unmatching and rematching.
- Implemented outstanding statement and Cash Movement items with governed classifications, owners, reasons, and no balance effect. Reconciliation adjustments use the existing Direct Cash In/Out source templates, accounting engine, evidence workflow, approval, posting, and reversal path; no manual journal or second ledger was introduced.
- Implemented reconciliation preparation, submission, review, approval, atomic completion, completion records, population-fingerprint freshness checks, completion locking, reopening with a required reason, immutable completion history, and controlled post-reopen rematching.
- Added statement-import, reconciliation, matching, outstanding-item, adjustment, evidence, history, and lifecycle APIs plus the Cash Accounts Statement Imports and Reconciliations workspaces. The frontend identifies statement lines and Cash Movements as separate sources and refreshes authoritative server state after actions.
- Added focused Phase 3C-B backend coverage for manual normalization/evidence/duplicate detection, no balance effect on validation, exact matching, separate movement amount/status behavior, governed adjustment posting, completion locking, reopening history, and frontend coverage for the two new workspaces.

## PostgreSQL and migrations

The backend uses `pgsql` through environment configuration. The local PostgreSQL migration status was verified, and the Phase 2B migrations `2026_08_03_000008_create_transaction_reference_registries` and `2026_08_03_000009_seed_reference_registries`, followed by Phase 3A migrations `2026_08_03_000010_create_cash_accounts_foundation` and `2026_08_03_000011_seed_cash_accounts_foundation`, Phase 3B migrations `2026_08_04_000012_create_cash_movement_documents` and `2026_08_04_000013_seed_cash_movement_catalog`, Phase 3C-A migrations `2026_08_04_000014_create_cash_count_foundation` and `2026_08_04_000015_seed_cash_count_catalog`, and Phase 3C-B migrations `2026_08_04_000016_create_statement_reconciliation_foundation`, `2026_08_04_000017_seed_statement_reconciliation_catalog`, and `2026_08_04_000018_seed_reconciliation_return_permission`, were applied with the normal `php artisan migrate` command. No reset or destructive migration command was used.

Required local configuration is kept in `backend/.env` and must not be committed. New environments should copy `backend/.env.example`, set a valid PostgreSQL database and credentials, and run:

```powershell
php artisan config:clear
php artisan cache:clear
php artisan migrate
```

## Authentication and access behavior

- Login is rate-limited and issues an expiring Sanctum token.
- Logout revokes the current token.
- Current-user access is protected by Sanctum and active-user middleware.
- Suspended or deactivated users cannot use ordinary protected routes.
- Initial owner bootstrap is setup-gated and rate-limited.
- Ordinary account creation occurs through a company invitation acceptance flow.
- Passwords, tokens, invitation tokens, and secrets are excluded from normal API payloads, audit metadata, and idempotency replay storage.

## API endpoints

- `GET /api/v1/health`
- `GET /api/v1/setup/status`
- `POST /api/v1/setup/bootstrap`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/invitations/accept`
- `GET /api/v1/companies`
- `POST /api/v1/companies/{company}/activate`
- `GET /api/v1/context/company`
- `GET|PATCH /api/v1/settings/company`
- `GET /api/v1/settings/users`
- `GET /api/v1/settings/users/roles`
- `GET /api/v1/settings/users/invitations`
- `POST /api/v1/settings/users/invitations`
- `POST /api/v1/settings/users/invitations/{invitation}/resend`
- `POST /api/v1/settings/users/invitations/{invitation}/cancel`
- `PATCH /api/v1/settings/users/{user}/role`
- `PATCH /api/v1/settings/users/{user}/status/{status}`
- `GET /api/v1/settings/activity`
- `GET|POST /api/v1/cash-accounts`
- `GET /api/v1/cash-accounts/types|lookups|summary|needs-attention`
- `GET|PATCH /api/v1/cash-accounts/{id}`
- `POST /api/v1/cash-accounts/{id}/activate|restrict|unrestrict|deactivate|reactivate`
- `GET|PATCH /api/v1/cash-accounts/{id}/capabilities`
- `GET|POST /api/v1/cash-accounts/{id}/custodians`
- `POST /api/v1/cash-accounts/{id}/custodians/{custodianId}/end`
- `GET /api/v1/cash-accounts/{id}/balance|movements|history`
- `GET /api/v1/cash-accounts/movement-purposes|movements`
- `GET|POST /api/v1/cash-accounts/cash-in|cash-out`
- `GET|PATCH /api/v1/cash-accounts/cash-in/{id}|cash-out/{id}`
- `POST /api/v1/cash-accounts/cash-in/{id}/submit|review|approve|post|cancel|reverse`
- `POST /api/v1/cash-accounts/cash-out/{id}/submit|review|approve|post|cancel|reverse`
- `POST /api/v1/cash-accounts/cash-in/{id}/evidence|cash-out/{id}/evidence`
- `GET /api/v1/cash-accounts/cash-in/{id}/history|cash-out/{id}/history`
- `GET|POST /api/v1/cash-accounts/transfers`
- `GET|PATCH /api/v1/cash-accounts/transfers/{id}`
- `POST /api/v1/cash-accounts/transfers/{id}/submit|review|approve|post|cancel|reverse`
- `POST /api/v1/cash-accounts/transfers/{id}/evidence`
- `GET /api/v1/cash-accounts/transfers/{id}/history`
- `GET /api/v1/cash-accounts/cash-counts/types|denominations|reports`
- `POST /api/v1/cash-accounts/cash-counts/denominations`
- `GET|POST /api/v1/cash-accounts/cash-counts`
- `GET /api/v1/cash-accounts/cash-counts/{id}`
- `POST /api/v1/cash-accounts/cash-counts/{id}/start|attempts|submit|review|approve|disposition|recount|close|cancel|evidence`
- `POST /api/v1/cash-accounts/cash-counts/{id}/attempts/{attemptId}/save|confirm`
- `POST /api/v1/cash-accounts/cash-adjustments/{id}/approve|post|reverse`
- `GET|POST /api/v1/cash-accounts/custodian-handovers`
- `GET /api/v1/cash-accounts/custodian-handovers/{id}`
- `POST /api/v1/cash-accounts/custodian-handovers/{id}/confirm|approve|complete|cancel|evidence`
- `GET|POST /api/v1/cash-accounts/opening-balances`
- `GET|PATCH /api/v1/cash-accounts/opening-balances/{id}`
- `POST /api/v1/cash-accounts/opening-balances/{id}/submit|approve|return|post|reverse`
- `POST /api/v1/cash-accounts/opening-balances/{id}/evidence`
- `GET /api/v1/cash-accounts/opening-balances/{id}/evidence|history`
- `GET /api/v1/cash-accounts/opening-balances/{id}/evidence/{attachmentId}/download`
- `GET /api/v1/master-registries`
- `GET /api/v1/master-registries/lookups`
- `GET|POST /api/v1/master-registries/business-partners`
- `POST /api/v1/master-registries/business-partners/quick-create`
- `GET|PATCH /api/v1/master-registries/business-partners/{id}`
- `POST /api/v1/master-registries/business-partners/{id}/deactivate`
- `POST /api/v1/master-registries/business-partners/{id}/reactivate`
- `GET /api/v1/master-registries/business-partners/{id}/history`
- `POST /api/v1/master-registries/business-partners/{id}/contacts`
- `POST /api/v1/master-registries/business-partners/{id}/addresses`
- `GET|POST /api/v1/master-registries/products-services`
- `POST /api/v1/master-registries/products-services/quick-create`
- `GET|PATCH /api/v1/master-registries/products-services/{id}`
- `POST /api/v1/master-registries/products-services/{id}/deactivate|reactivate`
- `GET /api/v1/master-registries/products-services/{id}/history`
- `GET|POST /api/v1/master-registries/categories`
- `GET|PATCH /api/v1/master-registries/categories/{id}`
- `POST /api/v1/master-registries/categories/{id}/deactivate|reactivate`
- `GET /api/v1/master-registries/categories/{id}/history`
- `GET|POST /api/v1/master-registries/units`
- `GET|PATCH /api/v1/master-registries/units/{id}`
- `POST /api/v1/master-registries/units/{id}/deactivate|reactivate`
- `GET /api/v1/master-registries/units/{id}/history`
- `GET /api/v1/master-registries/reference-lookups`
- `GET|POST /api/v1/master-registries/currencies`
- `GET|PATCH /api/v1/master-registries/currencies/{id}`
- `POST /api/v1/master-registries/currencies/{id}/deactivate|reactivate`
- `GET /api/v1/master-registries/currencies/{id}/history`
- The same list/detail/create/update/lifecycle/history contract is available for `payment-methods`, `payment-terms`, `tax-codes`, `account-titles`, `expense-categories`, `branches`, `warehouses`, `stock-locations`, and `reason-codes`.

All administrative responses use the shared `data`/`meta` success envelope or `message`/`errors` error envelope and include `X-Correlation-ID`.

## Frontend routes

- `/login`
- `/setup`
- `/preview`
- `/settings`
- `/settings/business-setup`
- `/settings/users-access`
- `/master-registries`
- `/master-registries/business-partners`
- `/master-registries/business-partners/new`
- `/customers`
- `/suppliers`
- `/products-services`
- `/products-services/new`
- `/categories`
- `/units`
- `/cash-accounts`
- `/collections`
- `/cash-accounts?mode=new`
- `/cash-accounts?account={id}`
- `/cash-accounts?opening=new`
- `/cash-accounts?opening={id}`
- `/cash-accounts?mode=movement&kind=cash_in|cash_out`
- `/cash-accounts?mode=transfer&purpose=INTERNAL_TRANSFER|DEPOSIT|WITHDRAWAL`
- `/cash-accounts?mode=history`
- `/cash-accounts?mode=counts`
- `/cash-accounts?mode=handovers`
- `/cash-accounts?mode=statements`
- `/cash-accounts?mode=statement-import&id={id}`
- `/cash-accounts?mode=reconciliations`
- `/cash-accounts?mode=reconciliation&id={id}`
- `/master-registries?registry=currencies`
- `/master-registries?registry=payment-methods`
- `/master-registries?registry=payment-terms`
- `/master-registries?registry=tax-codes`
- `/master-registries?registry=account-titles`
- `/master-registries?registry=expense-categories`
- `/master-registries?registry=branches`
- `/master-registries?registry=warehouses`
- `/master-registries?registry=stock-locations`
- `/master-registries?registry=reason-codes`
- Statement imports, statement lines, reconciliation matches, outstanding items, adjustments, lifecycle actions, evidence, and history are available under `/api/v1/cash-accounts/statement-imports`, `/api/v1/cash-accounts/reconciliations`, `/api/v1/cash-accounts/reconciliation-matches`, and `/api/v1/cash-accounts/reconciliation-adjustments` with the Phase 3C-B permission catalog.

## Testing

Backend coverage includes setup, idempotency replay safety, login, protected routes, company context, invitations, owner protection, permission enforcement, correlation IDs, validation envelopes, shared partner roles/history, duplicate handling, item integrity rules, dependency blocking, tenant isolation, Phase 2B reference registry rules, Phase 3A Cash Account/Opening Balance rules, Phase 3B governed movement/transfer rules, Phase 3C-A cash-count/variance/handover rules, and Phase 3C-B statement/reconciliation rules. The complete backend suite passes 29 tests, with 1 intentionally skipped PostgreSQL-only schema test, and 236 assertions. The focused Phase 3C-B suite passes 3 tests and 39 assertions. Frontend lint passes; the Vitest suite passes 10 tests across 4 files; TypeScript and the production Vite build pass.

## Deferred scope

The following remain intentionally deferred: full custom roles, complete permission administration UI, subscription billing and entitlements, MFA, notification administration, numbering configuration, approval-policy builder, richer tax/accounting configuration, integrations, UOM conversions, merge/import tooling, and all transaction modules.

Phase 3C-B intentionally does not implement named-but-unspecified machine-readable statement formats, live bank or wallet feeds, provider synchronization, bank credentials, OCR/PDF parsing, AI matching, cross-currency reconciliation, currency conversion, the full MDS-900 report engine, customer receipts, supplier payments, expenses, payment/disbursement source modules, or source-module economic ownership. Phase 2A and Phase 2B also do not implement Sales, Collections, Inventory movements/balances, Purchases, Expenses, Payments, Reports, price lists, tax engines, bundles, or transaction-specific customer/supplier ledgers.

No Master Registry CRUD or transaction module was started in Phase 1B.

## Known limitations

- Mail delivery is not configured; invitations record `not_configured` or the configured development delivery status and do not pretend that an email was delivered.
- The frontend currently stores the Sanctum token in browser local storage because Phase 1A established token-based API authentication. A future deployment decision may move this to a cookie-based Sanctum SPA flow with CSRF protection.
- Full permission administration and custom role design remain deferred.
- The local PHP runtime does not have the `intl` extension enabled; `php artisan db:show` connects successfully but exits while formatting table counts. Migration status and migration execution remain successful.

## Phase 3C-B completeness note

The documented MDS-700 reconciliation scope is implemented for the controlled manual-statement path. MDS-700 remains partially open only where the authoritative documents do not name a machine-readable statement format or provider feed; those capabilities are intentionally documented as gaps rather than invented. The full MDS-900 reporting catalog and source transaction modules remain outside this phase.

## Render staging deployment readiness

- Added deployment-only preparation for the existing Laravel backend and React frontend: `backend/Dockerfile`, the backend Nginx/startup files, root `render.yaml`, root secret/backup ignore rules, environment-driven CORS, proxy HTTPS handling, production API URL enforcement, and environment-driven private attachment disks.
- Added the focused [Render staging deployment guide](RENDER_STAGING_DEPLOYMENT.md). This is staging readiness only and does not mark SimpleBIZ production-ready.
- No SimpleBIZ business module, workflow, permission, accounting rule, database entity, or user-facing business capability was changed for deployment preparation.

## UI-01 - Reference-faithful dynamic frontend refinement

- Inspected the six supplied workspace references in `ui/`: Dashboard, Sales, Collections & Receipts, Purchases & Payables, Master Registries, and Settings & Administration. No dedicated Cash Accounts, authentication, setup, detail-page, or mobile reference screenshots were present in the repository.
- Refined the shared frontend shell with the reference-aligned blue/sky workspace surfaces, persistent desktop sidebar, responsive mobile drawer, active navigation, company context, global-search entry point, notification/help affordances, profile menu, breadcrumb, focus treatment, and reduced-motion support.
- Added lightweight shared frontend primitives for buttons, cards, badges, page headers, loading panels, empty states, and error panels. Design tokens are centralized in `web/src/index.css`.
- Refined the Dashboard hierarchy around action cards, Needs Attention, Profit & Loss Snapshot, Business Snapshot, Recent Activity, Records & Ledgers, and Action Center. Dashboard Cash Account content reads the existing summary and needs-attention APIs; unavailable Sales, Collections, Purchases, Payments, Inventory, Expenses, Profit & Loss, and activity projections remain explicitly deferred with no fabricated values.
- Preserved the existing API client, routes, business workflows, permissions, accounting behavior, database schema, Render configuration, and Cash Accounts feature behavior. No dependency, migration, backend, or business module was added.
- Added regression assertions for the deferred dashboard state and mobile navigation entry point. Frontend validation: 10 tests passed across 4 files, ESLint passed, TypeScript/Vite production build passed, and `npm ci` compatibility remains to be verified after the final lockfile check.

## UI-02 - Premium dashboard and interaction pass

- Reworked the Dashboard to closely follow the supplied reference composition: seven icon-led workspace tiles, a four-panel middle row for Needs Attention, Profit & Loss Snapshot, Business Snapshot, and Reports, plus Recent Activity, Records & Ledgers, and Action Center. Cash Accounts are populated from the existing reads; Sales, Collections, Purchases, Payments, Inventory, Expenses, Reports, and Profit & Loss remain explicitly deferred rather than represented with invented values.
- Added `VITE_DEMO_MODE=true` as an explicit demo-data switch and a `/preview` route provider. Demo fixtures are deterministic, read-only, labeled `Demo Data`, and limited to the dashboard Cash Account presentation. Live API responses take precedence; authenticated API errors remain visible and do not silently become fixtures.
- Added Lucide icon usage to the refined shell and dashboard, Recharts for dashboard analytics, TanStack Query for company-scoped reads, and a reusable TanStack Table foundation for recent movement rows.
- Added functional desktop sidebar collapse with localStorage persistence under `simplebiz.sidebar.collapsed`; the mobile drawer remains a separate open/close interaction with backdrop and Escape handling.
- Reduced bright panel borders and pastel saturation on the refined surface, added compact responsive states, and preserved keyboard focus treatment and reduced-motion behavior.
- Tightened the shared shell to match the supplied reference proportions: compact 244px sidebar, shorter sky-blue top bar, denser navigation spacing, and the repository logo asset at `/logo.png` in the sidebar, sign-in, and company-setup surfaces.
- Updated frontend regression coverage and documentation. The implementation changes only frontend presentation, read orchestration, and demo fixtures; no backend business logic, database design, permissions, accounting behavior, or API contract was changed.

## Phase 4A - Sales & Receivables

- Added the MDS-200 foundation for Sales, Sale Lines, Receivable Open Items, Billing Statements, and Sale Status History with company-scoped UUID records, immutable posted-document boundaries, lifecycle states, due/settlement/dispute fields, source identity, and optimistic versioning.
- Added server-owned Sales calculation and posting services. Drafts support service/non-stock lines, customer and payment-term validation, tax references, due-date calculation, deterministic BCMath totals, and persisted line snapshots. Client-supplied totals are not trusted.
- Added credit-sale lifecycle APIs under `/api/v1/sales`, read-only receivables and aging APIs under `/api/v1/receivables`, and billing statement generation under `/api/v1/billing-statements`. Credit posting creates the business transaction, balanced accounting transaction, and one receivable open item. It does not create receipts, cash movements, inventory movements, or customer payment applications.
- Cash Sales remain explicitly blocked behind Collections; stock-managed Sales remain explicitly blocked behind Inventory; missing posting Account Titles return a dependency response. Billing Statements summarize existing open items and do not create new receivables.
- Added Sales permissions for view/history/create/update/submit/review/approve/post/cancel, price and discount override boundaries, receivables, aging, and billing statements. Owners receive the complete Sales authority; administrators receive inquiry/draft/review capabilities but are explicitly not granted Sales approval, posting, cancellation, or override authority; members receive read-only Sales/receivables visibility.
- Added the authenticated React Sales & Receivables workspace with server-backed Sales history, credit-sale draft form, receivables/aging view, billing statement generation, detail view, dependency messaging, loading/error states, and a live Dashboard Sales action tile. No transaction demo fixtures were added.
- Validation: backend Phase 4A coverage passes 4 tests and 20 assertions; the existing backend suite passes 33 tests with 1 intentionally skipped PostgreSQL-only schema test; Pint, frontend ESLint, Vitest (11 tests across 4 files), TypeScript, and the production Vite build pass.

Phase 4A remains intentionally bounded: customer receipts/applications remain MDS-300, inventory effects remain MDS-600, cash effects remain MDS-700, and numbering/approval policy configuration remains MDS-1100. Returns, credit notes, sales adjustments, advanced pricing, multi-currency conversion, and full reporting are not invented in this increment.

## Phase 5A - Collections & Receipts Core Foundation

- Added MDS-300 company-scoped receipt, receipt tender, payment application, unapplied customer credit, and lifecycle-history records with UUID identity, durable receipt numbering (`RCT-######`), status separation, totals, currency, references, optimistic versioning, and audit/correlation fields.
- Added Collections permissions and setup bootstrap grants for receipt visibility, draft preparation, submission, approval, posting, voiding, applications, unapplied credit, customer ledger, and history. Existing companies receive the catalog through the Phase 5A permission migration; newly bootstrapped companies receive the same permissions during setup.
- Added authenticated APIs under `/api/v1/collections` for lookups, summary, receipt register/detail/history, draft creation/update, lifecycle actions, unapplied balances, later application, application reversal, and customer ledger projection.
- Receipt posting validates active customers, currencies, incoming Payment Methods, receiving Cash Accounts with `RECEIVE_FUNDS`, tender/application totals, customer/currency ownership, and available open-item balances. One database transaction creates the receipt posting, balanced accounting transaction, MDS-700 cash movement(s), MDS-200 settlement updates, application state, and unapplied customer credit. Cash Movement remains the authoritative cash engine; no Direct Cash In substitute is used.
- Added the React Collections workspace with receipt register, server-backed receipt form, open-item view, unapplied/advance view, customer ledger projection, lifecycle detail, tender/application detail, and history. Sales remains the source of receivable open items, while Collections owns receipt and application behavior.
- Deferred from this increment as required: remittance and variance workflows, controlled Other Receipts, failed/returned instrument workflows, refunds, promise-to-pay/dispute follow-up, full receipt printing/evidence orchestration, and the MDS-900 report engine.
- Focused validation: `CollectionsPhase5ATest` passes 2 tests and 14 assertions, including unapplied-receipt reversal; the complete backend suite passes 36 tests with 1 intentionally skipped PostgreSQL-only schema test and 275 assertions. Pint, frontend ESLint, Vitest (11 tests across 4 files), TypeScript, and the production Vite build pass.

## Phase 5B - MDS-300 Collections & Receipts completion

Phase 5B was audited against the authoritative MDS-300 specification and the existing Phase 5A implementation. The checkpoint archive is `backups/simplebiz-before-phase5b-collections-completion-20260814-082707.zip`. The pre-change branch was `develop` and the worktree was clean before the archive was created.

### Requirement traceability matrix

| MDS-300 area | Status before Phase 5B | Phase 5B evidence | Owner / remaining boundary |
| --- | --- | --- | --- |
| Receipt draft, tender, application, unapplied, customer ledger, lifecycle | Complete | `CollectionsService`, `CollectionsController`, `CollectionsPhase5ATest` | MDS-300; preserved |
| Receipt/application correction and linked reversal history | Partial | `CollectionsService::reverse`, `reverseApplication`, forward correction rows in `payment_applications`, linked MDS-700 reversal | MDS-300; closed for documented core reversal path |
| Failed/returned instrument state | Absent | `receipt_tenders` failure fields, `POST /collections/tenders/{id}/fail`, `CollectionsPhase5BTest` | MDS-300; provider integration remains out of scope |
| Cash remittance and expected/actual variance | Absent | `cash_remittances`, lines, variances, remittance lifecycle API and verification segregation | MDS-300; physical collection and employee recovery remain out of scope |
| Controlled Other Receipts | Absent | company-scoped `other_receipt_types`, governed draft/post path, source/evidence fields, no customer application | MDS-300; arbitrary accounting lines and source-module bypass remain blocked |
| Receipt reprint | Absent | `receipt_reprints`, `POST /collections/receipts/{id}/reprint`, audit record | MDS-300; print rendering/export delivery remains shared/UI work |
| Collection activity/history | Partial | `collection_activities`, activity list/create API, live activity query in Collections workspace | MDS-300; broad dunning, route planning, AI, and provider notifications remain excluded |
| Module-owned inquiry reports | Absent | `GET /collections/reports/{report}` for documented collection/remittance/Other Receipt views | MDS-300 source queries; shared catalog/export/scheduling remains MDS-900 |
| Permissions and tenant isolation | Partial | Phase 5B permission catalog, owner/administrator grants, member inquiry grants, company-scoped queries | MDS-1100 owns configuration/admin UI; no new role builder was started |

The remaining MDS-300 gaps are presentation-level print output/evidence orchestration, richer after-commit domain events/notifications, remittance posting through the existing MDS-700 transfer document when a destination account is configured, and exhaustive PostgreSQL-only constraint coverage. They are intentionally not represented as completed capabilities.

### Implemented Phase 5B behavior

- Posted Receipt reversal preserves the original, reverses active applications atomically, restores MDS-200 Open Items and source Sale settlement fields, creates explicit linked application-reversal rows, restores or reverses unapplied credit, and creates linked MDS-700 cash counter-effects.
- A failed payment instrument is preserved with failure reason/date/reference and failed clearing state. The conservative correction path reverses the posted receipt effects before marking the receipt failed; it does not claim a failed instrument cleared and does not store provider credentials.
- Remittances derive expected amounts from same-company, posted, unremitted Receipt Tenders. Submitted amount and difference are server-calculated, verification is segregated from preparation, variances are durable, and tenders become remitted only on acceptance.
- Other Receipts are limited to documented categories, require counterparty/purpose/evidence, use configured account classification, create no customer unapplied balance, cannot apply to receivables, and remain part of the existing Receipt/Cash Movement posting boundary.
- Reprints preserve the original receipt number and financial identity and create only a `receipt_reprints` audit record.
- Report endpoints are module-owned source projections; no second report engine or customer ledger was created. Customer refund execution remains MDS-500 and no Collections Cash Out/refund endpoint was added.

### Phase 5B API and frontend surface

- `GET /api/v1/collections/other-receipt-types`
- `POST /api/v1/collections/other-receipts`
- `POST /api/v1/collections/receipts/{id}/reprint`
- `POST /api/v1/collections/tenders/{id}/fail`
- `GET|POST /api/v1/collections/activities`
- `GET|POST /api/v1/collections/remittances`
- `POST /api/v1/collections/remittances/{id}/submit|verify|accept|reject`
- `POST /api/v1/collections/remittance-variances/{id}/resolve`
- `GET /api/v1/collections/reports/{report}` for documented module-owned report projections
- `/collections` reads authenticated collection-activity and remittance APIs through TanStack Query while retaining the approved reference layout and deterministic fallback fixtures for demo presentation.

### Phase 5B validation

- Focused `CollectionsPhase5BTest`: 3 passed, 19 assertions.
- Complete backend suite after the final implementation pass: 39 passed, 1 intentional PostgreSQL-only skip, 294 assertions.
- Frontend validation after the Phase 5B workspace read integration: ESLint passed, 5 Vitest files / 12 tests passed, TypeScript and the production Vite build passed.
- `php artisan migrate --force`: Phase 5B migration `2026_08_14_000025_complete_collections_phase5b` applied successfully against the local PostgreSQL connection after adding UUID initialization for controlled receipt types.
- No files were staged, committed, pushed, reset, or cleaned. No next official business module was started.

## Phase 5C - MDS-300 final closure and acceptance

The authoritative MDS-300 review confirmed the three Phase 5B gaps before implementation: printable Receipt presentation, post-commit Collections event coverage, and remittance linkage to the existing MDS-700 Internal Transfer engine. No next official business module was started. The required checkpoint is `backups/simplebiz-before-phase5c-mds300-closure-20260814-085200.zip`; it was created from the existing Phase 5B working state with generated dependency/build directories excluded.

### Final verified traceability

| MDS-300 requirement/event area | Final state | Evidence | Boundary / owner |
| --- | --- | --- | --- |
| Receipt identity, authoritative totals, customer/payer, currency, tender, application, unapplied/advance, status, issuer, source and reversal references | IMPLEMENTED | `ReceiptPrintableResource`, `GET /api/v1/collections/receipts/{id}/print`, `ReceiptPrintPage` | MDS-300; values are read from the posted Receipt graph |
| Printable original copy and browser print layout | IMPLEMENTED | `ReceiptPrintableResource`, `ReceiptPrintPage`, print media rules in `web/src/index.css` | No PDF/report engine was introduced |
| Authorized reprint identity, marker, reason, actor/time/count, and no financial effect | IMPLEMENTED | Existing `receipt_reprints`, `POST /api/v1/collections/receipts/{id}/reprint`, `?reprint_id=...`, `CollectionsPhase5CTest` | Original receipt number is preserved |
| Receipt/application/unapplied/reversal/failed-instrument/Other Receipt/collection activity events | IMPLEMENTED | `CollectionsLifecycleEvent` implements `ShouldDispatchAfterCommit`; dispatches are attached to durable service transitions | Delivery consumers remain outside this module |
| Cash remittance created/submitted/verified/variance/accepted/posted/reversed event coverage | IMPLEMENTED | `CollectionsService` lifecycle dispatches and remittance transfer linkage | Events carry company, actor, source record, correlation ID and business date |
| Notification distinction and delivery safety | NOT APPLICABLE for external delivery | MDS-300 §14 makes external email/SMS/push conditional on configuration/consent; repository has no notification delivery table/service. Audit/activity, remittance variance, failed-tender, and existing Needs Attention surfaces remain authoritative in-app signals | Notification administration/delivery remains a configured Core/MDS-1100 concern; no second notification subsystem was created |
| Remittance expected/actual/difference and variance evidence | IMPLEMENTED | Existing Phase 5B server-derived fields plus Phase 5C transfer guard requiring an approved variance before transfer posting | No forced balancing or silent variance adjustment |
| Remittance with source and destination company Cash Accounts | IMPLEMENTED | Migration `2026_08_14_000026_close_mds300_phase5c`, `postRemittanceTransfer`, `CashTransferService::create/approve/post` | Same company, same currency, distinct accounts, `TRANSFER_OUT`/`TRANSFER_IN` capabilities |
| MDS-700 movement/accounting invariance | IMPLEMENTED | Exactly one MDS-700 transfer, one source decrease, one destination increase, shared transfer accounting, no new Receipt/application | Customer settlement and Customer Ledger do not change on remittance |
| Remittance reversal | IMPLEMENTED | `POST /api/v1/collections/remittances/{id}/reverse` reuses `CashTransferService::reverse` and restores tender eligibility | Original transfer and reversal remain linked |

### Phase 5C implementation surface

- Added `CollectionsLifecycleEvent` as an after-commit event carrying `eventName`, company, source type/id, actor, correlation ID, and business date. Idempotent controller actions dispatch once because replayed requests return the stored response before the service runs.
- Added server-authoritative printable Receipt output. Sensitive external/instrument references are masked to the last four characters; protected credentials are never included. Print is a read operation; reprint is an explicit, permission-protected audit operation.
- Added `GET /api/v1/collections/receipts/{id}/print`, `GET /api/v1/collections/remittances/{id}`, and `POST /api/v1/collections/remittances/{id}/reverse`. Existing print/reprint permissions are preserved; reversal uses the new `collections.remittance.reverse` permission.
- Added `cash_remittances.source_cash_account_id`, `cash_transfer_document_id`, `transfer_posted_at`, and reversal evidence fields in the forward migration `2026_08_14_000026_close_mds300_phase5c`. The Cash Remittance and Cash Transfer models expose both directions of the link.
- When a destination Cash Account is configured, acceptance derives one source from the included posted Tenders, validates same company/currency and capabilities, creates/approves/posts one existing MDS-700 Internal Transfer, and only then marks Tenders `remitted`. Missing capability, invalid destination, missing TRANSFER Reason Code, unresolved variance, insufficient source balance, or any transfer failure rolls back the complete acceptance.
- When no destination Cash Account is configured, the existing custody/evidence acceptance path remains in place and no fabricated internal transfer is created.
- Added the responsive `web/src/features/collections/ReceiptPrintPage.tsx` route at `/collections/receipts/:id/print` with Print, permission-protected Reprint, original/duplicate marker, tender/application/unapplied/reversal summaries, masked references, and print CSS. The Collections workspace now shows live remittance source/destination and transfer status when available.

### Phase 5C validation

- Focused `CollectionsPhase5CTest`: 3 passed, 40 assertions (print payload/masking/reprint non-financial behavior, valid MDS-700 remittance transfer/reversal, and transfer rollback on invalid destination capability).
- Focused Phase 5A + 5B regression: 5 passed, 33 assertions.
- Complete backend suite: 42 passed, 1 intentional PostgreSQL-only skip, 334 assertions.
- `php artisan migrate --force`: `2026_08_14_000026_close_mds300_phase5c` applied successfully to the local PostgreSQL connection.
- `php artisan route:list --path=api/v1/collections`: 39 Collections routes listed, including print, remittance detail, and remittance reversal.
- `vendor/bin/pint --test`: passed. Frontend ESLint passed. Vitest passed 13 tests across 6 files. TypeScript/Vite production build passed. `git diff --check` passed.
- Docker/Render validation remains subject to the environment: the Docker CLI was present but the Docker Desktop daemon was unavailable, and the Render CLI was not installed; no deployment configuration was changed in Phase 5C.

### Final MDS-300 disposition

MDS-300 is fully implemented for the documented module scope and the verified Phase 5C gaps are closed. The following remain intentionally outside MDS-300 ownership: customer refund execution (MDS-500), cash movement/transfer accounting ownership (MDS-700), shared report engine/export/scheduling (MDS-900), notification administration and external delivery configuration (Core/MDS-1100), provider gateways/webhooks/bank feeds, and unsupported cross-currency or speculative workflows. No second transfer engine, second cash ledger, second Receipt source of truth, or unsupported business module was added.

## Phase 6A - MDS-600 Inventory Core Foundation

Phase 6A establishes the first authoritative Inventory foundation without starting the Purchases module or inventing deferred Inventory capabilities. A checkpoint archive was created before implementation at `backups/simplebiz-before-phase6a-inventory-20260814-092749.zip`. The Render blueprint and deployment configuration were preserved.

### Requirement traceability matrix

| MDS-600 area | Phase 6A state | Evidence | Boundary / remaining owner |
| --- | --- | --- | --- |
| Stock-managed product, warehouse, location and base-unit scope | IMPLEMENTED | Existing MDS-100/MDS-400 registries, Inventory lookups, same-company hierarchy validation | Product/Warehouse/Location ownership remains in Master Registries |
| Inventory Balance and availability | IMPLEMENTED | `inventory_balances`, server-derived `on_hand`, `reserved`, `incoming`, `outgoing`, `in_transit`, `held`, `count_frozen`, and `available` | No editable Product quantity or balance field was added |
| Immutable posted stock movements | IMPLEMENTED | `stock_movements`, movement snapshots, source links, status, correlation/idempotency identity, linked reversal fields | Posted movement rows are not edited; correction uses linked counter-movements |
| Draft document correction before posting | IMPLEMENTED | PATCH draft endpoints, optimistic `version` checks, full line replacement while draft, audit entries | Posted documents remain immutable and use reversal |
| Opening stock | IMPLEMENTED | `opening_stock_documents`, lines, create/post/reverse APIs, durable OPEN numbering | Physical count approval and count freeze remain later Inventory work |
| Direct receipt and issue | IMPLEMENTED | `stock_receipts`, `stock_issues`, create/post/reverse APIs, positive quantity and negative-stock guards | Purchases receipt and supplier workflow are not included in Phase 6A |
| Internal transfer | IMPLEMENTED | `stock_transfers`, two atomic `transfer_out`/`transfer_in` legs, linked reversal support | Full in-transit lifecycle is deferred; Phase 6A uses atomic same-company transfer posting |
| Sales stock issue | IMPLEMENTED | Sales lines persist Warehouse/Stock Location; posted credit Sales create one `sale_issue` per stock-managed line | Cash Sales remain behind Collections; valuation/COGS policy remains deferred |
| Reservations and held stock | DEFERRED | Availability model has the documented fields, but no reservation workflow was invented | Add only with an approved Sales fulfillment/reservation requirement |
| Valuation and accounting/COGS | DEFERRED | No valuation amount or second accounting engine was added | Requires approved valuation policy and accounting integration |
| Permissions, audit, events, company isolation | IMPLEMENTED | Phase 6A Inventory permission migration, `AuditService`, after-commit `InventoryLifecycleEvent`, company-scoped queries | Configuration/admin ownership remains MDS-1100 |

### Phase 6A API and frontend surface

- `GET /api/v1/inventory/summary`
- `GET /api/v1/inventory/lookups`
- `GET /api/v1/inventory/balances`
- `GET /api/v1/inventory/movements`
- `GET /api/v1/inventory/availability/{productId}`
- `POST|PATCH /api/v1/inventory/opening-stock`, `POST /opening-stock/{id}/post|reverse`, `GET /opening-stock/{id}`
- `POST|PATCH /api/v1/inventory/receipts`, `POST /receipts/{id}/post|reverse`, `GET /receipts/{id}`
- `POST|PATCH /api/v1/inventory/issues`, `POST /issues/{id}/post|reverse`, `GET /issues/{id}`
- `POST|PATCH /api/v1/inventory/transfers`, `POST /transfers/{id}/post|reverse`, `GET /transfers/{id}`
- `/inventory` now provides a responsive Inventory workspace with server-backed balances and movement history, TanStack Table, TanStack Query, Lucide icons, action forms, warehouse/location filters, and clear loading/error/empty states.

### Phase 6A validation

- `InventoryPhase6ATest`: 4 passed, 32 assertions, covering draft versioned updates, opening stock plus receipt idempotency, negative-stock rejection, atomic transfer/reversal, and posted stock-managed Sales integration.
- Complete backend suite: 46 passed, 1 intentionally skipped PostgreSQL-only schema test, 366 assertions.
- Frontend ESLint passed; Vitest passed 14 tests across 7 files; TypeScript and the production Vite build passed.
- Local PostgreSQL migrations `2026_08_14_000027_create_inventory_phase6a`, `2026_08_14_000028_seed_inventory_phase6a_permissions`, and `2026_08_14_000029_seed_inventory_draft_update_permissions` applied successfully.
- No Purchases tables, purchase APIs, seed/demo business data, Render plan changes, or deployment configuration changes were introduced.

## Phase 6B - MDS-600 Inventory Completion

Phase 6B completes the Inventory-owned controls that can be implemented without starting Purchases & Payables, changing Sales ownership, or introducing a second stock ledger. The forward-only checkpoint archive is `backups/simplebiz-before-phase6b-inventory-completion-20260814-100658.zip`. The existing Render blueprint, Docker/Nginx deployment configuration, and Phase 6A migrations were preserved.

### Requirement traceability matrix

| MDS-600 area | Phase 6B state | Evidence | Boundary / remaining owner |
| --- | --- | --- | --- |
| Governed Stock Adjustments | IMPLEMENTED | `inventory_adjustments`, immutable line snapshots, reason/evidence/explanation validation, submit/review/approve/post/cancel/reverse APIs, linked stock movements | Approval segregation remains permission and actor based; posted rows are corrected by linked reversal |
| Physical Count snapshot, entries and recount | IMPLEMENTED | `stock_counts`, `stock_count_items`, `stock_count_entries`, immutable expected snapshot, recount sequence, variance calculation, approval, linked variance adjustment, post and close APIs | Advanced count assignment/scheduling is outside this increment |
| Reservation, consume, release and expiry | IMPLEMENTED | `stock_reservations`, event history, atomic reserved quantity updates, oversubscription guard, Sales draft synchronization, post consumption, cancellation release | Reservations are source-linked; no free-floating commercial reservation source is created |
| Balance quantity states | IMPLEMENTED | Existing `on_hand`/`reserved`/`available` engine plus authoritative reservation and count variance effects | Incoming/in-transit remains zero unless an owning source module exists |
| Low stock, out of stock and reorder controls | IMPLEMENTED | `inventory_reorder_rules`, attention projection, summary counts, low/out-of-stock reports and recovery-compatible derived queries | No Purchase Order or supplier replenishment data is fabricated |
| Valuation support | IMPLEMENTED as optional source-backed support | Optional movement cost fields, `inventory_valuation_records`, BCMath totals, cost/currency/source fields, cost-sensitive permissions and valuation report | No FIFO, weighted average, FX conversion, or accounting/COGS method was invented; Settings/policy and accounting ownership remain external |
| Simple internal transfer | PRESERVED | Existing atomic linked transfer-out/transfer-in movement architecture and reversal | In-transit, partial receipt, discrepancy and transit custody require an approved edition/policy; no artificial transit state was added |
| Sales return effect | DEFERRED TO MDS-200 | No authoritative Sales Return source exists in the current MDS-200 implementation | No fake return document or duplicate Sales source was added |
| Purchase incoming/receipt contract | DEFERRED TO MDS-400 | Expected-receipts report returns zero with `deferred_to_mds_400` metadata | No Purchase Order, Supplier Invoice, Goods Receipt, or fake incoming data was created |
| Internal-use Stock Issue | IMPLEMENTED at Inventory boundary | Existing direct Stock Issue workflow remains available for internal use | Expense recognition is deferred to MDS-800 |
| Stock Card and Inventory reports | IMPLEMENTED | Server-running Stock Card plus Inventory-owned `inventory_position`, `stock_card`, `movement_register`, `low_stock`, `out_of_stock`, `count_variances`, `adjustments`, `transfers`, `reservations`, `valuation`, and `expected_receipts` projections | Shared export/scheduling/report engine remains MDS-900 |
| Dashboard and Needs Attention source measures | IMPLEMENTED at source layer | Inventory summary exposes low/out/count measures and `/inventory/attention` derives low/out conditions | Dashboard presentation can consume these measures; no demo inventory values were introduced |
| Barcode lookup | IMPLEMENTED | `/api/v1/inventory/barcode/{barcode}` resolves the existing Product barcode within company scope | Scanner SDK/device integration is not part of MDS-600 |
| Base-unit and conversion controls | IMPLEMENTED for base-unit integrity | Inventory lines and movement snapshots enforce the Product base Unit of Measure | Arbitrary conversion factors remain deferred to the authoritative Unit registry/MDS-1000 extension |
| Company scope, permissions, audit, idempotency and concurrency | IMPLEMENTED | Phase 6B permission catalog, company-scoped queries, AuditService, after-commit lifecycle events, idempotency keys, version checks and locked balance/reservation updates | Cost fields are separately permission-gated; platform configuration remains MDS-1100 |

### Phase 6B API and frontend surface

- `GET|POST|PATCH /api/v1/inventory/adjustments`, `GET /adjustments/{id}`, lifecycle actions `submit|review|approve|post|cancel|reverse`.
- `GET|POST /api/v1/inventory/counts`, `GET /counts/{id}`, `start`, `entries`, `recount`, `submit|review|approve|post|close|reopen|cancel`.
- `GET|POST /api/v1/inventory/reservations`, `GET /reservations/{id}`, `consume|release|expire`.
- `GET|POST|PATCH|DELETE /api/v1/inventory/reorder-rules` plus `GET /inventory/attention`.
- `GET /api/v1/inventory/stock-card`, `GET /api/v1/inventory/reports/{report}`, and `GET /api/v1/inventory/barcode/{barcode}`.
- `/inventory` now includes responsive Phase 6B action cards, out-of-stock summary, attention/count/adjustment/reservation/reorder panels, a physical-count shortcut, and source-controlled guidance for workflows that must originate from Sales or an approved Inventory document. Existing TanStack Query/Table and Lucide presentation is retained.

### Phase 6B validation

- `InventoryPhase6BTest`: 4 passed, 45 assertions, covering reason-controlled adjustment posting/reversal, physical count variance posting/closure, Sales reservation hold/consume behavior, reorder attention, Stock Card, reports, and barcode scope.
- Complete backend suite: 50 passed, 1 intentionally skipped PostgreSQL-only schema test, 411 assertions.
- `php artisan migrate:status`: all migrations through `2026_08_14_000030_complete_inventory_phase6b` are applied locally.
- `php artisan route:list --path=api/v1/inventory`: 62 Inventory routes listed.
- `vendor/bin/pint --test`: passed. Frontend ESLint passed; Vitest passed 14 tests across 7 files; TypeScript and the production Vite build passed. `git diff --check` passed.
- Docker image validation was not rerun because the Docker Desktop daemon is unavailable in this environment. Render CLI validation was not run because the Render CLI is not installed. No deployment configuration was changed.
- No Purchases & Payables commercial logic, Supplier Invoice, Expense, Payment, second ledger, duplicate registry, fake incoming stock, demo business data, Render plan, or user-facing non-Inventory module was added.

## Phase 7A - MDS-400 Purchases & Payables core foundation

Phase 7A starts the bounded MDS-400 slice approved after the MDS-600 gate: Purchase Orders, Goods Receipts, supplier-invoice matching and posting, Payable Open Items, and the MDS-600 incoming/receipt contract. It does not claim MDS-400 completion. A pre-change checkpoint was created at `backups/simplebiz-before-phase7a-purchases-20260814-103610.zip`; the branch remains `develop`, and no files were staged, committed, pushed, reset, or cleaned.

### Requirement traceability matrix

| MDS-400 area | Phase 7A state | Evidence | Boundary / remaining owner |
| --- | --- | --- | --- |
| Purchase Order identity, supplier, lines, totals and snapshots | IMPLEMENTED | `purchase_orders`, `purchase_order_lines`, `PurchasingService`, server-owned BCMath totals | Supplier/product/terms/tax registries remain MDS-1000 |
| Purchase Order draft, submit, review, approve, cancel, close and history | IMPLEMENTED | Lifecycle API under `/api/v1/purchases/orders`, optimistic versions, status history and audit | Advanced amendment/reopen and approval policy configuration remain MDS-1100/next Purchases slice |
| PO has no stock, payable, expense or cash effect | IMPLEMENTED | PO tests and service boundary; incoming is only a derived MDS-600 projection after approval | MDS-600 owns balance/movement effects |
| MDS-600 incoming projection from open stock PO quantities | IMPLEMENTED | `InventoryService::syncPurchaseIncoming`, balance `incoming`, partial/full receipt tests | Incoming is source-derived; no fake or manual incoming quantity exists |
| Goods Receipt identity, partial receiving and discrepancy quantities | IMPLEMENTED | `goods_receipts`, `goods_receipt_lines`, accepted/rejected/damaged/short/backordered fields, over-receipt guard | Returns, replacement and supplier credit workflows remain deferred |
| Accepted stock receipt integration | IMPLEMENTED | `InventoryService::postPurchaseReceipt`, `purchases` source module, `GoodsReceipt`/line source links, reversal reuse | MDS-600 remains the authoritative stock ledger |
| Service/non-stock receiving | IMPLEMENTED at evidence boundary | Goods Receipt captures accepted service quantities without a stock movement | Expense recognition remains MDS-800 |
| Supplier Invoice identity, duplicate external reference and totals | IMPLEMENTED | `supplier_invoices`, lines, company/supplier uniqueness, server calculations | Rich duplicate/suspicion scoring remains a later control increment |
| Two-way/three-way matching core and match exceptions | PARTIAL | PO quantity/price and stock receipt presence checks, durable `purchase_match_exceptions`, authorized exception approval | Configurable tolerance matrix, advanced matching and broader exception controls remain deferred |
| Supplier Invoice approval and accounting posting | IMPLEMENTED for configured core accounts | `BusinessTransaction`, balanced `accounting_transactions`, configured A/P plus Inventory/Expense/Recoverable Tax Account Titles | Templates/policy configuration remain MDS-1100; corrections/reversals need the next controlled slice |
| Payable Open Item, due date and aging | IMPLEMENTED | `payable_open_items`, payment-term-derived due date, `/purchases/payables` and `/purchases/payables/aging` | Payment allocation and settlement remain MDS-500 |
| Supplier ledger and purchase history source projections | PARTIAL | Read-only purchase history endpoint and payable source links | Expanded supplier ledger/report catalog remains next Purchases/MDS-900 work |
| Payment handoff, supplier payments, payment batches and cash out | DEFERRED TO MDS-500 | UI marks Pay Supplier as deferred; no payment table, API, or cash movement was added | MDS-500 owns execution and settlement; MDS-700 owns cash effects |
| Purchase returns and debit/credit adjustments | DEFERRED | No duplicate return/adjustment source was introduced | Next Purchases increment after this foundation |
| Evidence/attachments | PARTIAL | Existing evidence-reference contract is preserved on PO/GR/Supplier Invoice; existing governed AttachmentService remains the single attachment boundary | Purchases-specific upload presentation remains a later UI increment |
| Permissions, tenant isolation, audit, idempotency and concurrency | IMPLEMENTED for this slice | Phase 7A permission catalog, setup grants, company-scoped queries, AuditService, idempotency wrappers, locked lifecycle/posting paths | Role configuration remains MDS-1100 |
| Reference-faithful responsive Purchases workspace | IMPLEMENTED for core surface | Live TanStack Query reads, TanStack Table activity, Recharts summary, Lucide actions, responsive reference layout and deferred payment affordance | Detail pages, full report navigation and expanded forms remain next UI work |

### Phase 7A API and frontend surface

- `GET /api/v1/purchases/lookups` and `GET /api/v1/purchases/summary`.
- `GET|POST|PATCH /api/v1/purchases/orders`, `GET /orders/{id}`, lifecycle `submit|review|approve|cancel|close`, and order history.
- `GET|POST /api/v1/purchases/receipts`, `GET /receipts/{id}`, lifecycle `submit|post|reverse`.
- `GET|POST /api/v1/purchases/invoices`, `GET /invoices/{id}`, lifecycle `submit|approve|post`; reversal is explicitly guarded for the next correction slice.
- `GET /api/v1/purchases/payables`, `GET /payables/{id}`, and `GET /payables/aging`.
- `/purchases` now reads live summary, lookup, order, receipt, and invoice APIs. Its reference-faithful action cards open bounded creation/receiving/invoice flows; Pay Supplier is visibly locked to MDS-500. No static purchase demo data is used.

### Phase 7A validation

- `PurchasesPhase7ATest`: 3 passed, 36 assertions, covering PO approval/incoming, partial and full Goods Receipt stock effects, MDS-600 source identity, no cash effect, supplier invoice payable/accounting posting, duplicate supplier reference, and inactive supplier scope rejection.
- Complete backend suite after Phase 7A implementation: 53 passed, 1 intentionally skipped PostgreSQL-only schema test, 447 assertions.
- `php artisan migrate --force`: `2026_08_14_000031_create_purchases_phase7a` applied successfully to the local PostgreSQL connection.
- `php artisan route:list --path=api/v1/purchases`: 29 Purchases routes listed.
- `vendor/bin/pint --test`: passed. Frontend ESLint passed; Vitest passed 14 tests across 7 files; TypeScript and the production Vite build passed.
- Docker image validation remains unavailable because the Docker Desktop daemon is not running. Render CLI validation remains unavailable because the Render CLI is not installed. `render.yaml` was not modified.

Phase 7A remains intentionally partial against MDS-400. The next bounded Purchases increment should close controlled invoice correction/reversal, richer matching/tolerance and evidence presentation, direct-purchase completion, returns and supplier adjustments, supplier ledger/report projections, and the MDS-500 payment-handoff contract only when that phase is formally started. No MDS-500 or new business module was started here.

## Phase 7B - MDS-400 Purchases & Payables completion

Phase 7B completes the remaining governed Purchases source-document controls while preserving the strict MDS-500 boundary. The pre-change checkpoint is `backups/simplebiz-before-phase7b-purchases-completion-20260814-110824.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned, and `render.yaml` was preserved.

### MDS-400 traceability matrix

| MDS-400 area | Phase 7B state | Evidence / boundary |
| --- | --- | --- |
| Purchase Order amendment and reopen | IMPLEMENTED | `purchase_order_revisions`, versioned amendment endpoint, approval invalidation, incoming projection resynchronization, controlled reopen |
| Goods Receipt partial/discrepancy/reversal | IMPLEMENTED | Existing Phase 7A receiving source is preserved; reversal now reverses each linked MDS-600 Stock Receipt once, restores PO receiving eligibility and refreshes incoming |
| Purchase Return source document | IMPLEMENTED | `purchase_returns`, lines, source Goods Receipt/PO linkage, status history, eligible-line endpoint, explicit lifecycle and immutable posted source |
| Returnable quantity and concurrency | IMPLEMENTED | Locked Goods Receipt/source rows, authoritative prior-return aggregation, positive quantity and over-return rejection |
| MDS-600 return stock effect | IMPLEMENTED | Reuses `InventoryService::postPurchaseReturn`; `purchases` source module, Purchase Return source ID/line links, exact-once stock out and governed reversal; service items create no movement |
| Return commercial credit boundary | IMPLEMENTED | Posted Purchase Return creates an eligible Supplier Debit Adjustment; no automatic cash or fake payment and no direct Payable edit |
| Supplier Invoice correction/reversal | IMPLEMENTED | Immutable original, `supplier_invoice_corrections`, reason/evidence/lock-date validation, correction lifecycle/history endpoint, accounting reversal, payable effect reversal, idempotency and PO invoicing eligibility restoration |
| Supplier debit/credit adjustments | IMPLEMENTED | Source-linked `supplier_adjustments`, line snapshots, backend totals, approval/post/reverse lifecycle, balanced accounting, additive/subtractive Payable effects and reversal accounting |
| Payable corrections and hold/dispute | IMPLEMENTED | `payable_effects` and `payable_hold_histories`; remaining amount is derived through effects, original amount is preserved, aging uses corrected remaining, holds never create payment |
| MDS-500 payment-readiness contract | IMPLEMENTED at boundary | Payables expose company, branch, supplier, source invoice, currency, original/remaining amount, due/hold/settlement state, version and derived `payment_eligibility`; MDS-500 still owns settlement |
| Matching completion/history | IMPLEMENTED for documented core | Quantity/cost/receipt/missing-receipt exceptions, record-level tolerance, explicit resolution permission, durable `purchase_match_histories`, re-evaluation after correction; no source-document mutation or payment |
| Purchase history, supplier ledger and reports | IMPLEMENTED | Source projections for orders, receipts, returns, invoices, adjustments, payables, aging, matching exceptions and supplier ledger; currency remains source-separated and includes as-of context |
| Dashboard/Needs Attention measures | IMPLEMENTED | Live summary/attention projections include approvals, invoice exceptions, returns, adjustments, holds, overdue deliveries, pending receipt corrections, expected receipt quantity and Payable due states |
| Permissions, audit, events, idempotency, tenant isolation | IMPLEMENTED | Phase 7B permission catalog, company-scoped queries, optimistic versions, locked posting paths, audit records, after-commit lifecycle events and Idempotency-Key boundaries |
| Responsive Purchases workspace | IMPLEMENTED for Phase 7B surface | Live TanStack Query/Table, Recharts, Lucide source-document cards/forms, returnable-line data, correction queue/lifecycle actions, payable/hold visibility, loading/error/empty states and mobile-safe layouts |

### Phase 7B API and frontend surface

- Purchase Returns: `GET /api/v1/purchases/returns`, `GET /returns/{id}`, `GET /returns/eligible-lines`, `POST /returns`, lifecycle `submit|review|approve|post|cancel|reverse`.
- Supplier Adjustments: `GET|POST /api/v1/purchases/adjustments`, `GET /adjustments/{id}`, lifecycle `submit|approve|post|reverse`.
- Supplier Invoice Corrections: `GET /api/v1/purchases/invoice-corrections`, `GET /invoice-corrections/{id}`, `POST /invoices/{id}/correction`, lifecycle `submit|approve|post|reverse`.
- Payable controls: `GET /payables/{id}/effects`, `POST /payables/{id}/hold|dispute|release`, plus branch/company/payment-readiness fields on Payable resources.
- Matching, ledger, reports, attention, PO amendment/reopen and the existing Goods Receipt reversal remain under `/api/v1/purchases`.
- `/purchases` now reads live returns, adjustments, invoice corrections, Payables and attention data. Source-document actions require server-side lifecycle permissions; no client-supplied accounting or inventory balances are accepted.

### Phase 7B validation

- Focused `PurchasesPhase7BTest`: **5 passed, 73 assertions**, covering quantity-safe Purchase Returns, MDS-600 stock out/reversal, Supplier Invoice reversal and derived Payable correction, adjustment effects/hold/reversal accounting, match exception history/resolution, and PO amendment/re-approval/incoming projection.
- Complete backend suite after Phase 7B: **58 passed, 1 intentionally skipped PostgreSQL-only schema test, 520 assertions**.
- Local PostgreSQL migrations through `2026_08_14_000033_complete_purchases_payment_readiness` are applied. The migration is PostgreSQL-safe and its refresh path is also covered by the SQLite-backed test harness.
- `php artisan route:list --path=api/v1/purchases`: **63 routes**.
- `vendor/bin/pint --test`: passed. Frontend ESLint passed; Vitest passed **14 tests across 7 files**; TypeScript and the production Vite build passed. `git diff --check` passed.
- Docker image build/runtime validation remains unavailable because the Docker Desktop daemon is not running. Render CLI validation remains unavailable because the Render CLI is not installed. `render.yaml`, Dockerfile, Nginx configuration and Laravel startup configuration were not changed.

### Phase 7B boundaries and acceptance

- MDS-500 Supplier Payment execution, payment applications/batches, checks, bank transfers, outgoing Cash Movements and supplier refunds remain **DEFERRED TO OWNER MODULE MDS-500**.
- MDS-700 cash effects, MDS-800 expense-owned behavior, MDS-900 shared export/scheduling, and MDS-1100 configurable approval/tolerance policy remain owned by those modules. Phase 7B records source-level tolerance and preserves the configuration boundary; it does not invent a second settings engine.
- No Supplier Payment tables, fake payment records, Direct Cash Out, Cash Movement, second Inventory ledger, negative transaction-line correction, or demo purchase data was added.
- Posted Supplier Invoices and Goods Receipts remain immutable; corrections and reversals are linked source documents/counter-effects. Payable balances remain effect-derived and MDS-600 owns stock balances/movements.

For the implemented MDS-400-owned scope, the completion gate is:

`MDS-400 FULLY IMPLEMENTED`

The next official phase is **Phase 8A - MDS-500 Payments & Disbursements Core Foundation**. Phase 8A was implemented in the next run below.

## Phase 8A - MDS-500 Payments & Disbursements core foundation

Phase 8A implements the bounded supplier-payment vertical slice defined by MDS-500, using MDS-400 Payable Open Items as the source obligation and MDS-700 as the only cash-effect owner. The checkpoint archive is `backups/simplebiz-before-phase8a-payments-20260814-121500.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned, and `render.yaml` was preserved.

### MDS-500 traceability matrix

| MDS-500 area | Phase 8A state | Evidence / boundary |
| --- | --- | --- |
| Payment Request identity and source proposal | IMPLEMENTED for supplier obligations | `payment_requests`, source links, exact requested amount/date, request history, submit/return/approve/reject/cancel lifecycle |
| Payment Instruction identity and source linkage | IMPLEMENTED for supplier payments | `payment_instructions`, payable source links, supplier/currency/branch/method/account validation, server-owned net calculation |
| Payment workbench and eligibility | IMPLEMENTED | `/api/v1/payments/workbench`, company-scoped eligible posted supplier invoices, remaining amount/hold/due/source filters and no fake obligations |
| Approval and version control | IMPLEMENTED for the bounded flow | Submitted version snapshot, approval authority context, optimistic versions, exact submitted source revalidation and approval history |
| Scheduling | IMPLEMENTED at single-instruction level | Future scheduled date is governed and cannot be released early; recurring/batch scheduling remains deferred |
| Check foundation | IMPLEMENTED at reserve/release boundary | Check instrument and unique company/account check number are created only on release; full print/custody/stop/stale/cleared lifecycle remains deferred |
| Electronic and cash execution state | IMPLEMENTED as controlled manual channel boundary | Release creates an execution attempt; pending/failure/rejection are distinct; no external bank success is fabricated |
| Confirmation and evidence | IMPLEMENTED | Separate confirm routes; cash confirmation requires acknowledgement and evidence; electronic/check confirmation requires an external or instrument reference |
| MDS-700 cash movement | IMPLEMENTED | Confirmation alone calls `CashMovementService::createPaymentEffect`; the posted decrease is source-linked to the Payment Instruction and balanced through Accounts Payable; draft/approved/released/pending/failed payments create no cash movement |
| MDS-400 payable allocation | IMPLEMENTED for confirmed supplier payments | Partial/multi-step allocation updates `PayableEffect`, paid/remaining/settlement projections and payment allocation history without editing the source invoice |
| Remittance advice and timeline | IMPLEMENTED | Confirmed payments can create a durable Remittance Advice and expose status, approval, execution, confirmation and allocation timeline data |
| Permissions, audit/events, idempotency, company scope | IMPLEMENTED for this slice | Payment permission catalog, setup grants, company-scoped queries, version checks, Idempotency-Key wrappers and after-commit lifecycle event boundary |
| Batches, advances, expense payments, FX, bank APIs, advanced recovery/corrections | DEFERRED TO PHASE 8B | No undocumented batch, expense, external bank, FX, retry/recovery, reversal, unapply/reallocation, or demo functionality was invented |

### Phase 8A API and frontend surface

- Payment reads: `GET /api/v1/payments/lookups`, `/summary`, `/workbench`, `/requests`, and `/payments` with company-scoped pagination.
- Payment Requests: `POST /api/v1/payments/requests`, request detail, `submit|return|approve|reject|cancel`, and approved-request conversion to a Payment Instruction.
- Payment Instructions: `POST /api/v1/payments`, detail, `submit|return|approve|reject|schedule|release|confirm|confirm-cash|confirm-electronic|pending|fail|reject-channel|cancel|void`, allocation, timeline, and remittance advice endpoints.
- `/payments` now provides a responsive reference-faithful Payments & Disbursements workspace with live TanStack Query reads, a TanStack Table activity surface, a Recharts summary, Lucide icons, payment preparation form, lifecycle queue, workbench, loading/error/empty states, and explicit MDS-700 control-boundary messaging.

### Phase 8A validation

- Focused `PaymentsPhase8ATest`: **4 passed, 48 assertions**, covering cash confirmation and allocation, pending/failure without cash effects, approved-request conversion, held-payable exclusion, source history, and payment workbench scope.
- Complete backend suite after Phase 8A: **62 passed, 1 intentionally skipped PostgreSQL-only schema test, 568 assertions**.
- `php artisan migrate --force`: `2026_08_14_000034_create_payments_phase8a` applied successfully locally, including request/instruction/source/approval/instrument/attempt/confirmation/allocation/history/remittance tables and payment permissions.
- `php artisan route:list --path=api/v1/payments`: payment workbench, request, instruction lifecycle, confirmation, allocation, timeline, and remittance routes registered.
- `vendor/bin/pint --test`: passed. Frontend ESLint passed; Vitest passed **15 tests across 8 files**; TypeScript and the production Vite build passed.
- Docker image/runtime validation was not rerun because the Docker Desktop daemon is unavailable in this environment. Render CLI validation was not run because the Render CLI is not installed. `render.yaml` was not modified.
- No MDS-800 Expense records, business-module changes outside the payment handoff/cash boundary, direct payable balance edits, fake bank confirmations, demo payment data, Render plan changes, staging/commit/push operations, or deployment-configuration changes were introduced.

### Phase 8A boundaries and next gate

Phase 8A is the supplier-payment foundation, not full MDS-500 completion. The next bounded increment is **Phase 8B - MDS-500 payment completion and governed exception workflows**, subject to the authoritative MDS-500 specification and an explicit scope checkpoint. It may address the documented deferred areas in order—batch controls, fuller check custody, bank/integration boundaries, recovery/duplicate handling, unapply/reallocation, advances, corrections/reversals, and any approved cross-module payment sources—without starting MDS-800 or changing the MDS-700 ownership boundary.

## Phase 8B - MDS-500 Payments & Disbursements completion

Phase 8B closes the verified MDS-500-owned gaps from Phase 8A. The pre-change checkpoint is `backups/simplebiz-before-phase8b-payments-completion-20260814-123839.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned. `render.yaml`, Docker, Nginx, startup, Render service, and deployment configuration were preserved.

The complete MDS-500 traceability matrix, API/database/permission details, frontend surface, safety boundaries, and acceptance record are documented in [`docs/MDS-500-PHASE-8B.md`](MDS-500-PHASE-8B.md).

### Phase 8B implementation summary

- Payment corrections preserve originals and implement reason/evidence-controlled cancellation, void, confirmed reversal, allocation unapplication, and reallocation. Confirmed reversal uses the existing MDS-700 CashMovementService and restores MDS-400 payable effects without direct balance editing.
- Supplier advances, payment batches, item-level execution outcomes, maker-checker approval, validation, generation, release, execution, closure, check print/sign/release/stop/void/stale/replacement, controlled recovery, duplicate review, vouchers, payment evidence, operational reports, and Needs Attention measures are implemented.
- `/payments` now exposes live Phase 8B exception, batch, check, unallocated, and supplier-advance controls while retaining the Phase 8A responsive TanStack Query/Table, Recharts, and Lucide workspace.
- Migrations `2026_08_14_000035_complete_payments_phase8b` through `2026_08_14_000039_seed_payment_evidence_permissions_phase8b` applied successfully locally. No applied migration was modified.

### Phase 8B validation and boundaries

- Focused `PaymentsPhase8ATest` + `PaymentsPhase8BTest`: **10 passed, 130 assertions**.
- Complete backend suite: **68 passed, 1 intentionally skipped PostgreSQL-only schema test, 650 assertions**.
- Frontend: **15 tests across 8 files passed**, ESLint passed, TypeScript/Vite build passed, and Pint passed.
- Docker validation was unavailable because Docker Desktop was not running. Render CLI validation was unavailable because Render CLI is not installed. `git diff --check` passed.
- MDS-700 clearing/reconciliation, MDS-800 expense/reimbursement sources, MDS-900 shared reporting/export/scheduling, MDS-1100 configuration, and external bank/provider integrations remain in their authoritative owner boundaries. No fake provider success or MDS-800 source data was added.

For the implemented Free/staging/documented MDS-500 scope, the completion gate is:

`MDS-500 FULLY IMPLEMENTED`

The next official phase is **Phase 9A - MDS-800 Expenses Core Foundation**. It was not started during Phase 8B.

## Phase 9A - MDS-800 Expenses Core Foundation

Phase 9A implements the bounded direct-expense vertical slice defined by MDS-800. The checkpoint archive is `backups/simplebiz-before-phase9a-expenses-20260814-133421.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned. `render.yaml`, Docker, Nginx, startup, and deployment configuration were preserved.

The complete MDS-800 traceability matrix, API/database/permission details, frontend surface, ownership boundaries, and deferred Phase 9B scope are documented in [`docs/MDS-800-PHASE-9A.md`](MDS-800-PHASE-9A.md).

### Phase 9A implementation summary

- Expense Records, lines, category/account/tax classification, server-owned totals, line allocations, duplicate candidates, evidence metadata, status history, approvals, obligations and payment-readiness projections are implemented.
- Paid Now and Pay Later are implemented without direct cash creation. Posted Expenses create balanced accounting and an Expense Obligation; MDS-500 receives the obligation through an explicit Expense-owned payment source, and settlement synchronizes back through `ExpenseSettlementService`.
- `/expenses` now provides the responsive live workspace with summary cards, attention/unpaid/history panels, a recording form, TanStack Table, Recharts, Lucide controls, mobile layout, and no fabricated expense data.
- Reimbursements, recurring expenses, credits/refunds/adjustments, bulk/import/OCR/AI and advanced reports remain deferred to Phase 9B or their authoritative owner modules.

### Phase 9A validation

- Focused `ExpensesPhase9ATest`: **3 passed, 27 assertions**.
- Complete backend suite: **71 passed, 1 intentionally skipped PostgreSQL-only schema test, 677 assertions**.
- Frontend Vitest: **16 passed across 9 files**; ESLint passed; TypeScript/Vite build passed.
- `php artisan migrate --no-interaction`: migrations `2026_08_14_000040_create_expenses_phase9a` and `2026_08_14_000041_integrate_expenses_with_payments_phase9a` applied successfully locally.
- `php artisan route:list --path=api/v1/expenses`: 24 Expenses routes registered. PHP syntax checks passed for the new service/controller paths.
- Docker validation remains unavailable because Docker Desktop is not running. Render CLI validation remains unavailable because Render CLI is not installed. `render.yaml` was not modified.

For the implemented direct-expense scope, the completion gate is:

`MDS-800 PHASE 9A COMPLETE; MDS-800 FULL COMPLETION REMAINS DEFERRED`

The next official increment is **Phase 9B - MDS-800 Expenses Completion**, subject to its authoritative scope and a new checkpoint.

## Phase 9B - MDS-800 Expenses Completion

Phase 9B implements the verified MDS-800 completion increment. The checkpoint archive is `backups/simplebiz-before-phase9b-expenses-completion-20260814-141038.zip`. The complete gap matrix, API surface and ownership boundary are documented in [`docs/MDS-800-PHASE-9B.md`](MDS-800-PHASE-9B.md).

### Phase 9B implementation summary

- Reimbursement Claims, claimant/payee separation, linked Expenses, approval/history, reimbursement obligations and distinct MDS-500 payment sources are implemented. Confirmed payment allocation and unapplication synchronize through `ReimbursementSettlementService`.
- Recurring Expense templates, governed occurrences, pause/resume and replay-safe draft generation are implemented. Generated records remain subject to ordinary evidence, duplicate, approval, posting and settlement controls.
- Expense copy, adjustment, credit, refund-pending/refund-link and reversal workflows are implemented with immutable originals, reason controls, linked correction accounting, obligation projections and MDS-700 refund ownership.
- Functional currency/exchange-rate trace, controlled CSV preview/apply, Expense-specific report projections and additional Needs Attention measures are implemented. OCR/AI, automatic bank/card feeds, shared report scheduling/export, employee master data and configurable policy remain their authoritative owner boundaries.
- `/expenses` now exposes the completion workflows with live TanStack Query panels, responsive claim/recurring/correction/import surfaces and explicit MDS-500/MDS-700 boundary messaging.

### Phase 9B validation

- Focused `ExpensesPhase9ATest` + `ExpensesPhase9BTest`: **7 passed, 56 assertions**.
- Complete backend suite: **75 passed, 1 intentionally skipped PostgreSQL-only schema test, 706 assertions**.
- `php artisan migrate --no-interaction`: `2026_08_14_000042_complete_expenses_phase9b` applied successfully locally.
- Frontend: **16 tests across 9 files passed**, ESLint passed, TypeScript/Vite build passed.
- PHP syntax checks passed for the Phase 9B service/controller/migration paths. `render.yaml`, Dockerfile, Nginx, startup and deployment configuration were not modified.
- Docker runtime validation remains unavailable because the Docker Desktop daemon is not running. Render CLI validation remains unavailable because the Render CLI is not installed.

For the implemented MDS-800-owned completion scope, the gate is:

`MDS-800 PHASE 9B COMPLETE; shared owner-boundary capabilities remain with MDS-900, MDS-1000, MDS-1100 and MDS-700/MDS-500.`

The next official phase should be selected only after reviewing those owner-boundary capabilities; no new official module was started in Phase 9B.

## Phase 10A - MDS-900 Reports & Analytics Shared Reporting Foundation

Phase 10A implements the shared reporting path without moving business meaning out of the originating modules. The pre-change checkpoint is `backups/simplebiz-before-phase10a-reports-foundation-20260814-150000.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned. `render.yaml`, Dockerfile, Nginx, startup, and deployment configuration were preserved.

The complete Phase 10A traceability matrix, source inventory, API/database/permission details, output-storage boundary, and remaining MDS-900 scope are documented in [`docs/MDS-900-PHASE-10A.md`](MDS-900-PHASE-10A.md).

### Phase 10A implementation summary

- Added the governed Report Definition Registry, version metadata, Source Contracts, definition-owned parameters/columns, categories, permissions, and initial registrations only for existing Collections, Purchases, Payments, Inventory, Expenses, and MDS-900 audit sources.
- Added the company/user-scoped Report Request lifecycle, synchronous source adapters, freshness/as-of/currency context, source permission inheritance, allow-listed sorting/totals, Display Report output, status retention, safe failure state, and request idempotency.
- Added PDF, XLSX, and CSV export from the same governed result path, formula-injection neutralization, browser print auditing, drill-down context preservation, favorites, saved parameter views, recent/history, immutable database-backed snapshots, report access audits, and `ReportLifecycleEvent` coverage.
- Replaced the Reports placeholder route with a responsive `/reports` workspace using TanStack Query, TanStack Table, Recharts, and Lucide. Empty, failed, stale/snapshot, and source-context states remain distinct.
- Sales, general Cash Accounts, financial statements, scheduling/delivery, durable cloud archive, report packs, advanced comparisons, AI, and other reports without verified source contracts remain intentionally deferred; no formula was invented.

### Phase 10A validation

- Focused `ReportsPhase10ATest`: **2 passed, 52 assertions**.
- Complete backend suite: **77 passed, 1 intentionally skipped PostgreSQL-only schema test, 758 assertions**.
- Frontend Vitest: **17 passed across 10 files**; ESLint passed; TypeScript/Vite production build passed.
- Pint: passed for the complete backend tree.
- `php artisan migrate --no-interaction`: `2026_08_14_000043_create_reports_foundation_phase10a` and the forward-only `2026_08_14_000044_align_reports_source_columns_phase10a` applied successfully locally; migration status is clean through `000044`.
- `php artisan config:clear`, `php artisan cache:clear`, `php artisan route:list --path=reports`, and `git diff --check` completed successfully.
- Docker image/runtime validation remains unavailable because Docker Desktop is not running. Render CLI validation remains unavailable because the Render CLI is not installed.

For the implemented shared foundation, the gate is:

`MDS-900 PHASE 10A COMPLETE; MDS-900 COMPLETION REMAINS DEFERRED`

The next official increment is **Phase 10B - MDS-900 Reports & Analytics Completion**, subject to the remaining traceability matrix. It was not started during Phase 10A.

## Phase 10B - MDS-900 Reports & Analytics Completion

Phase 10B adds the source-bound reporting completion increment. The pre-change checkpoint is `backups/simplebiz-before-phase10b-reports-completion-20260814-153525.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned. Render, Docker, Nginx, startup, and deployment configuration were preserved.

The complete Phase 10B matrix, catalog, API/database details, frontend surface, explicit provider/storage boundaries, and remaining gaps are documented in [`docs/MDS-900-PHASE-10B.md`](MDS-900-PHASE-10B.md).

### Phase 10B implementation summary

- Added source-owned Sales Register, Sales by Product, Receivables Aging, Cash Position, Cash Account Ledger, Inventory Valuation, Business Performance, and Voided/Reversed report contracts and catalog definitions.
- Added report-definition review, supersession, publication, effective-date, and deactivation governance.
- Added daily/weekly/monthly report schedules, occurrence history, permission revalidation, in-app delivery records, retry lifecycle, automatic due-schedule command, and scheduler registration.
- Added governed report comparison, source-backed management analytics, immutable report-pack lifecycle, output retention expiry, legal hold, purge metadata, and private-output deletion.
- Extended `/reports` with responsive completion tabs for schedules, delivery history, analytics definitions, report packs, and governance while retaining TanStack Table, Recharts, and Lucide.
- No statutory financial statements, user formulas, arbitrary SQL, external email/SMS/cloud delivery, AI provider, or durable Render-disk archive was added.

### Phase 10B validation

- Focused `ReportsPhase10ATest` + `ReportsPhase10BTest`: **4 passed, 79 assertions**.
- Complete backend suite: **79 passed, 1 intentionally skipped PostgreSQL-only schema test, 786 assertions**.
- `php artisan migrate --force`: `2026_08_14_000045_complete_reports_phase10b` applied successfully locally.
- Frontend: **18 tests across 10 files passed**, ESLint passed, and TypeScript/Vite production build passed.
- Pint: passed for the complete backend tree. Report routes and the `reports:run-due` command are registered.
- Docker runtime validation remains unavailable because the Docker daemon is not running. Render CLI validation remains unavailable because Render CLI is not installed.

The completion gate is:

`MDS-900 PHASE 10B IMPLEMENTED; MDS-900 NOT YET FULLY IMPLEMENTED`

The remaining applicable gaps are a deployed queue-backed large-report execution policy and any approved notification/provider integration. Financial/statutory reports remain blocked by the authoritative accounting source boundary, and MDS-1100 retains configuration, entitlement, localization, and delivery-setting ownership. No next official module should be started until these gaps are resolved or explicitly accepted.

## Phase 10C - MDS-900 Reports & Analytics Final Closure

Phase 10C closes the remaining MDS-900-owned queue-generation gap. The pre-change checkpoint is `backups/simplebiz-before-phase10c-reports-closure-20260814-161758.zip`. The branch remains `develop`; existing worktree changes were preserved and no files were staged, committed, pushed, reset, or cleaned. `render.yaml` and the Render free-tier deployment shape were preserved.

The complete final closure matrix, queue lifecycle, schedule integration, provider/storage classification, API, frontend, permissions, and validation evidence are documented in [`docs/MDS-900-PHASE-10C.md`](MDS-900-PHASE-10C.md).

### Phase 10C implementation summary

- Added forward-only migration `2026_08_14_000046_complete_reports_phase10c` for async execution mode, queue timestamps/attempts, cancellation, retryability, failure category/history, and queue indexes. Added `reports.requests.cancel` and `reports.requests.retry` permissions for Business Owner/Administrator roles.
- Added `GenerateReportJob` using stable company/request/definition/version/correlation identifiers. Export requests and scheduled requests use the same source adapter, definition version, parameter validation, context, currency, masking, and output path as synchronous reporting.
- Added queued/processing/completed/failed/cancelled handling, status inquiry metadata, transaction locking, safe retries, request/output idempotency, cancellation checks, safe failure messages, and schedule completion only after output availability.
- Added request cancellation/retry API routes and responsive Reports UI polling, terminal-state handling, valid cancel/retry actions, and output download readiness without fake progress.
- No business-module ledger, formula, permission model outside report request controls, external notification provider, persistent-storage provider, Render service, worker plan, or other SimpleBIZ module was added.

### Phase 10C validation

- Focused `ReportsPhase10CTest`: **4 passed, 27 assertions**.
- Complete backend suite: **83 passed, 1 intentionally skipped PostgreSQL-only schema test, 813 assertions**.
- Frontend Vitest: **18 passed across 10 files**; ESLint and TypeScript/Vite build passed.
- Pint and `git diff --check` passed.
- Migration `000046` applied successfully locally.
- Docker, real queue-worker, and Render CLI validation were unavailable; the limitations and exact deployment dependencies are documented in the Phase 10C document.

The final gate for the documented/currently applicable MDS-900 application scope is:

`MDS-900 FULLY IMPLEMENTED`

External queue-worker/scheduler/provider/storage configuration remains explicitly outside this code-only closure pass. No next module is being implemented in Phase 10C.

## Phase 11 - MDS-200 Sales & Receivables Final Completion

Phase 11 adds the MDS-200-owned Sales Return, Sales Debit/Credit Adjustment, receivable-effect, posted Sale reversal, billing-statement source snapshot, correction reporting, and responsive Sales workspace correction surfaces. The authoritative matrix, ownership boundaries, exact files, migration details, and validation are documented in [`docs/MDS-200-PHASE-11.md`](MDS-200-PHASE-11.md).

The forward-only migrations `2026_08_15_000047_complete_sales_phase11` and `2026_08_15_000048_register_sales_corrections_report` applied successfully locally. The implementation preserves MDS-300 receipt/application ownership, MDS-500 refund ownership, MDS-600 inventory ownership, MDS-700 cash ownership, and MDS-900 shared report execution ownership.

The gate is:

`MDS-200 NOT YET FULLY IMPLEMENTED`

Cash/paid-now orchestration, complete presentation/export/detail coverage, and broader live dashboard/attention wiring remain documented gaps. No next official module was started.

## Phase 11B - MDS-200 Sales & Receivables Final Closure

Phase 11B closes the remaining MDS-200-owned gaps from Phase 11. The complete closure matrix, ownership boundaries, API/UI details, and failure semantics are documented in [`docs/MDS-200-PHASE-11B.md`](MDS-200-PHASE-11B.md). The branch remains `develop`; deployment configuration was preserved and no files were staged, committed, pushed, reset, or cleaned.

### Phase 11B implementation summary

- Added atomic, idempotent paid-now completion for approved cash Sales through the existing MDS-300 Receipt/Application path, including partial payment, owner-module inventory/cash effects, conflicting retry protection, and rollback-safe failure behavior.
- Added immutable billing statement row snapshots and responsive statement history, generation, detail, and browser print/Save-as-PDF presentation. Shared report export remains owned by MDS-900.
- Added live MDS-200 Sales dashboard and Needs Attention source contracts and wired Dashboard/Sales consumers to them, with explicit currency and freshness context and demo fixtures limited to demo mode.
- Added a responsive Sale detail screen and enriched the Sale resource with lifecycle, receivable, receipts, inventory, returns, and adjustments.
- Added focused Phase 11B regression coverage without changing business migrations or deployment configuration.

### Phase 11B validation

- Focused Sales tests: **11 passed, 89 assertions** after the final validation run (including Phase 4A, Phase 11, and Phase 11B coverage).
- Complete backend suite: **90 passed, 1 intentionally skipped PostgreSQL-only schema test, 882 assertions** after the final validation run.
- Frontend: `npm ci`, `npm run test`, ESLint, TypeScript/Vite production build, and Pint passed. npm reported two high-severity dependency advisories; no automatic audit fix was applied because dependency upgrades are outside this business-scope change.
- Migrations remain clean through `2026_08_15_000048_register_sales_corrections_report`; no new Phase 11B migration was needed.
- `git diff --check` passed. Docker runtime and Render CLI validation remain outside this application-scope pass and were not required because deployment configuration was not changed.

The completion gate is:

`MDS-200 FULLY IMPLEMENTED`

The next official module should be selected only after reviewing the documented owner boundaries. No next official module was started in Phase 11B.
## Phase 12 — MDS-700 Cash Accounts Final Closure

Phase 12 is implemented. Cash Account profiles now support governed `pending_closure` and `closed` lifecycle states with requested, under-review, balance-resolution, approved, closed, and cancelled closure states; request/review/resolve/approve/close/cancel actions; archive evidence; optimistic version checks; blocker snapshots; re-evaluation before close; segregation of duties; retained history; audit records; and lifecycle events. Closure blockers cover non-zero posted balance, unresolved movement/transfer documents, pending checks, open counts, open reconciliations, unresolved statement imports, open outstanding reconciliation items, and active custodians.

Financially used Cash Accounts cannot change their Asset Account Title or Currency mapping. Cash Account summary and Needs Attention now disclose MDS-700 ownership, source-as-of, freshness, currency context, source metrics, and drilldown-ready exception items. MDS-700 source projections and MDS-900 contracts/definitions were added for Cash Movement History, Cash Transfer History, Cash Count, Cash Reconciliation, and Cash Account Exceptions. Existing manual/limited statement import remains the Free-edition path because the specification does not name a machine statement format or provider contract.

The focused Phase 12 regression suite is `tests/Feature/CashAccountPhase12Test.php`. No business module beyond MDS-700 source ownership was implemented in this phase.

### Phase 12 validation

- Focused `CashAccountPhase12Test`: **2 passed, 25 assertions**.
- Complete backend suite: **92 passed, 1 intentionally skipped PostgreSQL-only schema test, 907 assertions**.
- Frontend: **18 tests passed across 10 files**; ESLint and TypeScript/Vite production build passed.
- `vendor/bin/pint --test` and `git diff --check` passed. Migrations `000049` and `000050` are applied locally.
- Docker runtime validation was unavailable because the Docker daemon is not running; Render CLI validation was unavailable because the CLI is not installed.

The completion gate is:

`MDS-700 FULLY IMPLEMENTED`

No next official module was started in Phase 12.

## Phase 13 — MDS-100 Dashboard Final Completion

Phase 13 completes the MDS-100 Dashboard composition and presentation layer. The complete traceability matrix, source ownership map, API contract, context behavior, failure semantics, responsive UI, and validation notes are documented in [`docs/MDS-100-PHASE-13.md`](MDS-100-PHASE-13.md).

### Phase 13 implementation summary

- Added the read-only `/api/v1/dashboard` orchestration endpoint and `DashboardService`. It composes permission-aware MDS-200 through MDS-900 source projections, explicit company/period/as-of/currency context, source freshness, partial failures, attention items, recent activity, quick actions, and governed report links.
- Added MDS-900 Business Performance integration through the published `ANL-MGT-001` source implementation. The Dashboard does not create P&L formulas or operational totals.
- Replaced the Dashboard's production fixture fallback with truthful live empty/error states. Preview and explicit demo mode remain isolated and labelled.
- Completed the responsive Dashboard UI with source-backed KPI cards, Needs Attention, Business Performance, Cash In vs Cash Out, Recent Activity, Records & Ledgers, Reports, and Action Center surfaces using TanStack Query/Table, Recharts, Lucide, and existing owner routes.
- No Dashboard-owned database table, migration, transaction workflow, operational ledger, deployment configuration, or unsupported feature was added.

### Phase 13 validation

- `php artisan route:list --path=api/v1/dashboard`: Dashboard route registered.
- PHP syntax checks passed for DashboardService, DashboardController, ReportsService, and API routes.
- Frontend `npm run lint`: passed.
- Frontend `npm run build`: passed.
- Focused DashboardPhase13Test: 3 passed, 30 assertions; full php artisan test: 95 passed, 1 skipped, 937 assertions.
- vendor/bin/pint --test, git diff --check, npm ci, npm run lint, npm run test (18 passed across 10 files), and npm run build passed.
- npm audit --audit-level=high still reports two high-severity advisories in brace-expansion and nanoid; no dependency remediation was performed in this phase.
- Docker client is installed but its Linux daemon is unavailable; Render CLI is not installed. Neither runtime validation could be executed.

The completion gate is:

`MDS-100 FULLY IMPLEMENTED`

Branch-filtered source projections remain an explicit owner-contract dependency and are rejected safely rather than represented with misleading company-wide values. No next phase was implemented in Phase 13.

## Phase 14 — MDS-1000 Master Registries Final Closure

Phase 14 completes the MDS-1000 Master Registries scope for the documented SimpleBIZ Free edition. The complete traceability matrix, registry inventory, ownership boundaries, API/UI details, and validation record are documented in [`docs/MDS-1000-PHASE-14.md`](MDS-1000-PHASE-14.md).

### Phase 14 implementation summary

- Added governed contact/address update, lifecycle, history, and safe detail resources under the authoritative Business Partner identity.
- Added versioned, effective-dated, company-scoped external identifier lifecycle metadata, masked responses, CRUD/history routes, duplicate protection, and product/partner detail presentation.
- Added bounded, searchable active/effective lookup contracts and registry status filters/pagination/detail/history UI.
- Replaced hard-coded Master Registry summary values with the live MDS-1000 summary projection and honest empty/unavailable states.
- Preserved MDS-700 Cash Account ownership, MDS-1100 configuration ownership, operational transaction snapshots, and all deployment configuration.

### Phase 14 validation

- Migrations `2026_08_15_000051_complete_master_registry_identifiers` and `2026_08_15_000052_add_master_registry_child_lifecycle` applied successfully.
- Focused Master Registry coverage: **12 passed, 69 assertions**.
- Full backend suite: **97 passed, 1 intentionally skipped PostgreSQL-only schema test, 964 assertions**.
- Frontend: **18 tests passed across 10 files**; ESLint, TypeScript/Vite build, Pint, and `git diff --check` passed.
- Existing npm high advisories remain in `brace-expansion` and `nanoid`; no dependency remediation was performed. Docker/Render CLI runtime checks were not available in this application-scope pass.

The completion gate is:

`MDS-1000 FULLY IMPLEMENTED`

The next recommended bounded module is:

`Phase 15 — MDS-1100 Settings & Administration Final Closure`

No MDS-1100 or MDS-000 implementation was started in Phase 14.

## Phase 15 - MDS-1100 Settings & Administration Final Closure

Phase 15 completes the documented SimpleBIZ Free-edition Settings & Administration scope. The complete closure matrix, ownership boundaries, API surface, configuration lifecycle, security/privacy controls, external-service limits, and validation evidence are documented in [`docs/MDS-1100-PHASE-15.md`](MDS-1100-PHASE-15.md).

### Phase 15 implementation summary

- Added the live settings workspace with permission-filtered destinations, search, setup progress, needs-attention items, recent activity, and truthful Free/commercial service states.
- Added company configuration metadata, versioned published change sets, effective-value/source reporting, personal preferences, notification preferences, module state, session controls, administrative audit search, scoped administrative exports, and protected two-party ownership transfer.
- Added responsive Account & Subscription, Company Profile, Security & Audit, and Settings Operations screens while preserving Core, MDS-1000, transaction, report, Commerce, Usage, and provider ownership boundaries.
- Preserved credential/secret boundaries and did not add transaction workflows, operational ledgers, report calculations, Commerce billing, external integrations, or unsupported provider behavior.

### Phase 15 validation

- Migration `2026_08_15_000053_complete_settings_phase15` applied successfully.
- Focused SettingsPhase15 coverage: **4 passed, 36 assertions**.
- Full backend suite: **101 passed, 1 intentionally skipped PostgreSQL-only schema test, 1,000 assertions**.
- Frontend: **18 tests passed across 10 files**; ESLint, TypeScript/Vite build, Pint, and `git diff --check` passed.
- Docker Desktop Linux daemon was unavailable; Render CLI was not installed. Docker image/runtime and Blueprint validation could not run locally.

The completion gate is:

`MDS-1100 FULLY IMPLEMENTED`

The next recommended bounded phase is:

`Phase 16 - MDS-000 Core + whole-system final acceptance`

No MDS-000 closure work was included in Phase 15.

## Phase 16 - MDS-000 Core + Whole-System Final Acceptance

Phase 16 is the final planned implementation phase. It closes the MDS-000 Core-owned gaps identified by direct repository inspection and records the whole-system acceptance decision in [`docs/MDS-000-PHASE-16-FINAL-ACCEPTANCE.md`](MDS-000-PHASE-16-FINAL-ACCEPTANCE.md). No new business module was started, and existing worktree changes were preserved without staging, committing, pushing, resetting, or cleaning.

### Phase 16 implementation summary

- Added permission- and company-scoped Core global search across the completed source registries and operational records, with safe result metadata and existing workspace routes.
- Added the Core search permission to fresh-company Business Owner, Administrator, and Member bootstrap grants, with migration coverage for existing roles.
- Added the Core in-app notification store/service/API with unread state, read/read-all actions, stable event/source metadata, expiry, idempotency, audit evidence, and an explicit external-delivery boundary.
- Added API correlation metadata for validation and safe unexpected errors, preserved safe HTTP error statuses, and strengthened bearer-token logout revocation.
- Wired the existing React shell search and notification controls to the Core APIs with Ctrl/Cmd+K, keyboard-accessible dialogs, loading/empty/error states, mobile-safe surfaces, read state, and route navigation.
- Added final cross-module acceptance coverage and reconciled the authoritative MDS-000 and module matrix. No transaction, accounting, permission-model, registry, deployment-plan, or user-facing business workflow was changed.

### Phase 16 validation

- Migration `2026_08_15_000054_core_final_acceptance` applied successfully locally.
- Focused `CoreFinalAcceptanceTest`: **4 passed, 39 assertions**.
- Complete backend suite: **105 passed, 1 intentionally skipped PostgreSQL-only schema test, 1,039 assertions**.
- Frontend: **18 tests passed across 10 files**; ESLint, TypeScript/Vite build, and Pint passed.
- `git diff --check` completed without content errors; existing LF-to-CRLF warnings remain from the Windows worktree.
- Search/notification routes and the scheduled reports command are registered.
- Docker client is installed but its Linux daemon is unavailable; Render CLI is not installed. Docker image/runtime and Blueprint validation therefore remain environment-dependent follow-up checks. Existing Render free-tier configuration was preserved.

The final gates are:

`MDS-000 FULLY IMPLEMENTED`

`SIMPLEBIZ ALL DOCUMENTED MODULES FULLY IMPLEMENTED`

`SYSTEM FINAL ACCEPTANCE PASSED`

Operational Docker/Render checks remain external deployment validation and do not represent an application Core gap.

## Release Readiness & UAT Audit — 15 August 2026

The post-implementation release audit is documented in [`docs/RELEASE-READINESS-UAT.md`](RELEASE-READINESS-UAT.md), with the blank human test scenarios in [`docs/UAT-CHECKLIST.md`](UAT-CHECKLIST.md). This is an audit and hardening record, not a new MDS phase.

### Audit result

- `MODULE REGRESSION GATE PASSED`
- `UAT READINESS PASSED`
- `NO APPLICATION-CODE RELEASE BLOCKERS`
- `PRODUCTION DEPLOYMENT NOT YET VERIFIED`
- `RELEASE READINESS PASSED WITH EXTERNAL DEPLOYMENT ACTIONS`

### Audit validation

- Repository started clean on `develop`; no staged files were present.
- Valid source backup: `backups/simplebiz-before-release-readiness-audit-20260815-132102.zip` (32,553,520 bytes, 779 entries).
- All migrations through `2026_08_15_000054_core_final_acceptance` are applied; normal `php artisan migrate` reports nothing pending.
- Focused critical acceptance set: **60 passed, 565 assertions**.
- Full backend suite: **105 passed, 1 intentionally skipped PostgreSQL-only schema test, 1,039 assertions**.
- Frontend: **18 tests across 10 files passed**; ESLint, TypeScript/Vite build, Pint, and `git diff --check` passed.
- Composer audit reports six `league/commonmark` advisories against transitive production version `2.8.3`; npm audit reports two high build/lint dependency advisories in `brace-expansion` and `nanoid`. No automatic dependency upgrade was performed.
- Docker Linux daemon and Render CLI were unavailable, so image/container, Nginx/PHP-FPM runtime, and Blueprint validation remain manual deployment checks.
- The existing Render staging guide was corrected to match `develop` and the Docker startup migration flow. Render plans/services and deployment configuration were not changed.

No completed module status was changed. No undocumented feature, new MDS phase, operational source of truth, deployment, destructive database action, Git reset/clean, staging, commit, push, or automatic major dependency upgrade was performed.

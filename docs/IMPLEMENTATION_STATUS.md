# SimpleBIZ implementation status

Updated: 4 August 2026

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

## PostgreSQL and migrations

The backend uses `pgsql` through environment configuration. The local PostgreSQL migration status was verified, and the Phase 2B migrations `2026_08_03_000008_create_transaction_reference_registries` and `2026_08_03_000009_seed_reference_registries`, followed by Phase 3A migrations `2026_08_03_000010_create_cash_accounts_foundation` and `2026_08_03_000011_seed_cash_accounts_foundation`, Phase 3B migrations `2026_08_04_000012_create_cash_movement_documents` and `2026_08_04_000013_seed_cash_movement_catalog`, and Phase 3C-A migrations `2026_08_04_000014_create_cash_count_foundation` and `2026_08_04_000015_seed_cash_count_catalog`, were applied with the normal `php artisan migrate` command. No reset or destructive migration command was used.

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
- `/cash-accounts?mode=new`
- `/cash-accounts?account={id}`
- `/cash-accounts?opening=new`
- `/cash-accounts?opening={id}`
- `/cash-accounts?mode=movement&kind=cash_in|cash_out`
- `/cash-accounts?mode=transfer&purpose=INTERNAL_TRANSFER|DEPOSIT|WITHDRAWAL`
- `/cash-accounts?mode=history`
- `/cash-accounts?mode=counts`
- `/cash-accounts?mode=handovers`
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
- Existing module routes remain visual foundations only; transaction posting is not implemented.

## Testing

Backend coverage includes setup, idempotency replay safety, login, protected routes, company context, invitations, owner protection, permission enforcement, correlation IDs, validation envelopes, shared partner roles/history, duplicate handling, item integrity rules, dependency blocking, tenant isolation, Phase 2B reference registry rules, Phase 3A Cash Account/Opening Balance rules, Phase 3B governed movement/transfer rules, and Phase 3C-A cash-count/variance/handover rules. The full backend suite passes 26 tests, with 1 intentionally skipped PostgreSQL-only schema test, and 197 assertions. The focused Phase 3C-A suite passes 2 tests and 53 assertions. Frontend lint, TypeScript build, production Vite build, and the frontend suite pass with 3 test files and 8 tests.

## Deferred scope

The following remain intentionally deferred: full custom roles, complete permission administration UI, subscription billing and entitlements, MFA, notification administration, numbering configuration, approval-policy builder, richer tax/accounting configuration, integrations, UOM conversions, merge/import tooling, and all transaction modules.

Phase 3C-A intentionally does not implement statement import, statement-line normalization, exact/split/combined matching, reconciliation, completion locking, reopening, rematching, customer receipts, supplier payments, expenses, payment/disbursement source modules, cross-currency transfers, or source-module economic ownership. Phase 2A and Phase 2B also do not implement Sales, Collections, Inventory movements/balances, Purchases, Expenses, Payments, Reports, price lists, tax engines, bundles, or transaction-specific customer/supplier ledgers.

No Master Registry CRUD or transaction module was started in Phase 1B.

## Known limitations

- Mail delivery is not configured; invitations record `not_configured` or the configured development delivery status and do not pretend that an email was delivered.
- The frontend currently stores the Sanctum token in browser local storage because Phase 1A established token-based API authentication. A future deployment decision may move this to a cookie-based Sanctum SPA flow with CSRF protection.
- Full permission administration and custom role design remain deferred.
- The local PHP runtime does not have the `intl` extension enabled; `php artisan db:show` connects successfully but exits while formatting table counts. Migration status and migration execution remain successful.

## Recommended next phase

The recommended next phase is **Phase 3C-B — Statement Import, Statement-Line Normalization, Exact/Split/Combined Matching, Reconciliation, Adjustments, Completion Locking, Reopening, and Rematching**. It must consume the existing posted movement engine and attachment/audit infrastructure, preserve immutable history, and keep source-module economic ownership explicit. Do not introduce a second ledger, generic unrestricted movement endpoint, or manual GL journal.

## Render staging deployment readiness

- Added deployment-only preparation for the existing Laravel backend and React frontend: `backend/Dockerfile`, the backend Nginx/startup files, root `render.yaml`, root secret/backup ignore rules, environment-driven CORS, proxy HTTPS handling, production API URL enforcement, and environment-driven private attachment disks.
- Added the focused [Render staging deployment guide](RENDER_STAGING_DEPLOYMENT.md). This is staging readiness only and does not mark SimpleBIZ production-ready.
- No SimpleBIZ business module, workflow, permission, accounting rule, database entity, or user-facing business capability was changed for deployment preparation.

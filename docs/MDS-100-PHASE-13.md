# SimpleBIZ Phase 13 — MDS-100 Dashboard Final Completion

Updated: 15 August 2026  
Branch: `develop`  
Status: `MDS-100 FULLY IMPLEMENTED` for the currently applicable documented scope.

## Scope and ownership

The Dashboard is a read-only management and routing workspace. It composes source projections owned by MDS-200 through MDS-900 and does not maintain operational balances, editable totals, transactions, or competing formulas.

| Dashboard area | Authoritative owner | Result |
|---|---|---|
| Company, period, as-of, currency context | MDS-100 / authenticated Company Context | Implemented |
| Branch context | Owner source contracts | Deferred until all source projections support branch scope; branch requests are rejected rather than showing misleading company totals |
| Net Sales and Receivables | MDS-200 | Implemented from Sales dashboard source |
| Collections and unapplied receipts | MDS-300 | Implemented from Collections summary; source-defined basis is disclosed |
| Purchases and Payables | MDS-400 | Implemented from Purchases summary; source-defined basis is disclosed |
| Payments | MDS-500 | Implemented from Payment summary and attention contracts |
| Cash Position and Cash In/Out | MDS-700 | Implemented from Cash Position and Cash Account Ledger source contracts; transfers remain separate |
| Inventory | MDS-600 | Implemented from Inventory summary and conditions |
| Expenses | MDS-800 | Implemented from Expense summary and attention contracts |
| Business Performance | MDS-900 | Implemented from governed `ANL-MGT-001` source projection |
| Profit & Loss snapshot | MDS-900 | Not applicable: the published MDS-900 catalog contains Business Performance, not a statutory or P&L definition |
| Needs Attention | MDS-100 composition; conditions remain owner-owned | Implemented with source identity, severity where supplied, count, route, permission filtering, deduplication, and partial failure disclosure |
| Recent Activity | Core Activity Events plus owner permissions | Implemented as a bounded, company-scoped, permission-filtered operational feed |
| Quick Actions, Records, Reports | Existing owner routes | Implemented as permission-aware links; Dashboard creates no transactions |

## API contract

`GET /api/v1/dashboard` is protected by Sanctum, active-user, and company context middleware. The Dashboard endpoint itself does not grant access to source records. Each source projection is checked with its existing owner permission before loading.

Supported query context:

- `period=today|week|month|quarter|year`
- `period=custom&from=YYYY-MM-DD&to=YYYY-MM-DD`
- `currency=CODE` when the company has an active matching currency
- `branch_id` is validated, then rejected until all owner source contracts can guarantee branch-filtered dashboard projections

The response contains `context`, `sources`, `kpis`, `attention`, `activity`, `quick_actions`, `reports`, and `refresh`. Every source has `available`, `unauthorized`, or `unavailable` status plus owner, permission, freshness, and source-as-of metadata. A source failure is never converted to zero.

Financial values remain in currency buckets. No Dashboard FX conversion or incompatible-currency grand total exists. `Cash In vs Cash Out` comes from the MDS-700 Cash Account Ledger projection; internal transfers are disclosed separately and do not become company inflow/outflow.

## Frontend behavior

`web/src/features/dashboard/DashboardPage.tsx` uses TanStack Query for the context-aware composition request, TanStack Table for Recent Activity, Recharts for source-backed Business Performance and Cash In/Out charts, and Lucide for icons. The production page does not fall back to fixtures when a live request succeeds with an empty result or fails. Fixtures remain isolated to `/preview` or explicit `VITE_DEMO_MODE=true`.

The page provides:

- stable loading, empty, unauthorized, unavailable, and partial-source states;
- period and currency context controls;
- source freshness and MDS owner labels;
- links from KPIs, attention items, activity, reports, and actions to owner workspaces;
- responsive card, chart, table, and action layouts for desktop, tablet, and mobile;
- keyboard-focusable controls, semantic headings, table captions, chart labels, and non-color status text.

## Attention and activity source inventory

| Source | Permission | Endpoint/service contract | Dashboard destination |
|---|---|---|---|
| MDS-200 | `sales.view` | `SalesService::attention` | `/sales` or supplied Sale route |
| MDS-300 | `collections.view` | Collections summary/unapplied projection | `/collections?view=unapplied` |
| MDS-400 | `purchases.view` | `PurchasingService::attention` | `/purchases` |
| MDS-500 | `payments.view` | `PaymentCompletionService::attention` | `/payments` |
| MDS-600 | `inventory.view` | `InventoryCompletionService::conditions` | `/inventory` |
| MDS-700 | `cash-accounts.view` | `CashPositionService::dashboard` | `/cash-accounts` |
| MDS-800 | `expenses.view` | `ExpenseService::attention` | `/expenses` |

Recent Activity reads only `activity_events`, limits results server-side, skips unknown events, applies source permissions, and exposes source record identity without exposing the audit log.

## Requirement traceability

| Requirement family | Final status | Evidence or boundary |
|---|---|---|
| Landing page, company context, period, as-of, currency, refresh | IMPLEMENTED | DashboardService::compose and the context bar resolve and refresh the complete response context |
| Loading, empty, full error, partial source failure | IMPLEMENTED | TanStack Query loading/error states and per-source available, unauthorized, and unavailable states |
| Permission-aware and entitlement-aware presentation | IMPLEMENTED | Each owner source is permission checked before loading; unauthorized cards and actions remain unavailable |
| Sales, collections, purchases, payments, cash, receivables, payables, inventory, expenses KPIs | IMPLEMENTED | Values are mapped from owner summary contracts without Dashboard-owned formulas |
| Business Performance and Cash In vs Cash Out | IMPLEMENTED | MDS-900 ANL-MGT-001 and MDS-700 Cash Account Ledger projections, with period/currency/freshness disclosure |
| Profit & Loss snapshot | NOT APPLICABLE | No published MDS-900 P&L definition exists; the UI explicitly discloses that no statutory P&L is inferred |
| Needs Attention | IMPLEMENTED | Owner attention contracts are aggregated with stable identity, de-duplication, source ownership, severity, and failure disclosure |
| Recent Activity | IMPLEMENTED | Bounded company-scoped Activity Events are filtered by source permission and expose source identity plus owner workspace routing |
| KPI, attention, activity, report, and action drill-down | IMPLEMENTED | Links preserve owner module and supported source context; detailed record routes remain owner-module owned |
| Desktop, tablet, mobile, keyboard, screen-reader, reduced-motion behavior | IMPLEMENTED | Responsive grid/table styles, semantic headings/captions, focus-visible controls, and reduced-motion rules are present |
| Dashboard-owned operational balances, transactions, formulas, or persistence | NOT APPLICABLE | Dashboard remains read-only; no Dashboard tables, migration, transaction workflow, or competing formula was added |
| Branch-filtered projections | DEFERRED TO OWNER MODULE | Valid branch selections are rejected safely until every owner source contract supports branch scope |
| Future MDS-900 P&L definition | DEFERRED TO OWNER MODULE | The Dashboard will consume a published definition when MDS-900 provides one |

## Validation and safety

- Added `DashboardService` and `DashboardController`; no Dashboard-owned database tables or migrations were added.
- Added a read-only MDS-900 integration method; MDS-900 remains the owner of Business Performance semantics and definition `ANL-MGT-001`.
- Existing Render, Docker, Nginx, PostgreSQL, startup, and queue configuration were not changed.
- No applied migration was modified. No reset, wipe, seed, transaction creation, stage, commit, push, or unsupported Dashboard feature was performed.
- Preview/demo data is never written to the database and cannot mask production API failures.

Remaining items explicitly deferred to owner/configuration boundaries are branch-filtered source projections and any future published MDS-900 P&L definition. They are not MDS-100-owned Dashboard gaps.

## Validation evidence

- php artisan test tests/Feature/DashboardPhase13Test.php: 3 passed, 30 assertions.
- php artisan test: 95 passed, 1 skipped, 937 assertions. The PostgreSQL schema test is skipped when the test database is not PostgreSQL.
- vendor/bin/pint --test: passed.
- npm ci: completed; the lockfile was not changed.
- npm run lint: passed.
- npm run test: 18 passed across 10 files.
- npm run build: passed with TypeScript and Vite.
- git diff --check: passed; only existing LF/CRLF normalization warnings were reported.
- npm audit --audit-level=high: two pre-existing high-severity advisories remain in brace-expansion and nanoid; no audit fix or unrelated dependency upgrade was applied.
- Docker client is installed, but the Docker Desktop Linux daemon is unavailable, so image/runtime validation could not run.
- Render CLI is not installed, so Blueprint validation could not run. Render deployment configuration was not changed.

# SimpleBIZ Human UAT Checklist

Use this checklist against the deployed staging environment after the operator has configured the environment, completed company setup, and prepared the test data plan in [RELEASE-READINESS-UAT.md](RELEASE-READINESS-UAT.md). Do not run these scenarios against production data.

The checklist intentionally leaves `Actual result`, `PASS / FAIL`, and `Notes` blank. Automated regression evidence does not replace human acceptance.

Recommended roles, using the roles implemented by SimpleBIZ, are Business Owner, Administrator, and Member. Where a module-specific user is needed, use an actual company role with the relevant permission rather than inventing a new role.

## 1. Login / Company

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| AUTH-01 | Fresh staging browser; no active company | Open `/setup`, complete the first-company form with valid values | One company and protected Business Owner account are created; the result is safe and the user can sign in |  |  |  |
| AUTH-02 | Existing active Business Owner | Sign in with valid credentials; refresh; open a protected workspace | Session restores and the active company context is displayed |  |  |  |
| AUTH-03 | Existing account | Submit an invalid email/password combination | Sign-in fails with a safe user-facing message and no sensitive detail |  |  |  |
| AUTH-04 | Signed-in user | Sign out, then use browser back and open a protected URL | Token is revoked and protected content redirects to sign-in |  |  |  |
| AUTH-05 | User with active memberships in two companies | Switch company, refresh, and revisit Dashboard and a list page | Only the selected company’s records and settings are displayed |  |  |  |

## 2. Dashboard

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| DASH-01 | Active company with no transactions | Open Dashboard | Empty/zero state is explicit and is not presented as fake financial activity |  |  |  |
| DASH-02 | Company with approved/postable test transactions | Review KPI cards, Needs Attention, Recent Activity, Action Center, and Records & Ledgers | Values are sourced from the owning modules and links open the relevant workspace |  |  |  |
| DASH-03 | Company with restricted permissions or a temporarily unavailable source | Load Dashboard and refresh | Restricted or failed source is disclosed; it does not silently become a financial zero |  |  |  |
| DASH-04 | Desktop, tablet, and mobile viewport | Resize or use representative devices; open cards and action links | No clipped content, inaccessible actions, or unusable horizontal overflow |  |  |  |

## 3. Sales & Receivables

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| SAL-01 | Customer, product/service, currency, and required registry data | Create a credit sale; review calculated totals; submit, review, approve, and post | Server-calculated sale posts once and creates the expected receivable; audit/history is present |  |  |  |
| SAL-02 | Posted credit sale with open receivable | Record a partial collection through Collections; review the receivable | Applied amount and remaining balance are correct |  |  |  |
| SAL-03 | Remaining receivable | Collect the remaining balance | Receivable settles exactly once and the receipt/cash effects are traceable |  |  |  |
| SAL-04 | Customer and active cash account | Use the Paid Now flow for a sale | Sale, receipt/application, and cash effect are created once; a failed tender creates no partial financial effect |  |  |  |
| SAL-05 | Posted sale with eligible line quantity | Create, review, approve, post, and reverse a sales return | Return is linked to the source sale, quantity-safe, reflected in receivable/inventory as applicable, and reversible |  |  |  |
| SAL-06 | Posted sale and authorized role | Create a sales adjustment and a sale reversal | Correction preserves the original source and creates governed linked effects with audit history |  |  |  |
| SAL-07 | Customer with multiple open items | Generate a billing statement and open it again | Statement is an authoritative read-only snapshot of existing receivables |  |  |  |

## 4. Collections & Receipts

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| COL-01 | Open customer receivable and permitted collector | Create a receipt with tender; submit, approve, and post | Receipt is posted once and application/cash ownership is clear |  |  |  |
| COL-02 | Receivable larger than tender | Apply a partial payment | Remaining receivable is correct and unapplied amount is not fabricated |  |  |  |
| COL-03 | Receipt with unapplied amount | Apply the unapplied amount later to a valid receivable | Allocation changes without creating a second cash effect |  |  |  |
| COL-04 | Failed or reversed tender | Mark the instrument failed or reverse the receipt | Reversal is governed, traceable, and does not leave the customer settled or cash incorrectly posted |  |  |  |
| COL-05 | Posted receipt | Print and reprint the receipt | Printed content reflects authoritative data; reprint is non-financial and is auditable |  |  |  |
| COL-06 | Cash remittance and authorized reviewer | Record, review, approve, and complete a remittance | Remittance posts the intended cash transfer once without a duplicate customer effect |  |  |  |

## 5. Purchases & Payables

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| PUR-01 | Supplier, product/service, and purchasing role | Create a purchase order; submit, review, approve, and close or receive it | Order lifecycle and revision history are visible |  |  |  |
| PUR-02 | Approved order and receiving location | Record a full and a partial goods receipt | Inventory effect is created once per receipt and remaining quantity is correct |  |  |  |
| PUR-03 | Supplier invoice and source order/receipt | Create, submit, approve, and post an invoice | One payable is created; posting does not create a payment automatically |  |  |  |
| PUR-04 | Open payable and payment role | Make a partial and then full supplier payment | Payable remaining amount, cash effect, allocation, and history are correct |  |  |  |
| PUR-05 | Posted purchase return or invoice | Process a return/correction and reverse it where permitted | Source links, quantity/amount controls, and reversal history remain intact |  |  |  |

## 6. Payments & Disbursements

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| PAY-01 | Eligible payable and payment preparation role | Create a payment request; submit, approve, schedule, and release | Request lifecycle is visible and approval separation is enforced |  |  |  |
| PAY-02 | Approved payment with valid channel | Confirm a cash/electronic payment and allocate it | Cash is affected only at the governed confirmation point and allocation is traceable |  |  |  |
| PAY-03 | Payment with multiple sources | Allocate partially, unapply, and reallocate | No duplicate cash effect; remaining allocations remain valid |  |  |  |
| PAY-04 | Payment instrument using a check | Exercise check number, replacement, and history behavior | Number uniqueness/replacement controls and links are preserved |  |  |  |
| PAY-05 | Payment batch with mixed items | Prepare, approve, partially execute, and review the batch | Item-level traceability and safe partial execution are retained |  |  |  |

## 7. Inventory

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| INV-01 | Stock-managed product, warehouse/location, inventory role | Record opening stock and a receipt | Current balance derives from posted movements and duplicate posting is prevented |  |  |  |
| INV-02 | Available stock | Reserve and issue stock through the owning transaction | Available/reserved quantities are correct and stock is consumed once |  |  |  |
| INV-03 | Insufficient available stock | Attempt an issue exceeding available quantity | Server rejects the issue without a partial movement |  |  |  |
| INV-04 | Stock count with authorized role | Start a count, record entries, submit, approve, and post variance | Count snapshot and variance are preserved; only the variance movement posts |  |  |  |
| INV-05 | Stock adjustment with reason | Create, approve, post, and reverse an adjustment | Reason, source link, balance, and reversal are traceable |  |  |  |
| INV-06 | Products with dependencies | Deactivate/reactivate a product, category, unit, or location | Dependency rules block unsafe deactivation and preserve history |  |  |  |

## 8. Cash Accounts

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| CASH-01 | Account title, currency, branch, and authorized cash role | Create a Cash Account profile, review capabilities, and activate it | Profile creation does not create money; physical activation requires the documented custodian |  |  |  |
| CASH-02 | Active account and evidence | Create, submit, approve, and post an Opening Balance | Evidence, segregation, balanced accounting, and one linked movement are present |  |  |  |
| CASH-03 | Active account with valid capability | Record Cash In and Cash Out through the governed lifecycle | Cash movement, accounting, purpose, and reversal behavior are correct |  |  |  |
| CASH-04 | Two same-currency accounts | Create and post an Internal Transfer, Deposit, and Withdrawal | Two linked legs post atomically; internal transfer has zero net company cash effect |  |  |  |
| CASH-05 | Account with insufficient balance | Attempt a negative-balance movement as a normal role and as an authorized override | Normal action is blocked; override requires permission and a reason and is audited |  |  |  |
| CASH-06 | Physical cash account | Start a count at a cut-off, enter denominations, confirm, review, and close | Expected/actual/variance values derive from the snapshot; no balance is edited directly |  |  |  |
| CASH-07 | Statement evidence and normalized lines | Create a statement import, add lines, validate, and review candidates | Statement lines remain separate from Cash Movements and validation does not change balance |  |  |  |
| CASH-08 | Posted movements and statement lines | Match, unmatch/rematch, prepare, complete, reopen, and rematch a reconciliation | Allocations remain bounded; completion locks; reopening requires reason and preserves history |  |  |  |
| CASH-09 | Account eligible for closure | Request, review, approve, and close an account | Closure is blocked when balance/dependencies are not satisfied and history is preserved |  |  |  |

## 9. Expenses

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| EXP-01 | Expense category/account/currency | Create an expense draft with a line and optional evidence | Server calculates totals and the draft is visible in history |  |  |  |
| EXP-02 | Draft expense requiring approval/evidence | Submit, review, approve, and post | Required controls are enforced and the posted expense is traceable |  |  |  |
| EXP-03 | Pay Later expense | Post a Pay Later expense and hand it to Payments | One obligation is created; no cash effect is created by the expense posting |  |  |  |
| EXP-04 | Paid Now or reimbursement scenario | Complete the documented settlement flow and review the handoff | Payment/reimbursement ownership is distinct and no duplicate obligation/cash effect appears |  |  |  |
| EXP-05 | Recurring template/import | Generate a recurring record or preview/apply a CSV import | Generated/imported records remain governed drafts and replay is safe |  |  |  |
| EXP-06 | Posted expense correction | Create credit/adjustment/refund/reversal where authorized | Original record remains intact and linked correction effects are correct |  |  |  |

## 10. Reports & Analytics

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| REP-01 | Source data and report-view permission | Open representative Sales, Receivables, Collections, Purchases, Payables, Payments, Inventory, Cash, Expenses, and Business Performance reports | Report totals reconcile to owner-module data and source permissions are respected |  |  |  |
| REP-02 | Source data across dates/currencies | Apply date, currency, branch, and other supported filters | Filters are reflected in the displayed result and do not cross company scope |  |  |  |
| REP-03 | Report with drill-down and history | Drill down, open history, and create a snapshot | Drill-down and snapshots retain source/version context and immutability expectations |  |  |  |
| REP-04 | Export-capable report | Request PDF, XLSX, and CSV where offered; download and print | Output is authorized, correctly formatted, and the request/output history is visible |  |  |  |
| REP-05 | Staging with current Render config | Request a queued-format report and observe status; review scheduled report behavior | Current `QUEUE_CONNECTION=sync` behavior is understood; scheduled processing is attempted only after an operator has configured a scheduler runtime |  |  |  |

## 11. Master Registries

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| REG-01 | Registry permission | Search, paginate, create, edit, deactivate, and reactivate a Business Partner | Shared identity, roles, history, and duplicate controls behave as documented |  |  |  |
| REG-02 | Customer and supplier role access | Add/view Customer and Supplier roles, contacts, addresses, and identifiers | Child records remain company-scoped and lifecycle history is visible |  |  |  |
| REG-03 | Product/service dependencies | Create a service and stock-managed product with category/unit | Rules for stock management, units, categories, and deactivation are enforced |  |  |  |
| REG-04 | Reference registry role | Create/edit/deactivate currency, payment method, terms, tax code, account title, and expense category | Defaults and dependency blocking work; inactive references disappear from valid lookups |  |  |  |
| REG-05 | Location hierarchy role | Create branch, warehouse, and stock location; search and use them in a transaction form | Hierarchy and same-company lookup constraints are respected |  |  |  |

## 12. Settings & Administration

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| SET-01 | Business Owner/Administrator | Open Settings landing, search settings, and review setup progress/Needs Attention | Settings cards and status are accurate and links open the intended owner surface |  |  |  |
| SET-02 | Business Owner/Administrator | Edit company profile, defaults, numbering, financial/tax settings, and module preferences | Changes validate, save, are audited, and affect consuming forms where applicable |  |  |  |
| SET-03 | Administrator | Invite a user; resend/cancel; accept from a separate test account; change role/status | Invitation is single-use/expiring; membership status and permissions update safely |  |  |  |
| SET-04 | Authorized user | Review profile/preferences, notification settings, sessions/security, audit/activity, and exports | Sensitive values are masked; actions are scoped, audited, and downloadable only when authorized |  |  |  |
| SET-05 | Business Owner | Request and complete an ownership transfer with the second party | Two-party protected acceptance is required and the transfer history is preserved |  |  |  |

## 13. Mobile / Responsive

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| MOB-01 | Mobile viewport | Open login, setup, Dashboard, and main navigation; open/close the drawer with keyboard and touch | Drawer, focus, close control, and content recovery are usable |  |  |  |
| MOB-02 | Mobile viewport | Open Sales, Collections, Purchases, Payments, Inventory, Cash, and Expenses tables | Tables remain readable, scroll or reflow intentionally, and actions are reachable |  |  |  |
| MOB-03 | Mobile viewport | Open forms, dialogs, statement/reconciliation screens, and report filters | Dialogs fit or scroll, fields remain labeled, and submit/cancel controls are accessible |  |  |  |
| MOB-04 | Tablet and wide desktop | Review Dashboard, Master Registries, Settings, and Reports | Layout uses available width without clipping or inaccessible horizontal overflow |  |  |  |

## 14. Permissions / Tenant Isolation

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| SEC-01 | Member and Administrator test users | Attempt allowed read actions and forbidden create/approve/post actions | Allowed actions work; forbidden actions fail server-side with 403 |  |  |  |
| SEC-02 | Two companies with known records and UUIDs | While scoped to Company A, open Company B record URLs and call direct API IDs | Company B records are not returned or changed |  |  |  |
| SEC-03 | Viewer/limited permission scenario | Hide or bypass a frontend button and call the API directly | UI hiding is not relied upon; API permission middleware still rejects the action |  |  |  |
| SEC-04 | Report/dashboard user with restricted source permission | Open dashboard, reports, exports, and drill-downs | Unauthorized source values are not disclosed through aggregates or downloads |  |  |  |
| SEC-05 | Attachment/report output from another company | Attempt direct download using a known UUID | Download is denied or not found and no file metadata leaks |  |  |  |

## 15. Error Handling

| UAT ID | Preconditions / role | Steps | Expected result | Actual result | PASS / FAIL | Notes |
|---|---|---|---|---|---|---|
| ERR-01 | Any form | Submit missing/invalid required fields | Validation envelope is understandable, fields remain usable, and no stack trace appears |  |  |  |
| ERR-02 | Signed-out browser | Call or navigate to a protected endpoint | 401 behavior is safe and the frontend offers sign-in recovery |  |  |  |
| ERR-03 | Limited role | Attempt an unauthorized action | 403 message is safe and no server detail is disclosed |  |  |  |
| ERR-04 | Unknown UUID/URL | Open a missing record or route | 404 behavior is safe and does not expose SQL, paths, or stack traces |  |  |  |
| ERR-05 | Concurrent edit or repeated request | Submit a stale version or replay an idempotent action | Conflict is clear, no duplicate financial effect occurs, and recovery is safe |  |  |  |
| ERR-06 | Network/server failure | Use browser/network tooling or a controlled staging interruption | Error is visible, retry behavior is safe, and fake financial data is not substituted |  |  |  |

## Sign-off

| Area | Tester | Date | Result | Notes |
|---|---|---|---|---|
| Core/auth/company |  |  |  |  |
| Sales/collections |  |  |  |  |
| Purchases/payments |  |  |  |  |
| Inventory/cash/expenses |  |  |  |  |
| Reports/dashboard |  |  |  |  |
| Registries/settings |  |  |  |  |
| Mobile/accessibility |  |  |  |  |
| Permissions/isolation |  |  |  |  |
| Release owner approval |  |  |  |  |

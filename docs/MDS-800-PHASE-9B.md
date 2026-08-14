# MDS-800 Phase 9B — Expenses Completion

Phase 9B closes the deferred MDS-800-owned completion increment after Phase 9A. The pre-change checkpoint is `backups/simplebiz-before-phase9b-expenses-completion-20260814-141038.zip`. The branch remains `develop`; no files were staged, committed, pushed, reset, or cleaned.

## Verified gap matrix

| Requirement | Phase 9A state | MDS-800 owner? | Phase 9B work |
| --- | --- | --- | --- |
| Reimbursement Claim identity, claimant, business purpose, linked Expenses | Partial | Yes | Implemented `reimbursement_claims`, linked Expense Records, claimant Business Partner, lifecycle, history, approval and audit records. |
| Reimbursement obligation and payment handoff | Deferred | MDS-800 source; MDS-500 payment execution | Implemented `reimbursement_obligations`; MDS-500 receives a distinct `reimbursement_obligation_id` source and returns allocation/unapplication settlement events. |
| Recurring Expense templates and controlled draft generation | Deferred | Yes | Implemented templates, monthly/quarterly/annual schedules, occurrences, pause/resume state and replay-safe scheduled generation. Generated records remain drafts. |
| Expense copy | Deferred | Yes | Implemented server-side copy with a source link and fresh number, version, history and duplicate checks. |
| Adjustment, credit, refund and reversal source records | Deferred | Yes | Implemented immutable linked `expense_adjustment_entries`, reason/date/status, balanced correction accounting, obligation effect projection and reversal dependency checks. |
| Expense refund cash ownership | Deferred | MDS-800 source; MDS-700 cash | Implemented a cash-pending refund that can only complete after linking a same-company MDS-700 Cash Movement. The Expense service never edits Cash Account balances directly. |
| Functional currency and exchange-rate trace | Partial | MDS-800 record; shared currency registry | Implemented functional currency, exchange rate, translated totals and translated settlement amounts while preserving source currency amounts. |
| Controlled Expense bulk import | Deferred | Yes; edition-gated by MDS-1100 policy | Implemented CSV preview, row-level validation, batch audit and draft-only apply. Import never submits, approves, posts, pays, seeds, or creates demo data. |
| Expense-specific reports | Partial | Yes; shared catalog/export remains MDS-900 | Implemented summary, category, account, payee, unpaid, approval, evidence, duplicate, reimbursement, recurring and credit/refund projections. Shared scheduling/export is not duplicated. |
| Needs Attention completion measures | Partial | Yes | Added pending reimbursement, recurring-due and cash-pending correction measures to the existing Expense attention response. |
| OCR, AI, bank-feed and card-feed automation | Deferred / future suggestion | No automatic implementation authorized | Not implemented. The authoritative MDS-800 specification treats these as suggestion/integration boundaries, not automatic posting capabilities. |
| Employee/contractor master data | Deferred owner boundary | MDS-1000 | Existing `users` plus shared Business Partner payee identity are used; no duplicate employee master was created. |
| Configurable policy, entitlements, retention and localization | Deferred owner boundary | MDS-1100 | Existing server permissions remain enforced. No second settings engine was invented. |
| Shared report catalog, export and scheduling | Deferred owner boundary | MDS-900 | Expense-specific projections are exposed without duplicating MDS-900 infrastructure. |

## API surface

- Reimbursements: `GET|POST /api/v1/expenses/reimbursements`, detail, `submit|approve|reject|return|post|cancel`, and `pay`.
- Recurring: `GET|POST /api/v1/expenses/recurring`, `PATCH /recurring/{id}`, and `POST /recurring/{id}/generate`.
- Corrections: `GET /api/v1/expenses/corrections`, `POST /expenses/{id}/copy`, `POST /expenses/{id}/correction`, and refund Cash Movement linking.
- Reports: `GET /api/v1/expenses/reports/{report}`.
- Import: `POST /api/v1/expenses/import/preview` and `POST /api/v1/expenses/import/{id}/apply`.

All routes are company-scoped and use the Phase 9B permission catalog. Payment execution, confirmation, allocation, reversal and cash effects remain in MDS-500/MDS-700 services.

## Data and safety boundaries

Migration `2026_08_14_000042_complete_expenses_phase9b` is forward-only and was applied locally. It adds the completion entities and the third payment source owner without changing applied Phase 9A migrations. No migration refresh, destructive database command, seeder, demo data, or business-module rewrite was used.

Posted Expense Records remain immutable. Corrections preserve the original record and create linked accounting/audit entries. A refund remains `cash_pending` until a same-company MDS-700 Cash Movement is linked. Reimbursement payments use MDS-500 Payment Instructions and do not create cash at handoff.


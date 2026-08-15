# MDS-700 Phase 12 — Cash Accounts Final Closure

Status: implemented in the `develop` branch on 2026-08-15.

## Scope

Phase 12 closes the MDS-700 gaps identified against the supplied Cash Accounts specification. It is limited to Cash Account lifecycle integrity, source-owned Cash Account projections, and regression coverage. It does not add a bank feed, OCR/parser, AI matching, foreign-exchange engine, or another business module.

## Delivered

- Added governed Cash Account closure states: `pending_closure` and `closed`, with requested, under-review, balance-resolution, approved, closed, and cancelled closure states; request, review, resolution, approval, execution, cancellation, version checks, blocker snapshots, effective date, retained account history, archive evidence, audit records, and after-commit lifecycle events.
- Closure is blocked by a non-zero posted balance, unresolved movement or transfer documents, pending checks, open counts, open reconciliations, unresolved statement imports, open reconciliation outstanding items, or an active primary custodian.
- Closure approval and execution enforce segregation of duties. Execution re-evaluates blockers so a stale approval cannot close an account after the account changes.
- Financially used accounts cannot change their Asset Account Title or Currency mapping. Identifier fields remain masked and encrypted as before.
- Cash Account summary and Needs Attention endpoints now disclose the MDS-700 source owner, as-of timestamp, freshness state, currency context, server-owned metrics, and drilldown-ready attention items.
- Added MDS-700 source projections for Cash Movement History, Cash Transfer History, Cash Count, Cash Reconciliation, and Cash Account Exceptions. Internal transfers remain two-leg movements and are not counted as company inflows or outflows.
- Registered the new MDS-900 source contracts and report definitions. Report execution remains owned by MDS-900; MDS-700 supplies source rows and semantics.
- Refined the Cash Account detail screen with closure status, effective date, lifecycle actions, blocker visibility, and MDS-700 control guidance.

## Edition and non-applicable scope

SimpleBIZ Free continues to use manual/limited statement import and the existing reviewable matching path. The MDS-700 specification does not name a machine statement format, bank-feed provider, or OCR contract, so no parser or provider integration was invented for this phase.

## Validation

- Focused `CashAccountPhase12Test`: **2 passed, 25 assertions**.
- Complete backend suite: **92 passed, 1 intentionally skipped PostgreSQL-only schema test, 907 assertions**.
- Frontend: **18 tests passed across 10 files**; ESLint and TypeScript/Vite production build passed.
- `vendor/bin/pint --test` and `git diff --check` passed.
- Migrations `2026_08_15_000049_complete_cash_phase12` and `2026_08_15_000050_register_cash_phase12_reports` are applied locally.
- Docker image/runtime validation was unavailable because the Docker daemon is not running in this environment. Render CLI validation was unavailable because the CLI is not installed; deployment configuration was not changed.

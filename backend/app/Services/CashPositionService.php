<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\CashAccount;
use App\Models\CashCountVariance;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Reconciliation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CashPositionService
{
    public function forAccount(CashAccount $account): array
    {
        $posted = $this->sum($account, ['movement_status' => 'posted']);
        $pending = $this->sum($account, ['movement_status' => 'pending']);
        $cleared = $this->sum($account, ['movement_status' => 'posted', 'clearing_status' => 'cleared']);
        $reconciled = $this->sum($account, ['movement_status' => 'posted', 'reconciliation_status' => 'reconciled']);

        return [
            'posted_balance' => $posted,
            'available_balance' => $posted,
            'pending_amount' => $pending,
            'cleared_amount' => $cleared,
            'reconciled_amount' => $reconciled,
            'currency' => $account->currency?->code,
            'currency_code' => $account->currency?->code,
            'pending_balance' => $pending,
            'as_of' => now()->toIso8601String(),
            'source_owner' => 'MDS-700 Cash Accounts',
            'source_freshness' => 'live_from_posted_movements',
        ];
    }

    public function grouped(int $companyId): array
    {
        return CashMovement::query()
            ->join('cash_accounts', 'cash_accounts.id', '=', 'cash_movements.cash_account_id')
            ->join('reference_currencies', 'reference_currencies.id', '=', 'cash_accounts.currency_id')
            ->where('cash_movements.company_id', $companyId)
            ->where('cash_movements.movement_status', 'posted')
            ->selectRaw("reference_currencies.code as currency, COALESCE(SUM(CASE WHEN cash_movements.direction = 'increase' THEN cash_movements.amount ELSE -cash_movements.amount END), 0) as posted_balance")
            ->groupBy('reference_currencies.code')
            ->orderBy('reference_currencies.code')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'currency_code' => $row->currency, 'posted_balance' => $this->decimal((string) $row->posted_balance), 'as_of' => now()->toIso8601String(), 'source_owner' => 'MDS-700 Cash Accounts', 'freshness_state' => 'current'])
            ->values()
            ->all();
    }

    public function dashboard(Company $company): array
    {
        $accounts = CashAccount::where('company_id', $company->id)->whereIn('status', ['active', 'restricted', 'pending_closure'])->with('currency')->get();
        $negativeAccounts = $accounts->map(fn (CashAccount $account) => [$account, $this->forAccount($account)])->filter(fn ($entry) => (float) $entry[1]['posted_balance'] < 0)->map(fn ($entry) => ['id' => $entry[0]->id, 'code' => $entry[0]->code, 'name' => $entry[0]->name, 'currency' => $entry[0]->currency?->code, 'posted_balance' => $entry[1]['posted_balance']])->values()->all();
        $pendingMovements = DB::table('cash_movement_documents')->where('company_id', $company->id)->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])->count();
        $pendingTransfers = DB::table('cash_transfer_documents')->where('company_id', $company->id)->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])->count();
        $unreconciledMovements = DB::table('cash_movements')->where('company_id', $company->id)->where('movement_status', 'posted')->where('reconciliation_status', 'unreconciled')->count();
        $varianceCount = CashCountVariance::where('company_id', $company->id)->whereIn('status', ['disposition_pending', 'investigating'])->count();
        $openReconciliations = Reconciliation::where('company_id', $company->id)->whereNotIn('status', ['completed', 'cancelled'])->count();
        $items = [];
        foreach ($negativeAccounts as $account) {
            $items[] = ['code' => 'negative_balance', 'severity' => 'high', 'title' => 'Negative Cash Account balance', 'count' => 1, 'source_owner' => 'MDS-700 Cash Accounts', 'source_id' => $account['id'], 'detail' => $account];
        }
        foreach ([['pending_movements', 'Pending Cash Movement documents', $pendingMovements, 'medium'], ['pending_transfers', 'Pending Cash Transfers', $pendingTransfers, 'medium'], ['unreconciled_movements', 'Posted movements not reconciled', $unreconciledMovements, 'medium'], ['count_variances', 'Cash Count variances requiring disposition', $varianceCount, 'high'], ['open_reconciliations', 'Open Cash Account reconciliations', $openReconciliations, 'medium']] as [$code, $title, $count, $severity]) {
            if ($count > 0) {
                $items[] = ['code' => $code, 'severity' => $severity, 'title' => $title, 'count' => $count, 'source_owner' => 'MDS-700 Cash Accounts'];
            }
        }

        return ['metrics' => ['active_accounts' => $accounts->where('status', 'active')->count(), 'restricted_accounts' => $accounts->where('status', 'restricted')->count(), 'pending_closure_accounts' => $accounts->where('status', 'pending_closure')->count(), 'negative_accounts' => count($negativeAccounts), 'pending_movements' => $pendingMovements, 'pending_transfers' => $pendingTransfers, 'unreconciled_movements' => $unreconciledMovements, 'count_variances' => $varianceCount, 'open_reconciliations' => $openReconciliations], 'position_by_currency' => $this->grouped($company->id), 'attention' => ['items' => $items, 'total' => count($items)], 'source_owner' => 'MDS-700 Cash Accounts', 'source_as_of_at' => now()->toIso8601String(), 'freshness_state' => 'current', 'currency_context' => 'Cash positions and attention amounts remain separated by account currency; transfers are not company inflows or outflows.'];
    }

    /**
     * Read-only MDS-700 source contracts for MDS-900.
     */
    public function report(string $report, Company $company, Request $request): array
    {
        return match ($report) {
            'cash_position' => $this->cashPositionReport($company, $request),
            'cash_account_ledger' => $this->cashAccountLedgerReport($company, $request),
            'cash_movement_history' => $this->cashMovementHistoryReport($company, $request),
            'cash_transfer_history' => $this->cashTransferHistoryReport($company, $request),
            'cash_count' => $this->cashCountReport($company, $request),
            'cash_reconciliation' => $this->cashReconciliationReport($company, $request),
            'cash_exceptions' => ['rows' => $this->dashboard($company)['attention']['items'], 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Cash Account exception records disclose their source and preserve currency separation.'],
            default => throw new RegistryConflictException('The requested Cash Accounts source report is not supported.'),
        };
    }

    private function cashMovementHistoryReport(Company $company, Request $request): array
    {
        $movements = CashMovement::where('company_id', $company->id)->when($request->input('from'), fn ($query, $from) => $query->whereDate('business_date', '>=', $from))->when($request->input('to'), fn ($query, $to) => $query->whereDate('business_date', '<=', $to))->with('account.currency')->orderByDesc('business_date')->orderByDesc('posted_at')->get();

        return ['rows' => $movements->map(fn (CashMovement $movement) => ['id' => $movement->id, 'business_date' => $movement->business_date?->toDateString(), 'account' => $movement->account?->display_name ?: $movement->account?->name, 'account_code' => $movement->account?->code, 'currency' => $movement->currency_code, 'direction' => $movement->direction, 'amount' => (string) $movement->amount, 'movement_status' => $movement->movement_status, 'clearing_status' => $movement->clearing_status, 'reconciliation_status' => $movement->reconciliation_status, 'source_event_type' => $movement->source_event_type, 'source_record_type' => $movement->source_record_type, 'source_record_id' => $movement->source_record_id, 'source_reference' => $movement->source_reference, 'posted_at' => $movement->posted_at?->toISOString()])->values()->all(), 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Cash Movement History preserves movement sign, status, source trace, and currency.'];
    }

    private function cashTransferHistoryReport(Company $company, Request $request): array
    {
        $transfers = DB::table('cash_transfer_documents as transfers')->leftJoin('cash_accounts as source', 'source.id', '=', 'transfers.source_cash_account_id')->leftJoin('cash_accounts as destination', 'destination.id', '=', 'transfers.destination_cash_account_id')->leftJoin('reference_currencies as currencies', 'currencies.id', '=', 'transfers.currency_id')->where('transfers.company_id', $company->id)->when($request->input('from'), fn ($query, $from) => $query->whereDate('transfers.business_date', '>=', $from))->when($request->input('to'), fn ($query, $to) => $query->whereDate('transfers.business_date', '<=', $to))->orderByDesc('transfers.business_date')->get(['transfers.id', 'transfers.document_number', 'transfers.business_date', 'transfers.status', 'transfers.amount', 'transfers.purpose', 'transfers.source_movement_id', 'transfers.destination_movement_id', 'source.code as source_account_code', 'source.name as source_account', 'destination.code as destination_account_code', 'destination.name as destination_account', 'currencies.code as currency']);

        return ['rows' => $transfers->map(fn ($transfer) => (array) $transfer)->values()->all(), 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Transfer History shows two-leg internal movement identities separately from company inflow and outflow totals.'];
    }

    private function cashCountReport(Company $company, Request $request): array
    {
        $counts = DB::table('cash_counts as counts')->leftJoin('cash_accounts as accounts', 'accounts.id', '=', 'counts.cash_account_id')->leftJoin('reference_currencies as currencies', 'currencies.id', '=', 'counts.currency_id')->leftJoin('cash_count_variances as variances', 'variances.id', '=', 'counts.variance_id')->where('counts.company_id', $company->id)->when($request->input('from'), fn ($query, $from) => $query->whereDate('counts.count_date', '>=', $from))->when($request->input('to'), fn ($query, $to) => $query->whereDate('counts.count_date', '<=', $to))->orderByDesc('counts.count_date')->get(['counts.id', 'counts.count_number', 'counts.count_date', 'counts.status', 'accounts.code as account_code', 'accounts.name as account', 'currencies.code as currency', 'counts.expected_amount', 'counts.actual_amount', 'counts.variance_amount', 'counts.variance_classification', 'variances.status as variance_status']);

        return ['rows' => $counts->map(fn ($count) => (array) $count)->values()->all(), 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Cash Count results preserve expected, actual, variance, disposition, and account currency.'];
    }

    private function cashReconciliationReport(Company $company, Request $request): array
    {
        $reconciliations = Reconciliation::where('company_id', $company->id)->when($request->input('from'), fn ($query, $from) => $query->whereDate('period_start', '>=', $from))->when($request->input('to'), fn ($query, $to) => $query->whereDate('period_end', '<=', $to))->with('account')->orderByDesc('period_end')->get();

        return ['rows' => $reconciliations->map(fn (Reconciliation $reconciliation) => ['id' => $reconciliation->id, 'reconciliation_number' => $reconciliation->reconciliation_number, 'account' => $reconciliation->account?->name, 'account_code' => $reconciliation->account?->code, 'currency' => $reconciliation->currency_code, 'period_start' => $reconciliation->period_start?->toDateString(), 'period_end' => $reconciliation->period_end?->toDateString(), 'status' => $reconciliation->status, 'matched_statement_amount' => (string) $reconciliation->matched_statement_amount, 'matched_movement_amount' => (string) $reconciliation->matched_movement_amount, 'outstanding_statement_amount' => (string) $reconciliation->outstanding_statement_amount, 'outstanding_movement_amount' => (string) $reconciliation->outstanding_movement_amount, 'difference_amount' => (string) $reconciliation->difference_amount, 'locked_at' => $reconciliation->locked_at?->toISOString()])->values()->all(), 'source_as_of_at' => now(), 'freshness_state' => 'current', 'currency_context' => 'Cash Reconciliation preserves the statement and internal movement populations, outstanding items, difference, status, and lock state.'];
    }

    private function cashPositionReport(Company $company, Request $request): array
    {
        $asOf = Carbon::parse($request->input('as_of') ?: now($company->timezone)->toDateString(), $company->timezone)->toDateString();
        $accounts = CashAccount::where('company_id', $company->id)
            ->whereIn('status', ['active', 'restricted'])
            ->with(['type', 'currency', 'branch'])
            ->orderBy('code')
            ->get();

        $rows = $accounts->map(function (CashAccount $account) use ($company, $asOf) {
            $base = CashMovement::where('company_id', $company->id)->where('cash_account_id', $account->id)->whereDate('business_date', '<=', $asOf);
            $posted = $this->net((clone $base)->where('movement_status', 'posted'));
            $pending = $this->net((clone $base)->where('movement_status', 'pending'));
            $cleared = $this->net((clone $base)->where('movement_status', 'posted')->where('clearing_status', 'cleared'));
            $reconciled = $this->net((clone $base)->where('movement_status', 'posted')->where('reconciliation_status', 'reconciled'));

            return [
                'id' => $account->id,
                'account' => $account->display_name ?: $account->name,
                'account_code' => $account->code,
                'account_type' => $account->type?->name,
                'branch' => $account->branch?->name,
                'currency' => $account->currency?->code,
                'status' => $account->status,
                'posted' => $posted,
                'available' => $posted,
                'pending' => $pending,
                'cleared' => $cleared,
                'reconciled' => $reconciled,
                'reconciliation_state' => $account->last_reconciliation_at ? 'reconciled_or_reviewed' : 'unreconciled',
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'source_as_of_at' => now(),
            'freshness_state' => 'current',
            'currency_context' => 'Cash positions remain separated by currency; internal transfers are not company cash inflows or outflows.',
            'as_of_basis' => $asOf,
        ];
    }

    private function cashAccountLedgerReport(Company $company, Request $request): array
    {
        $to = Carbon::parse($request->input('to') ?: now($company->timezone)->toDateString(), $company->timezone)->toDateString();
        $from = Carbon::parse($request->input('from') ?: Carbon::parse($to)->startOfMonth()->toDateString(), $company->timezone)->toDateString();
        $accountId = $request->input('cash_account_id');
        $accounts = CashAccount::where('company_id', $company->id)
            ->whereIn('status', ['active', 'restricted'])
            ->when($accountId, fn ($query) => $query->whereKey($accountId))
            ->with(['currency', 'type'])
            ->orderBy('code')
            ->get();

        $rows = $accounts->map(function (CashAccount $account) use ($company, $from, $to) {
            $before = CashMovement::where('company_id', $company->id)->where('cash_account_id', $account->id)->where('movement_status', 'posted')->whereDate('business_date', '<', $from);
            $movements = CashMovement::where('company_id', $company->id)->where('cash_account_id', $account->id)->where('movement_status', 'posted')->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('posted_at')->orderBy('id')->get();
            $opening = $this->net($before);
            $inflows = '0';
            $outflows = '0';
            $transfers = '0';
            $reconciliationState = 'unreconciled';
            foreach ($movements as $movement) {
                $signed = $movement->direction === 'increase' ? (string) $movement->amount : bcmul((string) $movement->amount, '-1', 6);
                if ($this->isTransfer($movement)) {
                    $transfers = bcadd($transfers, $signed, 6);
                } elseif ($movement->direction === 'increase') {
                    $inflows = bcadd($inflows, (string) $movement->amount, 6);
                } else {
                    $outflows = bcadd($outflows, (string) $movement->amount, 6);
                }
                if ($movement->reconciliation_status === 'reconciled') {
                    $reconciliationState = 'reconciled';
                }
            }
            $closing = bcadd(bcsub(bcadd($opening, $inflows, 6), $outflows, 6), $transfers, 6);

            return [
                'id' => $account->id,
                'account' => $account->display_name ?: $account->name,
                'account_code' => $account->code,
                'account_type' => $account->type?->name,
                'currency' => $account->currency?->code,
                'from' => $from,
                'to' => $to,
                'opening' => $opening,
                'inflows' => $inflows,
                'outflows' => $outflows,
                'transfers' => $transfers,
                'closing' => $closing,
                'reconciliation_state' => $reconciliationState,
                'movement_count' => $movements->count(),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'source_as_of_at' => now(),
            'freshness_state' => 'current',
            'currency_context' => 'Cash Account Ledger totals remain separated by account currency. Transfers are shown separately from company inflows and outflows.',
        ];
    }

    private function net($query): string
    {
        $value = $query->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN amount ELSE -amount END), 0) as total")->value('total');

        return $this->decimal((string) $value);
    }

    private function isTransfer(CashMovement $movement): bool
    {
        return str_contains((string) $movement->source_record_type, 'CashTransferDocument') || str_contains(strtolower((string) $movement->source_event_type), 'transfer');
    }

    private function sum(CashAccount $account, array $filters): string
    {
        $query = CashMovement::query()->where('cash_account_id', $account->id)->where('company_id', $account->company_id);
        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }
        $value = $query->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN amount ELSE -amount END), 0) as total")->value('total');

        return $this->decimal((string) $value);
    }

    private function decimal(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-0') {
            return '0';
        }
        if (str_contains($value, '.')) {
            [$whole, $fraction] = explode('.', $value, 2);
            $fraction = rtrim($fraction, '0');

            return $fraction === '' ? $whole : $whole.'.'.$fraction;
        }

        return $value;
    }
}

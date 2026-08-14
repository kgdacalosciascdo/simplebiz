<?php

namespace App\Services;

use App\Exceptions\RegistryConflictException;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
            'as_of' => now()->toIso8601String(),
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
            ->map(fn ($row) => ['currency' => $row->currency, 'posted_balance' => $this->decimal((string) $row->posted_balance), 'as_of' => now()->toIso8601String()])
            ->values()
            ->all();
    }

    /**
     * Read-only MDS-700 source contracts for MDS-900.
     */
    public function report(string $report, Company $company, Request $request): array
    {
        return match ($report) {
            'cash_position' => $this->cashPositionReport($company, $request),
            'cash_account_ledger' => $this->cashAccountLedgerReport($company, $request),
            default => throw new RegistryConflictException('The requested Cash Accounts source report is not supported.'),
        };
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

<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashMovement;

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

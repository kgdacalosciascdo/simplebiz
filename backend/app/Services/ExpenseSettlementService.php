<?php

namespace App\Services;

use App\Events\ExpenseLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseObligation;
use App\Models\ExpenseStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ExpenseSettlementService
{
    public function apply(ExpenseObligation $obligation, string $amount, Company $company, Request $request): ExpenseObligation
    {
        $locked = ExpenseObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with('expense')->firstOrFail();
        if (bccomp($amount, '0', 6) <= 0 || bccomp($amount, (string) $locked->remaining_amount, 6) > 0) {
            throw new RegistryConflictException('The Expense Obligation cannot accept an allocation greater than its remaining amount.');
        }
        $this->updateBalances($locked, bcadd((string) $locked->paid_amount, $amount, 6), $company, $request, 'EVT-EXP-SETTLEMENT');

        return $locked->refresh();
    }

    public function reverse(ExpenseObligation $obligation, string $amount, Company $company, Request $request): ExpenseObligation
    {
        $locked = ExpenseObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with('expense')->firstOrFail();
        $paid = bcsub((string) $locked->paid_amount, $amount, 6);
        if (bccomp($paid, '0', 6) < 0) {
            throw new RegistryConflictException('The Expense Obligation settlement cannot be reversed below zero.');
        }
        $this->updateBalances($locked, $paid, $company, $request, 'EVT-EXP-SETTLEMENT-REVERSAL');

        return $locked->refresh();
    }

    private function updateBalances(ExpenseObligation $obligation, string $paid, Company $company, Request $request, string $event): void
    {
        $remaining = bcsub((string) $obligation->original_amount, $paid, 6);
        if (bccomp($remaining, '0', 6) < 0) {
            throw new RegistryConflictException('The Expense Obligation balance cannot become negative.');
        }
        $oldExpenseStatus = $obligation->expense?->status;
        $settlement = bccomp($remaining, '0', 6) === 0 ? 'settled' : (bccomp($paid, '0', 6) > 0 ? 'partially_paid' : 'unpaid');
        $dueStatus = bccomp($remaining, '0', 6) === 0 ? 'settled' : ($obligation->due_date?->isPast() ? 'overdue' : ($obligation->due_date?->isToday() ? 'due_today' : 'not_due'));
        $obligation->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'settlement_status' => $settlement, 'payment_ready' => bccomp($remaining, '0', 6) > 0, 'due_status' => $dueStatus, 'version' => $obligation->version + 1]);
        if ($obligation->expense) {
            $expense = Expense::where('company_id', $company->id)->whereKey($obligation->expense_id)->lockForUpdate()->first();
            if (! $expense) {
                return;
            }
            $paymentStatus = bccomp($remaining, '0', 6) === 0 ? 'paid' : (bccomp($paid, '0', 6) > 0 ? 'partially_paid' : 'unpaid');
            $expenseStatus = $paymentStatus === 'paid' ? 'paid' : ($paymentStatus === 'partially_paid' ? 'partially_paid' : (in_array($expense->status, ['scheduled'], true) ? 'scheduled' : 'payment_ready'));
            $rate = (string) ($expense->exchange_rate ?: '1');
            $expense->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'functional_paid_amount' => bcmul($paid, $rate, 6), 'functional_remaining_amount' => bcmul($remaining, $rate, 6), 'payment_status' => $paymentStatus, 'status' => $expenseStatus, 'version' => $expense->version + 1]);
            ExpenseStatusHistory::create(['id' => (string) Str::uuid(), 'expense_id' => $expense->id, 'company_id' => $company->id, 'from_status' => $oldExpenseStatus, 'to_status' => $expenseStatus, 'approval_status' => $expense->approval_status, 'payment_status' => $paymentStatus, 'event_code' => $event, 'reason' => 'Expense settlement synchronized from confirmed MDS-500 allocation.', 'actor_id' => $request->user()?->id, 'version' => $expense->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
            Event::dispatch(new ExpenseLifecycleEvent($event, $company->id, $expense->id, $request->user()?->id, $request->attributes->get('correlation_id')));
        }
    }
}

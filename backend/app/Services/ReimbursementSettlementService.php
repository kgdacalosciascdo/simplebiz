<?php

namespace App\Services;

use App\Events\ExpenseLifecycleEvent;
use App\Exceptions\RegistryConflictException;
use App\Models\Company;
use App\Models\ReimbursementClaim;
use App\Models\ReimbursementObligation;
use App\Models\ReimbursementStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ReimbursementSettlementService
{
    public function apply(ReimbursementObligation $obligation, string $amount, Company $company, Request $request): ReimbursementObligation
    {
        $locked = ReimbursementObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with('claim')->firstOrFail();
        if (bccomp($amount, '0', 6) <= 0 || bccomp($amount, (string) $locked->remaining_amount, 6) > 0) {
            throw new RegistryConflictException('The Reimbursement Obligation cannot accept an allocation greater than its remaining amount.');
        }
        $this->updateBalances($locked, bcadd((string) $locked->paid_amount, $amount, 6), $company, $request, 'EVT-EXP-REIMBURSEMENT-SETTLED');

        return $locked->refresh();
    }

    public function reverse(ReimbursementObligation $obligation, string $amount, Company $company, Request $request): ReimbursementObligation
    {
        $locked = ReimbursementObligation::where('company_id', $company->id)->whereKey($obligation->id)->lockForUpdate()->with('claim')->firstOrFail();
        $paid = bcsub((string) $locked->paid_amount, $amount, 6);
        if (bccomp($paid, '0', 6) < 0) {
            throw new RegistryConflictException('The Reimbursement settlement cannot be reversed below zero.');
        }
        $this->updateBalances($locked, $paid, $company, $request, 'EVT-EXP-REIMBURSEMENT-SETTLEMENT-REVERSAL');

        return $locked->refresh();
    }

    private function updateBalances(ReimbursementObligation $obligation, string $paid, Company $company, Request $request, string $event): void
    {
        $remaining = bcsub((string) $obligation->original_amount, $paid, 6);
        $settlement = bccomp($remaining, '0', 6) === 0 ? 'settled' : (bccomp($paid, '0', 6) > 0 ? 'partially_paid' : 'unpaid');
        $dueStatus = bccomp($remaining, '0', 6) === 0 ? 'settled' : ($obligation->due_date?->isPast() ? 'overdue' : ($obligation->due_date?->isToday() ? 'due_today' : 'not_due'));
        $obligation->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'settlement_status' => $settlement, 'payment_ready' => bccomp($remaining, '0', 6) > 0, 'due_status' => $dueStatus, 'version' => $obligation->version + 1]);
        if (! $obligation->claim) {
            return;
        }
        $claim = ReimbursementClaim::where('company_id', $company->id)->whereKey($obligation->reimbursement_claim_id)->lockForUpdate()->firstOrFail();
        $status = bccomp($remaining, '0', 6) === 0 ? 'paid' : (bccomp($paid, '0', 6) > 0 ? 'partially_paid' : 'payment_ready');
        $claim->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'payment_status' => $settlement === 'settled' ? 'paid' : ($paid === '0' ? 'unpaid' : 'partially_paid'), 'status' => $status, 'version' => $claim->version + 1]);
        ReimbursementStatusHistory::create(['id' => (string) Str::uuid(), 'reimbursement_claim_id' => $claim->id, 'company_id' => $company->id, 'from_status' => $claim->getOriginal('status'), 'to_status' => $status, 'event_code' => $event, 'reason' => 'Reimbursement settlement synchronized from confirmed MDS-500 allocation.', 'actor_id' => $request->user()?->id, 'version' => $claim->version, 'correlation_id' => $request->attributes->get('correlation_id')]);
        Event::dispatch(new ExpenseLifecycleEvent($event, $company->id, $claim->id, $request->user()?->id, $request->attributes->get('correlation_id')));
    }
}

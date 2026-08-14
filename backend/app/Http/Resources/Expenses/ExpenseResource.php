<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'business_date' => $this->business_date?->toDateString(),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : null),
            'payee_id' => $this->payee_id,
            'payee' => $this->whenLoaded('payee', fn () => $this->payee ? ['id' => $this->payee->id, 'code' => $this->payee->code, 'display_name' => $this->payee->display_name] : null),
            'payee_name' => $this->payee_name_snapshot,
            'external_reference' => $this->external_reference,
            'description' => $this->description,
            'currency_id' => $this->currency_id,
            'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'name' => $this->currency->name, 'symbol' => $this->currency->symbol] : null),
            'settlement_intent' => $this->settlement_intent,
            'payment_term_id' => $this->payment_term_id,
            'payment_term' => $this->whenLoaded('paymentTerm', fn () => $this->paymentTerm ? ['id' => $this->paymentTerm->id, 'code' => $this->paymentTerm->code, 'name' => $this->paymentTerm->name, 'due_days' => $this->paymentTerm->due_days] : null),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'payment_status' => $this->payment_status,
            'evidence_status' => $this->evidence_status,
            'duplicate_status' => $this->duplicate_status,
            'approval_required' => $this->approval_required,
            'evidence_required' => $this->evidence_required,
            'subtotal' => (string) $this->subtotal,
            'taxable_amount' => (string) $this->taxable_amount,
            'tax_amount' => (string) $this->tax_amount,
            'recoverable_tax_amount' => (string) $this->recoverable_tax_amount,
            'nonrecoverable_tax_amount' => (string) $this->nonrecoverable_tax_amount,
            'withholding_amount' => (string) $this->withholding_amount,
            'total' => (string) $this->total,
            'paid_amount' => (string) $this->paid_amount,
            'remaining_amount' => (string) $this->remaining_amount,
            'functional_currency_id' => $this->functional_currency_id,
            'exchange_rate' => (string) $this->exchange_rate,
            'functional_total' => (string) $this->functional_total,
            'functional_paid_amount' => (string) $this->functional_paid_amount,
            'functional_remaining_amount' => (string) $this->functional_remaining_amount,
            'reimbursement_claim_id' => $this->reimbursement_claim_id,
            'source_recurring_template_id' => $this->source_recurring_template_id,
            'source_recurring_occurrence_id' => $this->source_recurring_occurrence_id,
            'copy_source_expense_id' => $this->copy_source_expense_id,
            'lines' => ExpenseLineResource::collection($this->whenLoaded('lines')),
            'allocations' => ExpenseAllocationResource::collection($this->whenLoaded('allocations')),
            'approvals' => $this->whenLoaded('approvals'),
            'status_history' => $this->whenLoaded('statusHistory'),
            'evidences' => ExpenseEvidenceResource::collection($this->whenLoaded('evidences')),
            'duplicate_candidates' => ExpenseDuplicateResource::collection($this->whenLoaded('duplicateCandidates')),
            'obligation' => $this->whenLoaded('obligation', fn () => $this->obligation ? new ExpenseObligationResource($this->obligation) : null),
            'reimbursement_claim' => $this->whenLoaded('reimbursementClaim', fn () => $this->reimbursementClaim ? ['id' => $this->reimbursementClaim->id, 'claim_number' => $this->reimbursementClaim->claim_number, 'status' => $this->reimbursementClaim->status] : null),
            'business_transaction_id' => $this->business_transaction_id,
            'accounting_transaction_id' => $this->accounting_transaction_id,
            'version' => $this->version,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

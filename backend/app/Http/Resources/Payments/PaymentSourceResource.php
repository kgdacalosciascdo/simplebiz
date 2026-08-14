<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentSourceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payable_open_item_id' => $this->payable_open_item_id, 'expense_obligation_id' => $this->expense_obligation_id, 'reimbursement_obligation_id' => $this->reimbursement_obligation_id, 'source_document_number' => $this->source_document_number, 'requested_amount' => (string) $this->requested_amount, 'allocated_amount' => (string) $this->allocated_amount, 'currency_id' => $this->currency_id, 'payable' => $this->whenLoaded('payable', fn () => $this->payable ? ['id' => $this->payable->id, 'remaining_amount' => (string) $this->payable->remaining_amount, 'due_date' => $this->payable->due_date?->toDateString(), 'settlement_status' => $this->payable->settlement_status] : null), 'expense_obligation' => $this->whenLoaded('expenseObligation', fn () => $this->expenseObligation ? ['id' => $this->expenseObligation->id, 'expense_id' => $this->expenseObligation->expense_id, 'expense_number' => $this->expenseObligation->expense?->expense_number, 'remaining_amount' => (string) $this->expenseObligation->remaining_amount, 'due_date' => $this->expenseObligation->due_date?->toDateString(), 'payment_ready' => $this->expenseObligation->payment_ready] : null), 'reimbursement_obligation' => $this->whenLoaded('reimbursementObligation', fn () => $this->reimbursementObligation ? ['id' => $this->reimbursementObligation->id, 'claim_id' => $this->reimbursementObligation->reimbursement_claim_id, 'remaining_amount' => (string) $this->reimbursementObligation->remaining_amount, 'due_date' => $this->reimbursementObligation->due_date?->toDateString(), 'payment_ready' => $this->reimbursementObligation->payment_ready] : null)];
    }
}

<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAllocationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payment_instruction_id' => $this->payment_instruction_id, 'payment_confirmation_id' => $this->payment_confirmation_id, 'payable_open_item_id' => $this->payable_open_item_id, 'expense_obligation_id' => $this->expense_obligation_id, 'reimbursement_obligation_id' => $this->reimbursement_obligation_id, 'currency_id' => $this->currency_id, 'amount' => (string) $this->amount, 'allocation_date' => $this->allocation_date?->toDateString(), 'status' => $this->status, 'created_by' => $this->created_by, 'reversed_at' => $this->reversed_at?->toISOString(), 'reversal_reason' => $this->reversal_reason];
    }
}

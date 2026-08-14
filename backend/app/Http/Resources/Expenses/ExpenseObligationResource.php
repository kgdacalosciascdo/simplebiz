<?php

namespace App\Http\Resources\Expenses;

use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseObligationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'expense_id' => $this->expense_id, 'expense_number' => $this->whenLoaded('expense', fn () => $this->expense?->expense_number), 'payee_id' => $this->payee_id, 'payee' => $this->whenLoaded('payee', fn () => $this->payee ? ['id' => $this->payee->id, 'code' => $this->payee->code, 'display_name' => $this->payee->display_name] : null), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'original_amount' => (string) $this->original_amount, 'paid_amount' => (string) $this->paid_amount, 'credited_amount' => (string) $this->credited_amount, 'refunded_amount' => (string) $this->refunded_amount, 'adjusted_amount' => (string) $this->adjusted_amount, 'remaining_amount' => (string) $this->remaining_amount, 'due_date' => $this->due_date?->toDateString(), 'due_status' => $this->due_status, 'payment_ready' => $this->payment_ready, 'settlement_status' => $this->settlement_status, 'payment_hold_reason' => $this->payment_hold_reason, 'version' => $this->version];
    }
}

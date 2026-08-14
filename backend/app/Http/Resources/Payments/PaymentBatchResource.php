<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentBatchResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'batch_number' => $this->batch_number, 'name' => $this->name, 'status' => $this->status, 'payment_method_id' => $this->payment_method_id, 'cash_account_id' => $this->cash_account_id, 'currency_id' => $this->currency_id, 'control_total' => (string) $this->control_total, 'item_count' => $this->item_count, 'succeeded_count' => $this->succeeded_count, 'failed_count' => $this->failed_count, 'version' => $this->version, 'prepared_by' => $this->prepared_by, 'submitted_by' => $this->submitted_by, 'approved_by' => $this->approved_by, 'generated_by' => $this->generated_by, 'released_by' => $this->released_by, 'closed_by' => $this->closed_by, 'generated_at' => $this->generated_at?->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String(), 'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => ['id' => $item->id, 'payment_instruction_id' => $item->payment_instruction_id, 'status' => $item->status, 'failure_reason' => $item->failure_reason, 'payment' => $item->relationLoaded('payment') && $item->payment ? ['id' => $item->payment->id, 'payment_number' => $item->payment->payment_number, 'status' => $item->payment->status, 'net_amount' => (string) $item->payment->net_amount] : null])), 'status_history' => $this->whenLoaded('statusHistory')];
    }
}

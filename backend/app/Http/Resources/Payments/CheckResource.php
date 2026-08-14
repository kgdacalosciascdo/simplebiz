<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class CheckResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payment_instruction_id' => $this->payment_instruction_id, 'check_number' => $this->check_number, 'status' => $this->status, 'print_status' => $this->print_status, 'release_status' => $this->release_status, 'masked_reference' => $this->masked_reference, 'printed_at' => $this->printed_at?->toIso8601String(), 'signed_at' => $this->signed_at?->toIso8601String(), 'stopped_at' => $this->stopped_at?->toIso8601String(), 'stop_reason' => $this->stop_reason, 'voided_at' => $this->voided_at?->toIso8601String(), 'void_reason' => $this->void_reason, 'stale_at' => $this->stale_at?->toIso8601String(), 'replaces_instrument_id' => $this->replaces_instrument_id, 'replaced_by_instrument_id' => $this->replaced_by_instrument_id, 'payment' => $this->whenLoaded('payment', fn () => ['id' => $this->payment->id, 'payment_number' => $this->payment->payment_number, 'status' => $this->payment->status, 'supplier' => $this->payment->relationLoaded('supplier') ? $this->payment->supplier?->display_name : null, 'amount' => (string) $this->payment->net_amount, 'cash_movement_id' => $this->payment->cash_movement_id, 'clearing_status' => $this->payment->relationLoaded('cashMovement') ? $this->payment->cashMovement?->clearing_status : null, 'reconciliation_status' => $this->payment->relationLoaded('cashMovement') ? $this->payment->cashMovement?->reconciliation_status : null])];
    }
}

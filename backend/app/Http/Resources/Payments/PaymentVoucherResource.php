<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentVoucherResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payment_instruction_id' => $this->payment_instruction_id, 'voucher_number' => $this->voucher_number, 'status' => $this->status, 'reprint_count' => $this->reprint_count, 'issued_at' => $this->issued_at?->toIso8601String(), 'last_reprinted_at' => $this->last_reprinted_at?->toIso8601String()];
    }
}

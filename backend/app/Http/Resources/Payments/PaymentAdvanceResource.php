<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAdvanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payment_instruction_id' => $this->payment_instruction_id, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'original_amount' => (string) $this->original_amount, 'applied_amount' => (string) $this->applied_amount, 'available_amount' => (string) $this->available_amount, 'status' => $this->status, 'payment_number' => $this->whenLoaded('payment', fn () => $this->payment->payment_number)];
    }
}

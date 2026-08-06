<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class UnappliedReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'receipt_id' => $this->receipt_id, 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'display_name' => $this->customer->display_name] : null), 'original_amount' => $this->original_amount, 'applied_later_amount' => $this->applied_later_amount, 'available_amount' => $this->available_amount, 'status' => $this->status, 'received_date' => $this->received_date?->toDateString(), 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null)];
    }
}

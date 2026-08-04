<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'display_name' => $this->customer->display_name] : null), 'source_sale_id' => $this->source_sale_id, 'source_document_number' => $this->source_document_number, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'original_amount' => $this->original_amount, 'applied_amount' => $this->applied_amount, 'remaining_amount' => $this->remaining_amount, 'due_date' => $this->due_date?->toDateString(), 'settlement_status' => $this->settlement_status, 'due_status' => $this->due_status, 'dispute_status' => $this->dispute_status, 'version' => $this->version];
    }
}

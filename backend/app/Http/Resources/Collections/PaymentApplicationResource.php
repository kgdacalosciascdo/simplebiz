<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentApplicationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'receipt_id' => $this->receipt_id, 'customer_id' => $this->customer_id, 'receivable_open_item_id' => $this->receivable_open_item_id, 'source_document_number' => $this->whenLoaded('receivable', fn () => $this->receivable?->source_document_number), 'amount' => $this->amount, 'application_date' => $this->application_date?->toDateString(), 'status' => $this->status, 'version' => $this->version, 'applied_at' => $this->applied_at?->toISOString()];
    }
}

<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentRequestSourceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payable_open_item_id' => $this->payable_open_item_id, 'source_document_number' => $this->source_document_number, 'proposed_amount' => (string) $this->proposed_amount, 'currency_id' => $this->currency_id];
    }
}

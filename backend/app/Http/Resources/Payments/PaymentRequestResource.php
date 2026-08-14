<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'request_number' => $this->request_number, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]), 'branch_id' => $this->branch_id, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'requested_amount' => (string) $this->requested_amount, 'requested_payment_date' => $this->requested_payment_date?->toDateString(), 'reason' => $this->reason, 'evidence_reference' => $this->evidence_reference, 'status' => $this->status, 'sources' => PaymentRequestSourceResource::collection($this->whenLoaded('sources')), 'status_history' => $this->whenLoaded('statusHistory'), 'version' => $this->version];
    }
}

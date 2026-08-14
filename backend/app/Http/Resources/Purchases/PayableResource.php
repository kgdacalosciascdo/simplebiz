<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PayableResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'branch_id' => $this->branch_id, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]), 'source_supplier_invoice_id' => $this->source_supplier_invoice_id, 'source_document_number' => $this->source_document_number, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'original_amount' => (string) $this->original_amount, 'paid_amount' => (string) $this->paid_amount, 'remaining_amount' => (string) $this->remaining_amount, 'due_date' => $this->due_date?->toDateString(), 'settlement_status' => $this->settlement_status, 'due_status' => $this->due_status, 'hold_status' => $this->hold_status, 'hold_reason' => $this->hold_reason, 'payment_eligibility' => bccomp((string) $this->remaining_amount, '0', 6) > 0 && $this->hold_status === 'not_held' ? 'eligible_for_mds500' : ($this->hold_status === 'not_held' ? 'settled_or_closed' : 'held'), 'version' => $this->version, 'effects' => PayableEffectResource::collection($this->whenLoaded('effects')), 'hold_history' => $this->whenLoaded('holdHistory')];
    }
}

<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesAdjustmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'adjustment_number' => $this->adjustment_number, 'adjustment_type' => $this->adjustment_type, 'sale_id' => $this->sale_id, 'sale' => $this->whenLoaded('sale', fn () => $this->sale ? ['id' => $this->sale->id, 'sale_number' => $this->sale->sale_number] : null), 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'display_name' => $this->customer->display_name] : null), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'adjustment_date' => $this->adjustment_date?->toDateString(), 'amount' => (string) $this->amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount, 'customer_credit_amount' => (string) $this->customer_credit_amount, 'refund_status' => $this->refund_status, 'status' => $this->status, 'evidence_reference' => $this->evidence_reference, 'explanation' => $this->explanation, 'lines' => SalesAdjustmentLineResource::collection($this->whenLoaded('lines')), 'history' => $this->whenLoaded('statusHistory'), 'version' => $this->version, 'posted_at' => $this->posted_at, 'reversed_at' => $this->reversed_at];
    }
}

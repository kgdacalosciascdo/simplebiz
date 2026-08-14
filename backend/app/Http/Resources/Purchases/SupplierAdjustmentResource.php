<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierAdjustmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'adjustment_number' => $this->adjustment_number, 'adjustment_type' => $this->adjustment_type, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'display_name' => $this->supplier->display_name]), 'supplier_invoice_id' => $this->supplier_invoice_id, 'purchase_return_id' => $this->purchase_return_id, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'adjustment_date' => $this->adjustment_date?->toDateString(), 'amount' => (string) $this->amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount, 'status' => $this->status, 'explanation' => $this->explanation, 'lines' => SupplierAdjustmentLineResource::collection($this->whenLoaded('lines')), 'version' => $this->version];
    }
}

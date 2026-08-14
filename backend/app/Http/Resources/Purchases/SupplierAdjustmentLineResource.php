<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierAdjustmentLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'description' => $this->description, 'supplier_invoice_line_id' => $this->supplier_invoice_line_id, 'purchase_return_line_id' => $this->purchase_return_line_id, 'product_service_id' => $this->product_service_id, 'quantity' => $this->quantity === null ? null : (string) $this->quantity, 'unit_amount' => (string) $this->unit_amount, 'amount' => (string) $this->amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount];
    }
}

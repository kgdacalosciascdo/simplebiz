<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'product_service_id' => $this->product_service_id, 'product_code' => $this->product_code_snapshot, 'product_name' => $this->product_name_snapshot, 'description' => $this->description, 'unit_of_measure_id' => $this->unit_of_measure_id, 'unit_code' => $this->unit_code_snapshot, 'quantity' => (string) $this->quantity, 'unit_cost' => (string) $this->unit_cost, 'gross_amount' => (string) $this->gross_amount, 'discount_amount' => (string) $this->discount_amount, 'tax_amount' => (string) $this->tax_amount, 'net_amount' => (string) $this->net_amount, 'received_quantity' => (string) $this->received_quantity, 'invoiced_quantity' => (string) $this->invoiced_quantity, 'stock_managed' => (bool) $this->stock_managed_snapshot, 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id];
    }
}

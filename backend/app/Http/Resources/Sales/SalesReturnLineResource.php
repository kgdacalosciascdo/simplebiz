<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesReturnLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'sale_line_id' => $this->sale_line_id, 'product_service_id' => $this->product_service_id, 'product_code_snapshot' => $this->product_code_snapshot, 'product_name_snapshot' => $this->product_name_snapshot, 'unit_code_snapshot' => $this->unit_code_snapshot, 'unit_name_snapshot' => $this->unit_name_snapshot, 'original_quantity' => (string) $this->original_quantity, 'previously_returned_quantity' => (string) $this->previously_returned_quantity, 'quantity' => (string) $this->quantity, 'unit_price' => (string) $this->unit_price, 'taxable_amount' => (string) $this->taxable_amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount, 'stock_managed_snapshot' => (bool) $this->stock_managed_snapshot, 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'inventory_movement_id' => $this->inventory_movement_id, 'reason' => $this->reason];
    }
}

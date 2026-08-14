<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'purchase_order_line_id' => $this->purchase_order_line_id, 'product_service_id' => $this->product_service_id, 'product_code' => $this->product_code_snapshot, 'product_name' => $this->product_name_snapshot, 'quantity' => (string) $this->quantity, 'accepted_quantity' => (string) $this->accepted_quantity, 'rejected_quantity' => (string) $this->rejected_quantity, 'damaged_quantity' => (string) $this->damaged_quantity, 'reason' => $this->reason, 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'inventory_movement_id' => $this->inventory_movement_id];
    }
}

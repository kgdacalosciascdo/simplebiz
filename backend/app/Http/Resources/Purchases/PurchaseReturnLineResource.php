<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'goods_receipt_line_id' => $this->goods_receipt_line_id, 'purchase_order_line_id' => $this->purchase_order_line_id, 'product_service_id' => $this->product_service_id, 'product_name' => $this->product_name_snapshot, 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'quantity' => (string) $this->quantity, 'original_received_quantity' => (string) $this->original_received_quantity, 'previously_returned_quantity' => (string) $this->previously_returned_quantity, 'remaining_returnable_quantity' => bcsub(bcsub((string) $this->original_received_quantity, (string) $this->previously_returned_quantity, 6), (string) $this->quantity, 6), 'unit_cost' => (string) $this->unit_cost, 'total_cost' => (string) $this->total_cost, 'stock_managed' => (bool) $this->stock_managed_snapshot, 'condition' => $this->condition, 'reason' => $this->reason, 'stock_issue_id' => $this->stock_issue_id, 'stock_issue_line_id' => $this->stock_issue_line_id, 'inventory_movement_id' => $this->inventory_movement_id];
    }
}

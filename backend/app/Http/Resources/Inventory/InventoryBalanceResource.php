<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_service_id, 'product' => $this->whenLoaded('productService', fn () => ['id' => $this->productService->id, 'code' => $this->productService->code, 'name' => $this->productService->name]), 'warehouse_id' => $this->warehouse_id, 'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]), 'stock_location_id' => $this->stock_location_id, 'stock_location' => $this->whenLoaded('stockLocation', fn () => ['id' => $this->stockLocation->id, 'code' => $this->stockLocation->code, 'name' => $this->stockLocation->name]), 'unit' => $this->whenLoaded('unitOfMeasure', fn () => ['id' => $this->unitOfMeasure->id, 'code' => $this->unitOfMeasure->code, 'name' => $this->unitOfMeasure->name]), 'on_hand' => $this->on_hand, 'reserved' => $this->reserved, 'available' => $this->available, 'incoming' => $this->incoming, 'outgoing' => $this->outgoing, 'in_transit' => $this->in_transit, 'held' => $this->held, 'count_frozen' => $this->count_frozen, 'status' => $this->status, 'last_movement_at' => $this->last_movement_at];
    }
}

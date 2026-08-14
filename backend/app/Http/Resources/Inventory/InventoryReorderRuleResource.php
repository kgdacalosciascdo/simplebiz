<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryReorderRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_service_id, 'product' => $this->whenLoaded('productService', fn () => ['code' => $this->productService->code, 'name' => $this->productService->name]), 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'reorder_point' => $this->reorder_point, 'minimum_quantity' => $this->minimum_quantity, 'target_quantity' => $this->target_quantity, 'suggested_quantity' => $this->suggested_quantity, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'version' => $this->version];
    }
}

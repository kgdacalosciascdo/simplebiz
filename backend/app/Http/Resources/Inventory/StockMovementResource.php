<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_service_id, 'product' => $this->whenLoaded('productService', fn () => ['code' => $this->productService->code, 'name' => $this->productService->name]), 'warehouse_id' => $this->warehouse_id, 'warehouse' => $this->whenLoaded('warehouse', fn () => ['code' => $this->warehouse->code, 'name' => $this->warehouse->name]), 'stock_location_id' => $this->stock_location_id, 'stock_location' => $this->whenLoaded('stockLocation', fn () => ['code' => $this->stockLocation->code, 'name' => $this->stockLocation->name]), 'movement_type' => $this->movement_type, 'direction' => $this->direction, 'quantity' => $this->quantity, 'unit_code' => $this->unit_code_snapshot, 'business_date' => $this->business_date?->toDateString(), 'posted_at' => $this->posted_at, 'source_module' => $this->source_module, 'source_type' => $this->source_type, 'source_id' => $this->source_id, 'source_line_id' => $this->source_line_id, 'source_document_number' => $this->source_document_number, 'status' => $this->status, 'original_movement_id' => $this->original_movement_id, 'reversal_movement_id' => $this->reversal_movement_id, 'transfer_id' => $this->transfer_id, 'explanation' => $this->explanation];
    }
}

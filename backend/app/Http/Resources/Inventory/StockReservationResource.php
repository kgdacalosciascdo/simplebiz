<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reservation_number' => $this->reservation_number, 'source_type' => $this->source_type, 'source_id' => $this->source_id, 'source_line_id' => $this->source_line_id, 'source_document_number' => $this->source_document_number, 'product_id' => $this->product_service_id, 'product' => $this->whenLoaded('productService', fn () => ['code' => $this->productService->code, 'name' => $this->productService->name]), 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'quantity' => $this->quantity, 'consumed_quantity' => $this->consumed_quantity, 'released_quantity' => $this->released_quantity, 'remaining_quantity' => $this->remaining, 'status' => $this->status, 'expires_at' => $this->expires_at, 'version' => $this->version];
    }
}

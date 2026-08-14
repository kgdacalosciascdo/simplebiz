<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'adjustment_number' => $this->adjustment_number, 'business_date' => $this->business_date?->toDateString(), 'source_type' => $this->source_type, 'source_reference' => $this->source_reference, 'reason_code_id' => $this->reason_code_id, 'evidence_reference' => $this->evidence_reference, 'explanation' => $this->explanation, 'status' => $this->status, 'version' => $this->version, 'stock_count_id' => $this->stock_count_id, 'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => ['id' => $line->id, 'product_id' => $line->product_service_id, 'product_code' => $line->product_code_snapshot, 'product_name' => $line->product_name_snapshot, 'warehouse_id' => $line->warehouse_id, 'stock_location_id' => $line->stock_location_id, 'unit_code' => $line->unit_code_snapshot, 'quantity' => $line->quantity, 'direction' => $line->direction, 'expected_quantity' => $line->expected_quantity, 'resulting_quantity' => $line->resulting_quantity, 'movement_id' => $line->movement_id, 'unit_cost' => $this->costVisible($request) ? $line->unit_cost : null, 'total_cost' => $this->costVisible($request) ? $line->total_cost : null, 'currency_code' => $this->costVisible($request) ? $line->currency_code : null])->values()), 'created_at' => $this->created_at, 'posted_at' => $this->posted_at];
    }

    private function costVisible(Request $request): bool
    {
        return (bool) $request->user()?->hasPermission('inventory.cost.view', $request->attributes->get('company')?->id);
    }
}

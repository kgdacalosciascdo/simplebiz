<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $blind = (bool) $this->blind && ! $request->user()?->hasPermission('inventory.counts.review', $request->attributes->get('company')?->id);

        return ['id' => $this->id, 'count_number' => $this->count_number, 'business_date' => $this->business_date?->toDateString(), 'mode' => $this->mode, 'warehouse_id' => $this->warehouse_id, 'stock_location_id' => $this->stock_location_id, 'blind' => $this->blind, 'status' => $this->status, 'snapshot_at' => $this->snapshot_at, 'version' => $this->version, 'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->product_service_id, 'product_code' => $item->product_code_snapshot, 'product_name' => $item->product_name_snapshot, 'unit_code' => $item->unit_code_snapshot, 'expected_quantity' => $blind ? null : $item->expected_quantity, 'counted_quantity' => $item->counted_quantity, 'variance_quantity' => $blind ? null : $item->variance_quantity, 'variance_status' => $item->variance_status, 'recount_required' => $item->recount_required, 'latest_entry_id' => $item->latest_entry_id])->values()), 'created_at' => $this->created_at, 'started_at' => $this->started_at, 'posted_at' => $this->posted_at, 'closed_at' => $this->closed_at];
    }
}

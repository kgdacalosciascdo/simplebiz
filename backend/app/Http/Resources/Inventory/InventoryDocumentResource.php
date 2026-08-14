<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'document_number' => $this->document_number, 'source_type' => $this->source_type ?? 'opening_stock', 'source_reference' => $this->source_reference, 'business_date' => $this->business_date?->toDateString(), 'status' => $this->status, 'explanation' => $this->explanation, 'version' => $this->version, 'lines' => $this->whenLoaded('lines'), 'created_at' => $this->created_at, 'posted_at' => $this->posted_at];
    }
}

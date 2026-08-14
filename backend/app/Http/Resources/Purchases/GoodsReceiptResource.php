<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'receipt_number' => $this->receipt_number, 'purchase_order_id' => $this->purchase_order_id, 'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => ['id' => $this->purchaseOrder->id, 'order_number' => $this->purchaseOrder->order_number]), 'supplier_id' => $this->supplier_id, 'receipt_date' => $this->receipt_date?->toDateString(), 'supplier_delivery_reference' => $this->supplier_delivery_reference, 'status' => $this->status, 'explanation' => $this->explanation, 'version' => $this->version, 'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

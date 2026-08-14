<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'return_number' => $this->return_number, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'display_name' => $this->supplier->display_name]), 'purchase_order_id' => $this->purchase_order_id, 'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => ['id' => $this->purchaseOrder->id, 'order_number' => $this->purchaseOrder->order_number]), 'goods_receipt_id' => $this->goods_receipt_id, 'goods_receipt' => $this->whenLoaded('goodsReceipt', fn () => ['id' => $this->goodsReceipt->id, 'receipt_number' => $this->goodsReceipt->receipt_number]), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'return_date' => $this->return_date?->toDateString(), 'status' => $this->status, 'supplier_authorization_reference' => $this->supplier_authorization_reference, 'shipping_reference' => $this->shipping_reference, 'evidence_reference' => $this->evidence_reference, 'explanation' => $this->explanation, 'lines' => PurchaseReturnLineResource::collection($this->whenLoaded('lines')), 'version' => $this->version];
    }
}

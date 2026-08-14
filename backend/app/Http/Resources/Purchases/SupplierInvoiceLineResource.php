<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInvoiceLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'product_service_id' => $this->product_service_id, 'product_code' => $this->product_code_snapshot, 'product_name' => $this->product_name_snapshot, 'description' => $this->description, 'quantity' => (string) $this->quantity, 'unit_cost' => (string) $this->unit_cost, 'tax_amount' => (string) $this->tax_amount, 'net_amount' => (string) $this->net_amount, 'stock_managed' => (bool) $this->stock_managed_snapshot, 'purchase_order_line_id' => $this->purchase_order_line_id, 'goods_receipt_line_id' => $this->goods_receipt_line_id];
    }
}

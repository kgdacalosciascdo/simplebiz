<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_service_id' => $this->product_service_id, 'item_type' => $this->item_type, 'item_code' => $this->item_code_snapshot, 'description' => $this->description_snapshot, 'unit_code' => $this->unit_code, 'unit_name' => $this->unit_name, 'quantity' => $this->quantity, 'unit_price' => $this->unit_price, 'gross_amount' => $this->gross_amount, 'discount_type' => $this->discount_type, 'discount_value' => $this->discount_value, 'discount_amount' => $this->discount_amount, 'tax_code_id' => $this->tax_code_id, 'tax_code' => $this->tax_code_snapshot, 'tax_basis' => $this->tax_basis, 'tax_rate' => $this->tax_rate, 'taxable_amount' => $this->taxable_amount, 'tax_amount' => $this->tax_amount, 'net_amount' => $this->net_amount, 'stock_managed' => $this->stock_managed_snapshot, 'non_stock' => $this->non_stock_snapshot, 'service' => $this->service_snapshot];
    }
}

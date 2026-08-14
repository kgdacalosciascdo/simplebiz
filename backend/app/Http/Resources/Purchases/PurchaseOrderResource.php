<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'order_number' => $this->order_number, 'purchase_type' => $this->purchase_type, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'payment_term_id' => $this->payment_term_id, 'purchase_date' => $this->purchase_date?->toDateString(), 'required_date' => $this->required_date?->toDateString(), 'supplier_reference' => $this->supplier_reference, 'notes' => $this->notes, 'status' => $this->status, 'subtotal' => (string) $this->subtotal, 'line_discount_total' => (string) $this->line_discount_total, 'taxable_amount' => (string) $this->taxable_amount, 'tax_total' => (string) $this->tax_total, 'total' => (string) $this->total, 'received_amount' => (string) $this->received_amount, 'invoiced_amount' => (string) $this->invoiced_amount, 'version' => $this->version, 'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')), 'history' => $this->whenLoaded('statusHistory'), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

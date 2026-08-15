<?php

namespace App\Http\Resources\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

class SalesAdjustmentLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'sale_line_id' => $this->sale_line_id, 'description' => $this->description, 'quantity' => $this->quantity === null ? null : (string) $this->quantity, 'unit_amount' => (string) $this->unit_amount, 'amount' => (string) $this->amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount];
    }
}

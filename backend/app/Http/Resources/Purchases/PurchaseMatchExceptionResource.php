<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseMatchExceptionResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'supplier_invoice_id' => $this->supplier_invoice_id, 'supplier_invoice_line_id' => $this->supplier_invoice_line_id, 'exception_type' => $this->exception_type, 'status' => $this->status, 'expected_amount' => $this->expected_amount === null ? null : (string) $this->expected_amount, 'actual_amount' => $this->actual_amount === null ? null : (string) $this->actual_amount, 'explanation' => $this->explanation, 'resolution' => $this->resolution, 'resolved_at' => $this->resolved_at];
    }
}

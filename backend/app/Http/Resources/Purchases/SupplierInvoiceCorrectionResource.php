<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInvoiceCorrectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'correction_number' => $this->correction_number, 'correction_type' => $this->correction_type, 'original_supplier_invoice_id' => $this->original_supplier_invoice_id, 'original_invoice' => $this->whenLoaded('originalInvoice', fn () => ['id' => $this->originalInvoice->id, 'invoice_number' => $this->originalInvoice->invoice_number]), 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'display_name' => $this->supplier->display_name]), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code]), 'correction_date' => $this->correction_date?->toDateString(), 'reason' => $this->reason, 'evidence_reference' => $this->evidence_reference, 'amount' => (string) $this->amount, 'tax_amount' => (string) $this->tax_amount, 'total_amount' => (string) $this->total_amount, 'status' => $this->status, 'version' => $this->version];
    }
}

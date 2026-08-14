<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'invoice_number' => $this->invoice_number, 'external_invoice_number' => $this->external_invoice_number, 'supplier_id' => $this->supplier_id, 'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'display_name' => $this->supplier->display_name]), 'purchase_order_id' => $this->purchase_order_id, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol]), 'invoice_date' => $this->invoice_date?->toDateString(), 'due_date' => $this->due_date?->toDateString(), 'match_status' => $this->match_status, 'match_exception' => $this->match_exception, 'match_tolerance_amount' => (string) $this->match_tolerance_amount, 'status' => $this->status, 'subtotal' => (string) $this->subtotal, 'tax_total' => (string) $this->tax_total, 'total' => (string) $this->total, 'paid_amount' => (string) $this->paid_amount, 'remaining_amount' => (string) $this->remaining_amount, 'payable_open_item_id' => $this->payable_open_item_id, 'version' => $this->version, 'lines' => SupplierInvoiceLineResource::collection($this->whenLoaded('lines')), 'match_exceptions' => PurchaseMatchExceptionResource::collection($this->whenLoaded('matchExceptions')), 'match_history' => PurchaseMatchHistoryResource::collection($this->whenLoaded('matchHistory')), 'corrections' => SupplierInvoiceCorrectionResource::collection($this->whenLoaded('corrections')), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

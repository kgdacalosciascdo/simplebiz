<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\Collections\ReceiptResource;
use App\Http\Resources\Inventory\StockMovementResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'sale_number' => $this->sale_number, 'sale_type' => $this->sale_type, 'payment_basis' => $this->payment_basis, 'sale_date' => $this->sale_date?->toDateString(), 'branch_id' => $this->branch_id, 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'display_name' => $this->customer->display_name] : null), 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'payment_term_id' => $this->payment_term_id, 'due_date' => $this->due_date?->toDateString(), 'status' => $this->status, 'return_status' => $this->return_status, 'settlement_status' => $this->settlement_status, 'due_status' => $this->due_status, 'customer_reference' => $this->customer_reference, 'channel' => $this->channel, 'notes' => $this->notes, 'document_discount_type' => $this->document_discount_type, 'document_discount_value' => $this->document_discount_value, 'subtotal' => $this->subtotal, 'line_discount_total' => $this->line_discount_total, 'document_discount_total' => $this->document_discount_total, 'taxable_amount' => $this->taxable_amount, 'tax_total' => $this->tax_total, 'total' => $this->total, 'paid_amount' => $this->paid_amount, 'receivable_amount' => $this->receivable_amount, 'remaining_amount' => $this->remaining_amount, 'blocked_code' => $this->blocked_code, 'blocked_reason' => $this->blocked_reason, 'version' => $this->version, 'lines' => SaleLineResource::collection($this->whenLoaded('lines')), 'inventory_movements' => StockMovementResource::collection($this->whenLoaded('inventoryMovements')), 'returns' => SalesReturnResource::collection($this->whenLoaded('salesReturns')), 'adjustments' => SalesAdjustmentResource::collection($this->whenLoaded('salesAdjustments')), 'receipts' => ReceiptResource::collection($this->whenLoaded('receipts')), 'receivable' => new ReceivableResource($this->whenLoaded('receivable')), 'history' => $this->whenLoaded('statusHistory'), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

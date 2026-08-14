<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'receipt_number' => $this->receipt_number, 'receipt_type' => $this->receipt_type, 'other_receipt_type' => $this->other_receipt_type, 'receipt_date' => $this->receipt_date?->toDateString(), 'customer_id' => $this->customer_id, 'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'display_name' => $this->customer->display_name] : null), 'counterparty_name' => $this->counterparty_name, 'source_module' => $this->source_module, 'source_reference' => $this->source_reference, 'classification' => $this->classification, 'business_purpose' => $this->business_purpose, 'evidence_reference' => $this->evidence_reference, 'source_sale_id' => $this->source_sale_id, 'currency' => $this->whenLoaded('currency', fn () => $this->currency ? ['id' => $this->currency->id, 'code' => $this->currency->code, 'symbol' => $this->currency->symbol] : null), 'amount' => $this->amount, 'tender_total' => $this->tender_total, 'applied_total' => $this->applied_total, 'unapplied_amount' => $this->unapplied_amount, 'external_reference' => $this->external_reference, 'customer_reference' => $this->customer_reference, 'status' => $this->status, 'application_status' => $this->application_status, 'version' => $this->version, 'tenders' => ReceiptTenderResource::collection($this->whenLoaded('tenders')), 'applications' => PaymentApplicationResource::collection($this->whenLoaded('applications')), 'unapplied' => new UnappliedReceiptResource($this->whenLoaded('unapplied')), 'history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory), 'reprints' => $this->whenLoaded('reprints'), 'activities' => $this->whenLoaded('activities'), 'created_at' => $this->created_at?->toISOString()];
    }
}

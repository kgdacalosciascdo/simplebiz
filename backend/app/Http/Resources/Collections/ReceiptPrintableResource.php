<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptPrintableResource extends JsonResource
{
    public function toArray($request): array
    {
        $reprint = $this->getAttribute('print_reprint');
        $company = $this->getAttribute('print_company');
        $mask = static fn (?string $value): ?string => self::mask($value);

        return [
            'id' => $this->id,
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
                'legal_name' => $company?->legal_name,
                'address' => $company?->principal_address,
            ],
            'receipt_number' => $this->receipt_number,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'receipt_type' => $this->receipt_type,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'display_name' => $this->customer->display_name,
            ] : null,
            'payer_name' => $this->payer_name_snapshot,
            'counterparty_name' => $this->counterparty_name,
            'branch' => $this->sourceSale?->branch ? [
                'id' => $this->sourceSale->branch->id,
                'code' => $this->sourceSale->branch->code,
                'name' => $this->sourceSale->branch->name,
            ] : null,
            'currency' => $this->currency ? [
                'id' => $this->currency->id,
                'code' => $this->currency->code,
                'symbol' => $this->currency->symbol,
                'decimal_precision' => $this->currency->decimal_precision,
            ] : null,
            'amount' => (string) $this->amount,
            'tender_total' => (string) $this->tender_total,
            'applied_total' => (string) $this->applied_total,
            'unapplied_amount' => (string) $this->unapplied_amount,
            'status' => $this->status,
            'application_status' => $this->application_status,
            'external_reference' => $mask($this->external_reference),
            'customer_reference' => $mask($this->customer_reference),
            'source' => [
                'sale_id' => $this->source_sale_id,
                'sale_number' => $this->sourceSale?->sale_number,
                'source_module' => $this->source_module,
                'source_reference' => $mask($this->source_reference),
            ],
            'tenders' => $this->tenders->map(fn ($tender) => [
                'id' => $tender->id,
                'payment_method' => $tender->paymentMethod?->name,
                'cash_account' => $tender->cashAccount?->display_name ?? $tender->cashAccount?->name,
                'amount' => (string) $tender->amount,
                'instrument_status' => $tender->instrument_status,
                'clearing_status' => $tender->clearing_status,
                'external_reference' => $mask($tender->external_reference),
                'instrument_reference' => $mask($tender->instrument_reference),
            ])->values(),
            'applications' => $this->applications->map(fn ($application) => [
                'id' => $application->id,
                'open_item_id' => $application->receivable_open_item_id,
                'document_number' => $application->receivable?->source_document_number,
                'source_sale_id' => $application->receivable?->source_sale_id,
                'amount' => (string) $application->amount,
                'application_date' => $application->application_date?->toDateString(),
                'status' => $application->status,
            ])->values(),
            'issuer' => $this->postedBy?->name ?? $this->createdBy?->name,
            'reversal' => [
                'status' => $this->status === 'reversed' ? 'reversed' : null,
                'reason' => $this->correction_reason,
                'reversed_at' => $this->reversed_at?->toISOString(),
            ],
            'copy' => [
                'is_reprint' => (bool) $reprint,
                'label' => $reprint ? 'DUPLICATE / REPRINT' : 'ORIGINAL',
                'reprint_id' => $reprint?->id,
                'reprint_reason' => $reprint?->reason,
                'reprinted_at' => $reprint?->created_at?->toISOString(),
                'reprint_count' => (int) $this->reprints->count(),
            ],
            'notes' => $this->notes,
            'evidence_reference' => $mask($this->evidence_reference),
        ];
    }

    private static function mask(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $length = strlen($value);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', max(4, $length - 4)).substr($value, -4);
    }
}

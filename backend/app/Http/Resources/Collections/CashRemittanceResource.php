<?php

namespace App\Http\Resources\Collections;

use Illuminate\Http\Resources\Json\JsonResource;

class CashRemittanceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'remittance_number' => $this->remittance_number,
            'currency_id' => $this->currency_id,
            'source_cash_account_id' => $this->source_cash_account_id,
            'destination_cash_account_id' => $this->destination_cash_account_id,
            'source_cash_account' => $this->whenLoaded('sourceAccount', fn () => $this->sourceAccount ? ['id' => $this->sourceAccount->id, 'code' => $this->sourceAccount->code, 'name' => $this->sourceAccount->name, 'currency_id' => $this->sourceAccount->currency_id] : null),
            'destination_cash_account' => $this->whenLoaded('destinationAccount', fn () => $this->destinationAccount ? ['id' => $this->destinationAccount->id, 'code' => $this->destinationAccount->code, 'name' => $this->destinationAccount->name, 'currency_id' => $this->destinationAccount->currency_id] : null),
            'remittance_date' => $this->remittance_date?->toDateString(),
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'status' => $this->status,
            'expected_amount' => (string) $this->expected_amount,
            'submitted_amount' => (string) $this->submitted_amount,
            'difference_amount' => (string) $this->difference_amount,
            'evidence_reference' => $this->evidence_reference,
            'deposit_reference' => $this->deposit_reference,
            'prepared_by' => $this->prepared_by,
            'submitted_by' => $this->submitted_by,
            'verified_by' => $this->verified_by,
            'accepted_by' => $this->accepted_by,
            'cash_transfer_document_id' => $this->cash_transfer_document_id,
            'transfer_posted_at' => $this->transfer_posted_at?->toISOString(),
            'transfer' => $this->whenLoaded('transfer', fn () => $this->transfer ? [
                'id' => $this->transfer->id,
                'document_number' => $this->transfer->document_number,
                'status' => $this->transfer->status,
                'amount' => (string) $this->transfer->amount,
                'source_movement_id' => $this->transfer->source_movement_id,
                'destination_movement_id' => $this->transfer->destination_movement_id,
                'legs' => $this->transfer->legs->map(fn ($leg) => ['id' => $leg->id, 'cash_movement_id' => $leg->cash_movement_id, 'cash_account_id' => $leg->cash_account_id, 'direction' => $leg->direction])->values(),
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => ['id' => $line->id, 'receipt_tender_id' => $line->receipt_tender_id, 'expected_amount' => (string) $line->expected_amount, 'submitted_amount' => (string) $line->submitted_amount, 'difference_amount' => (string) $line->difference_amount, 'receipt_number' => $line->tender?->receipt?->receipt_number])->values()),
            'variances' => $this->whenLoaded('variances', fn () => $this->variances),
        ];
    }
}

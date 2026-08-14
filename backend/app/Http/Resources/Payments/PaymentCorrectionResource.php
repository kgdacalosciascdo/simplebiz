<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentCorrectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'original_payment_id' => $this->original_payment_id, 'correction_number' => $this->correction_number, 'correction_type' => $this->correction_type, 'status' => $this->status, 'original_status' => $this->original_status, 'amount' => (string) $this->amount, 'reason' => $this->reason, 'evidence_reference' => $this->evidence_reference, 'reversal_cash_movement_id' => $this->reversal_cash_movement_id, 'accounting_transaction_id' => $this->accounting_transaction_id, 'completed_at' => $this->completed_at?->toIso8601String()];
    }
}

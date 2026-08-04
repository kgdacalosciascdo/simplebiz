<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'cash_account_id' => $this->cash_account_id, 'direction' => $this->direction, 'amount' => (string) $this->amount, 'currency' => $this->currency_code, 'business_date' => $this->business_date?->toDateString(), 'posted_at' => $this->posted_at, 'source_event_type' => $this->source_event_type, 'source_record_type' => $this->source_record_type, 'source_record_id' => $this->source_record_id, 'source_reference' => $this->source_reference, 'movement_status' => $this->movement_status, 'clearing_status' => $this->clearing_status, 'reconciliation_status' => $this->reconciliation_status, 'original_movement_id' => $this->original_movement_id, 'reversal_movement_id' => $this->reversal_movement_id, 'accounting_transaction_id' => $this->accounting_transaction_id, 'correlation_id' => $this->correlation_id];
    }
}

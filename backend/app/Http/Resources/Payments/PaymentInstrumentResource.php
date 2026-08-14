<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentInstrumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'instrument_type' => $this->instrument_type, 'status' => $this->status, 'check_number' => $this->check_number, 'masked_reference' => $this->masked_reference, 'external_reference' => $this->external_reference, 'print_status' => $this->print_status, 'release_status' => $this->release_status, 'evidence_reference' => $this->evidence_reference, 'cash_account_id' => $this->cash_account_id];
    }
}

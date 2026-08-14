<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentConfirmationResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'confirmed_amount' => (string) $this->confirmed_amount, 'confirmed_date' => $this->confirmed_date?->toDateString(), 'external_reference' => $this->external_reference, 'evidence_reference' => $this->evidence_reference, 'reason' => $this->reason, 'manual_confirmation' => (bool) $this->manual_confirmation, 'confirmed_by' => $this->confirmed_by, 'confirmed_at' => $this->confirmed_at?->toISOString()];
    }
}

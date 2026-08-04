<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovementPurposeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'document_kind' => $this->document_kind, 'direction' => $this->direction, 'required_capability' => $this->required_capability, 'requires_destination' => $this->requires_destination, 'requires_payment_method' => $this->requires_payment_method, 'requires_evidence' => $this->requires_evidence, 'reason_domain' => $this->reason_domain, 'clearing_mode' => $this->clearing_mode, 'approval_required' => $this->approval_required, 'reversal_allowed' => $this->reversal_allowed, 'allowed_source_types' => $this->allowed_source_types];
    }
}

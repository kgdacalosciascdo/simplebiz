<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentApprovalResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'action' => $this->action, 'submitted_version' => $this->submitted_version, 'actor_id' => $this->actor_id, 'reason' => $this->reason, 'authority_context' => $this->authority_context, 'acted_at' => $this->acted_at?->toISOString()];
    }
}

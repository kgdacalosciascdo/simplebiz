<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentExecutionAttemptResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'attempt_number' => $this->attempt_number, 'channel' => $this->channel, 'status' => $this->status, 'external_reference' => $this->external_reference, 'response_code' => $this->response_code, 'response_message' => $this->response_message, 'started_at' => $this->started_at?->toISOString(), 'finished_at' => $this->finished_at?->toISOString()];
    }
}

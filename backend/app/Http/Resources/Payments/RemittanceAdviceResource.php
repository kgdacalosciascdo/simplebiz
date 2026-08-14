<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Resources\Json\JsonResource;

class RemittanceAdviceResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'advice_number' => $this->advice_number, 'status' => $this->status, 'issued_at' => $this->issued_at?->toISOString()];
    }
}

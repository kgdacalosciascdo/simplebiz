<?php

namespace App\Http\Resources\Purchases;

use Illuminate\Http\Resources\Json\JsonResource;

class PayableEffectResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'payable_open_item_id' => $this->payable_open_item_id, 'source_type' => $this->source_type, 'source_id' => $this->source_id, 'effect_type' => $this->effect_type, 'amount_delta' => (string) $this->amount_delta, 'currency_id' => $this->currency_id, 'description' => $this->description, 'created_at' => $this->created_at];
    }
}

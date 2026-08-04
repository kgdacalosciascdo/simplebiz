<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashDenominationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'currency_id' => $this->currency_id, 'currency' => $this->whenLoaded('currency', fn () => ['id' => $this->currency->id, 'code' => $this->currency->code, 'name' => $this->currency->name]), 'denomination_type' => $this->denomination_type, 'face_value' => (string) $this->face_value, 'display_label' => $this->display_label, 'sort_order' => $this->sort_order, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'system_standard' => $this->system_standard, 'version' => $this->version];
    }
}

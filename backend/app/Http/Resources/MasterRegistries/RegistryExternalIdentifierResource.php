<?php

namespace App\Http\Resources\MasterRegistries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistryExternalIdentifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $value = (string) $this->value;
        $lastFour = mb_substr($value, -4);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'registry_type' => $this->registry_type,
            'record_id' => $this->record_id,
            'identifier_type' => $this->identifier_type,
            'masked_value' => mb_strlen($value) > 4 ? str_repeat('•', min(8, max(4, mb_strlen($value) - 4))).$lastFour : '••••',
            'last_four' => $lastFour,
            'source_system' => $this->source_system,
            'status' => $this->status,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'version' => $this->version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources\MasterRegistries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessPartnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'code' => $this->code, 'party_type' => $this->party_type, 'official_name' => $this->official_name, 'display_name' => $this->display_name, 'trade_name' => $this->trade_name, 'tax_reference' => $this->tax_reference, 'primary_email' => $this->primary_email, 'primary_phone' => $this->primary_phone, 'notes' => $this->notes, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'version' => $this->version, 'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => ['id' => $role->id, 'role' => $role->role, 'status' => $role->status])), 'contacts' => $this->whenLoaded('contacts'), 'addresses' => $this->whenLoaded('addresses'), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

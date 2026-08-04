<?php

namespace App\Http\Resources\MasterRegistries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'symbol' => $this->symbol, 'unit_type' => $this->unit_type, 'decimal_precision' => $this->decimal_precision, 'allows_fractional' => $this->allows_fractional, 'status' => $this->status, 'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(), 'version' => $this->version, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at];
    }
}

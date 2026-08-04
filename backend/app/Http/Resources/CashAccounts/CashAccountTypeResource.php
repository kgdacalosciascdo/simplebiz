<?php

namespace App\Http\Resources\CashAccounts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashAccountTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'classification' => $this->classification, 'default_capabilities' => $this->default_capabilities, 'allowed_capabilities' => $this->allowed_capabilities, 'requires_custodian' => $this->requires_custodian, 'supports_cash_count' => $this->supports_cash_count, 'supports_reconciliation' => $this->supports_reconciliation, 'supports_statement_import' => $this->supports_statement_import, 'supports_check' => $this->supports_check, 'system_standard' => $this->system_standard, 'status' => $this->status, 'version' => $this->version];
    }
}

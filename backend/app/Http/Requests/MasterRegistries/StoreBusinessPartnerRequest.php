<?php

namespace App\Http\Requests\MasterRegistries;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:80'], 'party_type' => ['nullable', 'in:individual,organization'], 'official_name' => ['required', 'string', 'max:180'], 'display_name' => ['nullable', 'string', 'max:180'], 'trade_name' => ['nullable', 'string', 'max:180'], 'tax_reference' => ['nullable', 'string', 'max:120'], 'primary_email' => ['nullable', 'email', 'max:255'], 'primary_phone' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string'], 'roles' => ['nullable', 'array'], 'roles.*' => ['in:customer,supplier,payee'], 'effective_from' => ['nullable', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'duplicate_override' => ['nullable', 'boolean'], 'duplicate_override_reason' => ['required_if:duplicate_override,true', 'string', 'max:500'], 'external_identifiers' => ['nullable', 'array'], 'external_identifiers.*.identifier_type' => ['required_with:external_identifiers', 'string', 'max:60'], 'external_identifiers.*.value' => ['required_with:external_identifiers', 'string', 'max:180']];
    }
}
